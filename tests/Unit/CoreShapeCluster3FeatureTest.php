<?php // tests/Unit/CoreShapeCluster3FeatureTest.php
declare(strict_types=1);
// FEATURE TESTS — core-shape Cluster 3 ("Actions out"), written by the
// independent test-author AFTER T07 and T08 landed, from the promise rather
// than from the diff.
//
// Contract source: `.superpowers/sdd/plan/core-shape/cluster-3-behaviour.md`
//   Behaviour: "the relation picker works through a REST route and wp.apiFetch
//   with the same per-type capability gate, and the package has no command
//   dispatcher, no nonce endpoint and no JS client of its own."
// plus spec.md FR-7 / FR-8 + SC-3 / SC-4, plan.md threat model #6 and #7,
// acceptance flows AF-5 / AF-6 / AF-7 / AF-9, and ARCHITECTURE-INVARIANTS.md
// INV-2 / INV-4 / INV-7.
//
// WHAT THIS FILE IS FOR, AND WHAT IT IS NOT. The task tests
// (`RelationSearchRouteTest`, `RelationFieldSearchTest`) pin the DECLARATION —
// one route, its args, its rate limit, its projection, four denial shapes. This
// file asks the four questions a task test cannot ask, because each one is a
// property of the FEATURE and not of a task:
//
//   1. ONE REQUEST END TO END. The gate and the handler are two callbacks, and
//      a route is only correct when the SAME request that the permission
//      admitted is the request the handler serves. Asserted here as one pass:
//      permission → callback → the WP_Query WordPress was handed → the JSON the
//      picker reads. A gate that admits `['release']` while the handler queries
//      something else passes both task tests and is a hole.
//   2. THE DENIAL MATRIX AS A MATRIX. Five refused actors through the ONE
//      wrapped permission the route ships, each one asserted to leave the
//      database untouched (no WP_Query built). Two of them are new: the
//      anonymous caller (AF-5) and a 300-entry post_type list, which is where a
//      gate that checks capabilities before membership turns a refusal into 300
//      capability lookups per request.
//   3. THE BUDGET AND THE ORDER TOGETHER (INV-7). Sixty pass, the sixty-first
//      is 429 with `retry_after` — AFTER thirty refusals from the same caller
//      have cost that caller nothing. "A refused caller never makes the site
//      write" is the invariant, and it is only observable when refusal and
//      spend are exercised in the same window.
//   4. THE ABSENCES AS ONE SURFACE (FR-7, SC-3, INV-2, INV-4). Not "the picker
//      no longer names the dispatcher" (a task test) but "the package has no
//      second door and mints no token": one caller of `register_rest_route()`
//      in the whole package, one `wp_verify_nonce()` in the whole package and
//      it is a classic-editor form's, no dispatcher file, no client file, no
//      loader line, and no nonce value anywhere in core's own JavaScript.
//
// HOW IT DRIVES THINGS. Through the real `NTDST_RelationField` constructor and
// the real `ntdst_rest()` wrapper, reading back what WordPress's registrar was
// handed — the same route table SC-4's three HTTP probes hit at shake-out.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/ServiceInterface.php';
require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';
require_once __DIR__ . '/../../api/Rest.php';

// The doubles below are shared, process-wide classes: whichever test file loads
// first defines them for every later one. Each is byte-compatible with the copy
// in RelationSearchRouteTest / RelationFieldSearchTest — same globals, same
// method set — so load ORDER can never decide whether a file passes.
if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<int, object> */
        public array $posts = [];

        public int $found_posts = 0;

        public function __construct(array $args = [])
        {
            $GLOBALS['_ntdst_test_wp_query_args'][] = $args;

            $this->posts = $GLOBALS['_ntdst_test_wp_query_posts'] ?? [];
            $this->found_posts = count($this->posts);
        }
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

if (!class_exists('WP_Post_Type')) {
    class WP_Post_Type
    {
        public object $cap;

        public function __construct(public string $name = '', string $editOthers = 'edit_others_posts')
        {
            $this->cap = (object) ['edit_others_posts' => $editOthers];
        }
    }
}

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

/**
 * The picker's request as WordPress hands it over.
 *
 * It answers `get_method()` because `ntdst_rest()`'s guard only charges a
 * route's budget for the handler whose verb matched — a double without one
 * would make the rate-limit case pass by never spending anything.
 */
final class Cluster3PickerRequest extends WP_REST_Request
{
    public function __construct(private array $own = [], private string $method = 'GET')
    {
        parent::__construct($own);
    }

    public function get_param(string $k): mixed
    {
        return $this->own[$k] ?? null;
    }

    public function get_method(): string
    {
        return $this->method;
    }
}

final class CoreShapeCluster3FeatureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** Directories the package ships PHP in — the scope INV-2, INV-4 and INV-7 are checked over. */
    private const SHIPPED_DIRS = ['api', 'core', 'admin', 'services', 'support'];

    /** The fake WP route table: '/ns/route' => the handler arg-arrays, exactly WP's shape. */
    private array $routes = [];

    /** Capabilities the current caller holds, by name. */
    private array $caps = [];

    /** Every capability name the code put to current_user_can(), in order. */
    private array $capChecks = [];

    /** The transient store the rate limiter counts through. */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // admin/RelationField.php mounts add_action() at FILE level and neither
        // the bootstrap nor the autoloader defines add_action — Brain Monkey
        // does, from the line above. So the shipped file is required here.
        require_once __DIR__ . '/../../admin/RelationField.php';

        $this->routes     = [];
        $this->caps       = [];
        $this->capChecks  = [];
        $this->transients = [];

        $GLOBALS['_ntdst_test_wp_query_args']  = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];

        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $this->routes['/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/')][] = $args;
                return true;
            },
        );

        // did_action 1 / doing_action false is "after rest_api_init" — ntdst_rest()
        // registers immediately, so the table is readable as soon as the service exists.
        Functions\when('did_action')->justReturn(1);
        Functions\when('doing_action')->justReturn(false);
        Functions\when('add_action')->justReturn(true);
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('apply_filters')->alias(static fn($hook, $value, ...$rest) => $value);
        Functions\when('do_action')->justReturn(null);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('register_post_type')->justReturn(new stdClass());
        Functions\when('register_post_meta')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v, $ttl = 0) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('current_user_can')->alias(function (string $cap) {
            $this->capChecks[] = $cap;
            return in_array($cap, $this->caps, true);
        });
        Functions\when('get_post_type_object')->alias(
            static fn(string $type) => new WP_Post_Type($type, "edit_others_{$type}s"),
        );

        $this->forgetRegisteredModels();
    }

    protected function tearDown(): void
    {
        $this->forgetRegisteredModels();
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // 1. The picker as an editor — one request, gate to JSON (AF-7)
    // =====================================================================

    /**
     * An Administrator's picker search on a declared relation target is
     * admitted, and the SAME request produces one capped query and the two-key
     * rows the JS renders.
     *
     * This is AF-7 without the browser. The point of driving both callbacks
     * with one request object is that the list the gate approved has to be the
     * list WordPress is asked for: a permission that reads `post_type` and a
     * handler that reads anything else would let an admitted request query a
     * type nobody gated, and each callback on its own looks correct.
     */
    public function testAnAdministratorsPickerSearchIsAdmittedAndAnsweredFromOneCappedQuery(): void
    {
        $this->declareRelationsTo(['release']);
        $this->caps = ['edit_others_releases'];

        // Twenty rows: the boundary the route asks WordPress for. A page of
        // exactly the cap must come back whole, and it must still be shaped.
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];
        for ($i = 1; $i <= 20; $i++) {
            $GLOBALS['_ntdst_test_wp_query_posts'][] = (object) [
                'ID'           => (string) (100 + $i),
                'post_title'   => "Release {$i}",
                'post_content' => 'private production notes',
                'post_status'  => $i % 2 === 0 ? 'draft' : 'publish',
            ];
        }

        $request = new Cluster3PickerRequest(['search' => 'rel', 'post_type' => ['release']]);
        $route   = $this->route();

        $this->assertTrue(
            ($route['permission_callback'])($request),
            'AF-7: an Administrator holding the target type\'s edit_others_* capability is admitted.',
        );

        $result = ($route['callback'])($request);

        $this->assertSame(
            ['results'],
            array_keys($result),
            'The picker answers ONE key. A `success`/`data` envelope is the dispatcher\'s shape and it is gone (FR-7).',
        );
        $this->assertCount(20, $result['results'], 'A page of exactly the cap comes back whole.');
        $this->assertLessThanOrEqual(20, count($result['results']), 'The picker never serves more than the cap.');

        foreach ($result['results'] as $index => $row) {
            $this->assertSame(
                ['id', 'title'],
                array_keys($row),
                "Row {$index} carries an id and a title and nothing else — no post_content, no status, "
                    . 'no permalink: an admin search row is not a post read.',
            );
            $this->assertIsInt($row['id'], 'The JS reads `id` as an int; WordPress hands post IDs over as strings.');
            $this->assertIsString($row['title']);
        }

        $this->assertSame(
            [['id' => 101, 'title' => 'Release 1'], ['id' => 102, 'title' => 'Release 2']],
            array_slice($result['results'], 0, 2),
            'The projection is the route\'s own, in WordPress\'s order.',
        );

        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One picker search is one query.');
        $query = $GLOBALS['_ntdst_test_wp_query_args'][0];

        $this->assertSame(
            ['release'],
            $query['post_type'] ?? null,
            'The type list the gate approved is the type list WordPress is asked for.',
        );
        $this->assertSame('rel', $query['s'] ?? null, 'The search term reaches WP_Query from the route\'s own argument.');
        $this->assertSame(20, $query['posts_per_page'] ?? null, 'The cap is asked of WordPress, not sliced after the fact.');
    }

    // =====================================================================
    // 2. The picker as an attacker — the denial matrix (threat model #6, AF-5/AF-6)
    // =====================================================================

    /**
     * Five refused actors, one route, one wrapped permission — and in every
     * case the database is never asked.
     *
     * Each row is a different way the gate could fail open, and the refusal is
     * asserted on the callback WordPress would really call (the wrapper's, not
     * `mayPickFromAll()` directly), because that is the only gate a request
     * passes through. Asserting "no WP_Query was built" beside the refusal is
     * what makes it a DENIAL rather than an empty answer: a handler that runs
     * and then hides its rows has already read rows the caller may not see.
     *
     * @dataProvider deniedActorProvider
     *
     * @param list<string>        $declaredTargets types a relation field points at
     * @param list<string>        $caps            what the caller holds
     * @param array<string,mixed> $params          the request as it arrives
     */
    public function testTheRouteRefusesEveryActorOutsideTheGateWithoutTouchingTheDatabase(
        array $declaredTargets,
        array $caps,
        array $params,
        string $why,
    ): void {
        $this->declareRelationsTo($declaredTargets);
        $this->caps = $caps;

        $result = ($this->route()['permission_callback'])(new Cluster3PickerRequest($params));

        $this->assertNotTrue($result, $why);
        $this->assertTrue(
            $result === false || $result instanceof WP_Error,
            $why . ' — a refusal is false or a WP_Error, never a truthy value WordPress reads as a pass.',
        );
        $this->assertSame(
            [],
            $GLOBALS['_ntdst_test_wp_query_args'],
            'A refused caller never makes the site read: the gate is on the route, not inside the handler.',
        );
    }

    /** @return array<string, array{0: list<string>, 1: list<string>, 2: array<string, mixed>, 3: string}> */
    public static function deniedActorProvider(): array
    {
        return [
            // AF-5. Over HTTP WordPress answers this one 401 before the callback
            // runs; here the callback itself is asked, because a permission that
            // says yes to a capability-less caller is a route that only WordPress's
            // default posture was protecting.
            'anonymous — holds no capability at all' => [
                ['release'],
                [],
                ['search' => 'a', 'post_type' => ['release']],
                'AF-5: an anonymous caller cannot enumerate a relation target.',
            ],
            // AF-6. edit_posts is "my own posts" and Contributor holds it; this
            // route returns everyone's, including drafts.
            'an Author — holds edit_posts, not edit_others_posts' => [
                ['release'],
                ['edit_posts', 'read'],
                ['search' => 'a', 'post_type' => ['release']],
                'AF-6: edit_others_posts is the gate; edit_posts is not a weaker spelling of it.',
            ],
            // EVERY, not ANY. The requested list mixes one type the caller may
            // edit with one nobody declared, so a gate that admits on the first
            // pass hands over the type it refused in the same response.
            'an Editor asking for a declared target plus an undeclared one' => [
                ['release'],
                ['edit_others_releases', 'edit_others_secret_types'],
                ['search' => 'a', 'post_type' => ['release', 'secret_type']],
                'One undeclared type in the list refuses the whole request — the picker is not a general query surface.',
            ],
            'post_type absent from the request' => [
                ['release'],
                ['edit_others_releases'],
                ['search' => 'a'],
                'An absent list is a refusal, not a vacuous foreach truth that queries every type.',
            ],
            'post_type present but empty' => [
                ['release'],
                ['edit_others_releases'],
                ['search' => 'a', 'post_type' => []],
                'An empty list is a refusal — WP_Query with no post_type searches `post`, which is the fail-open direction.',
            ],
        ];
    }

    /**
     * A 300-entry `post_type` list whose first entry is undeclared is refused
     * before a single capability is looked up.
     *
     * The membership test is cheap and local; `current_user_can()` is neither —
     * on a real site it runs the `user_has_cap` filter chain per call, which
     * every membership plugin hooks. A gate that checks capabilities first, or
     * that keeps checking after the first failure, turns one unauthenticated-ish
     * request into hundreds of filter passes, and the happy path never shows it.
     */
    public function testAThreeHundredEntryTypeListIsRefusedBeforeAnyCapabilityIsLookedUp(): void
    {
        $this->declareRelationsTo(['release']);
        $this->caps = ['edit_others_releases'];

        $junk = array_map(static fn(int $i): string => "junk_type_{$i}", range(1, 299));
        array_unshift($junk, 'not_a_declared_target');

        $this->assertCount(300, $junk, 'control: the burst is three hundred entries long.');

        $result = ($this->route()['permission_callback'])(
            new Cluster3PickerRequest(['search' => 'a', 'post_type' => $junk]),
        );

        $this->assertNotTrue($result, 'A list of undeclared types is refused.');
        $this->assertSame([], $GLOBALS['_ntdst_test_wp_query_args'], 'Nothing was queried.');
        $this->assertSame(
            [],
            $this->capChecks,
            'The first undeclared type ends the gate: no capability is looked up at all, so a 300-entry '
                . 'list cannot buy 300 user_has_cap filter passes per request.',
        );
    }

    // =====================================================================
    // 3. The budget, and its order (INV-7, AF-9)
    // =====================================================================

    /**
     * Thirty refusals cost the caller nothing; sixty searches then pass and the
     * sixty-first answers 429 with `retry_after`.
     *
     * AF-9 and INV-7 in one window, because neither is observable alone. If the
     * limiter were charged before the permission, the refused requests would
     * have spent half the budget and the thirty-first legitimate search would
     * be throttled — which is both a denial of service against the editor and,
     * in the other direction, a caller who can make the site WRITE a transient
     * without passing a gate.
     */
    public function testRefusedRequestsSpendNoBudgetAndTheSixtyFirstAdmittedSearchIsThrottled(): void
    {
        $this->declareRelationsTo(['release']);
        $this->caps = ['edit_others_releases'];

        $permission = $this->route()['permission_callback'];

        // Same caller, same bucket — refused thirty times.
        for ($i = 0; $i < 30; $i++) {
            $refused = $permission(new Cluster3PickerRequest(['search' => 'a', 'post_type' => ['not_declared']]));
            $this->assertNotTrue($refused, 'control: the undeclared type is refused every time.');
        }

        $admitted = [];
        for ($i = 0; $i < 61; $i++) {
            // Distinct request objects: the guard memoizes per request, so a
            // burst is sixty-one requests and not one repeated.
            $admitted[] = $permission(new Cluster3PickerRequest(['search' => 'a', 'post_type' => ['release']]));
        }

        $this->assertTrue(
            $admitted[0],
            'INV-7: a refused caller never spends quota — thirty denials left the whole budget intact.',
        );
        $this->assertTrue($admitted[59], 'The sixtieth admitted search of the window is still within the budget.');

        $this->assertInstanceOf(WP_Error::class, $admitted[60], 'AF-9: the sixty-first is refused.');
        $data = $admitted[60]->get_error_data();
        $this->assertSame(429, $data['status'] ?? null, 'Throttling answers 429, not 403.');
        $this->assertSame(60, $data['retry_after'] ?? null, 'A client cannot back off without being told how long.');
    }

    // =====================================================================
    // 4. The package surface — no dispatcher, no nonce endpoint, no client (FR-7, SC-3)
    // =====================================================================

    /**
     * The command dispatcher, the nonce endpoint and the package's own JS
     * client are gone from the shipped package — file, symbol, loader line and
     * route alike.
     *
     * cluster-3-behaviour.md's third observable (`ls` prints two "No such file"
     * lines) plus the half of SC-3 that a grep cannot answer: booting the
     * picker must register the ONE route and no sibling. A dispatcher that
     * survived as a require line would boot; a nonce endpoint that survived
     * would register.
     */
    public function testThePackageShipsNoDispatcherNoNonceEndpointAndNoClientOfItsOwn(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileDoesNotExist($root . '/api/Actions.php', 'FR-7: the command dispatcher is removed, not deprecated.');
        $this->assertFileDoesNotExist($root . '/assets/js/ntdst-api.js', 'FR-7: the package ships no REST client of its own.');

        $this->assertFalse(class_exists('NTDST_Actions'), 'No alias and no forwarder was left behind (FR-7).');

        $loader = (string) file_get_contents($root . '/ntdst-core.php');
        $this->assertSame(
            0,
            preg_match_all('/require_once[^;]*Actions\.php/', $loader),
            'The explicit require_once list no longer loads the dispatcher.',
        );
        $this->assertSame(
            0,
            preg_match_all('/\bntdst_actions\b|\bntdst_enqueue_api_client\b/', $this->uncommented($loader)),
            'Boot neither constructs the dispatcher nor enqueues a client.',
        );

        // Booting the picker registers ONE route, and the two the dispatcher owned are not in the table.
        $this->service();

        $this->assertSame(
            ['/ntdst/v1/relation/search'],
            array_keys($this->routes),
            'The picker registers exactly one route.',
        );
        foreach (array_keys($this->routes) as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '#/(action|get_nonce)$#',
                $path,
                'No command endpoint and no nonce endpoint: INV-4 says core mints nothing.',
            );
        }
    }

    /**
     * Core's own JavaScript transports through `wp.apiFetch` and never handles
     * a nonce value.
     *
     * Threat model #7: the REST nonce belongs to `wp-api-fetch`'s middleware,
     * which mints it, sends it as `X-WP-Nonce` and refreshes it against
     * `admin-ajax.php?action=rest-nonce` when a tab has been open for thirteen
     * hours (AF-8). A nonce literal in this file would mean the picker had
     * taken that job back — a token core prints once at page load and cannot
     * refresh, which is exactly the failure the deleted client had.
     *
     * Read as text with comments stripped, because an absence has no behaviour
     * to drive and the file's own prose EXPLAINS the nonce it must not carry.
     */
    public function testCoreJsUsesWpApiFetchAndCarriesNoNonceOfItsOwn(): void
    {
        $js = $this->uncommented($this->source('assets/js/metabox-fields.js'));

        $this->assertSame(1, preg_match('/wp\.apiFetch\s*\(/', $js), 'The picker transports through WordPress\'s own client.');
        $this->assertSame(0, preg_match_all('/nonce/i', $js), 'Core\'s JS never reads, prints or sends a nonce value.');
        $this->assertSame(0, preg_match_all('/ntdstAPI/', $js), 'The package\'s own JS client is gone with its global.');
        $this->assertSame(
            0,
            preg_match_all('/(?<![.\w])fetch\s*\(/', $js),
            'A raw fetch() to /wp-json/ would bypass the nonce middleware entirely (INV-4).',
        );
    }

    // =====================================================================
    // 5. INV-2 and INV-4 as properties of the package, not of a file
    // =====================================================================

    /**
     * INV-2 — `NTDST_Rest::registerOne()` is the only caller of
     * `register_rest_route()` in the package, and there is no second door.
     *
     * The invariant's own mechanical check, run as an assertion instead of by
     * hand: comment lines are dropped for the reason the document gives (a
     * docblock explaining what replaced the v2 dispatcher is not a door), and
     * the answer is asserted as an EXACT set so a new registrar shows up as a
     * named file rather than as a count nobody reads.
     */
    public function testRegisterRestRouteHasExactlyOneCallerAndThereIsNoSecondDoor(): void
    {
        $this->assertSame(
            ['api/Rest.php'],
            $this->filesMatching('/register_rest_route\s*\(/', self::SHIPPED_DIRS),
            'INV-2: one HTTP surface. Every route registers through ntdst_rest(), which wraps the permission, '
                . 'the budget and the arguments — a direct registration is a route nothing gates.',
        );

        foreach (['/wp_ajax_/' => 'an admin-ajax handler', '#ntdst/api_data#' => 'a dispatch filter', '/\bntdst_actions\b/' => 'the command dispatcher'] as $pattern => $what) {
            $this->assertSame(
                [],
                $this->filesMatching($pattern, self::SHIPPED_DIRS),
                "INV-2: {$what} is a second door into the package, and there is none.",
            );
        }
    }

    /**
     * INV-4 — core mints no CSRF token. The whole package holds exactly one
     * `wp_verify_nonce()`, and it is the classic-editor metabox form's.
     *
     * The exact set is the assertion. `admin/MetaboxGenerator.php`'s pair
     * (`wp_nonce_field()` on render, `wp_verify_nonce()` on save) IS WordPress's
     * own CSRF gate for an admin form POST and the document names it as the one
     * hit that is not a violation; anything else — a minted `wp_rest` nonce, a
     * `check_ajax_referer()`, an Origin or Referer check standing in for the
     * nonce on core's HTTP surface — is the invariant breaking.
     */
    public function testCoreMintsNoNonceAndTheOneVerifyIsTheClassicEditorForm(): void
    {
        $this->assertSame(
            ['admin/MetaboxGenerator.php'],
            $this->filesMatching('/\bwp_create_nonce\s*\(|\bwp_verify_nonce\s*\(/', [...self::SHIPPED_DIRS, 'assets']),
            'INV-4: the only nonce the package verifies is the metabox form\'s. A nonce on core\'s REST '
                . 'surface would be core doing WordPress\'s job with a token it cannot refresh.',
        );

        $metabox = $this->uncommented($this->source('admin/MetaboxGenerator.php'));
        $this->assertSame(
            0,
            preg_match_all('/\bwp_create_nonce\s*\(/', $metabox),
            'Even there core mints nothing by hand: wp_nonce_field() is WordPress\'s printer.',
        );

        foreach (['/\bcheck_ajax_referer\s*\(/' => 'check_ajax_referer()', '/HTTP_ORIGIN|HTTP_REFERER/' => 'an Origin or Referer check'] as $pattern => $what) {
            $this->assertSame(
                [],
                $this->filesMatching($pattern, [...self::SHIPPED_DIRS, 'assets']),
                "INV-4: {$what} is not access control and never stood in for the nonce.",
            );
        }
    }

    // =====================================================================
    // harness
    // =====================================================================

    /** Construct the picker for real: the constructor is what declares the route. */
    private function service(): NTDST_RelationField
    {
        return new NTDST_RelationField();
    }

    /** The single registered route's argument array. */
    private function route(): array
    {
        if ($this->routes === []) {
            $this->service();
        }

        $this->assertArrayHasKey(
            '/ntdst/v1/relation/search',
            $this->routes,
            'control: constructing the picker must register GET /ntdst/v1/relation/search.',
        );

        return $this->routes['/ntdst/v1/relation/search'][0];
    }

    /** The shipped file, as text. */
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Source with comments removed — line, block and docblock.
     *
     * The invariant documents say a docblock naming a deleted symbol is prose
     * and not a door, so the scans below answer about CODE.
     */
    private function uncommented(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*(//|\*|\#).*$#m', '', $source);
    }

    /**
     * Every shipped file under $dirs whose CODE matches $pattern, as paths
     * relative to the package root, sorted.
     *
     * @param  list<string> $dirs
     * @return list<string>
     */
    private function filesMatching(string $pattern, array $dirs): array
    {
        $root  = dirname(__DIR__, 2);
        $hits  = [];

        foreach ($dirs as $dir) {
            $path = $root . '/' . $dir;

            if (!is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (!in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

                if (str_contains($relative, '/vendor/') || str_starts_with($relative, 'vendor/')) {
                    continue;
                }

                if (preg_match($pattern, $this->uncommented((string) file_get_contents($file->getPathname())))) {
                    $hits[] = $relative;
                }
            }
        }

        sort($hits);

        return array_values(array_unique($hits));
    }

    /**
     * Declare a model whose relation fields point at $targets, so each becomes
     * a derived allow-list entry — the way a real module earns one.
     *
     * @param list<string> $targets
     */
    private function declareRelationsTo(array $targets): void
    {
        Functions\when('get_post_types')->justReturn(['exhibition']);

        $fields = [];
        foreach ($targets as $index => $target) {
            $fields["piece_{$index}"] = ['type' => 'relation', 'post_type' => $target];
        }

        (new NTDST_Data_Manager())->register('exhibition', [
            'label'        => 'Exhibition',
            'meta_prefix'  => '_ex_',
            'auto_metabox' => false,
            'fields'       => $fields,
        ]);
    }

    /** The Manager keeps its models statically; no test may inherit another's. */
    private function forgetRegisteredModels(): void
    {
        $models = new ReflectionProperty(NTDST_Data_Manager::class, 'models');
        $models->setAccessible(true);
        $models->setValue(null, []);
    }
}
