<?php // tests/Unit/RestInternalByDefaultTest.php
// FEATURE TEST — specs/core-shape Cluster 2, written by the independent
// test-author AFTER T04–T06 landed, from the cluster's promise and from
// spec.md FR-4 / FR-5 / FR-6 / SC-2 / SC-6 and plan.md threat rows #4, #5, #9.
// It does not re-test the tasks; the three contract files do that. It tests
// what a SITE gets.
//
// THE BEHAVIOUR UNDER TEST, in the cluster's own words:
//   a route that says nothing is reachable only by a logged-in user;
//   ->public() is the one way to make it anonymous;
//   a write verb that names no capability does not exist;
//   the CORS allow-list is WordPress's own and fails closed.
//
// HOW IT IS TESTED — through a CONSUMER, not through the API surface. One
// module (NtdstFeatureBaselineModule below) declares what a real site like
// ntdst-baseline declares: one namespace, namespace defaults, four reads, four
// writes, one ->public(), one rate_limit, one cors(). Nothing here calls a
// single-purpose helper in isolation; every assertion is read off the route
// table WordPress was handed, the way the cluster's Observable reads it off
// `rest_get_server()->get_routes()`:
//
//     is_string($handlers[0]['permission_callback'])
//         ? $handlers[0]['permission_callback']
//         : 'closure'
//
// DECLARED TWICE, ON PURPOSE. Every route assertion runs under both boot
// orders a WordPress module can have: declared BEFORE rest_api_init (queued,
// flushed when the hook fires) and declared FROM INSIDE a rest_api_init
// callback (the idiomatic place, where did_action() already reads 1 and only
// doing_action() can tell the two apart). A site that boots the second way and
// silently gets a different permission posture is the failure this pins.
//
// WHAT IS NOT ASSERTED HERE: HTTP status codes. The unit tier cannot produce
// one, and a 403 test would pass against a route that got registered — which is
// the weaker property. The write-verb rule is asserted as ABSENCE from the
// route table (threat row #4), and the CORS refusal as a decision that REMOVES
// the headers core already emitted (threat row #9).
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class RestInternalByDefaultTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    // The rest_api_init harness — the hook states, WP_Hook's live iteration,
    // WordPress's allow-list and the statics reset — lives in ONE place
    // (tests/Support/RestApiInitHarness.php), shared with NtdstRestDefaultsTest
    // and NtdstRestCorsTest. Three hand-copies meant a fix to one of them fixed
    // a third of the suite.
    use RestApiInitHarness;

    /** The consumer's namespace — the one a site declares once and never repeats. */
    private const NS = 'ntdst-baseline/v1';

    /** Route keys, exactly as WordPress keys them in get_routes(). */
    private const HEALTH      = '/ntdst-baseline/v1/health';
    private const SETTINGS    = '/ntdst-baseline/v1/settings';
    private const MANIFEST    = '/ntdst-baseline/v1/manifest';
    private const SEARCH      = '/ntdst-baseline/v1/search';
    private const PURGE       = '/ntdst-baseline/v1/purge';
    private const CACHE       = '/ntdst-baseline/v1/cache/(?P<key>[a-z0-9-]+)';
    private const FLUSH       = '/ntdst-baseline/v1/flush';
    private const SUBSCRIBERS = '/ntdst-baseline/v1/subscribers';

    /** The one cross-origin consumer the module declares. */
    private const ORIGIN = NtdstFeatureBaselineModule::ORIGIN;

    /** The fake WP route table: '/ns/route' => every arg-array register_rest_route() received. */
    private array $routeTable = [];

    /** Every _doing_it_wrong() call: [function, message, version]. */
    private array $wrongs = [];

    /** Every capability current_user_can() was asked about, in order. */
    private array $capsAsked = [];

    /** Capabilities the sentinel user holds. Empty = a logged-in nobody. */
    private array $heldCaps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->routeTable = [];
        $this->wrongs     = [];
        $this->capsAsked  = [];
        $this->heldCaps   = [];

        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

        // Resets every static the class declares (by reflection), clears the
        // process-wide recorders tests/bootstrap.php defines, puts WordPress's
        // own CORS emitter back on the bus where core mounts it, and says this
        // is a REST request.
        $this->resetRestHarness();

        // WP core's registrar. Recording it is how ABSENCE becomes observable:
        // a route the wrapper refuses never reaches this stub.
        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $key = '/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/');
                $this->routeTable[$key][] = $args;
                return true;
            },
        );

        // WP_REST_Server::get_routes($namespace) — the register the cluster's
        // Observable reads, including its namespace filter and its
        // route => LIST-of-handlers shape.
        Functions\when('rest_get_server')->alias(function () {
            $table = $this->routeTable;
            return new class ($table) {
                public function __construct(private array $table) {}

                public function get_routes($namespace = null): array
                {
                    if ($namespace === null || $namespace === '') {
                        return $this->table;
                    }

                    $prefix = '/' . trim((string) $namespace, '/') . '/';

                    return array_filter(
                        $this->table,
                        static fn(string $route): bool => str_starts_with($route, $prefix),
                        ARRAY_FILTER_USE_KEY,
                    );
                }
            };
        });

        // Recorded, not defined as a plain function: SchedulerTest patches
        // add_action and Patchwork throws DefinedTooEarly if a plain definition
        // wins the race. The PRIORITY is kept because a flush mounted from
        // inside the running hook only runs if the iteration has not passed it.
        Functions\when('add_action')->alias(function ($hook, $cb = null, $priority = 10, $args = 1) {
            $this->hooked[(string) $hook][(int) $priority][] = $cb;
            return true;
        });
        Functions\when('remove_filter')->alias(function ($hook, $cb = null, $priority = 10) {
            $this->forgetRecordedFilter($hook, $cb);
            return true;
        });
        Functions\when('remove_action')->justReturn(true);

        Functions\when('did_action')->alias(fn($hook = null) => $this->restApiInitDid);
        Functions\when('doing_action')->alias(function ($hook = null) {
            if ($hook === null || (string) $hook === 'rest_api_init') {
                return $this->restApiInitDoing;
            }

            return false;
        });

        Functions\when('_doing_it_wrong')->alias(function ($function = '', $message = '', $version = '') {
            $this->wrongs[] = [(string) $function, (string) $message, (string) $version];
        });

        // The capability sentinel. Every closure permission has to come through
        // here; one that hardcodes true, or asks about the wrong capability,
        // cannot pass.
        Functions\when('current_user_can')->alias(function ($cap) {
            $this->capsAsked[] = (string) $cap;
            return in_array((string) $cap, $this->heldCaps, true);
        });

        // Nothing is allowed until a test says so, and every question is
        // recorded — "was WordPress asked at all, and about what?" is the
        // assertion in threat row #9.
        $this->wordPressAllowsOnly();

        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_url')->returnArg();
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn($hook, $value = null, ...$rest) => $value);
        Functions\when('doing_it_wrong_run')->justReturn(null);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_http_origin')->alias(static fn() => (string) ($GLOBALS['_ntdst_test_http_origin'] ?? ''));
    }

    protected function tearDown(): void
    {
        $this->forgetRecordedHooks();

        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Harness
    // =====================================================================

    /**
     * The two boot orders a WordPress module can have. Both are real; the
     * second is the idiomatic one, and the one where did_action() alone cannot
     * tell "inside the hook" from "long after it".
     *
     * @return array<string, array{0: bool}>
     */
    public static function bootOrderProvider(): array
    {
        return [
            'declared before rest_api_init'        => [false],
            'declared inside a rest_api_init hook' => [true],
        ];
    }

    /** Boot the consumer module the way a site would, then let WordPress run. */
    private function bootModule(bool $insideRestApiInit): void
    {
        (new NtdstFeatureBaselineModule(self::NS))->boot($insideRestApiInit);

        $this->fireRestApiInit();
    }

    /** @return list<array<string, mixed>> every registration WordPress received for a route */
    private function registrationsFor(string $key): array
    {
        return $this->routeTable[$key] ?? [];
    }

    /** The permission_callback WordPress was handed, exactly as given. */
    private function permissionCallbackOf(string $key): mixed
    {
        $registrations = $this->registrationsFor($key);

        $this->assertNotEmpty($registrations, "control: {$key} must have been registered.");

        return $registrations[0]['permission_callback'] ?? null;
    }

    /**
     * The cluster's Observable, verbatim: what `wp eval` would print for each
     * route — the literal string, or the word 'closure'.
     *
     * @return array<string, string>
     */
    private function observable(string $namespace = self::NS): array
    {
        $printed = [];

        foreach (rest_get_server()->get_routes($namespace) as $route => $handlers) {
            $callback       = $handlers[0]['permission_callback'] ?? null;
            $printed[$route] = is_string($callback) ? $callback : 'closure';
        }

        ksort($printed);

        return $printed;
    }

    /**
     * README's anonymous-surface snippet, run against the register the site
     * really published. This is FR-5's replacement for publicSurface().
     *
     * @return list<string>
     */
    private function anonymousRoutes(string $namespace = self::NS): array
    {
        $routes = rest_get_server()->get_routes($namespace);

        $anonymous = array_keys(array_filter(
            $routes,
            static fn(array $handlers): bool => in_array(
                '__return_true',
                array_column($handlers, 'permission_callback'),
                true,
            ),
        ));

        sort($anonymous);

        return $anonymous;
    }

    /**
     * README's second rule: treat EVERY closure as unanswered-for. A route
     * whose permission_callback is neither literal string cannot be settled by
     * reading the register — it has to be settled by reading what the route
     * declared.
     *
     * @return list<string>
     */
    private function unansweredForRoutes(string $namespace = self::NS): array
    {
        $unanswered = [];

        foreach (rest_get_server()->get_routes($namespace) as $route => $handlers) {
            foreach ($handlers as $handler) {
                $callback = $handler['permission_callback'] ?? null;

                if ($callback !== 'is_user_logged_in' && $callback !== '__return_true') {
                    $unanswered[] = $route;
                    break;
                }
            }
        }

        sort($unanswered);

        return $unanswered;
    }

    private function routeKeys(): array
    {
        return array_keys($this->routeTable);
    }

    /** Messages of every refusal, for a readable failure. */
    private function wrongMessages(): array
    {
        return array_map(static fn(array $w) => $w[1], $this->wrongs);
    }

    // =====================================================================
    // DENIAL FIRST — the writes the module never named a capability for
    // (FR-4, SC-2, threat row #4)
    // =====================================================================

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheModulesUnnamedWritesAreAbsentFromTheRouteTable(bool $inside): void
    {
        // WHY: on a site with open registration "logged in" is "anyone", so an
        // unnamed POST /purge would be a world-writable endpoint. The promise is
        // that such a route is NEVER handed to register_rest_route() — asserted
        // as absence and as a call count of ZERO, never as a 403.
        $this->bootModule($inside);

        foreach ([self::PURGE, self::CACHE, self::FLUSH] as $refused) {
            $this->assertNotContains(
                $refused,
                $this->routeKeys(),
                "{$refused} names no capability — it must not exist, not merely deny.",
            );
            $this->assertCount(
                0,
                $this->registrationsFor($refused),
                "zero registrations for {$refused}.",
            );
        }

        // Control: the module's reads DID register, so the absence above is not
        // the whole module failing to boot.
        $this->assertContains(self::HEALTH, $this->routeKeys(), 'control: the module registered its reads.');
        $this->assertContains(self::SUBSCRIBERS, $this->routeKeys(), 'control: the named write registered.');
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testEachRefusedWriteIsLoudExactlyOnce(bool $inside): void
    {
        // WHY: threat row #4 mitigation is "_doing_it_wrong + log". A refusal a
        // developer cannot see is a route that silently disappears in
        // production — the same failure as a route that silently opens.
        $this->bootModule($inside);

        $this->assertCount(
            3,
            $this->wrongs,
            'three unnamed writes, three refusals, no more and no fewer: ' . implode(' | ', $this->wrongMessages()),
        );
        $this->assertCount(
            3,
            $this->logMessages('api', 'error'),
            'each refusal also reaches the api log at error level, once.',
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testPublicOnAWriteDoesNotPublishItAndDoesNotLatchOntoTheNextRoute(bool $inside): void
    {
        // WHY: ->public() on POST /flush is the threat itself, not an exception
        // to it. It must refuse — and the capability write declared right after
        // it must be untouched by the refusal.
        $this->bootModule($inside);

        $this->assertNotContains(self::FLUSH, $this->routeKeys(), 'no shorthand publishes a write verb.');
        $this->assertNotSame(
            '__return_true',
            $this->permissionCallbackOf(self::SUBSCRIBERS),
            'a refused public() must not spill onto the next declaration.',
        );
    }

    // =====================================================================
    // The reads — internal by default, anonymous only where declared
    // =====================================================================

    /**
     * @dataProvider bootOrderProvider
     */
    public function testAnUnnamedGetRegistersTheLiteralIsUserLoggedInString(bool $inside): void
    {
        // WHY: SC-2 and the cluster's Observable. get_routes() is the only place
        // a site reads back what it published; a closure there is opaque, so the
        // default arrives as the STRING itself.
        $this->bootModule($inside);

        $this->assertSame('is_user_logged_in', $this->permissionCallbackOf(self::HEALTH));
        $this->assertSame('is_user_logged_in', $this->permissionCallbackOf(self::SETTINGS));
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testExactlyOneRouteInTheModuleIsAnonymous(bool $inside): void
    {
        // WHY: FR-5. The anonymous surface is a question about the whole
        // namespace, not about one route, and README's snippet is the way a
        // site asks it. The answer must be the one route that asked.
        $this->bootModule($inside);

        $this->assertSame(
            [self::MANIFEST],
            $this->anonymousRoutes(),
            '->public() is the ONE way in, and only /manifest used it.',
        );
        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf(self::MANIFEST),
            "public() registers the STRING '__return_true' so the surface is readable.",
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheObservablePrintsWhatTheClusterPromised(bool $inside): void
    {
        // WHY: this is the cluster's Observable, run here instead of over
        // wp-cli — the whole namespace at once, so a route that quietly changed
        // posture shows up beside its neighbours.
        $this->bootModule($inside);

        $this->assertSame(
            [
                self::HEALTH      => 'is_user_logged_in',
                self::MANIFEST    => '__return_true',
                self::SEARCH      => 'closure',
                self::SETTINGS    => 'is_user_logged_in',
                self::SUBSCRIBERS => 'closure',
            ],
            $this->observable(),
            'the published namespace, exactly: two internal reads, one anonymous read, two closures.',
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheUnansweredForSetIsExactlyTheTwoClosureRoutes(bool $inside): void
    {
        // WHY: README tells a site to treat every closure as unanswered-for and
        // settle it by reading the declaration. That instruction is only
        // followable if the closures are the ones the site can account for: the
        // capability write, and the rate-limited read (the documented caveat).
        $this->bootModule($inside);

        $this->assertSame(
            [self::SEARCH, self::SUBSCRIBERS],
            $this->unansweredForRoutes(),
            'anything else reading as a closure is a route no audit can settle.',
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testARateLimitedPublicReadRegistersAClosureNotTheAnonymousString(bool $inside): void
    {
        // WHY: README documents this caveat explicitly — the limiter has to run,
        // so a rate-limited route registers guard() even when it is public. A
        // site auditing on the literal string will NOT see this route, which is
        // exactly why the caveat is documented and pinned here.
        $this->bootModule($inside);

        $callback = $this->permissionCallbackOf(self::SEARCH);

        $this->assertIsCallable($callback, 'a rate-limited route still registers a permission callback.');
        $this->assertIsNotString($callback, 'a rate-limited route reads as a closure — README says so.');
        $this->assertNotContains(
            self::SEARCH,
            $this->anonymousRoutes(),
            "the literal-string audit cannot see it; that is the caveat, and it must stay true both ways.",
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheCapabilityWriteDeniesAUserWhoDoesNotHoldTheCapability(bool $inside): void
    {
        // WHY: the one case that MUST be a closure, so the one case that cannot
        // be read back — it is DRIVEN. A logged-in nobody must be refused, and
        // the closure must have asked about the declared capability rather than
        // deciding for itself.
        $this->heldCaps = []; // logged in, holds nothing

        $this->bootModule($inside);

        $callback = $this->permissionCallbackOf(self::SUBSCRIBERS);

        $this->assertIsCallable($callback);
        $this->assertFalse((bool) $callback(null), 'a subscriber must not reach POST /subscribers.');
        $this->assertContains(
            'manage_options',
            $this->capsAsked,
            'the closure must put the declared capability to current_user_can().',
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheCapabilityWriteAllowsTheUserWhoHoldsIt(bool $inside): void
    {
        $this->heldCaps = ['manage_options'];

        $this->bootModule($inside);

        $callback = $this->permissionCallbackOf(self::SUBSCRIBERS);

        $this->assertTrue((bool) $callback(null), 'an administrator must reach the route the module opened to them.');
        $this->assertSame(['manage_options'], array_values(array_unique($this->capsAsked)));
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testWhatTheModuleDeclaredAboutTheIndexReachesWordPressUntouched(bool $inside): void
    {
        // WHY: FR-4 / D2c — "internal" and "hidden from the index" are separate
        // words. The module hides its namespace from the index by default and
        // shows the one route it publishes; both must arrive as declared, or a
        // site's index posture is decided by a rule nobody wrote.
        $this->bootModule($inside);

        $this->assertSame(
            false,
            $this->registrationsFor(self::HEALTH)[0]['show_in_index'] ?? null,
            'the namespace default reaches every route it covers.',
        );
        $this->assertSame(
            true,
            $this->registrationsFor(self::MANIFEST)[0]['show_in_index'] ?? null,
            'a per-route declaration wins over the namespace default.',
        );
    }

    // =====================================================================
    // Cross-module — two consumers, one namespace (threat row #5)
    // =====================================================================

    public function testASecondModuleCannotPublishTheFirstModulesPendingRoute(): void
    {
        // WHY: threat row #5. Two modules declare in the same request, into the
        // same namespace — the shape a site reaches the moment a plugin extends
        // another plugin's API. A ->public() on a handle that declared nothing
        // must not reach across to whatever is pending elsewhere; the dangerous
        // failure is silent.
        ntdst_rest(self::NS)->get('/health', static fn() => ['ok' => true]);

        ntdst_rest(self::NS)->public(); // a second module, same namespace, nothing of its own

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf(self::HEALTH),
            "another module's public() must never open a route it did not declare.",
        );
        $this->assertSame(
            [],
            $this->anonymousRoutes(),
            'nothing in the namespace is anonymous — no module asked for it.',
        );
    }

    public function testAPublicCallThatMarksNothingIsLoud(): void
    {
        // WHY: threat row #5 again, the other half. public() that marks nothing
        // leaves an author believing a route is anonymous when it is not (or
        // the reverse). "Changes nothing" is only safe when it also says so.
        ntdst_rest(self::NS)->get('/health', static fn() => ['ok' => true]);

        ntdst_rest(self::NS)->public();

        $this->fireRestApiInit();

        $this->assertNotSame(
            [],
            $this->wrongs,
            'a public() with nothing pending must refuse out loud, not silently do nothing.',
        );
    }

    public function testAPublicCallOnAHeldHandleAfterTheHookCannotChangeThePublishedSurface(): void
    {
        // WHY: threat row #5 at the site level. A module keeps what a verb
        // returned and publishes it later — from a callback, from a filter, or
        // simply further down the file than the hook it fires on. By then
        // WordPress holds the route and its permission_callback; nothing the
        // framework does can change it. The author's line says the endpoint is
        // anonymous and the site serves it internal, and the only thing
        // standing between that mismatch and a support ticket is a refusal
        // loud enough to read.
        $held = ntdst_rest(self::NS)->get('/health', static fn() => ['ok' => true]);

        $this->fireRestApiInit();

        $held->public();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf(self::HEALTH),
            'the published route keeps the permission it registered with.',
        );
        $this->assertSame(
            [],
            $this->anonymousRoutes(),
            'the anonymous surface a site can read back did not change, because it cannot.',
        );
        $this->assertCount(
            1,
            $this->registrationsFor(self::HEALTH),
            'and nothing was registered a second time behind WordPress.',
        );
        $this->assertNotSame(
            [],
            $this->wrongs,
            'a public() that can no longer do anything must SAY so — silence here reads as success.',
        );
    }

    // =====================================================================
    // CORS through the feature (FR-6, SC-6, threat row #9)
    // =====================================================================

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheModulesOriginIsAddedToWordPresssOwnListAndNothingElseIs(bool $inside): void
    {
        // WHY: threat row #9. allowed_http_origins is site-wide — admin-ajax
        // reads the same list — so the filter must add the declared origin and
        // NOTHING else, and must not drop or reorder WordPress's own entries.
        $this->bootModule($inside);

        $this->assertSame(
            [...$this->wpDefaultOrigins, self::ORIGIN],
            $this->allowList(),
            'WordPress\'s entries first, in WordPress\'s order, then the one declared origin.',
        );
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testTheDeclaredOriginIsGrantedWithoutCredentials(bool $inside): void
    {
        $this->bootModule($inside);
        $this->wordPressAllowsOnly(self::ORIGIN);
        $this->askedWordPress = [];

        $decision = NTDST_Rest::corsDecisionFor(self::ORIGIN);

        $this->assertIsArray($decision, 'the module declared a policy, so the site decides.');
        $this->assertSame([self::ORIGIN], $this->askedWordPress, 'asked WordPress once, with the exact origin.');
        $this->assertContains('Access-Control-Allow-Origin: ' . self::ORIGIN, $decision['set']);
        $this->assertContains(
            'Access-Control-Allow-Credentials',
            $decision['remove'],
            'credentials are OFF unless the site asked — core\'s own header must be taken back off the wire.',
        );
        $this->assertNotContains('Access-Control-Allow-Credentials: true', $decision['set']);
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testAnOriginWordPressRefusesLosesBothHeaders(bool $inside): void
    {
        // WHY: fail CLOSED. Core's rest_send_cors_headers already echoed the
        // origin back with credentials at priority 10, so abstaining leaves
        // core's reflection standing. Refusing means REMOVING.
        $this->bootModule($inside);
        $this->wordPressAllowsOnly(self::ORIGIN);

        $decision = NTDST_Rest::corsDecisionFor('https://evil.test');

        $this->assertSame([], $decision['set'], 'nothing is granted to an origin WordPress does not allow.');
        $this->assertContains('Access-Control-Allow-Origin', $decision['remove']);
        $this->assertContains('Access-Control-Allow-Credentials', $decision['remove']);
    }

    /**
     * @dataProvider bootOrderProvider
     */
    public function testOriginNullIsRefusedWithoutAskingWordPress(bool $inside): void
    {
        // WHY: a file:// page and a sandboxed iframe both send `Origin: null`.
        // It identifies nobody, so it is never a question worth putting to a
        // filter another plugin can answer 'yes' to.
        $this->bootModule($inside);
        $this->wordPressAllowsOnly('null', self::ORIGIN);
        $this->askedWordPress = [];

        $decision = NTDST_Rest::corsDecisionFor('null');

        $this->assertSame([], $decision['set'], 'Origin: null is not an identity.');
        $this->assertSame([], $this->askedWordPress, 'WordPress must not be asked to bless "null".');
    }

    public function testASecondModuleAskingForTheWildcardChangesNothingAndSaysSo(): void
    {
        // WHY: threat row #9. '*' would hand the whole internet the site's REST
        // surface AND its admin-ajax surface. A second module must not be able
        // to widen what the first declared, and must not fail silently.
        $this->bootModule(false);

        $before = count($this->wrongs);

        ntdst_rest('other-plugin/v1')->cors(['*']);

        $this->assertSame(
            [...$this->wpDefaultOrigins, self::ORIGIN],
            $this->allowListIfMounted(),
            "'*' must add nothing — not itself, not a widening of anyone else's list.",
        );
        $this->assertCount(
            $before + 1,
            $this->wrongs,
            'the refused wildcard reports exactly once: ' . implode(' | ', $this->wrongMessages()),
        );
    }

    // =====================================================================
    // Empty and re-entry edges
    // =====================================================================

    public function testANamespaceThatDeclaresNothingRegistersNothingAndKeepsNoPolicy(): void
    {
        // WHY: the empty state. Asking for a namespace handle is not declaring
        // anything, so nothing may reach WordPress — no route, no allow-list
        // entry, and no site CORS policy that would take core's emitter off the
        // bus on behalf of a module that never asked.
        ntdst_rest('quiet/v1');

        $this->fireRestApiInit();

        $this->assertSame([], $this->routeKeys(), 'a namespace that declared nothing publishes nothing.');
        $this->assertArrayNotHasKey(
            'allowed_http_origins',
            $GLOBALS['_ntdst_test_filters'] ?? [],
            'no cors() call, no filter on WordPress\'s site-wide list.',
        );
        $this->assertNull(
            NTDST_Rest::corsDecisionFor(self::ORIGIN),
            'no declaration, no decision — core does not answer for a site that said nothing.',
        );
        $this->assertSame([], $this->wrongs, 'and it is not an error to hold a handle you never used.');
    }

    public function testFiringRestApiInitTwiceDoesNotRegisterAnythingTwice(): void
    {
        // WHY: re-entry. rest_api_init is reached more than once in ordinary
        // use — the cluster's OWN Observable does it: `wp eval` fires
        // do_action('rest_api_init') and then calls rest_get_server(), which
        // fires it again while it builds the server. Every declaration's flush
        // callback is still mounted, so a second firing hands WordPress the
        // whole namespace a second time. WP's register_route() merges rather
        // than replaces when $override is false, so the endpoint ends up with
        // two handler entries for the same method: the register a site reads
        // back is no longer a faithful list of what it declared, and every
        // audit that counts handlers (README's array_column snippet included)
        // counts each one twice. A declaration is a statement, not an
        // instruction to be replayed — flushing it must be idempotent.
        (new NtdstFeatureBaselineModule(self::NS))->boot(false);

        $this->fireRestApiInit();
        $this->fireRestApiInit();

        foreach ([self::HEALTH, self::SETTINGS, self::MANIFEST, self::SEARCH, self::SUBSCRIBERS] as $route) {
            $this->assertCount(
                1,
                $this->registrationsFor($route),
                "{$route} must reach register_rest_route() exactly once.",
            );
        }

        foreach ([self::PURGE, self::CACHE, self::FLUSH] as $refused) {
            $this->assertCount(0, $this->registrationsFor($refused), "{$refused} stays absent on the second pass.");
        }

        $this->assertSame(
            [self::MANIFEST],
            $this->anonymousRoutes(),
            'the anonymous surface is the same after the second pass as after the first.',
        );
    }
}

/**
 * A consumer module, shaped like the ones a site ships (ntdst-baseline's own
 * modules are this shape): one namespace, declared once, booted either inline
 * or from rest_api_init. It exists so the tests above never touch NTDST_Rest's
 * API in isolation — they read what a site publishes.
 */
final class NtdstFeatureBaselineModule
{
    public const ORIGIN = 'https://app.example.test';

    public function __construct(private string $namespace) {}

    /** Both boot orders a WordPress module really has. */
    public function boot(bool $insideRestApiInit): void
    {
        if ($insideRestApiInit) {
            add_action('rest_api_init', function (): void {
                $this->declareRoutes();
            });

            return;
        }

        $this->declareRoutes();
    }

    private function declareRoutes(): void
    {
        $rest = ntdst_rest($this->namespace);

        // The namespace's own words: keep this API out of the public index,
        // and let one front-end origin talk to it.
        $rest->defaults(['show_in_index' => false]);
        $rest->cors([self::ORIGIN]);

        // Reads.
        $rest->get('/health', static fn() => ['ok' => true]);
        $rest->get('/settings', static fn() => ['settings' => []]);
        $rest->get('/manifest', static fn() => ['name' => 'baseline'], ['show_in_index' => true])->public();
        $rest->get('/search', static fn() => ['results' => []], ['rate_limit' => 60])->public();

        // Writes. Three of these name no capability; the site's author believes
        // all four are live.
        $rest->post('/purge', static fn() => ['purged' => true]);
        $rest->delete('/cache/(?P<key>[a-z0-9-]+)', static fn() => ['deleted' => true]);
        $rest->post('/flush', static fn() => ['flushed' => true])->public();
        $rest->post('/subscribers', static fn() => ['created' => 1], ['permission' => 'manage_options']);
    }
}
