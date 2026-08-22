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

    // The rest_api_init harness — the three hook states, WordPress's own
    // allow-list, and who is emitting CORS headers — lives in ONE place
    // (tests/Support/RestApiInitHarness.php), shared with
    // NtdstRestDefaultsTest and RestInternalByDefaultTest.
    use RestApiInitHarness;

    private const ALLOWED = 'https://app.example.com';

    /** @var array<string, array<string, mixed>> */
    private array $registered = [];

    /** @var list<array{0: string, 1: string, 2: string}> Every _doing_it_wrong() call. */
    private array $doingItWrong = [];

    /** @var list<array{0: string, 1: mixed, 2: int}> Every add_action() call. */
    private array $actions = [];

    /** @var list<string> Every origin handed to sanitize_url(), in order. */
    private array $sanitized = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->registered = [];
        $this->doingItWrong = [];
        $this->actions = [];
        $this->sanitized = [];
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

        // The CORS declaration is SITE-WIDE and lives in class statics, so it
        // leaks from one test into the next: test two would read test one's
        // origins and pass on evidence it never produced. resetRestHarness()
        // resets every static by reflection, clears the process-wide hook
        // recorders, puts WordPress's own CORS emitter back on the bus at
        // priority 10 (where core mounts it), and says this is a REST request.
        $this->resetRestHarness();

        // Unless a case says otherwise, the hook has already come and gone —
        // the state most of this file's declarations are read in, and the one
        // where a route declared now registers immediately.
        $this->restApiInitState('after');

        Functions\when('register_rest_route')->alias(
            function (string $ns, string $route, array $args) {
                $this->registered['/' . trim($ns, '/') . $route] = $args;
                return true;
            },
        );
        Functions\when('rest_get_server')->alias(function () {
            $table = $this->registered;
            return new class ($table) {
                public function __construct(private array $table) {}
                public function get_routes(): array { return $this->table; }
            };
        });

        // did_action() reads 1 from the moment rest_api_init STARTS and never
        // goes back, so it cannot tell "inside the hook" from "after it".
        // doing_action() is the second bit, and cors() has to be safe in all
        // three states.
        Functions\when('did_action')->alias(fn($hook = null) => $this->restApiInitDid);
        Functions\when('doing_action')->alias(function ($hook = null) {
            if ($hook === null || (string) $hook === 'rest_api_init') {
                return $this->restApiInitDoing;
            }

            return false;
        });
        Functions\when('add_action')->alias(
            function ($hook, $cb = null, $priority = 10) {
                $this->actions[] = [$hook, $cb, (int) $priority];
                $this->hooked[(string) $hook][(int) $priority][] = $cb;
                return true;
            },
        );
        // remove_filter() really TAKES THE CALLBACK OFF. Recording the call and
        // leaving core's emitter mounted would let "core's handler is off" pass
        // against a wrapper that asked politely and moved on.
        Functions\when('remove_filter')->alias(
            function ($hook, $cb = null, $priority = 10) {
                $this->removedFilters[] = [$hook, $cb];
                $this->forgetRecordedFilter($hook, $cb);
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
        // Identity, but RECORDED: sanitize_url() is what stops a header
        // injection reaching the wire, and "it was called" is the assertion.
        Functions\when('sanitize_url')->alias(function ($url = '') {
            $this->sanitized[] = (string) $url;
            return $url;
        });

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

        // The key SET is not the promise — which keys the class happens to
        // hold is its own business, and pinning it turns every internal tidy-up
        // into a red test. The promise is that no ORIGIN LIST lives here: two
        // allow-lists is one too many, and the one WordPress reads would drift
        // from the one we check.
        $this->assertArrayNotHasKey('origins', $this->corsStatics());
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
            $this->wpDefaultOrigins,
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

        $this->assertSame($this->wpDefaultOrigins, $this->allowListIfMounted(), 'A wildcard widened the site-wide list.');
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
            $this->wpDefaultOrigins,
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

    public function testDeclaringCorsBeforeTheHookSchedulesTheSwapOfCoresEmitter(): void
    {
        // Scheduling is what the BEFORE state means: rest_api_init has not run,
        // so there is a hook to hang the swap on. The other two states are
        // covered by testCorsTakesCoresEmitterOffTheBusInEveryHookState, which
        // is where a cors() that arrives too late to schedule anything has to
        // act immediately instead.
        $this->restApiInitState('before');

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
    // =====================================================================
    // FIX WAVE 1 — B1/B2: the swap happens in EVERY hook state
    // (auditor C1 Critical, reviewer L-2, threat row #9)
    // =====================================================================

    /**
     * @dataProvider hookStateProvider
     */
    public function testCorsTakesCoresEmitterOffTheBusInEveryHookState(string $state): void
    {
        // WHY: this is the Critical. cors() does two things — it widens
        // WordPress's site-wide allow-list, and it replaces core's
        // rest_send_cors_headers() with an emitter that fails closed. Only the
        // second one was conditional on timing. A consumer that calls cors()
        // from inside rest_api_init at a priority past the scheduled swap, or
        // after the hook has finished, therefore widened the list while CORE'S
        // REFLECT-ANY-ORIGIN HANDLER WAS STILL MOUNTED: every origin on the
        // widened list gets Access-Control-Allow-Origin AND
        // Access-Control-Allow-Credentials: true from core itself.
        //
        // The order of the two effects is the whole property, so all three
        // states are asserted, not just the one the framework was written for.
        $this->declareInHookState($state, function (): void {
            ntdst_rest('cors-state/v1')->cors([self::ALLOWED]);
        });

        $this->assertFalse(
            $this->coreCorsEmitterIsMounted(),
            "declared {$state} the hook: core's reflect-any-origin emitter is STILL on rest_pre_serve_request, "
                . 'and the allow-list has already been widened.',
        );
        $this->assertNotNull(
            $this->ntdstCorsEmitterPriority(),
            "declared {$state} the hook: nothing of ours went on the bus, so nobody answers for the origin.",
        );
        $this->assertContains(
            self::ALLOWED,
            $this->allowListIfMounted(),
            "control: the declaration did reach WordPress's list in the {$state} state.",
        );
    }

    /**
     * @dataProvider hookStateProvider
     */
    public function testTheFailClosedEmitterAnswersForAnOriginInEveryHookState(string $state): void
    {
        // WHY: the swap is not the point — being the one who ANSWERS is. A
        // decision that comes back null in one timing state means core's
        // headers stand unchallenged for that request.
        $this->declareInHookState($state, function (): void {
            ntdst_rest('cors-answer/v1')->cors([self::ALLOWED]);
        });

        $this->wordPressAllowsOnly(self::ALLOWED);

        $this->assertNotNull(
            NTDST_Rest::corsDecisionFor('https://evil.example.net'),
            "declared {$state} the hook: the site declared a policy, so it must decide — including refusing.",
        );
        $this->assertSame(
            [],
            NTDST_Rest::corsDecisionFor('https://evil.example.net')['set'],
            'and the refusal grants nothing.',
        );
    }

    // =====================================================================
    // FIX WAVE 1 — B18: our emitter gets the LAST word (sentinel I6)
    // =====================================================================

    public function testTheEmitterGoesOnAtTheLastPossiblePriority(): void
    {
        // WHY: rest_pre_serve_request is a filter any plugin can join. Core's
        // own emitter sits at 10, and anything mounted after us can re-add the
        // Access-Control-Allow-Origin header we just removed — the last writer
        // of a header wins on the wire. PHP_INT_MAX is how a fail-closed
        // decision stays the final one.
        ntdst_rest('cors-last/v1')->cors([self::ALLOWED]);
        NTDST_Rest::mountCors();

        $this->assertSame(
            PHP_INT_MAX,
            $this->ntdstCorsEmitterPriority(),
            'the emitter must be the last word on rest_pre_serve_request, not one voice at priority 10.',
        );
    }

    // =====================================================================
    // FIX WAVE 1 — B12: declared origins are REST-ONLY (sentinel C2, Critical)
    // =====================================================================

    public function testADeclaredOriginDoesNotWidenTheListOutsideARestRequest(): void
    {
        // WHY: sentinel C2, and it is a full account takeover. allowed_http_origins
        // is site-wide, and admin-ajax.php / admin-post.php / the customizer all
        // call send_origin_headers(), which grants
        // Access-Control-Allow-Credentials: true to every allowed origin —
        // unconditionally, whatever cors() said about credentials. A declared
        // origin could therefore fetch admin-ajax.php?action=rest-nonce WITH the
        // victim's cookies, read the response cross-origin, and then hold a
        // valid REST nonce for that logged-in user.
        //
        // So the declaration is scoped to the request kind it was written for:
        // while WordPress is serving a REST request, and never on the ajax
        // surface.
        $this->servingRestRequest(false);

        ntdst_rest('cors-rest-only/v1')->cors(['https://a.test']);

        $this->assertSame(
            $this->wpDefaultOrigins,
            $this->allowListIfMounted(),
            'admin-ajax reads this list too — a REST declaration must not appear on it.',
        );
        $this->assertFalse(
            $this->wordPressAllows('https://a.test'),
            'the declared origin was allowed on a non-REST request.',
        );

        $this->servingRestRequest(true);

        $this->assertSame(
            [...$this->wpDefaultOrigins, 'https://a.test'],
            $this->allowListIfMounted(),
            'control: on a REST request the same declaration does apply.',
        );
    }

    public function testTheResolverIsNotConsultedOutsideARestRequest(): void
    {
        // WHY: the same scoping, for the callable form — and here it is not
        // only a widening but a CALL. A resolver written to answer "may this
        // front-end read my REST API" would otherwise be asked to vouch for an
        // admin-ajax request it knows nothing about, and its "yes" would carry
        // credentials.
        $asked = [];
        $resolver = static function (string $origin) use (&$asked): bool {
            $asked[] = $origin;
            return true;
        };

        $this->servingRestRequest(false);

        ntdst_rest('cors-fn-rest-only/v1')->cors($resolver);

        $this->assertFalse(
            $this->wordPressAllows('https://anything.test'),
            'a resolver that says yes to everything must not be consulted outside REST.',
        );
        $this->assertSame([], $asked, 'the resolver was asked about a request it was never declared for.');

        $this->servingRestRequest(true);

        $this->assertTrue($this->wordPressAllows('https://anything.test'), 'control: on REST it is consulted.');
        $this->assertSame(['https://anything.test'], $asked);
    }

    // =====================================================================
    // FIX WAVE 1 — B15: credentials belong to the origin that asked for them
    // (sentinel I3)
    // =====================================================================

    public function testCredentialsFollowTheDeclarationThatNamedTheOrigin(): void
    {
        // WHY: two modules, two declarations, one site. A single site-wide
        // `credentials` flag means the module that asked for credentials grants
        // them to the OTHER module's origin as well — a third-party analytics
        // origin someone whitelisted for a public feed suddenly reads
        // authenticated responses. The decision is per origin.
        ntdst_rest('cred-a/v1')->cors(['https://a.test']);
        ntdst_rest('cred-b/v1')->cors(['https://b.test'], true);

        $this->wordPressAllowsOnly('https://a.test', 'https://b.test');

        $withCredentials = NTDST_Rest::corsDecisionFor('https://b.test');
        $without         = NTDST_Rest::corsDecisionFor('https://a.test');

        $this->assertContains(
            'Access-Control-Allow-Credentials: true',
            $withCredentials['set'],
            'the origin whose module asked for credentials gets them.',
        );
        $this->assertNotContains(
            'Access-Control-Allow-Credentials: true',
            $without['set'],
            'the origin declared WITHOUT credentials must not inherit another module\'s grant.',
        );
        $this->assertContains(
            'Access-Control-Allow-Credentials',
            $without['remove'],
            "and core's own credentials header is taken back off the wire for it.",
        );
    }

    public function testMaxAgeStaysTheHighestAnyDeclarationAskedFor(): void
    {
        // WHY: max-age is a cache hint, not a permission — the worst a longer
        // one does is make a preflight rarer. Taking the max keeps two modules
        // from silently shortening each other's, which credentials cannot be
        // treated the same way (above).
        ntdst_rest('age-a/v1')->cors(['https://a.test'], false, 600);
        ntdst_rest('age-b/v1')->cors(['https://b.test'], false, 60);

        $this->wordPressAllowsOnly('https://a.test', 'https://b.test');

        $this->assertContains('Access-Control-Max-Age: 600', NTDST_Rest::corsDecisionFor('https://a.test')['set']);
        $this->assertContains('Access-Control-Max-Age: 600', NTDST_Rest::corsDecisionFor('https://b.test')['set']);
    }

    // =====================================================================
    // FIX WAVE 1 — B16: resolver hygiene (sentinel I4/S5)
    // =====================================================================

    public function testTheEmittedOriginPassesThroughSanitizeUrl(): void
    {
        // WHY: the origin is attacker-controlled and it is written into a
        // RESPONSE HEADER. WordPress's own send_origin_headers() runs it
        // through sanitize_url() first, and dropping that step here is a
        // header-injection surface this package would own alone.
        $this->wordPressAllowsOnly(self::ALLOWED);

        $decision = $this->decide(self::ALLOWED);

        $this->assertContains('Access-Control-Allow-Origin: ' . self::ALLOWED, $decision['set']);
        $this->assertContains(
            self::ALLOWED,
            $this->sanitized,
            'the origin reached the header without passing through sanitize_url().',
        );
    }

    public function testAResolverThatThrowsDeniesTheOriginAndLeavesOneErrorInTheLog(): void
    {
        // WHY: the resolver is consumer code, called from inside a WordPress
        // FILTER, on a request that is already being served. An exception there
        // takes the whole request down — an ajax surface included — and a
        // resolver that talks to a database or an HTTP API will throw
        // eventually. It must fail closed and leave a trace, exactly once.
        ntdst_rest('cors-throw/v1')->cors(static function (string $origin): bool {
            throw new RuntimeException('the resolver looked something up and it was not there');
        });

        $this->assertFalse(
            $this->wordPressAllows('https://a.test'),
            'a resolver that threw must not be read as a yes.',
        );
        $this->assertCount(
            1,
            $this->logMessages('api', 'error'),
            'a thrown resolver leaves exactly one error in the api log — silence here is a debugging dead end.',
        );
    }

    // =====================================================================
    // FIX WAVE 1 — B7/B17: refusals are readable, and visible on production
    // =====================================================================

    public function testTheWildcardRefusalNamesTheAllowListEntryAndTheReleaseThatRefusedIt(): void
    {
        // WHY: the message is the whole remediation. A cors() refusal that
        // reads "Route was not registered" sends the author looking at their
        // routes, which are fine — the wildcard is in a completely different
        // call. And the @since version is what tells a consumer whether an
        // upgrade introduced the refusal.
        ntdst_rest('cors-msg/v1')->cors(['*']);

        $this->assertCount(1, $this->doingItWrong, 'control: the wildcard was refused.');

        [, $message, $version] = $this->doingItWrong[0];

        $this->assertStringNotContainsString(
            'Route was not registered',
            $message,
            'a CORS refusal must not be reported as a route refusal — it sends the author to the wrong file.',
        );
        $this->assertStringContainsString(
            'allow-list',
            $message,
            'the message must name what was refused: an allow-list entry. Reported: ' . $message,
        );
        $this->assertSame('5.0.0', $version, 'the refusal names the release that introduced it.');
    }

    public function testAWildcardStillLeavesTheFailClosedEmitterAnsweringForTheSite(): void
    {
        // WHY: the wildcard is refused, but the site still SAID cors(). Walking
        // away would leave core's reflect-any-origin emitter mounted — so the
        // author who asked for "*" would get exactly what they asked for, by
        // accident, through core.
        ntdst_rest('cors-star4/v1')->cors(['*']);

        $this->assertFalse(
            $this->coreCorsEmitterIsMounted(),
            "a refused wildcard must not leave core's reflect-any-origin emitter in charge.",
        );

        $this->wordPressAllowsOnly(self::ALLOWED);

        $this->assertNotNull(
            NTDST_Rest::corsDecisionFor('https://evil.example.net'),
            'the site declared a policy; the fail-closed emitter answers for it.',
        );
    }

    public function testANonFatalCorsRefusalIsWrittenToTheLog(): void
    {
        // WHY: sentinel I5. Inside a REST request WordPress suppresses
        // _doing_it_wrong() entirely (doing_it_wrong_trigger_error is false
        // there), so on production the wildcard refusal is currently invisible:
        // the author sees a CORS failure in a browser and nothing anywhere
        // explains it.
        ntdst_rest('cors-log/v1')->cors(['*']);

        $this->assertCount(
            1,
            $this->logMessages('api', 'warning'),
            'a non-fatal refusal must reach the api log at warning level.',
        );
    }
}
