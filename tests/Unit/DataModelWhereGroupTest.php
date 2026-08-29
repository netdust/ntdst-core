<?php // tests/Unit/DataModelWhereGroupTest.php
// 5.1.0 — the chain can express a GROUPED meta clause.
//
// 5.0.0 removed `orWhere()` and told the caller to write "a meta_query with an
// explicit relation" (README, `### 5.0.0 — BREAKING`). That advice has no
// destination on the builder: `where()`, `whereNot()` and `whereIn()` each
// append ONE flat clause to `query_args['meta_query']`, and WP_Query joins the
// flat list with its default AND. A consumer that needs "confirmed OR (pending
// AND paid)" therefore has to leave the chain, hand-write the whole argument
// bag, and prefix its own meta keys — which is the bypass the one-query-API
// rule (FR-4) exists to prevent, and it re-implements prefixMetaKey() at the
// call site where a typo'd prefix silently matches nothing.
//
// `whereGroup()` gives that advice a destination INSIDE the chain: a child
// builder collects clauses through the ordinary `where*` methods — so the
// model's prefix, not the caller, decides the key — and the group lands as one
// nested clause under the parent's `meta_query`.
//
// WHAT THIS FILE ASSERTS, in order:
//   1. The nested clause reaches WP_Query, with the model's PREFIX on the keys.
//   2. The relation is validated, case-insensitively, and anything else THROWS.
//      A silent fallback to AND is the fail-open version of this method: an
//      `whereGroup('or', …)` typo'd to `'ro'` would quietly narrow a query the
//      caller meant to widen, and nothing about the call site would look wrong.
//   3. An EMPTY group is a no-op. `['relation' => 'OR']` with no clauses is not
//      a neutral argument to WP_Query — it is a malformed one — so a builder
//      callback that added nothing (every branch behind a false condition) must
//      leave the query exactly as it found it.
//   4. It COMPOSES: after a flat where(), inside a declared scope(), and inside
//      another group. A scope is the whole reason this method exists — the
//      named fragment is where a grouped constraint belongs.
//   5. `whereMissing()` emits NOT EXISTS, and `whereNotIn()` mirrors whereIn()
//      with NOT IN — the two clause shapes a group of "absent or excluded"
//      conditions needs, and neither was expressible before.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

// Records the argument bag; serves no rows. What this file asks is what
// WordPress was ASKED, which is the only place a meta_query is observable
// without reaching into the builder's protected state.
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

final class DataModelWhereGroupTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const PREFIX = '_probe_';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['_ntdst_test_wp_query_args'] = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];

        Functions\when('wp_parse_args')->alias(
            static fn($args, $defaults = []) => array_merge((array) $defaults, (array) $args),
        );
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -- 1. the nested clause, with the model's prefix ------------------------

    /**
     * The shape WP_Query is handed: ONE entry in the parent's `meta_query`,
     * carrying its own relation and the child's clauses.
     *
     * Asserted as the whole `meta_query`, not key-by-key: the defect this
     * replaces is a group that FLATTENS into the parent list, and a per-key
     * assertion passes just as well on a flattened bag.
     */
    public function testAGroupLandsAsOneNestedClauseUnderMetaQuery(): void
    {
        $this->model()
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                $g->where('status', 'confirmed')->where('status', 'pending');
            })
            ->get();

        $this->assertSame(
            [
                [
                    'relation' => 'OR',
                    ['key' => self::PREFIX . 'status', 'value' => 'confirmed'],
                    ['key' => self::PREFIX . 'status', 'value' => 'pending'],
                ],
            ],
            $this->metaQuery(),
            'A group is ONE clause carrying its own relation — not two clauses '
                . "flattened into the parent's implicit AND.",
        );
    }

    /**
     * The keys are the MODEL's, not the caller's. The child is built from the
     * same post type, schema, prefix and scopes, so a clause added inside the
     * callback goes through the same prefixMetaKey() as one added outside it.
     * A child built without the prefix would query for a bare `status` key and
     * silently match nothing.
     */
    public function testTheGroupsKeysCarryTheModelsMetaPrefix(): void
    {
        $this->model()
            ->whereGroup('AND', static function (NTDST_Data_Model $g): void {
                $g->where('status', 'confirmed');
            })
            ->get();

        $group = $this->metaQuery()[0];

        $this->assertSame(
            self::PREFIX . 'status',
            $group[0]['key'],
            "The child builder is the model's own: the prefix is applied inside the group too.",
        );
    }

    // -- 2. the relation is validated -----------------------------------------

    /** Case is the caller's business; the argument WP_Query gets is upper. */
    public function testTheRelationIsAcceptedCaseInsensitivelyAndNormalisedToUpper(): void
    {
        $this->model()
            ->whereGroup('or', static function (NTDST_Data_Model $g): void {
                $g->where('status', 'confirmed');
            })
            ->get();

        $this->assertSame(
            'OR',
            $this->metaQuery()[0]['relation'],
            "WP_Query reads 'OR'/'AND'; a lowercase relation is the caller's spelling, not a second meaning.",
        );
    }

    /**
     * Anything that is not AND or OR is a THROW, not a default.
     *
     * @dataProvider badRelationProvider
     */
    public function testAnUnknownRelationThrows(string $relation): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->model()->whereGroup($relation, static function (NTDST_Data_Model $g): void {
            $g->where('status', 'confirmed');
        });
    }

    /** @return array<string, array{0: string}> */
    public static function badRelationProvider(): array
    {
        return [
            'a typo'          => ['ro'],
            'SQL that is not WP_Query\'s' => ['NOT'],
            'empty'           => [''],
            'an injection'    => ["OR' OR 1=1 --"],
        ];
    }

    // -- 3. an empty group is a no-op -----------------------------------------

    /**
     * A callback whose every branch was skipped adds nothing. Appending a bare
     * `['relation' => 'OR']` would hand WP_Query a clause with no conditions.
     */
    public function testAnEmptyGroupAppendsNothing(): void
    {
        $this->model()
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                // every branch behind a false condition
            })
            ->get();

        $args = $GLOBALS['_ntdst_test_wp_query_args'][0];

        $this->assertArrayNotHasKey(
            'meta_query',
            $args,
            'An empty group leaves the query exactly as it found it — no empty relation clause.',
        );
    }

    /** And it leaves the clauses that were already there untouched. */
    public function testAnEmptyGroupDoesNotDisturbExistingClauses(): void
    {
        $this->model()
            ->where('status', 'confirmed')
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
            })
            ->get();

        $this->assertSame(
            [['key' => self::PREFIX . 'status', 'value' => 'confirmed']],
            $this->metaQuery(),
            'The no-op is a no-op on a non-empty query too.',
        );
    }

    // -- 4. it composes --------------------------------------------------------

    /** A flat clause and a group sit side by side, in call order. */
    public function testAGroupComposesAfterAFlatWhere(): void
    {
        $this->model()
            ->where('kind', 'edition')
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                $g->where('status', 'confirmed')->whereMissing('archived_at');
            })
            ->get();

        $this->assertSame(
            [
                ['key' => self::PREFIX . 'kind', 'value' => 'edition'],
                [
                    'relation' => 'OR',
                    ['key' => self::PREFIX . 'status', 'value' => 'confirmed'],
                    ['key' => self::PREFIX . 'archived_at', 'compare' => 'NOT EXISTS'],
                ],
            ],
            $this->metaQuery(),
            'The parent list keeps its flat clause and gains the group beside it.',
        );
    }

    /**
     * The reason the method exists. A scope is a named CONSTRAINT fragment, and
     * "active for the admin list" is exactly the constraint that needs a group:
     * not archived, and either confirmed or still pending payment.
     *
     * The child must therefore carry the model's SCOPES too — a scope that
     * calls another scope inside the group would throw "Unknown query scope"
     * against a child built without them.
     */
    public function testADeclaredScopeCanExpressAGroupedClause(): void
    {
        $model = new NTDST_Data_Model(
            'probe',
            ['status' => ['type' => 'text'], 'archived_at' => ['type' => 'text']],
            self::PREFIX,
            [
                'adminActive' => static function (NTDST_Data_Model $q): void {
                    $q->whereMissing('archived_at')
                        ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                            $g->where('status', 'confirmed')->where('status', 'pending');
                        });
                },
            ],
        );

        $model->scope('adminActive')->get();

        $this->assertSame(
            [
                ['key' => self::PREFIX . 'archived_at', 'compare' => 'NOT EXISTS'],
                [
                    'relation' => 'OR',
                    ['key' => self::PREFIX . 'status', 'value' => 'confirmed'],
                    ['key' => self::PREFIX . 'status', 'value' => 'pending'],
                ],
            ],
            $this->metaQuery(),
            'A scope narrows through the same chain, so a grouped constraint is expressible '
                . 'in the one place a constraint belongs.',
        );
    }

    /** A scope that calls a scope inside the group still resolves. */
    public function testTheChildBuilderInheritsTheModelsScopes(): void
    {
        $model = new NTDST_Data_Model(
            'probe',
            ['status' => ['type' => 'text']],
            self::PREFIX,
            [
                'confirmed' => static function (NTDST_Data_Model $q): void {
                    $q->where('status', 'confirmed');
                },
            ],
        );

        $model->whereGroup('OR', static function (NTDST_Data_Model $g): void {
            $g->scope('confirmed')->where('status', 'pending');
        })->get();

        $this->assertSame(
            [
                [
                    'relation' => 'OR',
                    ['key' => self::PREFIX . 'status', 'value' => 'confirmed'],
                    ['key' => self::PREFIX . 'status', 'value' => 'pending'],
                ],
            ],
            $this->metaQuery(),
            "The child is built with the model's scopes, so a named fragment resolves inside a group.",
        );
    }

    /** A group inside a group nests, rather than collapsing a level. */
    public function testGroupsNest(): void
    {
        $this->model()
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                $g->where('status', 'confirmed')
                    ->whereGroup('AND', static function (NTDST_Data_Model $inner): void {
                        $inner->where('status', 'pending')->where('paid', '1');
                    });
            })
            ->get();

        $this->assertSame(
            [
                [
                    'relation' => 'OR',
                    ['key' => self::PREFIX . 'status', 'value' => 'confirmed'],
                    [
                        'relation' => 'AND',
                        ['key' => self::PREFIX . 'status', 'value' => 'pending'],
                        ['key' => self::PREFIX . 'paid', 'value' => '1'],
                    ],
                ],
            ],
            $this->metaQuery(),
            'An inner group is a clause of the outer one — the level is kept, not flattened.',
        );
    }

    /**
     * A PIN of documented behaviour, NOT a RED-first contract: whereGroup()
     * reads `$child->query_args['meta_query']` and nothing else, so the
     * behaviour was already correct when this was written. It is here because
     * that narrowness is a DECISION the README now states, and an
     * implementation that later merged the whole child bag would satisfy every
     * other test in this file while quietly giving a group the power to set
     * the page size of the query it sits in.
     *
     * Written first as four assertArrayNotHasKey() calls, which FAILED — and
     * the failure was the test's, not the code's. `queryRows()` applies its own
     * defaults (post_status, posts_per_page, orderby, order) to every query, so
     * those keys are present on any read and their absence is not a thing this
     * layer can ever show. The assertions are by VALUE below, which is what
     * actually distinguishes "the child was ignored" from "the child won".
     *
     * A group IS a meta_query clause. WP_Query has no nested `tax_query`
     * inside one, no per-group `posts_per_page`, and no way to express a
     * core-column condition there — so a child that sets one is asking for
     * something the parent clause cannot carry. Dropping it is the honest
     * answer; MERGING it would apply a constraint the caller wrote inside an
     * OR as though they had written it outside, inverting the query's meaning.
     */
    public function testTheGroupTakesTheChildsMetaClausesAndNothingElse(): void
    {
        $this->model()
            ->where('kind', 'edition')
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                $g->where('status', 'confirmed')
                    ->whereTax('genre', 'jazz')
                    ->limit(5)
                    ->orderBy('post_date')
                    ->where('post_status', 'draft');
            })
            ->get();

        $args = $GLOBALS['_ntdst_test_wp_query_args'][0];

        $this->assertSame(
            [
                ['key' => self::PREFIX . 'kind', 'value' => 'edition'],
                [
                    'relation' => 'OR',
                    ['key' => self::PREFIX . 'status', 'value' => 'confirmed'],
                ],
            ],
            $args['meta_query'] ?? [],
            "The group carries the child's META clauses — only those.",
        );

        // `tax_query` has no default, so its ABSENCE is the whole assertion.
        $this->assertArrayNotHasKey(
            'tax_query',
            $args,
            "A group is a meta clause; the child's taxonomy filter is not the parent's.",
        );

        // The rest are asserted by VALUE, not by absence. queryRows() applies
        // its own defaults to every query — post_status/posts_per_page/orderby
        // /order are always present — so "not set" is not observable here and
        // an assertArrayNotHasKey on them could never pass. What IS observable
        // is whether the child's value WON, which is the actual risk: the
        // parent must still show the default, never the child's number.
        $this->assertSame(
            10,
            $args['posts_per_page'] ?? null,
            "The default page size stands: a group cannot set the limit of the query it sits in (the child asked for 5).",
        );
        $this->assertSame(
            'publish',
            $args['post_status'] ?? null,
            'A core-column condition inside an OR group would silently become an AND on the whole '
                . "query, so it is dropped: the child asked for 'draft' and the default stands.",
        );
        $this->assertSame('date', $args['orderby'] ?? null, "Ordering is the query's, not a clause's.");
        $this->assertSame('DESC', $args['order'] ?? null, "Ordering is the query's, not a clause's.");
    }

    // -- 5. the two clause shapes a group needs -------------------------------

    /** whereMissing() is the absence clause: NOT EXISTS, no value. */
    public function testWhereMissingEmitsNotExistsOnThePrefixedKey(): void
    {
        $this->model()->whereMissing('archived_at')->get();

        $this->assertSame(
            [['key' => self::PREFIX . 'archived_at', 'compare' => 'NOT EXISTS']],
            $this->metaQuery(),
            'An absent meta key is asked for by NOT EXISTS. A value would make it a comparison.',
        );
    }

    /** whereNotIn() mirrors whereIn() with the negated compare. */
    public function testWhereNotInMirrorsWhereInWithNotIn(): void
    {
        $this->model()->whereNotIn('status', ['cancelled', 'refunded'])->get();

        $this->assertSame(
            [
                [
                    'key' => self::PREFIX . 'status',
                    'value' => ['cancelled', 'refunded'],
                    'compare' => 'NOT IN',
                ],
            ],
            $this->metaQuery(),
            'The excluded set is one clause, the same shape whereIn() emits.',
        );
    }

    /**
     * whereIn('ID', …) is a post__in, not a meta clause — whereNotIn must keep
     * that symmetry, or excluding IDs would query for a meta key named `ID`.
     */
    public function testWhereNotInOnIdsUsesPostNotIn(): void
    {
        $this->model()->whereNotIn('ID', ['7', 9])->get();

        $args = $GLOBALS['_ntdst_test_wp_query_args'][0];

        $this->assertSame([7, 9], $args['post__not_in'] ?? null, 'Post IDs are a core argument, cast to int.');
        $this->assertArrayNotHasKey('meta_query', $args, 'An ID exclusion is not a meta clause.');
    }

    // -- harness --------------------------------------------------------------

    private function model(): NTDST_Data_Model
    {
        return new NTDST_Data_Model(
            'probe',
            [
                'status' => ['type' => 'text'],
                'kind' => ['type' => 'text'],
                'paid' => ['type' => 'text'],
                'archived_at' => ['type' => 'text'],
            ],
            self::PREFIX,
        );
    }

    /** The meta_query WordPress was actually handed by the one read made. */
    private function metaQuery(): array
    {
        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One read is one query.');

        return $GLOBALS['_ntdst_test_wp_query_args'][0]['meta_query'] ?? [];
    }
}
