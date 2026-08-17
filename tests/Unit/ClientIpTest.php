<?php // tests/Unit/ClientIpTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * CONTRACT TEST — written independently, BEFORE support/ClientIp.php exists
 * (spec `intake-to-core`, T03, Test-author: split, Tier A).
 *
 * This file pins the canonical client-IP resolver that replaces the FOUR
 * divergent copies (ntdst-core Endpoints + Logger, ntdst-baseline, todai
 * intake). It is IMMUTABLE for the implementer: green it, never weaken it.
 * A dispute is escalated, not edited.
 *
 * WHY THIS TEST EXISTS (threat 10 — X-Forwarded-For spoofing). The two
 * implementations being merged are each half-right, and a test that passes
 * against either one alone has NOT pinned the contract:
 *
 *   - ntdst-baseline/support/ClientIp.php has CIDR-aware trust matching
 *     (correct) but returns the LEFTMOST valid X-Forwarded-For entry —
 *     spoofable: whatever the client itself prepended is handed back.
 *   - NTDST_Endpoints::getClientIp() walks the chain right-to-left,
 *     skipping trusted hops (correct), but matches trusted proxies with
 *     in_array() only — wrong behind Ploi/Cloudflare, which front from a
 *     RANGE, so the whole allow-list silently fails open to REMOTE_ADDR
 *     use... and, worse, tempts operators to widen the list.
 *
 * The canonical resolver must have BOTH halves: CIDR-aware trust matching
 * AND the right-to-left skip-trusted walk.
 *
 * ── API pinned here ───────────────────────────────────────────────────────
 *   NTDST_ClientIp::resolve(array $server, array $trustedProxies): string
 *       Pure. Applies NO filters — the caller's list is the whole truth.
 *       (Signature carried over unchanged from ntdst-baseline's
 *       SecurityService.php:392 call site, so baseline can delegate in T03b
 *       without changing its `ntdst/baseline/security/config` surface.)
 *
 *   NTDST_ClientIp::detect(array $server): string
 *       The WordPress-aware entry point. Applies `ntdst/trusted_proxies`
 *       and THEN the historical `netdust_trusted_proxies` (FR-1b), then
 *       delegates to resolve(). This is what NTDST_Endpoints and
 *       NTDST_Logger consume.
 *
 *   Class naming follows this package's convention (NTDST_Scheduler,
 *   NTDST_Logger, NTDST_Endpoints): global classes, `NTDST_` prefix. Files
 *   land at support/ClientIp.php + support/Cidr.php and are added to
 *   ntdst-core.php's explicit require list and to tests/bootstrap.php —
 *   wiring the loader is part of greening this RED. This file deliberately
 *   requires nothing, so the RED reads "class absent", not "path stale".
 *
 * ── DOCUMENTED FALLBACK, decided here (the two sources disagree) ──────────
 *   Missing / empty / unparseable REMOTE_ADDR resolves to '' (the empty
 *   string) — ntdst-baseline's contract, not Endpoints' '0.0.0.0'.
 *   Rationale: 0.0.0.0 is a REAL address (the unspecified address). Handing
 *   it back invents an identity that every unidentifiable caller shares, so
 *   CLI, cron and malformed requests would collide into ONE rate-limit
 *   bucket and one audit-log subject. '' is the honest "no client IP" and
 *   is unmistakable at the call site. A consumer that wants the historical
 *   placeholder coalesces at its own call site ( ?: '0.0.0.0' ); the shared
 *   primitive must not decide that for eleven packages.
 *
 * Every assertion below is derived from the spec's FR-1 / FR-1b, the
 * threat model (threat 10) and the two source implementations' documented
 * behaviour — not from any implementation of the new class.
 */
final class ClientIpTest extends TestCase
{
    // Verifies Mockery/Brain Monkey expectations into PHPUnit's assertion
    // count, so failOnRisky="true" does not flag filter-only tests.
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // 1. DENIAL PATH — the security core (threat 10).
    //    An untrusted peer's X-Forwarded-For is not evidence of anything.
    // =====================================================================

    public function test_untrusted_remote_addr_makes_forwarded_for_be_ignored_entirely(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '198.51.100.7',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('198.51.100.7', $resolved);
    }

    public function test_untrusted_remote_addr_cannot_promote_itself_by_claiming_a_trusted_hop(): void
    {
        // The attacker forges a chain that looks exactly like traffic from
        // the trusted proxy. Trust is decided on REMOTE_ADDR alone — the
        // one value the attacker cannot choose.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '203.0.113.66',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 10.0.0.1',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('203.0.113.66', $resolved);
    }

    public function test_empty_trusted_list_denies_every_forwarded_for(): void
    {
        // Default-deny: with nothing trusted, no header can move the needle,
        // not even one arriving from loopback.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ],
            [],
        );

        $this->assertSame('127.0.0.1', $resolved);
    }

    public function test_ip_just_outside_the_trusted_range_is_not_trusted(): void
    {
        // 11.0.0.1 is one range away from 10.0.0.0/8 — boundary, denied.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '11.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('11.0.0.1', $resolved);
    }

    public function test_ipv4_mapped_ipv6_remote_addr_does_not_match_an_ipv4_range(): void
    {
        // Family-confusion bypass: ::ffff:10.0.0.1 renders as "inside"
        // 10.0.0.0/8 to a textual matcher. Binary comparison is fail-closed
        // across address families, so this peer stays untrusted.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '::ffff:10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('::ffff:10.0.0.1', $resolved);
    }

    // =====================================================================
    // 2. CIDR-AWARE TRUST — what the exact-match copies get wrong.
    // =====================================================================

    public function test_proxy_inside_an_ipv4_cidr_range_is_trusted(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.4.5.6',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_proxy_inside_an_ipv6_cidr_range_is_trusted(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '2001:db8:0:1::5',
                'HTTP_X_FORWARDED_FOR' => '2606:4700::1111',
            ],
            ['2001:db8::/32'],
        );

        $this->assertSame('2606:4700::1111', $resolved);
    }

    public function test_bare_ip_entries_in_the_trusted_list_still_match_exactly(): void
    {
        $server = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ];

        $this->assertSame('203.0.113.9', NTDST_ClientIp::resolve($server, ['127.0.0.1', '::1']));

        // ...and a neighbour of a bare entry is NOT covered by it.
        $server['REMOTE_ADDR'] = '127.0.0.2';
        $this->assertSame('127.0.0.2', NTDST_ClientIp::resolve($server, ['127.0.0.1', '::1']));
    }

    public function test_a_narrow_prefix_covers_only_its_own_block(): void
    {
        $trusted = ['192.168.10.0/24'];

        $inside = NTDST_ClientIp::resolve(
            ['REMOTE_ADDR' => '192.168.10.255', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            $trusted,
        );
        $outside = NTDST_ClientIp::resolve(
            ['REMOTE_ADDR' => '192.168.11.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            $trusted,
        );

        $this->assertSame('203.0.113.9', $inside);
        $this->assertSame('192.168.11.1', $outside);
    }

    // =====================================================================
    // 3. THE WALK — right-to-left, skipping trusted hops.
    //    What the leftmost-wins copy gets wrong.
    // =====================================================================

    public function test_walk_returns_the_rightmost_untrusted_hop_not_the_leftmost_entry(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('203.0.113.9', $resolved);
        $this->assertNotSame('1.2.3.4', $resolved, 'Leftmost-wins is the spoofable walk.');
    }

    public function test_attacker_prepended_forwarded_for_never_becomes_the_client_ip(): void
    {
        // The real client (203.0.113.9) sends its own X-Forwarded-For
        // claiming to be loopback; the trusted proxy APPENDS the observed
        // peer. Everything left of the proxy's own append is attacker text.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1, 203.0.113.9',
            ],
            ['10.0.0.0/8', '127.0.0.1'],
        );

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_attacker_cannot_impersonate_another_client_by_prepending_its_ip(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.200, 198.51.100.201, 203.0.113.9',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_chained_trusted_proxies_are_skipped_until_the_first_untrusted_hop(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 192.168.5.5, 10.0.0.2',
            ],
            ['10.0.0.0/8', '192.168.0.0/16'],
        );

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_chain_entries_are_trimmed_and_tolerate_missing_spaces(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => "  1.2.3.4 ,\t203.0.113.9  ,10.0.0.2",
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_every_hop_trusted_means_internal_traffic_and_resolves_to_remote_addr(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 10.0.0.3',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('10.0.0.1', $resolved);
    }

    public function test_ipv6_client_behind_a_chain_of_trusted_ipv6_proxies(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '2001:db8::1',
                'HTTP_X_FORWARDED_FOR' => '2606:4700::1111, 2001:db8:1::2, 2001:db8::9',
            ],
            ['2001:db8::/32'],
        );

        $this->assertSame('2606:4700::1111', $resolved);
    }

    // =====================================================================
    // 4. MALFORMED INPUT — the walk terminates, it does not step over.
    // =====================================================================

    public function test_malformed_candidate_terminates_the_walk_and_falls_back_to_remote_addr(): void
    {
        // Nothing to the LEFT of garbage may be trusted: the proxy that
        // wrote the garbage is the last hop we can reason about.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9, not-an-ip, 10.0.0.2',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('10.0.0.1', $resolved);
        $this->assertNotSame('203.0.113.9', $resolved, 'The walk must stop at garbage, not skip past it.');
    }

    public function test_rightmost_entry_being_garbage_falls_back_to_remote_addr(): void
    {
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9, <script>alert(1)</script>',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('10.0.0.1', $resolved);
    }

    public function test_empty_entries_in_the_chain_do_not_leak_a_left_hand_value(): void
    {
        // "1.2.3.4, ," — a trailing empty field is not a valid hop, so the
        // walk terminates rather than reaching the attacker-controlled left.
        $resolved = NTDST_ClientIp::resolve(
            [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4, ,',
            ],
            ['10.0.0.0/8'],
        );

        $this->assertSame('10.0.0.1', $resolved);
    }

    public function test_absent_or_empty_forwarded_for_resolves_to_remote_addr(): void
    {
        $trusted = ['10.0.0.0/8'];

        $this->assertSame('10.0.0.1', NTDST_ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.1'], $trusted));
        $this->assertSame('10.0.0.1', NTDST_ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => ''],
            $trusted,
        ));
        $this->assertSame('10.0.0.1', NTDST_ClientIp::resolve(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '   '],
            $trusted,
        ));
    }

    // =====================================================================
    // 5. NO REMOTE_ADDR — the documented fallback (see the header note).
    // =====================================================================

    public function test_missing_remote_addr_resolves_to_the_empty_string(): void
    {
        $this->assertSame('', NTDST_ClientIp::resolve([], ['10.0.0.0/8']));
    }

    public function test_empty_or_unparseable_remote_addr_resolves_to_the_empty_string(): void
    {
        $this->assertSame('', NTDST_ClientIp::resolve(['REMOTE_ADDR' => ''], ['10.0.0.0/8']));
        $this->assertSame('', NTDST_ClientIp::resolve(['REMOTE_ADDR' => 'not-an-ip'], ['10.0.0.0/8']));
        $this->assertSame('', NTDST_ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.1:51234'], ['10.0.0.0/8']));
    }

    public function test_non_string_remote_addr_resolves_to_the_empty_string(): void
    {
        $this->assertSame('', NTDST_ClientIp::resolve(['REMOTE_ADDR' => ['10.0.0.1']], ['10.0.0.0/8']));
        $this->assertSame('', NTDST_ClientIp::resolve(['REMOTE_ADDR' => null], ['10.0.0.0/8']));
    }

    public function test_unusable_remote_addr_never_falls_through_to_forwarded_for(): void
    {
        // The dangerous shortcut would be "no usable peer, so believe the
        // header". An unidentifiable peer is untrusted by definition.
        $resolved = NTDST_ClientIp::resolve(
            ['REMOTE_ADDR' => '', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            ['10.0.0.0/8', '203.0.113.9'],
        );

        $this->assertSame('', $resolved);
    }

    // =====================================================================
    // 6. FILTERS (FR-1b) — new name, historical name still honoured.
    // =====================================================================

    public function test_detect_applies_the_ntdst_slash_namespaced_filter(): void
    {
        Filters\expectApplied('ntdst/trusted_proxies')->once()->andReturn(['10.0.0.0/8']);

        $resolved = NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '10.9.9.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_detect_still_applies_the_historical_netdust_filter_after_the_new_one(): void
    {
        // FR-1b: the 11 existing consumers filter `netdust_trusted_proxies`
        // and must remain effective. It runs AFTER the new name and receives
        // the new name's output, so it has the final word.
        Filters\expectApplied('ntdst/trusted_proxies')->once()->andReturn(['10.0.0.0/8']);
        Filters\expectApplied('netdust_trusted_proxies')->once()->with(['10.0.0.0/8'])->andReturn([]);

        $resolved = NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '10.9.9.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        // The historical filter emptied the list → nothing is trusted.
        $this->assertSame('10.9.9.9', $resolved);
    }

    public function test_the_historical_filter_can_still_widen_trust_on_its_own(): void
    {
        // A consumer that only knows the old name keeps working unchanged.
        Filters\expectApplied('netdust_trusted_proxies')->once()->andReturn(['10.0.0.0/8']);

        $resolved = NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '10.9.9.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        $this->assertSame('203.0.113.9', $resolved);
    }

    public function test_detect_trusts_loopback_by_default_with_no_filters_registered(): void
    {
        // FR-3: NTDST_Endpoints' behaviour is unchanged — its default
        // trusted list was loopback v4 + v6.
        $this->assertSame('203.0.113.9', NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));

        $this->assertSame('2606:4700::1111', NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '::1',
            'HTTP_X_FORWARDED_FOR' => '2606:4700::1111',
        ]));
    }

    public function test_detect_denies_a_public_peer_by_default(): void
    {
        $this->assertSame('198.51.100.7', NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function test_detect_applies_the_full_walk_not_just_the_first_entry(): void
    {
        Filters\expectApplied('ntdst/trusted_proxies')->once()->andReturn(['10.0.0.0/8']);

        $this->assertSame('203.0.113.9', NTDST_ClientIp::detect([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9, 10.0.0.2',
        ]));
    }

    public function test_resolve_is_pure_and_never_widens_the_callers_list_via_filters(): void
    {
        // ntdst-baseline (T03b) passes its own `ntdst/baseline/security/config`
        // list into resolve(). If resolve() also applied core's filters, a
        // site-level filter would silently widen baseline's configured trust.
        Filters\expectApplied('ntdst/trusted_proxies')->never();
        Filters\expectApplied('netdust_trusted_proxies')->never();

        $resolved = NTDST_ClientIp::resolve(
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
            [],
        );

        $this->assertSame('127.0.0.1', $resolved);
    }
}
