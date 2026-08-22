<?php // tests/Unit/NtdstRestTest.php
// SPLIT RED — Cluster B1 (T03 + T04). Written by the independent test-author
// BEFORE api/Rest.php has any logic, and IMMUTABLE from here: the implementer
// greens it without weakening an assertion. Adding a missing WordPress function
// stub to setUp() is fine; relaxing or deleting an assertion is an escalation,
// not an edit.
//
// Contract source: spec.md FR-3, SC-1, SC-2, decision D6; plan.md threat model
// items 1 and 3; tasks.md Cluster B1.
//
// AMENDED for specs/core-shape FR-4 (T04) by the independent test-author. The
// default permission moved from "refused" to "logged in", so the absence rule
// this file encoded is now VERB-DEPENDENT: an unnamed GET registers with the
// string 'is_user_logged_in', and only POST/PUT/PATCH/DELETE without a named
// capability stay absent from the route table. The two cases that asserted
// absence for every verb were rewritten here; nothing was relaxed — the
// dangerous half (a write verb anyone logged in could reach) is asserted
// exactly as before. The GET-side facts and every public()/pending case live
// in tests/Unit/NtdstRestDefaultsTest.php, which owns FR-4.
//
// WHY THIS FILE ASSERTS ABSENCE AND CALL COUNTS RATHER THAN STATUS CODES:
//
//  (1) WordPress FAILS OPEN on a missing permission_callback. Since 5.5 core
//      fires _doing_it_wrong() and then REGISTERS THE ROUTE ANYWAY, and
//      wp-includes/rest-api.php:890 reads
//          if ( ! empty( $_handler['permission_callback'] ) )
//      so an absent callback means the check is SKIPPED — the route is public.
//      A 403 test would therefore pass against a registered-but-denied route,
//      which is a different and weaker property. The property this framework
//      promises is that the route is NEVER HANDED TO register_rest_route() at
//      all, so it is ABSENT from rest_get_server()->get_routes(). (SC-1)
//
//  (2) WordPress invokes permission_callback TWICE per served request — once on
//      dispatch and once while computing the Allow header. A side-effectful
//      permission callable (an audit write, a counter, a rate-limit decrement)
//      therefore fires twice. The wrapper memoizes per request AND per route,
//      so the callable runs exactly once. A memo keyed globally instead of by
//      the WP_REST_Request object would hand one user's decision to the next
//      request — asserted below as its own failure mode. (SC-2)
//
// The harness has no live WP REST server, so register_rest_route() and
// rest_get_server() are Brain Monkey stubs that build the route table this file
// then reads. That table IS the observable in tasks.md Cluster B1
// ("do_action('rest_api_init'); print_r(array_keys(rest_get_server()->get_routes()))").
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

// WP classes do not exist in this harness. Guarded because DownloadDispatchTest
// defines the same doubles and PHPUnit loads every file in the suite — whichever
// loads first wins, so this file uses only the intersection of that API
// (constructor params + get_param + get_json_params).
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $queryParams = [];

        public function __construct(private array $params = []) {}
        public function get_json_params(): array { return $this->params; }
        public function get_body_params(): array { return []; }
        public function get_param(string $k): mixed { return $this->params[$k] ?? $this->queryParams[$k] ?? null; }
        public function get_file_params(): array { return []; }
        public function set_query_params(array $params): void { $this->queryParams = $params; }
        public function get_query_params(): array { return $this->queryParams; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->msg; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

final class NtdstRestTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The fake WP route table: '/ns/route' => list of handler arg-arrays, exactly WP's shape. */
    private array $routeTable = [];

    /** Callbacks the wrapper hung on a WP hook, so a deferred registration can be flushed. */
    private array $hooked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->routeTable = [];
        $this->hooked = [];

        // WP core's registrar. Recording it is how absence becomes observable:
        // a route the wrapper refuses never reaches this stub, so its key never
        // appears in the table rest_get_server() serves back.
        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $key = '/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/');
                $this->routeTable[$key][] = $args;
                return true;
            },
        );

        Functions\when('rest_get_server')->alias(function () {
            $table = $this->routeTable;
            return new class($table) {
                public function __construct(private array $table) {}
                public function get_routes(): array { return $this->table; }
            };
        });

        // Do NOT define add_action — SchedulerTest patches it and Patchwork
        // throws DefinedTooEarly if a plain definition wins the race. Recording
        // through Brain Monkey lets a deferred (rest_api_init) registration be
        // flushed by serveRestApiInit() without pinning WHEN the wrapper
        // registers: immediate registration satisfies the same assertions.
        Functions\when('add_action')->alias(function ($hook, $cb = null, $priority = 10, $args = 1) {
            $this->hooked[(string) $hook][] = $cb;
            return true;
        });

        // Ambient WP functions a thin wrapper is likely to touch. Stubbed
        // generously so the implementer is never blocked by a harness gap.
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('apply_filters')->alias(fn($hook, $value, ...$rest) => $value);
        Functions\when('did_action')->justReturn(1);
        Functions\when('doing_it_wrong_run')->justReturn(null);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Harness helpers
    // =====================================================================

    /** Fire rest_api_init, so a wrapper that queues its routes flushes them. */
    private function serveRestApiInit(): void
    {
        foreach ($this->hooked['rest_api_init'] ?? [] as $cb) {
            $cb(rest_get_server());
        }
    }

    /** @return array<string, mixed>|null the first registered handler for a route key */
    private function registeredArgs(string $routeKey): ?array
    {
        $this->serveRestApiInit();
        return rest_get_server()->get_routes()[$routeKey][0] ?? null;
    }

    private function routeKeys(): array
    {
        $this->serveRestApiInit();
        return array_keys(rest_get_server()->get_routes());
    }

    /** A permission callable that always allows and counts its invocations. */
    private function counter(bool $decision = true): object
    {
        return new class($decision) {
            public int $calls = 0;
            public function __construct(private bool $decision) {}
            public function __invoke($request = null): bool
            {
                $this->calls++;
                return $this->decision;
            }
        };
    }

    // =====================================================================
    // T03 — permission is REQUIRED; a route without one is NEVER registered
    // =====================================================================

    public function testFacadeReturnsOneChainableWrapperPerNamespace(): void
    {
        // WHY: the memo in T04 is per wrapper, and every route in a namespace
        // must share one wrapper — two wrappers for one namespace would mean
        // two registration paths for the same surface. Chainability is the
        // declared shape in FR-3: ntdst_rest('ns')->get(...)->post(...).
        $this->assertTrue(function_exists('ntdst_rest'), 'ntdst_rest() is the v3 resource-routing facade (FR-1).');

        $wrapper = ntdst_rest('x/v1');
        $this->assertSame($wrapper, ntdst_rest('x/v1'), 'one cached wrapper per namespace.');
        $this->assertNotSame($wrapper, ntdst_rest('y/v1'), 'a different namespace gets its own wrapper.');

        $chained = $wrapper->get('/thing', fn() => [], ['permission' => fn() => true]);
        $this->assertSame($wrapper, $chained, 'verb methods return $this so declarations chain.');
    }

    public function testRouteWithCallablePermissionIsRegistered(): void
    {
        // WHY: the positive control. Without it, every absence assertion below
        // would pass vacuously against a wrapper that registers nothing at all.
        ntdst_rest('x/v1')->get('/thing', fn() => ['ok' => true], ['permission' => fn() => true]);

        $this->assertContains('/x/v1/thing', $this->routeKeys(), 'a route WITH a callable permission is registered.');

        $args = $this->registeredArgs('/x/v1/thing');
        $this->assertIsArray($args);
        $this->assertArrayHasKey('permission_callback', $args, 'the wrapper must hand WP a permission_callback.');
        $this->assertIsCallable($args['permission_callback'], 'an empty permission_callback is the WP fail-open (rest-api.php:890).');
        $this->assertNotEmpty(
            $args['permission_callback'],
            'rest-api.php:890 skips the check when permission_callback is empty — the route would be public.',
        );
        $this->assertArrayHasKey('callback', $args, 'the declared handler is the route callback.');
        $this->assertSame(['GET'], $this->methodsOf($args), 'get() declares an HTTP GET resource route (FR-2).');
    }

    /** @return list<string> the registered methods, normalized from WP's string-or-array shape */
    private function methodsOf(array $args): array
    {
        $methods = $args['methods'] ?? [];
        $methods = is_array($methods) ? $methods : explode(',', (string) $methods);
        return array_values(array_map(static fn($m) => strtoupper(trim((string) $m)), $methods));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function permissionlessOptionsProvider(): array
    {
        return [
            'no options at all' => [[]],
            'options without a permission key' => [['args' => ['id' => ['required' => true]]]],
            'a mistyped permission key' => [['permissions' => 'is_user_logged_in']],
        ];
    }

    /**
     * @dataProvider permissionlessOptionsProvider
     */
    public function testWriteRouteWithoutPermissionIsAbsentFromTheRouteTable(array $options): void
    {
        // WHY: threat-model item 1 — WP registers a permission-less route and
        // then skips the check (rest-api.php:890), leaving it world-readable.
        // The framework's promise is absence, not denial: assert the key is not
        // in the route table. A control route is declared in the same run so
        // this cannot pass vacuously. (SC-1)
        //
        // FR-4 narrowed this to the WRITE verbs. An unnamed GET is now internal
        // ('is_user_logged_in') rather than refused, but an unnamed write is
        // still never handed to register_rest_route() — on a site with open
        // registration "logged in" is "anyone". The verb changed; the property
        // did not.
        $rest = ntdst_rest('x/v1');
        $rest->post('/guarded', fn() => [], ['permission' => fn() => true]);
        $rest->post('/open', fn() => ['secret' => 'leaked'], $options);

        $keys = $this->routeKeys();

        $this->assertContains('/x/v1/guarded', $keys, 'control: a properly declared route must still register.');
        $this->assertNotContains(
            '/x/v1/open',
            $keys,
            'a route declared without a callable permission must NEVER be handed to register_rest_route().',
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonCallablePermissionProvider(): array
    {
        return [
            'null' => [null],
            'the empty string' => [''],
            'boolean true' => [true],
            'an array that is not a callable' => [['NoSuchClass', 'noSuchMethod']],
            'an options-shaped array' => [['capability' => 'manage_options']],
            'an integer' => [1],
        ];
    }

    /**
     * @dataProvider nonCallablePermissionProvider
     */
    public function testNonCallablePermissionIsRefused(mixed $permission): void
    {
        // WHY: the key being PRESENT is not the property — being CALLABLE is.
        // 'permission' => true is the most dangerous shape, because it reads
        // like "allowed" and WP would happily accept a truthy permission_callback
        // it can call... except it cannot call a bool, and any wrapper that
        // passed it through unchecked would recreate the fail-open.
        $rest = ntdst_rest('x/v1');
        $rest->post('/guarded', fn() => [], ['permission' => fn() => true]);
        $rest->post('/open', fn() => ['secret' => 'leaked'], ['permission' => $permission]);

        $keys = $this->routeKeys();

        $this->assertContains('/x/v1/guarded', $keys, 'control: a properly declared route must still register.');
        $this->assertNotContains('/x/v1/open', $keys, 'a non-callable permission is no permission — refuse the route.');
    }

    /**
     * A string permission is a CAPABILITY, and an unknown one denies everyone.
     *
     * This row used to live in nonCallablePermissionProvider() and assert that
     * `'ntdst_no_such_permission_function'` refuses the route. It cannot any
     * more: this class now accepts `'public'`, `'logged_in'` or a capability
     * slug, and a capability slug is byte-identical to a typo'd function name.
     * There is nothing to tell them apart with.
     *
     * What CAN be asserted is the property that makes the ambiguity safe — an
     * unrecognised string becomes `current_user_can($string)`, which is false
     * for everyone. The route registers and then denies, rather than
     * registering and admitting.
     */
    public function testAnUnrecognisedStringPermissionRegistersButDeniesEveryone(): void
    {
        ntdst_rest('perm/v1')->post('/typo', fn() => [], [
            'permission' => 'ntdst_no_such_permission_function',
        ]);

        $this->assertContains('/perm/v1/typo', $this->routeKeys(), 'A string permission must register.');

        // The harness stubs current_user_can() to true for every other test
        // here; this one needs WP's real answer for an unknown capability,
        // which is false for everyone.
        Functions\when('current_user_can')->alias(static fn($cap) => $cap === 'manage_options');

        $routes = rest_get_server()->get_routes();
        $callback = $routes['/perm/v1/typo'][0]['permission_callback'] ?? null;

        $this->assertIsCallable($callback, 'A string permission must still produce a callback.');
        $this->assertFalse(
            (bool) $callback(null),
            'An unrecognised capability admitted the caller — a typo must fail CLOSED.',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verbProvider(): array
    {
        return [
            'get' => ['get', 'GET'],
            'post' => ['post', 'POST'],
            'put' => ['put', 'PUT'],
            'patch' => ['patch', 'PATCH'],
            'delete' => ['delete', 'DELETE'],
        ];
    }

    /**
     * @dataProvider verbProvider
     */
    public function testEveryVerbMapsToItsMethodAndEnforcesTheSameFailClosedRule(string $verb, string $method): void
    {
        // WHY: fail-closed that only holds on get() is a hole on the write
        // verbs, which are the ones that mutate. The method mapping is FR-2
        // (verbs belong to ntdst_rest alone, and get() now always means an HTTP
        // GET resource route).
        //
        // FR-4 split the unnamed case by verb, and the split is the mitigation:
        // an unnamed GET is INTERNAL (is_user_logged_in — WordPress's own
        // wp_ajax_ posture), an unnamed WRITE is ABSENT. Both halves are
        // asserted here, so a wrapper that publishes an unnamed write, or that
        // silently drops every unnamed read, fails.
        $rest = ntdst_rest('x/v1');
        $rest->{$verb}('/guarded', fn() => [], ['permission' => fn() => true]);
        $rest->{$verb}('/open', fn() => [], []);

        $keys = $this->routeKeys();

        $this->assertContains('/x/v1/guarded', $keys, "{$verb}() must register a route with a callable permission.");
        $this->assertSame([$method], $this->methodsOf($this->registeredArgs('/x/v1/guarded') ?? []));

        if ($method === 'GET') {
            $this->assertContains('/x/v1/open', $keys, 'an unnamed GET is internal, not refused (FR-4).');
            $this->assertSame(
                'is_user_logged_in',
                $this->registeredArgs('/x/v1/open')['permission_callback'] ?? null,
                "an unnamed GET registers with the STRING 'is_user_logged_in' (FR-4, SC-2).",
            );

            return;
        }

        $this->assertNotContains(
            '/x/v1/open',
            $keys,
            "{$verb}() names no capability — it must never be handed to register_rest_route().",
        );
    }

    // =====================================================================
    // T04 — the permission callable runs exactly once per served request
    // =====================================================================

    public function testPermissionCallableIsInvokedExactlyOncePerServedRequest(): void
    {
        // WHY: WP core invokes permission_callback twice per served request —
        // once on dispatch, once while computing the Allow header in
        // rest_send_allow_header(). A side-effectful permission callable (audit
        // write, counter, quota decrement) would therefore fire twice. The
        // wrapper memoizes per request, so the consumer's callable sees exactly
        // one invocation. (SC-2)
        $permission = $this->counter(true);
        ntdst_rest('x/v1')->get('/thing', fn() => [], ['permission' => $permission]);

        $args = $this->registeredArgs('/x/v1/thing');
        $this->assertIsArray($args, 'control: the guarded route must be registered before its callback can be driven.');
        $callback = $args['permission_callback'];
        $this->assertIsCallable($callback);

        $request = new WP_REST_Request(['id' => 7]);

        // Exactly what WP does across one served request: same request object, twice.
        $first = $callback($request);
        $second = $callback($request);

        $this->assertSame(1, $permission->calls, 'WP calls permission_callback twice; the consumer callable must run once.');
        $this->assertTrue((bool) $first, 'the memoized decision is the real decision, not a swallowed one.');
        $this->assertSame($first, $second, 'the second WP invocation returns the memoized decision unchanged.');
    }

    public function testMemoIsPerRequestSoTwoUsersEachGetTheirOwnDecision(): void
    {
        // WHY: a memo keyed on anything global (a static bool, the route, the
        // wrapper) leaks the first caller's ALLOW to the next request. Under
        // Application-Password auth on the Partner API that is one company
        // reading another's data with no credential of its own. The memo must
        // key off the WP_REST_Request object. (threat-model item 3)
        $seen = [];
        $currentUser = new stdClass();
        $currentUser->login = 'alice';

        $permission = function ($request) use (&$seen, $currentUser) {
            $seen[] = $currentUser->login;
            return $currentUser->login === 'alice'; // only alice is authorized
        };

        ntdst_rest('x/v1')->get('/thing', fn() => [], ['permission' => $permission]);

        $args = $this->registeredArgs('/x/v1/thing');
        $this->assertIsArray($args, 'control: the guarded route must be registered before its callback can be driven.');
        $callback = $args['permission_callback'];

        // Request 1 — alice, served (WP invokes the callback twice).
        $alice = new WP_REST_Request([]);
        $this->assertTrue((bool) $callback($alice), 'alice is authorized.');
        $callback($alice);

        // Request 2 — a NEW request, a different authenticated user.
        $currentUser->login = 'bob';
        $bob = new WP_REST_Request([]);
        $decision = $callback($bob);

        $this->assertFalse((bool) $decision, 'bob must be denied — no memo may carry alice\'s ALLOW into another request.');
        $this->assertSame(['alice', 'bob'], $seen, 'each request evaluates the permission exactly once, on its own.');
    }

    public function testMemoIsPerRouteSoOneRoutesAllowNeverServesAnother(): void
    {
        // WHY: memoizing on the request alone (and not also on the route) makes
        // the FIRST authorization decision of a request answer for every other
        // route in the namespace. WP computes the Allow header by walking the
        // sibling handlers of the matched route, so a request really does reach
        // more than one permission_callback — this is not a theoretical path.
        $allow = $this->counter(true);
        $deny = $this->counter(false);

        $rest = ntdst_rest('x/v1');
        $rest->get('/public-ish', fn() => [], ['permission' => $allow]);
        $rest->get('/restricted', fn() => ['secret' => 'leaked'], ['permission' => $deny]);

        $allowArgs = $this->registeredArgs('/x/v1/public-ish');
        $denyArgs = $this->registeredArgs('/x/v1/restricted');
        $this->assertIsArray($allowArgs, 'control: both routes must be registered.');
        $this->assertIsArray($denyArgs, 'control: both routes must be registered.');

        $request = new WP_REST_Request([]);

        $this->assertTrue((bool) $allowArgs['permission_callback']($request), 'the permissive route allows.');
        $this->assertFalse(
            (bool) $denyArgs['permission_callback']($request),
            'the restricted route must reach its OWN permission — a shared memo would return the previous ALLOW.',
        );
        $this->assertSame(1, $allow->calls, 'each route memoizes independently, once per request.');
        $this->assertSame(1, $deny->calls, 'the restricted route evaluated its own permission exactly once.');
    }
}
