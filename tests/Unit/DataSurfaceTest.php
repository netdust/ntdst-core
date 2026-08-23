<?php // tests/Unit/DataSurfaceTest.php
// core-trim T04 — the Data layer offers ONE way to read rows.
//
// This is the RED contract for spec FR-4 and SC-3. Until this task, the layer
// answered the same question twice: `NTDST_Data_Manager::getFormattedPosts()`
// (and its global `ntdst_get_formatted_posts()`) took a raw WP_Query argument
// bag and returned rows, while `->withMeta()->withTerms()->get()` took the same
// question through the builder. Two read paths on one layer is the defect
// find()/getMeta() already produced once here: a consumer gating with one and
// reading with the other has a bypass, and nothing about the call site looks
// wrong. So the SECOND path goes and the chain is the one way in.
//
// The term writers go with it for a different reason: `attachTerms()`,
// `syncTerms()` and `detachTerms()` wrap `wp_set_post_terms()` — one WordPress
// call each — and the fleet survey found zero readers. Wrapping a single core
// call in a method nobody calls is the "wrap, never replace" rule read
// backwards (philosophy §1). `whereDate()` and `orWhere()` are the same story
// on the query side: `date_query` and a flat root-level OR `meta_query` are
// WordPress's own arguments, and the builder cannot express the nested groups a
// caller reaching for `orWhere()` usually wants.
//
// WHAT THIS FILE ASSERTS, in order:
//   1. THE MANAGER'S SURFACE, by reflection — the registry registers models and
//      hands them out; it does not run queries. Asserted as an EXACT list, not
//      as absences: an exact list is what stops a third read path arriving
//      later under a new name.
//   2. THE GLOBAL IS GONE — `ntdst_get_formatted_posts()` was the second path's
//      public front door, and the one shipped caller (admin/RelationField.php's
//      relation picker) is re-routed to WP_Query by this task.
//   3. THE MODEL KEEPS NO TERM WRITER AND NO SECOND QUERY CLAUSE.
//   4. THE MODEL'S NON-CHAIN, NON-CRUD SURFACE IS THE FOUR READERS field-types
//      pinned (INV-1) — unchanged by this task, and re-asserted here because
//      moving the query engine INTO the model is exactly the change that could
//      grow a fifth public reader by accident.
//   5. THE CHAIN STILL ANSWERS. The deletions above are only safe if the one
//      remaining path still returns meta and terms, so the round trip is
//      exercised behaviourally through a WP_Query stub — including the negative
//      half: `include_meta`/`include_terms` are the layer's OWN arguments and
//      must never reach WP_Query, which would silently query for a meta key
//      named `include_meta`.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

// The suite has no WP_Query until here. It records every argument bag it is
// handed — the negative assertion below reads that recording — and serves the
// rows the test seeded.
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
    }
}

final class DataSurfaceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The model under test: one declared field, one prefix. */
    private const PREFIX = '_probe_';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['_ntdst_test_wp_query_args'] = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -- 1. the registry registers; it does not query -------------------------

    /**
     * FR-4. `getFormattedPosts()`, `getPostMeta()` and `getPostTerms()` were
     * PUBLIC STATICS on the registry — a query engine reachable from anywhere,
     * bypassing the model that declares what the rows mean. The engine stays
     * (the chain runs on it) but it becomes the model's own private business.
     *
     * Asserted as an exact list: `assertFalse(method_exists(...))` per name
     * would pass again the day a fourth static arrives.
     */
    public function testTheManagersStaticSurfaceIsExactlyTheScopeRegistry(): void
    {
        // getMethods()'s bitmask is an OR, not an AND — `IS_PUBLIC|IS_STATIC`
        // returns every public method AND every static one. The static half is
        // selected here instead.
        $statics = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass(NTDST_Data_Manager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn(ReflectionMethod $m): bool => $m->isStatic(),
            ),
        );
        sort($statics);

        $this->assertSame(
            ['addScope', 'getScope'],
            $statics,
            'NTDST_Data_Manager is a registry. The only thing it answers statically is '
                . 'the global scope table; a static that READS ROWS is the second query '
                . 'API this task removed.',
        );
    }

    /** The instance side is the registry proper: declare, hand out, ask. */
    public function testTheManagersInstanceSurfaceIsExactlyTheRegistry(): void
    {
        $instance = array_values(array_filter(
            array_map(
                static fn(ReflectionMethod $m): string => $m->getName(),
                (new ReflectionClass(NTDST_Data_Manager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            static fn(string $name): bool => !(new ReflectionMethod(NTDST_Data_Manager::class, $name))->isStatic(),
        ));
        sort($instance);

        $this->assertSame(
            ['get', 'isRegistered', 'register', 'registerTaxonomy'],
            $instance,
            'Declare a model, declare a taxonomy, hand a model out, ask whether one exists.',
        );
    }

    /**
     * FR-4. The second path's front door. A global function is the cheapest
     * possible bypass — no object to obtain, no model to name, so nothing tells
     * the caller which declaration the rows belong to.
     */
    public function testTheGlobalSecondQueryHelperIsGone(): void
    {
        $this->assertFalse(
            function_exists('ntdst_get_formatted_posts'),
            'ntdst_get_formatted_posts() read rows with no model and no schema. '
                . 'ntdst_data()->get(<model>)->...->get() is the one way.',
        );
    }

    // -- 2. the model keeps no term writer and no second query clause ---------

    /**
     * FR-4. Five methods, zero readers on the fleet. Three of them wrap a
     * single `wp_set_post_terms()` call; two express query arguments WordPress
     * already names better (`date_query`, `meta_query`).
     *
     * @dataProvider removedModelMethodProvider
     */
    public function testTheModelCarriesNoRemovedMethod(string $method, string $instead): void
    {
        $this->assertFalse(
            method_exists(NTDST_Data_Model::class, $method),
            sprintf('%s() was removed in v5.0.0. Write %s instead.', $method, $instead),
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function removedModelMethodProvider(): array
    {
        return [
            'attachTerms' => ['attachTerms', 'wp_set_post_terms($id, $terms, $tax, true)'],
            'syncTerms'   => ['syncTerms', 'wp_set_post_terms($id, $terms, $tax, false)'],
            'detachTerms' => ['detachTerms', 'wp_set_post_terms($id, $remaining, $tax, false)'],
            'whereDate'   => ['whereDate', "a date_query on the model's own query args"],
            'orWhere'     => ['orWhere', 'a meta_query with an explicit relation'],
        ];
    }

    /**
     * INV-1, re-asserted at the one moment it could break: this task moves the
     * query engine INTO NTDST_Data_Model. If any of the three moved helpers
     * lands PUBLIC, the layer has grown a fifth way to read the field
     * description — the exact second exposure INV-1 exists to prevent — and
     * this list is what notices.
     *
     * The subtracted names are a DENY-LIST, never an inventory: nothing here is
     * asserted PRESENT. What must not grow is the remainder.
     */
    public function testTheModelsPublicSurfaceBesidesTheChainAndCrudIsTheFourReaders(): void
    {
        $chainAndCrud = [
            '__construct',
            'all', 'count', 'first', 'get', 'paginate',
            'limit', 'orderBy', 'scope', 'where', 'whereIn', 'whereNot', 'whereTax',
            'withMeta', 'withTerms',
            'create', 'delete', 'deleteMeta', 'find', 'getMeta',
            'update', 'updateMeta', 'updateMetaBatch',
        ];

        $methods = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NTDST_Data_Model::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $readers = array_values(array_diff($methods, $chainAndCrud));
        sort($readers);

        $this->assertSame(
            ['getMetaPrefix', 'getSchema', 'registerRestMeta', 'restFields'],
            $readers,
            'Besides the chain and CRUD, NTDST_Data_Model answers exactly these four. '
                . 'A query helper that moved into this class must be private.',
        );
    }

    // -- 3. the one remaining path still answers ------------------------------

    /**
     * SC-3 / FR-4's other half. Removing the second path is only safe while the
     * first one still returns what the second one did: a row carrying its meta
     * and its terms.
     *
     * The meta bag is asserted PROJECTED — `title`, not `_probe_title` — because
     * that is what the chain has always returned through the model's schema, and
     * a refactor that returned the raw bag would leak every undeclared key to
     * whoever calls `get()`.
     */
    public function testTheChainStillReturnsRowsCarryingMetaAndTerms(): void
    {
        $this->seedOnePost();

        $rows = $this->model()->withMeta()->withTerms()->get();

        $this->assertCount(1, $rows);

        $this->assertArrayHasKey('meta', $rows[0], 'withMeta() must still put the meta bag on the row.');
        $this->assertSame(
            ['title' => 'a probe row'],
            $rows[0]['meta'],
            "The bag is the model's DECLARED shape, read back under unprefixed names.",
        );

        $this->assertArrayHasKey('terms', $rows[0], 'withTerms() must still put the terms on the row.');
        $this->assertSame(
            ['genre' => [['id' => 7, 'name' => 'Jazz', 'slug' => 'jazz']]],
            $rows[0]['terms'],
            'Terms stay grouped by taxonomy and reduced to id/name/slug.',
        );
    }

    /**
     * The negative half. `include_meta` and `include_terms` are the LAYER's own
     * arguments; WP_Query has never heard of them. Leaking one through would
     * not error — WP_Query ignores unknown top-level keys — so the failure mode
     * is silent, and only a recording of what WordPress was actually asked can
     * see it.
     */
    public function testTheLayersOwnArgumentsNeverReachWordPress(): void
    {
        $this->seedOnePost();

        $this->model()->withMeta()->withTerms()->get();

        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One read is one query.');

        $args = $GLOBALS['_ntdst_test_wp_query_args'][0];
        $this->assertArrayNotHasKey('include_meta', $args);
        $this->assertArrayNotHasKey('include_terms', $args);
        $this->assertSame('probe', $args['post_type'], "The model's own type is what is queried.");
    }

    /**
     * A post with NO terms is served from the warm cache, and never falls
     * through to the SQL path.
     *
     * `readPostTerms()` reads WordPress's `<taxonomy>_relationships` cache —
     * which WP_Query primed on the read above — and then decided whether to
     * trust it by asking whether it had COLLECTED anything: `if (!empty($terms))
     * return $terms;`. An empty result is indistinguishable from a cache miss
     * under that test, so every post that genuinely has no terms took the
     * hand-written three-way JOIN, on every read, forever. A gallery whose
     * artworks are untagged pays a query per row to be told again that there is
     * nothing to tell.
     *
     * The cache HIT is the thing to read: `wp_cache_get()` answers `false` on a
     * miss and an array — possibly an empty one — on a hit. When every taxonomy
     * of the type answered, the answer is complete, and `[]` is a real answer.
     *
     * Counted rather than asserted-absent: `$wpdb` is a global, so the only
     * honest way to say "the SQL path did not run" is to hand the layer a
     * recorder and read the count. Two reads are made, because the claim is
     * about a WARM cache staying warm, not about a single lucky call.
     */
    public function testAPostWithNoTermsIsServedFromTheCacheAndNeverReachesTheSqlPath(): void
    {
        $this->seedOnePost(false);

        $wpdb = new class {
            public string $term_relationships = 'wp_term_relationships';
            public string $term_taxonomy = 'wp_term_taxonomy';
            public string $terms = 'wp_terms';
            public int $reads = 0;

            public function prepare(string $sql, ...$args): string { return $sql; }

            public function get_results(string $sql): array
            {
                ++$this->reads;

                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $rows = $this->model()->withTerms()->get();
        $this->model()->withTerms()->get();

        $this->assertSame(
            [],
            $rows[0]['terms'],
            'A post with no terms carries an empty terms bag — the cache said so.',
        );
        $this->assertSame(
            0,
            $wpdb->reads,
            'A warm relationship cache that answers "no terms" is a HIT, not a miss: '
                . 'the SQL fallback must not run.',
        );
    }

    // -- harness --------------------------------------------------------------

    private function model(): NTDST_Data_Model
    {
        return new NTDST_Data_Model('probe', ['title' => ['type' => 'text']], self::PREFIX);
    }

    /**
     * One published row, its meta warm in core's `post_meta` cache and its one
     * term warm in the taxonomy relationship cache — the state WP_Query leaves
     * behind on any real read, which is the state the row formatter reads.
     */
    private function seedOnePost(bool $with_terms = true): void
    {
        $GLOBALS['_ntdst_test_wp_query_posts'] = [
            (object) [
                'ID'            => 11,
                'post_type'     => 'probe',
                'post_title'    => 'a probe row',
                'post_excerpt'  => 'an excerpt',
                'post_content'  => 'a body',
                'post_password' => '',
                'post_name'     => 'a-probe-row',
                'post_date'     => '2026-08-23 10:00:00',
                'post_modified' => '2026-08-23 10:00:00',
                'post_author'   => 3,
            ],
        ];

        Functions\when('wp_parse_args')->alias(
            static fn($args, $defaults = []) => array_merge((array) $defaults, (array) $args),
        );
        Functions\when('post_password_required')->justReturn(false);
        Functions\when('get_permalink')->justReturn('https://example.test/a-probe-row');
        Functions\when('mysql2date')->alias(static fn($format, $date) => $date);
        Functions\when('get_the_author_meta')->justReturn('A Writer');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('get_object_taxonomies')->justReturn(['genre']);
        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));

        // Core primes both caches on the query above; the formatter reads them
        // rather than issuing SQL of its own.
        Functions\when('wp_cache_get')->alias(function ($id = null, $group = '') use ($with_terms) {
            if ($group === 'post_meta') {
                return [self::PREFIX . 'title' => ['a probe row']];
            }

            // A HIT either way. An untagged post's relationship cache holds an
            // empty array, which is an answer — not the `false` of a miss.
            if ($group === 'genre_relationships') {
                return $with_terms
                    ? [(object) ['term_id' => 7, 'name' => 'Jazz', 'slug' => 'jazz']]
                    : [];
            }

            return false;
        });
    }
}
