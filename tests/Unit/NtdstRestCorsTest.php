<?php // tests/Unit/NtdstRestCorsTest.php
// CORS: declared here, KEPT BY WORDPRESS. — specs/core-shape FR-6 / SC-6,
// threat row #9 (CORS list widening), INV-5 (core keeps no table WordPress
// already keeps).
//
// WordPress already owns an origin allow-list: `get_allowed_http_origins()`
// builds `[http://home, https://home, http://admin, https://admin]` and hands
// it to the `allowed_http_origins` filter; `is_allowed_http_origin($origin)`
// answers over that list with a strict `in_array()`
// (wp-includes/http.php:448–487). So `cors([...])` does not keep a list. It
// ADDS to WordPress's, and the emitter ASKS WordPress.
//
// What WordPress gets wrong is only the REST emitter:
// `rest_send_cors_headers()` (priority 10 on rest_pre_serve_request) does this,
// verbatim from wp-includes/rest-api.php:
//
//     header( 'Access-Control-Allow-Origin: ' . $origin );
//     header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
//     header( 'Access-Control-Allow-Credentials: true' );
//
// It echoes ANY origin back and grants credentials — so any site can read a
// logged-in visitor's authenticated responses. It ignores the allow-list it
// already has. That handler comes off the bus; ours goes on.
//
// TWO CONSEQUENCES THESE TESTS PIN, because threat row #9 names them:
//   1. `allowed_http_origins` is site-wide. admin-ajax's `send_origin_headers()`
//      reads the same list. So the filter must add THE DECLARED ORIGINS AND
//      NOTHING ELSE — no wildcard, no non-string entry, no silent widening.
//   2. Credentials stay off unless the site asked, and an origin WordPress
//      refuses gets both headers REMOVED (core already emitted its own at
//      priority 10; failing closed means overriding, not abstaining).
//
// THE DECISION IS A PURE FUNCTION. `corsDecision()` takes an origin and a
// policy and returns the headers to set and to remove. Emission is a thin
// wrapper over it. That is the seam NTDST_Response::fileHeaders() already uses
// in this package, and it is the whole reason this control is testable at the
// unit tier at all — an isolated test cannot observe a real header() call. The
// policy no longer carries origins: allowed-ness comes from
// `is_allowed_http_origin()`, one source of truth for the whole site.
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

    /**
     * What `get_allowed_http_origins()` builds before the filter runs, for a
     * site whose home and admin share a host. Every list assertion below is
     * exact and order-preserving against this: WordPress's entries first, in
     * WordPress's order, then what was declared.
     */
    private const WP_DEFAULTS = ['http://site.test', 'https://site.test'];

    /** @var array<string, array<string, mixed>> */
    private array $registered = [];

    /** @var list<array{0: string, 1: string, 2: string}> Every _doing_it_wrong() call. */
    private array $doingItWrong = [];

    /** @var list<array{0: string, 1: mixed}> Every remove_filter() call: [hook, callback]. */
    private array $removedFilters = [];

    /** @var list<array{0: string, 1: mixed, 2: int}> Every add_action() call. */
    private array $actions = [];

    /** @var list<string|null> Every origin the code asked WordPress about. */
    private array $askedWordPress = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->registered = [];
        $this->doingItWrong = [];
        $this->removedFilters = [];
        $this->actions = [];
        $this->askedWordPress = [];
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

        // The CORS declaration is SITE-WIDE and lives in class statics, so it
        // leaks from one test into the next: test two would read test one's
        // origins and pass on evidence it never produced. Reset every static
        // to its declared default — by reflection over whatever the class
        // declares, so this keeps working when the origin list stops being a
        // static at all (which is the point of this task).
        $rest = new ReflectionClass(NTDST_Rest::class);
        foreach ($rest->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if ($property->hasDefaultValue()) {
                $property->setAccessible(true);
                $property->setValue(null, $property->getDefaultValue());
            }
        }

        // tests/bootstrap.php defines add_filter as a RECORDER (first mount per
        // hook wins). Clear only the hooks this file drives — other test files'
        // load-time mounts must survive, the suite shares one process.
        foreach (['allowed_http_origins', 'allowed_http_origin', 'rest_pre_serve_request'] as $hook) {
            unset($GLOBALS['_ntdst_test_filters'][$hook], $GLOBALS['_ntdst_test_filters_at'][$hook]);
        }

        $GLOBALS['_ntdst_test_http_origin'] = '';

        Functions\when('register_rest_route')->alias(
            function (string $ns, string $route, array $args) {
                $this->registered['/' . trim($ns, '/') . $route] = $args;
                return true;
            },
        );
        Functions\when('did_action')->justReturn(1);
        Functions\when('add_action')->alias(
            function ($hook, $cb = null, $priority = 10) {
                $this->actions[] = [$hook, $cb, (int) $priority];
                return true;
            },
        );
        Functions\when('remove_filter')->alias(
            function ($hook, $cb = null, $priority = 10) {
                $this->removedFilters[] = [$hook, $cb];
                return true;
            },
        );
        Functions\when('_doing_it_wrong')->alias(
            function ($fn = '', $message = '', $version = '') {
                $this->doingItWrong[] = [(string) $fn, (string) $message, (string) $version];
            },
        );
        Functions\when('apply_filters')->alias(static fn($hook, $value = null, ...$rest) => $value);
        Functions\when('get_http_origin')->alias(static fn() => (string) ($GLOBALS['_ntdst_test_http_origin'] ?? ''));
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('sanitize_url')->returnArg();

        // Nothing is allowed until a test says so. Every call is recorded, so
        // "was WordPress asked at all, and about what?" is assertable.
        $this->wordPressAllowsOnly();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Harness — WordPress's side of the contract, reproduced exactly
    // =====================================================================

    /**
     * Run WordPress's `allowed_http_origins` filter over its own defaults.
     *
     * @return list<string>
     */
    private function allowList(): array
    {
        $this->assertArrayHasKey(
            'allowed_http_origins',
            $GLOBALS['_ntdst_test_filters'] ?? [],
            'cors() must add the declared origins to WordPress\'s own allow-list '
            . '(INV-5: core keeps no table WordPress already keeps).',
        );

        $list = ($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])(self::WP_DEFAULTS);

        $this->assertIsArray($list, 'The allowed_http_origins filter must return the list, not a scalar.');

        return array_values($list);
    }

    /**
     * The same list, without requiring that anything be mounted. A declaration
     * that adds no origin — an empty list, a refused wildcard, a resolver that
     * says no — has nothing to mount, and whether it mounts an inert callback
     * anyway is not a promise worth pinning. What the list CONTAINS is.
     *
     * @return list<string>
     */
    private function allowListIfMounted(): array
    {
        $list = isset($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])
            ? ($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])(self::WP_DEFAULTS)
            : self::WP_DEFAULTS;

        return array_values((array) $list);
    }

    /**
     * `is_allowed_http_origin()`, reproduced from wp-includes/http.php:478–500 —
     * the filtered list, a STRICT in_array, then the `allowed_http_origin`
     * result filter. This is the question the site will really ask, so it is
     * the question these tests ask, whichever of the two filters answers it.
     */
    private function wordPressAllows(string $origin): bool
    {
        $GLOBALS['_ntdst_test_http_origin'] = $origin;

        $list = isset($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])
            ? ($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])(self::WP_DEFAULTS)
            : self::WP_DEFAULTS;

        $result = in_array($origin, (array) $list, true) ? $origin : '';

        if (isset($GLOBALS['_ntdst_test_filters']['allowed_http_origin'])) {
            $result = ($GLOBALS['_ntdst_test_filters']['allowed_http_origin'])($result, $origin);
        }

        return $result !== '';
    }

    /**
     * Stub `is_allowed_http_origin()` — WordPress returns THE ORIGIN when it is
     * allowed and an EMPTY STRING when it is not, never a bool.
     */
    private function wordPressAllowsOnly(string ...$allowed): void
    {
        Functions\when('is_allowed_http_origin')->alias(
            function ($origin = null) use ($allowed) {
                $this->askedWordPress[] = $origin;
                return in_array($origin, $allowed, true) ? (string) $origin : '';
            },
        );
    }

    /**
     * The site policy. It carries NO origins: after this task the allow-list
     * lives in WordPress and the decision asks for it.
     */
    private function decide(?string $origin, bool $credentials = false, int $maxAge = 0): array
    {
        return NTDST_Rest::corsDecision($origin, [
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

    /** @return array<string, mixed> The class's own CORS statics. */
    private function corsStatics(): array
    {
        $property = (new ReflectionClass(NTDST_Rest::class))->getProperty('cors');
        $property->setAccessible(true);

        return (array) $property->getValue();
    }

    // =====================================================================
    // The list belongs to WordPress
    // =====================================================================

    public function testADeclaredOriginIsAddedToWordPresssOwnAllowList(): void
    {
        ntdst_rest('cors-list/v1')->cors(['https://a.test']);

        $this->assertSame(
            ['http://site.test', 'https://site.test', 'https://a.test'],
            $this->allowList(),
            'The filter must return WordPress\'s defaults, in order, plus what was declared — and nothing else.',
        );
    }

    public function testTheClassKeepsNoOriginListOfItsOwn(): void
    {
        ntdst_rest('cors-nostore/v1')->cors(['https://a.test'], true, 600);

        $statics = $this->corsStatics();

        $this->assertArrayNotHasKey(
            'origins',
            $statics,
            'Two allow-lists is one too many: the one WordPress reads would drift from the one we check.',
        );
        $this->assertSame(
            ['credentials', 'max_age'],
            array_keys($statics),
            'Only the two things WordPress has no answer for stay here.',
        );
    }

    public function testTwoNamespacesEachAddTheirOwnOriginAndNeitherLosesTheOther(): void
    {
        ntdst_rest('cors-a/v1')->cors(['https://a.test']);
        ntdst_rest('cors-b/v1')->cors(['https://b.test']);

        $this->assertSame(
            ['http://site.test', 'https://site.test', 'https://a.test', 'https://b.test'],
            $this->allowList(),
        );
    }

    public function testDeclaringTheSameOriginTwiceDoesNotStackItUp(): void
    {
        // The list is a SET — WordPress answers over it with in_array(). A
        // merge that appends blindly grows on every declaration and hides which
        // module actually asked for an origin.
        ntdst_rest('cors-dup/v1')->cors(['https://a.test']);
        ntdst_rest('cors-dup2/v1')->cors(['https://a.test', 'https://site.test']);

        $this->assertSame(
            ['http://site.test', 'https://site.test', 'https://a.test'],
            $this->allowList(),
            'A declared origin WordPress already allows must not be added a second time.',
        );
    }

    public function testAnEmptyDeclarationWidensNothing(): void
    {
        ntdst_rest('cors-empty/v1')->cors([]);

        $this->assertSame(
            self::WP_DEFAULTS,
            $this->allowListIfMounted(),
            'Declaring no origins must leave WordPress\'s list exactly as it was.',
        );
    }

    // =====================================================================
    // Denials — threat row #9: this list also reaches admin-ajax
    // =====================================================================

    public function testAWildcardIsRefusedAndWidensNothing(): void
    {
        // '*' is a misconfiguration, not a shorthand. `allowed_http_origins` is
        // site-wide: admin-ajax's send_origin_headers() reads the same list, so
        // a wildcard here would hand every origin the site's ajax surface too.
        ntdst_rest('cors-star/v1')->cors(['*']);

        $this->assertCount(1, $this->doingItWrong, 'A refused wildcard must tell the author once.');

        $this->assertSame(self::WP_DEFAULTS, $this->allowListIfMounted(), 'A wildcard widened the site-wide list.');
        $this->assertFalse($this->wordPressAllows('https://evil.example.net'), 'A wildcard granted an origin.');
    }

    public function testAWildcardIsReportedOnceNoMatterHowOftenItIsDeclared(): void
    {
        // cors() runs on every request. A refusal that shouts once per request
        // is a log-flood, and authors stop reading refusals that repeat.
        ntdst_rest('cors-star2/v1')->cors(['*']);
        ntdst_rest('cors-star2/v1')->cors(['*']);

        $this->assertCount(1, $this->doingItWrong);
    }

    public function testAWildcardBesideARealOriginTakesTheWholeDeclarationDown(): void
    {
        // Half-honouring a bad list is the worst outcome: the author reads
        // "some of it worked" and never fixes the wildcard.
        ntdst_rest('cors-star3/v1')->cors(['https://a.test', '*']);

        $this->assertCount(1, $this->doingItWrong);
        $this->assertFalse($this->wordPressAllows('https://a.test'));
        $this->assertFalse($this->wordPressAllows('https://evil.example.net'));
    }

    public function testANonStringEntryNeverReachesTheAllowList(): void
    {
        // A malformed config — `true`, `1`, a stray `0` — would match EVERY
        // origin under a loose in_array(). WordPress compares strictly, so a
        // non-string cannot match; it still has no business on a site-wide list.
        ntdst_rest('cors-junk/v1')->cors([true, 1, 0, null, 'https://ok.test']);

        $this->assertSame(
            ['http://site.test', 'https://site.test', 'https://ok.test'],
            $this->allowList(),
        );
    }

    public function testAnOriginThatOnlyPrefixesOrSuffixesAnAllowedOneIsRefused(): void
    {
        ntdst_rest('cors-affix/v1')->cors([self::ALLOWED]);

        $this->assertTrue($this->wordPressAllows(self::ALLOWED));
        $this->assertFalse($this->wordPressAllows('https://app.example.com.evil.net'));
        $this->assertFalse($this->wordPressAllows('https://notapp.example.com'));
        $this->assertFalse($this->wordPressAllows('http://app.example.com'), 'The scheme is part of the origin.');
    }

    public function testACallableIsConsultedForTheRequestsOriginRatherThanStored(): void
    {
        // A resolver decides per request. There is nothing to put on a static
        // list, so the list must not be where it goes: the filter asks it.
        $asked = [];
        $resolver = static function (string $origin) use (&$asked): bool {
            $asked[] = $origin;
            return str_ends_with($origin, '.vad.be');
        };

        ntdst_rest('cors-fn/v1')->cors($resolver);

        $this->assertTrue($this->wordPressAllows('https://analytics.vad.be'));
        $this->assertContains(
            'https://analytics.vad.be',
            $asked,
            'The resolver was never asked about the origin of the request in hand.',
        );
        $this->assertFalse($this->wordPressAllows('https://evil.net'));
        $this->assertArrayNotHasKey('origins', $this->corsStatics());
    }

    public function testACallableThatSaysNoLeavesWordPresssListUntouched(): void
    {
        ntdst_rest('cors-fn2/v1')->cors(static fn(string $origin): bool => false);

        $this->assertFalse($this->wordPressAllows('https://evil.net'));
        $this->assertTrue(
            $this->wordPressAllows('http://site.test'),
            'A resolver that grants nothing must not take WordPress\'s own origins away with it.',
        );
        $this->assertTrue($this->wordPressAllows('https://site.test'));

        $GLOBALS['_ntdst_test_http_origin'] = 'https://evil.net';
        $this->assertSame(
            self::WP_DEFAULTS,
            $this->allowListIfMounted(),
            'A resolver that grants nothing must not add, drop or reorder WordPress\'s own entries.',
        );
    }

    // =====================================================================
    // The decision asks WordPress
    // =====================================================================

    public function testTheDecisionAsksWordPressAboutTheOrigin(): void
    {
        $this->wordPressAllowsOnly(self::ALLOWED);

        $decision = $this->decide(self::ALLOWED);

        $this->assertSame(
            [self::ALLOWED],
            $this->askedWordPress,
            'The decision must put the question to is_allowed_http_origin() — once, with the exact origin.',
        );
        $this->assertContains('Access-Control-Allow-Origin: ' . self::ALLOWED, $decision['set']);
        $this->assertContains('Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE', $decision['set']);
        // Vary is NOT here on purpose: it is not a policy decision. A shared
        // cache needs it whether or not we grant, so sendCors() emits it
        // unconditionally before consulting the decision at all.
        $this->assertNotContains('Vary: Origin', $decision['set']);
    }

    public function testAnOriginWordPressRefusesLosesBothHeaders(): void
    {
        $this->wordPressAllowsOnly(self::ALLOWED);

        $decision = $this->decide('https://evil.example.net');

        $this->assertSame([], $decision['set'], 'Nothing is granted to an origin WordPress does not allow.');
        $this->assertContains(
            'Access-Control-Allow-Origin',
            $decision['remove'],
            "WP core already emitted one at priority 10. Not overriding it leaves core's reflection standing.",
        );
        $this->assertContains('Access-Control-Allow-Credentials', $decision['remove']);
    }

    public function testWordPressIsTheOnlySourceOfTruthForAllowedNess(): void
    {
        // A policy array that still carries an `origins` key is a leftover from
        // the shape this task replaced. It must decide nothing — in either
        // direction — or the site has two allow-lists that can disagree.
        $this->wordPressAllowsOnly(self::ALLOWED);

        $stale = NTDST_Rest::corsDecision(self::ALLOWED, [
            'origins' => [],
            'credentials' => false,
            'max_age' => 0,
        ]);
        $this->assertContains(
            'Access-Control-Allow-Origin: ' . self::ALLOWED,
            $stale['set'],
            'WordPress allowed it; a stale local list refused it.',
        );

        $widened = NTDST_Rest::corsDecision('https://evil.example.net', [
            'origins' => ['https://evil.example.net', '*'],
            'credentials' => false,
            'max_age' => 0,
        ]);
        $this->assertSame([], $widened['set'], 'A stale local list granted what WordPress refuses.');
    }

    public function testOriginNullIsRefusedWithoutAskingWordPress(): void
    {
        // A file:// page and a sandboxed iframe both send `Origin: null`. WP
        // core echoes it back WITH credentials. It can never be attributed to
        // anyone, so it can never be trusted — and it is not a question worth
        // putting to a filter another plugin can answer 'yes' to.
        $this->wordPressAllowsOnly('null', self::ALLOWED);

        $this->assertSame([], $this->decide('null')['set'], 'Origin: null is not an identity.');
        $this->assertSame([], $this->askedWordPress, 'WordPress must not be asked to bless "null".');
    }

    public function testAnEmptyOriginIsRefusedWithoutAskingWordPress(): void
    {
        // is_allowed_http_origin(null) falls back to get_http_origin(), so
        // handing it an absent origin asks a different question than the one in
        // hand — and gets an answer about whatever the request happens to carry.
        $this->wordPressAllowsOnly('', self::ALLOWED);
        $GLOBALS['_ntdst_test_http_origin'] = self::ALLOWED;

        $this->assertSame([], $this->decide('')['set']);
        $this->assertSame([], $this->decide(null)['set']);
        $this->assertSame([], $this->askedWordPress);
    }

    public function testCredentialsAreStrippedUnlessTheSiteAsksForThem(): void
    {
        $this->wordPressAllowsOnly(self::ALLOWED);

        $default = $this->decide(self::ALLOWED);
        $this->assertContains('Access-Control-Allow-Credentials', $default['remove']);
        $this->assertNotContains('access-control-allow-credentials', $this->names($default));

        $optedIn = $this->decide(self::ALLOWED, true);
        $this->assertContains('Access-Control-Allow-Credentials: true', $optedIn['set']);
    }

    public function testCredentialsAreNeverGrantedToAnOriginWordPressRefuses(): void
    {
        $this->wordPressAllowsOnly(self::ALLOWED);

        $decision = $this->decide('https://evil.example.net', true);

        $this->assertSame([], $decision['set']);
        $this->assertContains('Access-Control-Allow-Credentials', $decision['remove']);
    }

    public function testMaxAgeIsSentOnlyWhenTheSiteAsksForIt(): void
    {
        $this->wordPressAllowsOnly(self::ALLOWED);

        $this->assertNotContains('Access-Control-Max-Age: 0', $this->decide(self::ALLOWED)['set']);
        $this->assertContains('Access-Control-Max-Age: 600', $this->decide(self::ALLOWED, false, 600)['set']);
    }

    // =====================================================================
    // Wiring — the decision must actually reach a request
    // =====================================================================

    public function testDeclaringCorsMountsTheFilter(): void
    {
        ntdst_rest('cors1/v1')->cors([self::ALLOWED]);

        $this->assertContains(
            ['rest_api_init', [NTDST_Rest::class, 'mountCors'], 15],
            $this->actions,
            'Declaring a policy must schedule the swap of core\'s emitter.',
        );

        NTDST_Rest::mountCors();

        $this->assertContains(
            ['rest_pre_serve_request', 'rest_send_cors_headers'],
            $this->removedFilters,
            'Core\'s reflect-any-origin handler must come off the bus.',
        );
        $this->assertSame(
            [NTDST_Rest::class, 'sendCors'],
            $GLOBALS['_ntdst_test_filters']['rest_pre_serve_request'] ?? null,
            'Ours must go on in its place.',
        );
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
