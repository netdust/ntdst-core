<?php // tests/Unit/RelationSearchRouteTest.php
declare(strict_types=1);
// SPLIT RED — core-shape Cluster 3 (T07). Written by the independent
// test-author BEFORE admin/RelationField.php declares a route, and IMMUTABLE
// from here: the implementer greens it without weakening an assertion. Adding a
// missing WordPress function stub to setUp() is fine; relaxing or deleting an
// assertion is an escalation, not an edit.
//
// Contract source: spec.md FR-8 + SC-4; plan.md Interfaces (the T07 block) and
// threat model items 6 and 7; tasks.md T07; cluster-3-behaviour.md.
//
// THE BEHAVIOUR THIS FILE OWNS. The relation picker's query surface stops being
// a command on the action router and becomes ONE resource route —
// `GET /ntdst/v1/relation/search` — carrying the SAME per-type gate it carries
// today, declared through `ntdst_rest()` so the framework's one HTTP surface
// owns its permission, its budget and its arguments (INV-2, INV-4, INV-7).
//
// WHY THE GATE IS THE CENTRE OF THE FILE. A route with no stated permission is
// `is_user_logged_in` — on this framework by design (FR-4) — and that posture
// on this handler is threat model #6 said out loud: every account, including a
// Subscriber on a site with open registration, could enumerate every row of
// every relation-target type, published or not. So the denial cases come first
// and there are four of them, one per way the gate can be made to fail open:
//
//   1. AN EMPTY LIST. `mayPickFromAll([])` over an empty array is the classic
//      vacuous truth — `foreach` over nothing, `return true`. A caller who
//      simply omits `post_type` must be refused, not admitted.
//   2. A TYPE NOBODY POINTS A RELATION FIELD AT. The allow-list is DERIVED from
//      the registered schemas; a type outside it is unreachable here even for a
//      caller who may edit it. This is what stops the picker from becoming a
//      general query surface over the site.
//   3. ANY ONE TYPE FAILING. Two types are requested, the caller may edit one.
//      A gate that admits on ANY pass rather than EVERY pass hands over the
//      type it refused, in the same response, and the happy path never shows
//      it. This is the assertion the ported `mayPickFrom()` loop exists for.
//   4. THE CAPABILITY ITSELF. `edit_others_posts`, read off the type object —
//      never `edit_posts`, which Contributor and Author both hold and which
//      means "my own posts" while this returns everyone's.
//
// WHY IT DRIVES THE ROUTE TABLE RATHER THAN THE METHODS. Every case below asks
// WordPress's registrar what it was handed, then calls the `permission_callback`
// and the `callback` WordPress would call. That is the observable in
// cluster-3-behaviour.md (SC-4's three probes are the same questions over HTTP),
// and it keeps the test honest about the wrapper: `ntdst_rest()` wraps a stated
// permission in its own guard, so the gate that ships is the wrapped one, and a
// test that called `mayPickFromAll()` directly would assert a gate no request
// passes through.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/ServiceInterface.php';
require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';
require_once __DIR__ . '/../../api/Rest.php';

// The recording WP_Query. Guarded, and deliberately built on the SAME two
// globals RelationFieldSearchTest's and DataSurfaceTest's doubles use: a class
// is process-wide, so whichever file loads first defines it for all of them,
// and two doubles recording to different places would make a file pass or fail
// on test ORDER.
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

// The type object is where the capability is read from, so the type object is
// what the fixture serves — with a `cap` bag shaped like WordPress's.
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

// The intersection of the WP_REST_Request doubles already in this suite
// (NtdstRestTest, ActionsRateBucketTest) — guarded, so whichever file loads
// first defines it for all three.
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
 * A request that also answers `get_method()`.
 *
 * It EXTENDS WP_REST_Request because the permission the plan names is typed
 * `fn(WP_REST_Request $r)`, and it adds the verb because `ntdst_rest()`'s guard
 * only spends a route's budget for the handler whose method matched — a plain
 * double without `get_method()` would make the rate-limit case pass by never
 * charging anything.
 */
final class RelationSearchRequest extends WP_REST_Request
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

final class RelationSearchRouteTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The fake WP route table: '/ns/route' => the handler arg-array, exactly WP's shape. */
    private array $routes = [];

    /** Capabilities the current caller holds, by name. */
    private array $caps = [];

    /** The transient store the rate limiter writes through. */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // admin/RelationField.php mounts `add_action('after_setup_theme', …)` at
        // FILE level, and neither the bootstrap nor the autoloader defines
        // add_action — Brain Monkey does, from setUp() above. So the shipped
        // file is required HERE, one line later, rather than at load time.
        require_once __DIR__ . '/../../admin/RelationField.php';

        $this->routes = [];
        $this->caps = [];
        $this->transients = [];
        $GLOBALS['_ntdst_test_wp_query_args'] = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];

        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $this->routes['/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/')][] = $args;
                return true;
            },
        );

        // did_action 1 / doing_action false is "after the hook" — ntdst_rest()
        // registers immediately, so the route table is readable the moment the
        // service is constructed. add_action is recorded, not executed.
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
        Functions\when('current_user_can')->alias(fn(string $cap) => in_array($cap, $this->caps, true));
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
    // 1. The gate — the four ways it could fail open
    // =====================================================================

    /**
     * No requested type at all is a refusal, not a pass.
     *
     * Both spellings of "nothing": the parameter absent, and the parameter
     * present as an empty array. A loop over an empty list returns true unless
     * the emptiness is checked FIRST, and a caller who omits `post_type`
     * reaches a handler that would then be asked to search nothing — or
     * everything, depending on the next hand on the file.
     */
    public function testAnEmptyPostTypeListIsRefused(): void
    {
        $this->declareRelationsTo(['artwork']);
        $this->caps = ['edit_others_artworks'];

        $permission = $this->permission();

        $this->assertDenied(
            $permission(new RelationSearchRequest(['search' => 'blue', 'post_type' => []])),
            'An empty post_type list must be refused — a gate that loops over nothing must not answer true.',
        );
        $this->assertDenied(
            $permission(new RelationSearchRequest(['search' => 'blue'])),
            'An absent post_type must be refused for the same reason as an empty one.',
        );
    }

    /**
     * A type no relation field points at is refused, even to a caller who may
     * edit it.
     *
     * The allow-list is DERIVED from the registered schemas. `page` is a type
     * this caller can edit others' posts of, and it is still refused, because
     * the picker is not a general query surface over the site: only declared
     * relation targets are reachable through it.
     */
    public function testATypeThatIsNotADeclaredRelationTargetIsRefused(): void
    {
        $this->declareRelationsTo(['artwork']);
        $this->caps = ['edit_others_artworks', 'edit_others_pages'];

        $this->assertDenied(
            ($this->permission())(new RelationSearchRequest(['search' => 'blue', 'post_type' => ['page']])),
            'Only a declared relation target is reachable through the picker — the allow-list is derived, '
                . 'so a type nobody points a relation field at must stay unreachable.',
        );
    }

    /**
     * Two types requested, one refused — the whole request is refused.
     *
     * This is the ANY-versus-EVERY assertion, and it is the one the happy path
     * can never make: a gate that admits when any requested type passes hands
     * back the refused type's rows in the same response.
     */
    public function testOneRefusedTypeRefusesTheWholeRequest(): void
    {
        $this->declareRelationsTo(['artwork', 'release']);
        $this->caps = ['edit_others_artworks']; // and NOT edit_others_releases

        $this->assertDenied(
            ($this->permission())(new RelationSearchRequest([
                'search'    => 'blue',
                'post_type' => ['artwork', 'release'],
            ])),
            'Every requested type must pass. One type the caller may not edit others of refuses the request.',
        );
    }

    /**
     * `edit_posts` is not enough; `edit_others_posts` is the capability.
     *
     * An Author holds `edit_posts` for the type and gets nothing here, because
     * this route returns EVERY row of the type and `edit_posts` means "my own".
     * SC-4's second probe is this case over HTTP (403 as an Author).
     */
    public function testACallerWithOnlyEditPostsIsRefused(): void
    {
        $this->declareRelationsTo(['artwork']);
        $this->caps = ['edit_posts', 'edit_artworks', 'read'];

        $this->assertDenied(
            ($this->permission())(new RelationSearchRequest(['search' => 'blue', 'post_type' => ['artwork']])),
            'edit_others_posts is the gate — edit_posts means "my own posts" and this route returns everyone\'s.',
        );
    }

    /**
     * Every requested type passing is the only way through.
     */
    public function testEveryRequestedTypePassingIsAdmitted(): void
    {
        $this->declareRelationsTo(['artwork', 'release']);
        $this->caps = ['edit_others_artworks', 'edit_others_releases'];

        $this->assertTrue(
            ($this->permission())(new RelationSearchRequest([
                'search'    => 'blue',
                'post_type' => ['artwork', 'release'],
            ])),
            'A caller who may edit others\' posts of every requested declared target is admitted.',
        );
    }

    // =====================================================================
    // 2. The declaration
    // =====================================================================

    /**
     * Constructing the service registers exactly ONE route: GET
     * /ntdst/v1/relation/search, with a permission callback of its own.
     *
     * "Exactly one" is half the assertion: the action-router registration this
     * replaces must be gone, not doubled, and a second route (a nonce endpoint,
     * a sibling POST) would be a second surface to gate.
     */
    public function testConstructingTheServiceRegistersOneRelationSearchGetRoute(): void
    {
        $this->declareRelationsTo(['artwork']);

        $this->service();

        $this->assertSame(
            ['/ntdst/v1/relation/search'],
            array_keys($this->routes),
            'The picker declares exactly one route, in ntdst/v1, at /relation/search.',
        );
        $this->assertCount(1, $this->routes['/ntdst/v1/relation/search'], 'One route is one handler declaration.');

        $route = $this->route();

        $this->assertSame('GET', strtoupper((string) $route['methods']), 'A search is a read.');
        $this->assertIsCallable($route['callback'], 'The route must carry the search handler.');
        $this->assertInstanceOf(
            Closure::class,
            $route['permission_callback'],
            'The route states its own permission — never a bare posture string like is_user_logged_in, '
                . 'which on this handler is threat model #6.',
        );
    }

    /**
     * The route declares `search` and `post_type` — and nothing named
     * `post_types`.
     *
     * The plural was the action router's parameter name; the route's argument
     * is `post_type`, the JS emits `post_type[]`, and the handler's dual read
     * goes. Two spellings for one input is how one of them stops being
     * validated.
     */
    public function testTheRouteDeclaresSearchAndPostTypeArguments(): void
    {
        $this->declareRelationsTo(['artwork']);
        $this->service();

        $args = $this->route()['args'] ?? [];

        $this->assertArrayNotHasKey('post_types', $args, 'The plural spelling is retired — the argument is post_type.');

        $this->assertSame('string', $args['search']['type'] ?? null, 'search is a string.');
        $this->assertTrue($args['search']['required'] ?? false, 'A search with no term is not a search.');
        $this->assertSame(
            'sanitize_text_field',
            $args['search']['sanitize_callback'] ?? null,
            'The term reaches WP_Query, so WordPress sanitizes it at the door.',
        );

        $this->assertSame('array', $args['post_type']['type'] ?? null, 'post_type is a list — post_type[]=… on the wire.');
        $this->assertSame(
            'string',
            $args['post_type']['items']['type'] ?? null,
            'Each requested type is a string; the permission reads them one by one.',
        );
    }

    /**
     * The route carries a 60-per-60s budget, spent only by admitted callers.
     *
     * Asserted by burst rather than by reading the declaration back: the budget
     * is only real if the wrapped permission enforces it. The 60th admitted
     * request still passes and the 61st is a 429 that tells a client how long
     * to wait — an autocomplete fires on keystrokes, so a limit with no retry
     * signal is a picker that silently dies mid-word.
     */
    public function testTheRouteIsRateLimitedAtSixtyPerSixtySeconds(): void
    {
        $this->declareRelationsTo(['artwork']);
        $this->caps = ['edit_others_artworks'];

        $permission = $this->permission();
        $results = [];

        for ($i = 0; $i < 61; $i++) {
            // Distinct request objects: the guard memoizes per request, so a
            // burst is sixty-one requests rather than one repeated.
            $results[] = $permission(new RelationSearchRequest(['search' => 'blue', 'post_type' => ['artwork']]));
        }

        $this->assertTrue($results[59], 'The sixtieth request of a window is still within the budget.');
        $this->assertInstanceOf(WP_Error::class, $results[60], 'The sixty-first request in the window is refused.');
        $this->assertSame(429, $results[60]->get_error_data()['status'] ?? null);
        $this->assertSame(60, $results[60]->get_error_data()['retry_after'] ?? null, 'A client cannot back off without one.');
    }

    // =====================================================================
    // 3. The handler
    // =====================================================================

    /**
     * The route answers `results` rows of exactly `id` and `title`, from one
     * query capped at twenty.
     *
     * The projection is the contract the JS was rewritten against — it reads
     * `id` and `title` and has no fallbacks left — and the cap is in the query
     * literal, so it is asserted on what WordPress was handed.
     */
    public function testTheRouteReturnsIdAndTitleRowsFromOneCappedQuery(): void
    {
        $this->declareRelationsTo(['artwork']);
        $this->caps = ['edit_others_artworks'];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [
            (object) ['ID' => '11', 'post_title' => 'Blue Nude', 'post_content' => 'a body', 'post_status' => 'publish'],
        ];

        $callback = $this->route()['callback'];

        $result = $callback(new RelationSearchRequest(['search' => 'blue', 'post_type' => ['artwork']]));

        $this->assertSame(
            ['results' => [['id' => 11, 'title' => 'Blue Nude']]],
            $result,
            'The picker row is an int id and a title — and nothing else: no permalink, no excerpt, '
                . 'no thumbnail, and above all no post_content on an admin search row.',
        );

        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One search is one query.');
        $this->assertSame(
            [
                's'                   => 'blue',
                'post_type'           => ['artwork'],
                'posts_per_page'      => 20,
                'post_status'         => 'publish',
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => true,
            ],
            $GLOBALS['_ntdst_test_wp_query_args'][0],
            'The picker asks WordPress for exactly these seven arguments — the cap of twenty among them.',
        );
    }

    // =====================================================================
    // 4. The dispatcher and the JS client are gone from the picker
    // =====================================================================

    /**
     * Neither the picker's PHP nor its JS mentions the action router or the
     * package's own API client.
     *
     * The three greps in tasks.md, as one assertion. This is a guard over
     * symbols that must be ABSENT, so it reads the files: an absence has no
     * behaviour to drive.
     */
    public function testThePickerFilesNameNeitherTheActionRouterNorTheJsClient(): void
    {
        foreach (['admin/RelationField.php', 'admin/MetaboxGenerator.php', 'assets/js/metabox-fields.js'] as $file) {
            $source = $this->source($file);

            $this->assertSame(
                0,
                preg_match_all('/ntdst_actions|ntdstAPI/', $source),
                "{$file} must name neither ntdst_actions() nor window.ntdstAPI — the picker goes through the "
                    . 'one HTTP surface and WordPress\'s own transport.',
            );
        }
    }

    /**
     * The handler no longer reads the plural parameter.
     */
    public function testTheHandlerHasNoPostTypesRead(): void
    {
        $this->assertSame(
            0,
            preg_match_all('/post_types/', $this->source('admin/RelationField.php')),
            'The dual `post_types ?? post_type` read is deleted — one spelling, declared in the route args.',
        );
    }

    /**
     * The metabox enqueue depends on `wp-api-fetch`, not on the package's own
     * client, and never enqueues one.
     *
     * `wp-api-fetch` is what supplies the REST nonce (threat model #7), so this
     * dependency is a security control and not a bundling detail: without it,
     * cookie-authenticated calls from the picker are anonymous and the route
     * answers 401.
     */
    public function testTheMetaboxEnqueueDependsOnWpApiFetchAndEnqueuesNoOwnClient(): void
    {
        $enqueued = [];

        Functions\when('ntdst_enqueue_api_client')->alias(function (): void {
            $this->fail('The package enqueues no API client of its own — wp.apiFetch is WordPress\'s.');
        });
        Functions\when('plugins_url')->justReturn('https://site.test/wp-content/plugins/ntdst-core/assets/js/metabox-fields.js');
        Functions\when('wp_enqueue_script')->alias(
            function ($handle, $src = '', $deps = [], $ver = false, $in_footer = false) use (&$enqueued): void {
                $enqueued[(string) $handle] = (array) $deps;
            },
        );

        $generator = NTDST_MetaboxGenerator::instance();
        $generator->register('exhibition', ['fields' => ['piece' => ['type' => 'relation', 'post_type' => 'artwork']]]);

        $GLOBALS['post_type'] = 'exhibition';
        $generator->enqueue_metabox_scripts('post.php');

        $this->assertArrayHasKey('ntdst-metabox-fields', $enqueued, 'control: the metabox script is enqueued on post.php.');
        $this->assertContains(
            'wp-api-fetch',
            $enqueued['ntdst-metabox-fields'],
            'wp.apiFetch carries the REST nonce; without the dependency the picker calls the route anonymously.',
        );
        $this->assertNotContains(
            'ntdst-api',
            $enqueued['ntdst-metabox-fields'],
            'The package ships no JS client of its own any more.',
        );
    }

    /**
     * The picker's JS calls the route through wp.apiFetch, sends `post_type[]`,
     * and reads the route's own two keys.
     *
     * A file-content guard, for the same reason as the greps above: the JS has
     * no PHP harness, and what must be true of it is that three shapes are gone
     * and two are present.
     */
    public function testThePickerJsCallsTheRouteThroughWpApiFetch(): void
    {
        $js = $this->source('assets/js/metabox-fields.js');

        // Counted rather than matched against the whole file: a failing
        // assertStringContainsString prints eleven kilobytes of JavaScript, and
        // the number is the fact.
        $this->assertSame(1, preg_match('/wp\.apiFetch\s*\(/', $js), 'The picker transports through WordPress\'s own client.');
        $this->assertSame(1, preg_match('#/ntdst/v1/relation/search#', $js), 'It calls the route by path.');
        $this->assertSame(
            1,
            preg_match('/URLSearchParams|post_type\[\]/', $js),
            'The query string is built with URLSearchParams so the list goes out as post_type[]=…',
        );
        $this->assertSame(0, preg_match_all('/post_types/', $js), 'The plural spelling is gone from the wire.');
        $this->assertSame(
            0,
            preg_match_all('/post\.ID\s*\|\||post\.post_title\s*\|\|/', $js),
            'The route returns id and title only — a fallback to ID/post_title is a second response shape '
                . 'nobody serves any more.',
        );
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

    /** The permission WordPress would call — the wrapped one, budget included. */
    private function permission(): callable
    {
        $permission = $this->route()['permission_callback'];

        $this->assertIsCallable($permission, 'The route must state a permission callback.');

        return $permission;
    }

    /** A refusal is `false` or a WP_Error — never true, and never a truthy value. */
    private function assertDenied(mixed $result, string $message): void
    {
        if ($result instanceof WP_Error) {
            return;
        }

        $this->assertFalse($result, $message);
    }

    /** The shipped file, as text, for the absence guards. */
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
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
