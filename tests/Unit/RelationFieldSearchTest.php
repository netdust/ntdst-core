<?php // tests/Unit/RelationFieldSearchTest.php
declare(strict_types=1);
// The relation-field autocomplete's gate, its projection and its one widening.
//
// `NTDST_RelationField::handleRelationSearch()` is the admin picker's query
// surface, and Cluster A rewrote it whole — the derived allow-list replaced
// `canQueryPostType()`, the per-type `edit_others_posts` check replaced a
// blanket `edit_posts`, and three media gates (`canQueryUnpublishedMedia()`,
// `nonViewableMediaParentIds()`, T31's `post_parent__not_in`) were deleted as
// subsumed. It landed with NO test of its own: the gate reviewers spent five
// generations narrowing was, at that moment, asserted by nothing.
//
// This file is that assertion, and it is written BEFORE T10/T07 open the file
// again — so the next hand on it changes behaviour against a stated contract
// instead of against a reading of the diff.
//
// THREE CASES, and they are the three things the method decides:
//
//   1. THE DENIAL. A `post_type` the caller may not edit returns
//      `WP_Error('forbidden_post_type')` — and constructs NO `WP_Query`. The
//      second half is the one that matters and the one an assertion on the
//      return value alone would miss: a gate that denies AFTER running the
//      query has already done the work, and the next refactor that returns the
//      rows "since we have them" is a one-line mistake away.
//   2. THE PROJECTION. An allowed type returns rows of EXACTLY `id` and
//      `title`. metabox-fields.js reads those two and discards the rest, and
//      the removed `ntdst_get_formatted_posts()` used to attach a permalink, an
//      excerpt and two thumbnail URLs — an exact key list is what keeps a
//      future formatter from putting `content` back on an admin-search row.
//   3. THE WIDENING. `attachment` is stored `post_status = 'inherit'`, never
//      `publish`, so a picker scoped to attachments returns nothing unless the
//      query asks for both. Asserted on the ARGUMENTS WordPress was handed,
//      because the widening is invisible in the result set of a double.
//
// HOW IT OBSERVES. The allow-list is DERIVED from the registered schemas, so
// the fixture declares a real model through `NTDST_Data_Manager::register()` —
// the same door a module uses — rather than stubbing a private method out.
// `ntdst_data()` is defined at bootstrap and Patchwork refuses to redefine it
// (DefinedTooEarly), which is fine here: the Manager keeps its models in a
// static, so registering through a fresh Manager is what the singleton reads.
//
// The service is built with `newInstanceWithoutConstructor()`. Its constructor
// registers an action on `ntdst_actions()` and hooks `add_meta_boxes`; neither
// is part of what this file asserts, and requiring api/Actions.php to reach a
// pure method would drag the router into a test about a query.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/ServiceInterface.php';
require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

// The recording WP_Query. Guarded, and deliberately built on the SAME two
// globals DataSurfaceTest's double uses: a class is process-wide, so whichever
// file loads first defines it for both, and two doubles that record to
// different places would make this file pass or fail on test ORDER.
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

// `mayPickFrom()` reads the capability off the TYPE OBJECT, so the type object
// is what the fixture serves — with a `cap` bag shaped like WordPress's.
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

final class RelationFieldSearchTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** Capabilities the current caller holds, by name. */
    private array $caps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // admin/RelationField.php mounts `add_action('after_setup_theme', …)` at
        // FILE level, and neither the bootstrap nor the autoloader defines
        // add_action — Brain Monkey does, from setUp() above. So the shipped
        // file is required HERE, one line later, rather than at load time.
        require_once __DIR__ . '/../../admin/RelationField.php';

        $GLOBALS['_ntdst_test_wp_query_args'] = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];
        $this->caps = [];

        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('register_post_type')->justReturn(new stdClass());
        Functions\when('register_post_meta')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn();
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

    // -- 1. the denial ---------------------------------------------------------

    /**
     * A type the caller may not edit is refused, and no query is run.
     *
     * `artwork` IS a declared relation target — it passes the allow-list — so
     * this isolates the CAPABILITY half of the gate: the caller holds nothing,
     * `mayPickFrom()` says no, and the method must stop before WordPress is
     * asked anything at all.
     */
    public function testATypeTheCallerMayNotEditIsRefusedAndNoQueryIsRun(): void
    {
        $this->declareRelationTo('artwork');

        $result = $this->service()->handleRelationSearch(null, ['search' => 'blue', 'post_type' => 'artwork']);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame(
            'forbidden_post_type',
            $result->get_error_code(),
            'A post type the caller may not edit others of must be refused by code.',
        );
        $this->assertSame(
            [],
            $GLOBALS['_ntdst_test_wp_query_args'],
            'The denial must come BEFORE the query — a gate that runs the search first has '
                . 'already done the work it was meant to refuse.',
        );
    }

    // -- 2. the projection -----------------------------------------------------

    /**
     * An allowed type returns rows of exactly `id` and `title`.
     */
    public function testAnAllowedTypeReturnsRowsOfExactlyIdAndTitle(): void
    {
        $this->declareRelationTo('artwork');
        $this->caps = ['edit_others_artworks'];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [
            (object) ['ID' => '11', 'post_title' => 'Blue Nude', 'post_content' => 'a body', 'post_status' => 'publish'],
        ];

        $result = $this->service()->handleRelationSearch(null, ['search' => 'blue', 'post_type' => 'artwork']);

        $this->assertSame(
            ['results' => [['id' => 11, 'title' => 'Blue Nude']]],
            $result,
            'The picker row is an int id and a title — and nothing else: no permalink, no excerpt, '
                . 'no thumbnail, and above all no post_content on an admin search row.',
        );

        // And the query that produced it, whole. The four defaults below
        // `posts_per_page` are the ones the removed `ntdst_get_formatted_posts()`
        // applied to this call; they are what keeps the picker's result set the
        // same as it was before FR-4 took that helper away, and nothing else in
        // the suite reads them.
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
            'The picker asks WordPress for exactly these seven arguments.',
        );
    }

    // -- 3. the widening -------------------------------------------------------

    /**
     * An `attachment` search asks for `publish` AND `inherit`.
     *
     * Attachments are stored `inherit`, so the layer's `publish` default alone
     * renders a media picker that can never return a row. `publish` stays
     * beside it, because a mixed search must still find the other types' rows.
     */
    public function testAnAttachmentSearchWidensThePostStatusToPublishAndInherit(): void
    {
        $this->declareRelationTo('attachment');
        $this->caps = ['edit_others_attachments'];

        $this->service()->handleRelationSearch(null, ['search' => 'blue', 'post_type' => 'attachment']);

        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One search is one query.');

        $args = $GLOBALS['_ntdst_test_wp_query_args'][0];

        $this->assertSame(['publish', 'inherit'], $args['post_status']);
        $this->assertSame(['attachment'], $args['post_type']);
        $this->assertSame('blue', $args['s']);
    }

    // -- harness ---------------------------------------------------------------

    private function service(): NTDST_RelationField
    {
        return (new ReflectionClass(NTDST_RelationField::class))->newInstanceWithoutConstructor();
    }

    /**
     * Declare a model whose one relation field points at $target, so $target
     * becomes a derived allow-list entry — the way a real module earns one.
     */
    private function declareRelationTo(string $target): void
    {
        Functions\when('get_post_types')->justReturn(['exhibition']);

        (new NTDST_Data_Manager())->register('exhibition', [
            'label'        => 'Exhibition',
            'meta_prefix'  => '_ex_',
            'auto_metabox' => false,
            'fields'       => [
                'piece' => ['type' => 'relation', 'post_type' => $target],
            ],
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
