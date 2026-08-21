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

    /**
     * The site policy shape. Tests used to hand a BARE LIST here, which worked
     * because corsDecision() carried a "a list is shorthand for ['origins']"
     * branch. Nothing but these tests ever passed one, so the branch went and
     * the harness builds the real shape instead.
     *
     * @param list<string>|callable $origins
     */
    private function decide(?string $origin, $origins, bool $credentials = false, int $maxAge = 0): array
    {
        return NTDST_Rest::corsDecision($origin, [
            'origins' => $origins,
            'credentials' => $credentials,
            'max_age' => $maxAge,
        ]);
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

    public function testAStarInADeclaredAllowListIsDroppedNotHonoured(): void
    {
        // '*' is a misconfiguration, not a shorthand. It used to refuse the
        // ROUTE, which it no longer can: the allow-list is site-wide and is
        // declared apart from any route. It refuses the POLICY instead — the
        // list is not stored, so nothing is granted to anyone.
        ntdst_rest('cors9/v1')->cors(['*']);

        $this->assertNull(
            NTDST_Rest::corsDecisionFor(self::ALLOWED),
            'A wildcard was stored as a policy, so it can grant.',
        );
    }

    public function testWithNoPolicyDeclaredTheDecisionIsNull(): void
    {
        // sendCors() runs on EVERY REST request on the site. Stripping
        // Access-Control-Allow-Origin from another plugin's route would break
        // its clients with nothing pointing back here. Null is "did nothing",
        // made assertable — and it is what a site that never called cors()
        // must get.
        $this->assertNull(NTDST_Rest::corsDecisionFor('https://evil.example.net'));

        ntdst_rest('cors10/v1')->cors([self::ALLOWED]);

        $this->assertNotNull(NTDST_Rest::corsDecisionFor('https://evil.example.net'));
    }

    // =====================================================================
    // Allows
    // =====================================================================

    public function testAnExactOriginIsEchoed(): void
    {
        $d = $this->decide(self::ALLOWED, [self::ALLOWED]);

        $this->assertContains('Access-Control-Allow-Origin: ' . self::ALLOWED, $d['set']);
        // Vary is NOT here on purpose: it is not a policy decision. A shared
        // cache needs it whether or not we grant, so sendCors() emits it
        // unconditionally before consulting the decision at all.
        $this->assertNotContains('Vary: Origin', $d['set']);
    }

    public function testCredentialsAreStrippedUnlessTheSiteAsksForThem(): void
    {
        $default = $this->decide(self::ALLOWED, [self::ALLOWED]);
        $this->assertContains('Access-Control-Allow-Credentials', $default['remove']);
        $this->assertNotContains('access-control-allow-credentials', $this->names($default));

        $optedIn = $this->decide(self::ALLOWED, [self::ALLOWED], true);
        $this->assertContains('Access-Control-Allow-Credentials: true', $optedIn['set']);
    }

    public function testMaxAgeIsSentOnlyWhenTheSiteAsksForIt(): void
    {
        $this->assertNotContains('Access-Control-Max-Age: 0', $this->decide(self::ALLOWED, [self::ALLOWED])['set']);
        $this->assertContains(
            'Access-Control-Max-Age: 600',
            $this->decide(self::ALLOWED, [self::ALLOWED], false, 600)['set'],
        );
    }

    public function testAResolverCallableDecidesDynamically(): void
    {
        $policy = static fn(string $o): bool => str_ends_with($o, '.vad.be');

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

    public function testDeclaringCorsMountsTheFilter(): void
    {
        ntdst_rest('cors1/v1')->cors([self::ALLOWED]);

        $this->assertNotNull(NTDST_Rest::corsDecisionFor(self::ALLOWED));
    }

    public function testARetiredCorsOptionIsIgnoredRatherThanTakingTheRouteAway(): void
    {
        // The option is gone, but an author who wrote it when it worked keeps
        // their endpoint. They are told once what replaced it.
        ntdst_rest('cors2/v1')->post('/legacy', fn() => [], [
            'permission' => static fn() => true,
            'cors' => [self::ALLOWED],
        ]);

        $this->assertArrayHasKey(
            '/cors2/v1/legacy',
            $this->registered,
            'A retired option took the endpoint away over a name.',
        );
        $this->assertArrayNotHasKey(
            'cors',
            $this->registered['/cors2/v1/legacy'],
            'The retired option was passed through to register_rest_route().',
        );
    }

    public function testAnOptionThatNeverExistedStillRefusesTheRoute(): void
    {
        ntdst_rest('cors3/v1')->post('/typo', fn() => [], [
            'permission' => static fn() => true,
            'corss' => [self::ALLOWED],
        ]);

        $this->assertArrayNotHasKey('/cors3/v1/typo', $this->registered);
    }
}
