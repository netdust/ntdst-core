<?php // tests/Unit/NtdstRestCorsTest.php
// CORS as a declared route option — correcting WordPress's own default.
//
// WP's `rest_send_cors_headers()` (priority 10 on rest_pre_serve_request) does
// this, verbatim from wp-includes/rest-api.php:
//
//     header( 'Access-Control-Allow-Origin: ' . $origin );
//     header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
//     header( 'Access-Control-Allow-Credentials: true' );
//
// It echoes ANY origin back and grants credentials — so any site can read a
// logged-in visitor's authenticated responses. For `Origin: null` (a file://
// page or a sandboxed iframe) it skips sanitisation and still emits
// `Allow-Origin: null` with credentials. And it never sends
// `Access-Control-Allow-Headers`, which is why every consumer that needs a
// cross-origin JSON POST hand-rolls that line.
//
// THE DECISION IS A PURE FUNCTION. `corsDecision()` takes an origin and a
// policy and returns the headers to set and to remove. Emission is a thin
// wrapper over it. That is the seam NTDST_Response::fileHeaders() already uses
// in this package, and it is the whole reason this control is testable at the
// unit tier at all — an isolated test cannot observe a real header() call.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestCorsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ALLOWED = 'https://app.example.com';

    /** @var array<string, array<string, mixed>> */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->registered = [];
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

        Functions\when('register_rest_route')->alias(
            function (string $ns, string $route, array $args) {
                $this->registered['/' . trim($ns, '/') . $route] = $args;
                return true;
            },
        );
        Functions\when('did_action')->justReturn(1);
        Functions\when('add_action')->justReturn(true);
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('apply_filters')->alias(static fn($hook, $value = null, ...$rest) => $value);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('sanitize_url')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed>|list<string> $policy */
    private function decide(?string $origin, array $policy): array
    {
        return NTDST_Rest::corsDecision($origin, $policy);
    }

    /** Header names this decision sets, lowercased, for terse assertions. */
    private function names(array $decision): array
    {
        return array_map(
            static fn($h) => strtolower(explode(':', $h, 2)[0]),
            $decision['set'],
        );
    }

    // =====================================================================
    // Denials first — the whole reason this exists
    // =====================================================================

    public function testAForeignOriginGetsNoAllowOriginAndCoresIsRemoved(): void
    {
        $d = $this->decide('https://evil.example.net', [self::ALLOWED]);

        $this->assertSame([], $d['set'], 'Nothing is granted to an origin that is not on the list.');
        $this->assertContains(
            'Access-Control-Allow-Origin',
            $d['remove'],
            "WP core already emitted one at priority 10. Not overriding it leaves core's reflection standing.",
        );
        $this->assertContains('Access-Control-Allow-Credentials', $d['remove']);
    }

    public function testAnOriginThatOnlyPREFIXESAnAllowedOneIsRefused(): void
    {
        $d = $this->decide('https://app.example.com.evil.net', [self::ALLOWED]);

        $this->assertSame([], $d['set']);
    }

    public function testAnOriginThatOnlySUFFIXESAnAllowedOneIsRefused(): void
    {
        $d = $this->decide('https://notapp.example.com', [self::ALLOWED]);

        $this->assertSame([], $d['set']);
    }

    public function testOriginNullIsNeverAllowed(): void
    {
        // A file:// page and a sandboxed iframe both send `Origin: null`. WP
        // core echoes it back WITH credentials. It can never be attributed to
        // anyone, so it can never be trusted — not even if a policy lists it.
        foreach ([['null'], [self::ALLOWED]] as $policy) {
            $this->assertSame([], $this->decide('null', $policy)['set'], 'Origin: null is not an identity.');
        }
    }

    public function testAnEmptyOriginGrantsNothing(): void
    {
        $this->assertSame([], $this->decide('', [self::ALLOWED])['set']);
        $this->assertSame([], $this->decide(null, [self::ALLOWED])['set']);
    }

    public function testAWildcardInAPolicyIsRefusedRatherThanHonoured(): void
    {
        // `'*'` in an allow-list is a misconfiguration, not a shorthand. It
        // must never widen the gate, and never match an actual origin.
        $d = $this->decide('https://evil.example.net', ['*']);

        $this->assertSame([], $d['set'], 'A wildcard policy grants nothing at all.');
    }

    public function testANonStringInTheAllowListMatchesNothing(): void
    {
        // A malformed config — `true`, `1`, a stray `0` — would match EVERY
        // origin under a loose in_array(). The list is byte-exact strings or
        // it is not a list.
        foreach ([[true], [1], [0], [null]] as $rubbish) {
            $this->assertSame(
                [],
                $this->decide('https://evil.example.net', $rubbish)['set'],
                'A non-string allow-list entry must never match an origin.',
            );
        }
    }

    public function testAPolicyNamingAStarRefusesTheRouteOutright(): void
    {
        // Failing closed SILENTLY would leave the author believing
        // cross-origin works. Misconfiguration refuses, loudly — the same
        // treatment a missing permission gets.
        ntdst_rest('cors9/v1')->post('/wild', fn() => [], [
            'permission' => static fn() => true,
            'cors' => ['*'],
        ]);

        $this->assertArrayNotHasKey('/cors9/v1/wild', $this->registered);
    }

    public function testARouteThisPackageNeverRegisteredIsNotTouched(): void
    {
        // The filter runs on EVERY REST request on the site. Removing
        // Access-Control-Allow-Origin from another plugin's route would break
        // its clients with nothing pointing back here. Null is "did nothing",
        // made assertable.
        ntdst_rest('cors10/v1')->post('/mine', fn() => [], [
            'permission' => static fn() => true,
            'cors' => [self::ALLOWED],
        ]);

        $this->assertNull(NTDST_Rest::corsDecisionFor('/wc/v3/orders', 'https://evil.example.net'));
        $this->assertNotNull(NTDST_Rest::corsDecisionFor('/cors10/v1/mine', 'https://evil.example.net'));
    }

    // =====================================================================
    // Allows
    // =====================================================================

    public function testAnExactOriginIsEchoedWithVary(): void
    {
        $d = $this->decide(self::ALLOWED, [self::ALLOWED]);

        $this->assertContains('Access-Control-Allow-Origin: ' . self::ALLOWED, $d['set']);
        $this->assertContains(
            'Vary: Origin',
            $d['set'],
            'Without Vary, a shared cache serves one origin the response computed for another.',
        );
    }

    public function testCredentialsAreStrippedUnlessTheSiteAsksForThem(): void
    {
        $default = $this->decide(self::ALLOWED, [self::ALLOWED]);
        $this->assertContains('Access-Control-Allow-Credentials', $default['remove']);
        $this->assertNotContains('access-control-allow-credentials', $this->names($default));

        $optedIn = $this->decide(self::ALLOWED, ['origins' => [self::ALLOWED], 'credentials' => true]);
        $this->assertContains('Access-Control-Allow-Credentials: true', $optedIn['set']);
    }

    public function testAllowHeadersIsSentBecauseWordPressNeverSendsIt(): void
    {
        // Without this a cross-origin `Content-Type: application/json` POST
        // fails its preflight, which is why every consumer hand-rolls the line.
        $d = $this->decide(self::ALLOWED, [self::ALLOWED]);
        $header = $this->headerNamed($d['set'], 'Access-Control-Allow-Headers');

        $this->assertStringContainsString('Content-Type', $header);
        $this->assertStringContainsString('X-WP-Nonce', $header);
    }

    public function testAPolicyMayNameItsOwnHeadersAndMaxAge(): void
    {
        $d = $this->decide(self::ALLOWED, [
            'origins' => [self::ALLOWED],
            'headers' => ['Content-Type', 'X-Tenant'],
            'max_age' => 600,
        ]);

        $this->assertSame(
            'Access-Control-Allow-Headers: Content-Type, X-Tenant',
            $this->headerNamed($d['set'], 'Access-Control-Allow-Headers', true),
        );
        $this->assertContains('Access-Control-Max-Age: 600', $d['set']);
    }

    public function testAResolverCallableDecidesDynamically(): void
    {
        $policy = ['origins' => static fn(string $o): bool => str_ends_with($o, '.vad.be')];

        $this->assertContains(
            'Access-Control-Allow-Origin: https://analytics.vad.be',
            $this->decide('https://analytics.vad.be', $policy)['set'],
        );
        $this->assertSame([], $this->decide('https://evil.net', $policy)['set']);
    }

    private function headerNamed(array $set, string $name, bool $whole = false): string
    {
        foreach ($set as $h) {
            if (stripos($h, $name . ':') === 0) {
                return $whole ? $h : $h;
            }
        }

        $this->fail("no {$name} header in: " . implode(' | ', $set));
    }

    // =====================================================================
    // Wiring — the decision must actually reach a request
    // =====================================================================

    private function corsFilter(): callable
    {
        $this->assertArrayHasKey(
            'rest_pre_serve_request',
            $GLOBALS['_ntdst_test_filters'] ?? [],
            'NTDST_Rest must mount rest_pre_serve_request to correct core CORS.',
        );

        return $GLOBALS['_ntdst_test_filters']['rest_pre_serve_request'];
    }

    public function testDeclaringCorsMountsTheFilterAndIsAnAcceptedOption(): void
    {
        ntdst_rest('cors1/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'cors' => [self::ALLOWED],
        ]);

        $this->assertArrayHasKey(
            '/cors1/v1/thing',
            $this->registered,
            'A `cors` option must not refuse the route — the option list is closed.',
        );
        $this->assertIsCallable($this->corsFilter());
    }

    public function testARouteWithNoCorsOptionIsLeftAlone(): void
    {
        // Opt-in, deliberately. Core does NOT silently change the CORS
        // behaviour of routes nobody declared a policy for — that would be a
        // breaking change, and it is recorded as an open question instead.
        ntdst_rest('cors2/v1')->post('/plain', fn() => [], ['permission' => static fn() => true]);
        ntdst_rest('cors2/v1')->post('/guarded', fn() => [], [
            'permission' => static fn() => true,
            'cors' => [self::ALLOWED],
        ]);

        $applied = NTDST_Rest::corsFor('/cors2/v1/plain');

        $this->assertNull($applied, 'No declaration, no policy.');
        $this->assertNotNull(NTDST_Rest::corsFor('/cors2/v1/guarded'));
    }

    public function testTheRouteLookupIsCaseInsensitiveLikeWordPressOwn(): void
    {
        // WP matches routes with preg_match('@^…$@i'). A case-SENSITIVE scope
        // check silently stops running for /CORS3/V1/THING while WordPress
        // dispatches it — the exact bug that took a consumer's CORS correction
        // offline and handed core's reflect-any default back to the wire.
        ntdst_rest('cors3/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'cors' => [self::ALLOWED],
        ]);

        $this->assertNotNull(NTDST_Rest::corsFor('/CORS3/V1/THING'));
    }
}
