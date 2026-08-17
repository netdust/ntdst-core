<?php // tests/Unit/RateLimiterTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * CONTRACT TEST — written independently, BEFORE support/RateLimiter.php exists
 * (spec `intake-to-core`, T04, Test-author: split, Tier A).
 *
 * This file pins the fleet's only rate limiter as it comes out of
 * `NTDST_Endpoints` (`checkRateLimit()` + `consumeRateBudget()`) and becomes
 * public API for two callers with different bucket shapes. It is IMMUTABLE for
 * the implementer: green it, never weaken it. A dispute is escalated, not
 * edited.
 *
 * ── API pinned here ───────────────────────────────────────────────────────
 *   NTDST_RateLimiter::attempt(
 *       string  $key,                 // the FULL transient key, caller-built
 *       int     $limit,               // attempts allowed per window
 *       int     $window,              // window in seconds (the transient TTL)
 *       ?object $memoScope = null,    // per-request memo identity, or none
 *   ): bool                           // true = allowed (one unit consumed)
 *
 *   Static, like NTDST_ClientIp::resolve(). Class naming follows this
 *   package's convention (NTDST_ClientIp, NTDST_Scheduler, NTDST_Endpoints):
 *   global class, `NTDST_` prefix, file at support/RateLimiter.php, added to
 *   ntdst-core.php's explicit require list and to tests/bootstrap.php —
 *   wiring the loader is part of greening this RED. This file deliberately
 *   requires nothing, so the RED reads "class absent", not "path stale".
 *
 * ── WHY THE KEY, THE LIMIT AND THE WINDOW ARE ALL CALLER-OWNED ────────────
 * Two callers with different shapes consume this:
 *   - NTDST_Endpoints — bucket `u{userId}` when logged in else `ip`+md5(IP),
 *     prefix `ntdst_rate_`, filters `ntdst/api/rate_limit/{$action}` and
 *     `ntdst/api/rate_window/{$action}` over its RATE_LIMIT/RATE_WINDOW
 *     constants;
 *   - the todai intake (T07) — bucket (ip + form_key), prefix
 *     `todai_intake_rate_`, filters `todai/intake/*`.
 * So the limiter resolves NO identity and applies NO filters of its own. It
 * receives the finished key and the finished numbers. That is FR-3 at the
 * unit level: a limiter that hashed, prefixed or re-filtered anything would
 * silently move all 11 consuming sites' buckets. Sections 4 and 6 pin it.
 *
 * ── WHY THE MEMO IS THE MOST IMPORTANT ASSERTION IN THIS FILE ─────────────
 * (Endpoints.php:95-115 is the specification; restated because the extracted
 * class must carry the guarantee, and a fact this code cannot show must be
 * written down.) WordPress invokes a route's `permission_callback` TWICE per
 * served HTTP request: once in `WP_REST_Server::respond_to_request()`, and
 * again in `rest_send_allow_header()` on `rest_post_dispatch`, which re-runs
 * every matched handler's permission callback to build the `Allow` header —
 * on every response, errors included, because `set_matched_route()` is
 * unconditional. Without memoization each served request consumes TWO budget
 * units and every configured limit is HALVED on the wire: the fleet default
 * 30 behaves as 15, and a limit of 3 was measured passing exactly 2 requests.
 *
 * Threat 11 names both halves of the failure — the memo LOST (limits halve)
 * and the memo turned into a persistent bypass (a decision replayed for a
 * caller it was never taken for). Section 2 pins the first, section 3 the
 * second. SC-3 requires that removing the memo FAILS this file; section 2's
 * `test_the_limit_is_reached_after_the_configured_number_of_requests_not_half`
 * is that test — it reproduces the live regression exactly.
 *
 * The memo identity is an OBJECT the caller supplies (Endpoints passes the
 * WP_REST_Request; both core invocations receive the same instance, and two
 * different HTTP requests can never share one). The memo is therefore
 * per-request by construction: it needs no reset, and its entries die with
 * the object. Callers with no such object pass nothing and get the
 * unmemoized limiter (section 3).
 *
 * Every assertion below is derived from FR-2 / FR-3, SC-3, threat 11 and the
 * documented behaviour of the two private methods being extracted — not from
 * any implementation of the new class.
 */
final class RateLimiterTest extends TestCase
{
    // Verifies Mockery/Brain Monkey expectations into PHPUnit's assertion
    // count, so failOnRisky="true" does not flag expectation-only tests.
    use MockeryPHPUnitIntegration;

    /** Fake transient store: key => ['value' => mixed, 'ttl' => int]. */
    private array $transients = [];

    /** Number of set_transient() writes since the store was installed. */
    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];
        $this->writes = 0;
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Test double — an in-memory transient store. Assertions are made on
    // what the limiter DID to it (which keys exist, what they hold, what TTL
    // they carry), never on how the limiter is built inside.
    // =====================================================================

    /**
     * @param array<string, mixed> $seed Pre-existing transient values.
     */
    private function installTransientStore(array $seed = []): void
    {
        foreach ($seed as $key => $value) {
            $this->transients[$key] = ['value' => $value, 'ttl' => 0];
        }

        Functions\when('get_transient')->alias(
            fn($key) => $this->transients[$key]['value'] ?? false,
        );

        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) {
            $this->writes++;
            $this->transients[$key] = ['value' => $value, 'ttl' => (int) $ttl];
            return true;
        });
    }

    /** The consumed units currently recorded for a bucket. */
    private function consumed(string $key): int
    {
        return (int) ($this->transients[$key]['value'] ?? 0);
    }

    /** The TTL the limiter last wrote for a bucket. */
    private function ttl(string $key): int
    {
        return $this->transients[$key]['ttl'] ?? -1;
    }

    // =====================================================================
    // 1. THE BOUNDARY — N attempts pass, N+1 is denied, at the CONFIGURED
    //    number. This is what a rate limit IS.
    // =====================================================================

    public function test_exactly_the_configured_number_of_attempts_pass_and_the_next_is_denied(): void
    {
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('ip9f8|send_magic_link');

        $decisions = [];
        for ($i = 0; $i < 4; $i++) {
            $decisions[] = NTDST_RateLimiter::attempt($key, 3, 60);
        }

        $this->assertSame([true, true, true, false], $decisions);
    }

    public function test_a_denied_attempt_consumes_no_budget(): void
    {
        // Denial reads the bucket and stops. If a denial also incremented,
        // a caller under sustained load could never come back inside the
        // window, because every rejected attempt would push the counter up.
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('u7|save_thing');

        NTDST_RateLimiter::attempt($key, 2, 60);
        NTDST_RateLimiter::attempt($key, 2, 60);
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 2, 60));
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 2, 60));

        $this->assertSame(2, $this->consumed($key));
    }

    public function test_each_allowed_attempt_consumes_exactly_one_unit(): void
    {
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('u7|save_thing');

        NTDST_RateLimiter::attempt($key, 30, 60);
        $this->assertSame(1, $this->consumed($key));

        NTDST_RateLimiter::attempt($key, 30, 60);
        $this->assertSame(2, $this->consumed($key));

        NTDST_RateLimiter::attempt($key, 30, 60);
        $this->assertSame(3, $this->consumed($key));
    }

    public function test_a_counter_left_by_an_earlier_request_is_honoured(): void
    {
        // WP transients come back as strings from the options table, so the
        // stored value is read as an int, not compared as text.
        $this->installTransientStore(['ntdst_rate_seeded' => '2']);

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_seeded', 3, 60));
        $this->assertSame(3, $this->consumed('ntdst_rate_seeded'));
        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_seeded', 3, 60));
    }

    public function test_an_absent_bucket_starts_at_zero(): void
    {
        // get_transient() returns false when the key has expired or was never
        // written. That is "no attempts yet", not a poisoned counter.
        $this->installTransientStore();

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_fresh', 1, 60));
        $this->assertSame(1, $this->consumed('ntdst_rate_fresh'));
    }

    public function test_a_bucket_at_exactly_the_limit_is_denied_not_allowed(): void
    {
        // The off-by-one that lets one extra request through every window.
        $this->installTransientStore(['ntdst_rate_full' => 5]);

        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_full', 5, 60));
        $this->assertSame(5, $this->consumed('ntdst_rate_full'));
    }

    // =====================================================================
    // 2. THE MEMO — SC-3's named regression. Remove the memoization and
    //    these tests must go red.
    // =====================================================================

    public function test_two_calls_sharing_one_memo_scope_consume_exactly_one_unit(): void
    {
        // This is WP's double permission_callback invocation, in miniature:
        // one HTTP request, one request object, two calls.
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('u7|save_thing');
        $request = new stdClass();

        $this->assertTrue(NTDST_RateLimiter::attempt($key, 30, 60, $request));
        $this->assertTrue(NTDST_RateLimiter::attempt($key, 30, 60, $request));

        $this->assertSame(1, $this->consumed($key));
        $this->assertSame(1, $this->writes, 'The second call must not write again.');
    }

    public function test_two_different_memo_scopes_consume_two_units(): void
    {
        // Two HTTP requests are two objects, and must cost two units — the
        // memo may only collapse invocations WITHIN one request.
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('u7|save_thing');

        $this->assertTrue(NTDST_RateLimiter::attempt($key, 30, 60, new stdClass()));
        $this->assertTrue(NTDST_RateLimiter::attempt($key, 30, 60, new stdClass()));

        $this->assertSame(2, $this->consumed($key));
    }

    public function test_the_limit_is_reached_after_the_configured_number_of_requests_not_half(): void
    {
        // THE regression this whole extraction must not re-introduce
        // (threat 11, SC-3). Each served request invokes the callback twice.
        // With a limit of 3 the wire behaviour must be: requests 1-3 allowed,
        // request 4 denied. Without the memo, request 2 already exhausts the
        // budget and this asserts [true, false, false, false] — measured live
        // as "limit 3 passed exactly 2 requests".
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('ip9f8|send_magic_link');

        $wire = [];
        for ($i = 0; $i < 4; $i++) {
            $request = new stdClass();               // one served HTTP request
            $dispatch = NTDST_RateLimiter::attempt($key, 3, 60, $request);
            NTDST_RateLimiter::attempt($key, 3, 60, $request); // rest_send_allow_header()
            $wire[] = $dispatch;
        }

        $this->assertSame([true, true, true, false], $wire);
        $this->assertSame(3, $this->consumed($key), 'A served request costs one unit, not two.');
    }

    public function test_the_second_invocation_returns_the_same_decision_as_the_first(): void
    {
        // rest_send_allow_header() computes the Allow header from the return
        // value. A memo that replayed the wrong answer would advertise a
        // method the dispatch just refused.
        $this->installTransientStore(['ntdst_rate_full' => 5]);
        $request = new stdClass();

        $dispatch = NTDST_RateLimiter::attempt('ntdst_rate_full', 5, 60, $request);
        $allowHeader = NTDST_RateLimiter::attempt('ntdst_rate_full', 5, 60, $request);

        $this->assertFalse($dispatch);
        $this->assertFalse($allowHeader);
    }

    public function test_repeated_calls_in_one_scope_never_drain_the_budget(): void
    {
        // A generalisation of the halving bug: whatever number of times one
        // request re-asks, it owns exactly one unit.
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('u7|save_thing');
        $request = new stdClass();

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(NTDST_RateLimiter::attempt($key, 3, 60, $request));
        }

        $this->assertSame(1, $this->consumed($key));
    }

    // =====================================================================
    // 3. THE MEMO IS NOT A BYPASS — the other half of threat 11. A cached
    //    decision belongs to ONE scope and ONE key, and never leaks.
    // =====================================================================

    public function test_a_memoized_allow_is_never_replayed_for_a_different_scope(): void
    {
        // Otherwise the first request through the door would authorise every
        // request behind it.
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('ip9f8|send_magic_link');

        $this->assertTrue(NTDST_RateLimiter::attempt($key, 1, 60, new stdClass()));
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 1, 60, new stdClass()));
    }

    public function test_a_memoized_denial_belongs_to_its_own_scope_only(): void
    {
        // The denial is cached for the request that earned it — even after
        // the window expires and the bucket frees up. A fresh request gets a
        // fresh decision from the store.
        $this->installTransientStore();
        $key = 'ntdst_rate_' . md5('ip9f8|send_magic_link');

        NTDST_RateLimiter::attempt($key, 1, 60, new stdClass());
        $denied = new stdClass();
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 1, 60, $denied));

        $this->transients = []; // the window expires mid-request

        $this->assertFalse(
            NTDST_RateLimiter::attempt($key, 1, 60, $denied),
            'The refused request keeps its decision for its whole lifetime.',
        );
        $this->assertTrue(
            NTDST_RateLimiter::attempt($key, 1, 60, new stdClass()),
            'A new request must be decided from the bucket, not from the memo.',
        );
    }

    public function test_no_memo_scope_means_no_memoization_at_all(): void
    {
        // The intake and any other caller without a per-request object get
        // the plain limiter. Nothing may be cached process-wide on their
        // behalf — that would be a bypass with no owner.
        $this->installTransientStore();
        $key = 'todai_intake_rate_' . md5('203.0.113.9|contact');

        NTDST_RateLimiter::attempt($key, 5, 60);
        NTDST_RateLimiter::attempt($key, 5, 60);
        NTDST_RateLimiter::attempt($key, 5, 60);

        $this->assertSame(3, $this->consumed($key));
    }

    public function test_null_scope_calls_do_not_share_a_decision_with_each_other(): void
    {
        $this->installTransientStore();
        $key = 'todai_intake_rate_' . md5('203.0.113.9|contact');

        $this->assertTrue(NTDST_RateLimiter::attempt($key, 1, 60, null));
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 1, 60, null));
    }

    public function test_one_scope_decides_each_key_separately(): void
    {
        // Endpoints memoizes per (request, action): one request may check
        // several buckets, and the first answer must not stand in for the
        // rest.
        $this->installTransientStore();
        $request = new stdClass();
        $magicLink = 'ntdst_rate_' . md5('u7|send_magic_link');
        $saveThing = 'ntdst_rate_' . md5('u7|save_thing');

        NTDST_RateLimiter::attempt($magicLink, 30, 60, $request);
        NTDST_RateLimiter::attempt($saveThing, 30, 60, $request);

        $this->assertSame(1, $this->consumed($magicLink));
        $this->assertSame(1, $this->consumed($saveThing));
    }

    public function test_a_denial_on_one_key_does_not_deny_another_key_in_the_same_scope(): void
    {
        $this->installTransientStore(['ntdst_rate_full' => 9]);
        $request = new stdClass();

        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_full', 9, 60, $request));
        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_other', 9, 60, $request));
    }

    public function test_a_memoized_allow_on_one_key_does_not_allow_an_exhausted_key(): void
    {
        $this->installTransientStore(['ntdst_rate_full' => 9]);
        $request = new stdClass();

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_other', 9, 60, $request));
        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_full', 9, 60, $request));
    }

    // =====================================================================
    // 4. THE KEY AND THE WINDOW ARE THE CALLER'S (FR-3).
    // =====================================================================

    public function test_the_caller_supplied_key_is_the_transient_key_verbatim(): void
    {
        // Both prefixes must survive untouched: ntdst-core's existing sites
        // keep their buckets, and the intake keeps its own namespace.
        $this->installTransientStore();
        $core = 'ntdst_rate_' . md5('u7|save_thing');
        $intake = 'todai_intake_rate_' . md5('203.0.113.9|contact');

        NTDST_RateLimiter::attempt($core, 30, 60);
        NTDST_RateLimiter::attempt($intake, 5, 3600);

        $this->assertSame([$core, $intake], array_keys($this->transients));
    }

    public function test_distinct_keys_are_independent_buckets(): void
    {
        $this->installTransientStore();

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_a', 1, 60));
        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_b', 1, 60));
        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_a', 1, 60));
    }

    public function test_the_same_key_is_one_shared_bucket_across_callers(): void
    {
        $this->installTransientStore();

        NTDST_RateLimiter::attempt('ntdst_rate_shared', 3, 60);
        NTDST_RateLimiter::attempt('ntdst_rate_shared', 3, 60, new stdClass());
        NTDST_RateLimiter::attempt('ntdst_rate_shared', 3, 60, null);

        $this->assertSame(3, $this->consumed('ntdst_rate_shared'));
        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_shared', 3, 60));
    }

    public function test_the_window_is_the_transient_ttl(): void
    {
        $this->installTransientStore();

        NTDST_RateLimiter::attempt('ntdst_rate_a', 30, 60);
        NTDST_RateLimiter::attempt('ntdst_rate_b', 5, 3600);

        $this->assertSame(60, $this->ttl('ntdst_rate_a'));
        $this->assertSame(3600, $this->ttl('ntdst_rate_b'));
    }

    public function test_the_ttl_is_re_applied_on_every_increment(): void
    {
        // Sliding window: existing behaviour, deliberately unchanged. The
        // TTL is rewritten on each consumed unit rather than left to run out
        // from the first attempt.
        $this->installTransientStore(['ntdst_rate_sliding' => 1]);
        $this->transients['ntdst_rate_sliding']['ttl'] = 3;

        NTDST_RateLimiter::attempt('ntdst_rate_sliding', 30, 60);

        $this->assertSame(2, $this->consumed('ntdst_rate_sliding'));
        $this->assertSame(60, $this->ttl('ntdst_rate_sliding'));
    }

    // =====================================================================
    // 5. A LIMIT OF <= 0 DISABLES THE LIMIT (existing filter escape hatch).
    // =====================================================================

    public function test_a_limit_of_zero_never_denies_and_consumes_nothing(): void
    {
        $this->installTransientStore();

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_off', 0, 60));
        }

        $this->assertSame(0, $this->writes, 'A disabled limit must not keep a counter.');
        $this->assertSame([], $this->transients);
    }

    public function test_a_negative_limit_never_denies(): void
    {
        $this->installTransientStore(['ntdst_rate_off' => 999]);

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_off', -1, 60));
        $this->assertSame(999, $this->consumed('ntdst_rate_off'));
    }

    public function test_a_limit_of_one_is_enforced_and_not_treated_as_disabled(): void
    {
        // The boundary between "disabled" and "the strictest possible limit".
        $this->installTransientStore();

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_one', 1, 60));
        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_one', 1, 60));
    }

    // =====================================================================
    // 6. NO IDENTITY, NO FILTERS OF ITS OWN — FR-3 pinned at the unit level.
    // =====================================================================

    public function test_the_limiter_applies_no_filters_of_its_own(): void
    {
        // NTDST_Endpoints resolves `ntdst/api/rate_limit/{$action}` and
        // `ntdst/api/rate_window/{$action}` BEFORE calling, and the intake
        // resolves `todai/intake/*`. If the limiter re-applied the callers'
        // filters the numbers would be filtered twice; if it introduced a
        // filter of its own, a site could widen every consumer's limit from
        // one place the callers never named.
        Filters\expectApplied('ntdst/api/rate_limit/save_thing')->never();
        Filters\expectApplied('ntdst/api/rate_window/save_thing')->never();
        Filters\expectApplied('ntdst/rate_limit')->never();
        Filters\expectApplied('ntdst/rate_window')->never();
        Filters\expectApplied('ntdst/rate_limiter/key')->never();

        $this->installTransientStore();

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_x', 1, 60));
        $this->assertFalse(NTDST_RateLimiter::attempt('ntdst_rate_x', 1, 60));
    }

    public function test_the_limiter_resolves_no_identity_of_its_own(): void
    {
        // The bucket is the caller's decision: Endpoints keys on user-or-IP,
        // the intake on (ip + form_key). A limiter that looked up the current
        // user or the client IP would silently re-bucket one of them.
        Functions\expect('get_current_user_id')->never();
        Functions\expect('wp_get_current_user')->never();
        Functions\expect('is_user_logged_in')->never();

        $this->installTransientStore();

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_x', 2, 60));
        $this->assertSame(1, $this->consumed('ntdst_rate_x'));
    }

    public function test_the_limiter_accepts_any_object_as_a_memo_scope(): void
    {
        // Endpoints passes a WP_REST_Request; there is no WP runtime in the
        // unit tier and there must be no type coupling to one either.
        $this->installTransientStore();
        $scope = new class {
            public string $route = '/ntdst/v1/action';
        };

        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_x', 1, 60, $scope));
        $this->assertTrue(NTDST_RateLimiter::attempt('ntdst_rate_x', 1, 60, $scope));
        $this->assertSame(1, $this->consumed('ntdst_rate_x'));
    }
}
