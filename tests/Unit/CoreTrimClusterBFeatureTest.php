<?php // tests/Unit/CoreTrimClusterBFeatureTest.php
declare(strict_types=1);
// core-trim Cluster B, at the FEATURE level: one query API, one logger, one
// hook spelling — seen the way a module sees them.
//
// The three tasks each pinned their own half (T04 the Data surface, T05 the
// Logger surface, T06 the six hook names). This file asks the question none of
// them can ask alone: does a REAL model — daan's `gig` shape, a dozen declared
// fields, a taxonomy and a scope — still go through its whole life against the
// trimmed core? Declared through NTDST_Data_Manager::register(), written with
// create()/update()/delete(), read back with ->withMeta()->withTerms()->get(),
// and logged through the one API that is left.
//
// WHY A FLOW AND NOT SIX UNIT CASES. Cluster B is three deletions and a rename,
// and the failure mode of that shape is not a broken method — it is a seam that
// only shows up in sequence: a hook that fires in the right place but the wrong
// order once a register() runs before it, a chain read that works on a bare
// model but not on a registered one, a logger call that was fine until the file
// that declares it stopped loading. So the six hooks are observed ACROSS one
// register → create → update → delete flow, in the order a listener would see
// them, with the payload it would receive.
//
// The denial half is the point of the file:
//   · wp_insert_post() returns WP_Error → `creating` fired, `created` NEVER,
//     and the caller gets the WP_Error back (INV-4, fail closed and loudly).
//   · wp_delete_post() returns false → `deleting` fired, `deleted` NEVER.
//     An "after" hook on a write that did not happen is a listener acting on a
//     row that still exists.
//   · the eight removed read helpers plus `getPostMeta()` are unreachable on
//     BOTH halves of the layer, and the global front door is gone.
//   · a registration that fails logs at ERROR through `ntdst_log()->error` —
//     and `ntdst_log_error()` / `ntdst_log_debug()` / `ntdst_log_info()` do not
//     exist to be called.
//
// HARNESS NOTES (the traps this suite has already paid for once).
//   · tests/bootstrap.php defines `ntdst_log()`, `add_filter()`, `sanitize_key()`
//     and `wp_unslash()` for real, before Patchwork. None of the four is stubbed
//     here; the log recorder in $GLOBALS['_ntdst_test_log'] is READ instead.
//   · services/Logger.php cannot be require'd in this process — it declares
//     ntdst_log() unconditionally and the bootstrap already has one, so the
//     require is a redeclare fatal. The class half is eval'd out of the shipped
//     source, the pattern LoggerSurfaceTest established.
//   · WP_Query and WP_Error are declared here under class_exists guards, on the
//     same $GLOBALS protocol DataSurfaceTest uses, so either load order works.
//     The error object this file throws at the layer is a SUBCLASS: whichever
//     file's WP_Error wins the declaration, the subclass is guaranteed to answer
//     get_error_message(), which the failure logger calls.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';
require_once __DIR__ . '/../../core/LogLevel.php';

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

/** A WP_Error that answers code AND message, whichever stub won the base class. */
class NTDST_ClusterB_Refusal extends WP_Error
{
    public function __construct(private string $refusalCode = 'refused', private string $refusalMessage = 'WordPress said no')
    {
        parent::__construct($refusalCode, $refusalMessage);
    }

    public function get_error_code(): string
    {
        return $this->refusalCode;
    }

    public function get_error_message(): string
    {
        return $this->refusalMessage;
    }
}

final class CoreTrimClusterBFeatureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TYPE = 'gig';

    private const PREFIX = '_gig_';

    private const POST_ID = 4242;

    /** The six spellings FR-11 retired. A listener on any of them is inert. */
    private const RETIRED_HOOKS = [
        'ntdst_model_create_before',
        'ntdst_model_create_after',
        'ntdst_model_update_before',
        'ntdst_model_update_after',
        'ntdst_model_delete_before',
        'ntdst_model_delete_after',
    ];

    /** FR-4's removals, as a module would try to call them. */
    private const REMOVED_READ_HELPERS = [
        'getFormattedPosts',
        'getPostMeta',
        'getPostTerms',
        'attachTerms',
        'syncTerms',
        'detachTerms',
        'whereDate',
        'orWhere',
    ];

    /** daan's gig, declared: a dozen fields across a dozen types. */
    private const GIG_FIELDS = [
        'venue'        => ['type' => 'text'],
        'city'         => ['type' => 'text'],
        'country'      => ['type' => 'text'],
        'starts_at'    => ['type' => 'date'],
        'doors_at'     => ['type' => 'date'],
        'ticket_url'   => ['type' => 'url'],
        'promoter'     => ['type' => 'email'],
        'fee'          => ['type' => 'float'],
        'capacity'     => ['type' => 'int'],
        'sold_out'     => ['type' => 'bool'],
        'support_act'  => ['type' => 'text'],
        'rider_notes'  => ['type' => 'textarea'],
    ];

    /** @var list<array{0: string, 1: array<int, mixed>}> every model hook seen, in order */
    private array $fired = [];

    /** @var array<string, mixed> the row's meta, as the write path leaves it */
    private array $stored = [];

    /** @var array<int, array<int, mixed>> every register_post_type() call */
    private array $postTypeCalls = [];

    /** @var array<int, array<int, mixed>> every register_taxonomy() call */
    private array $taxonomyCalls = [];

    /** What register_post_type() answers — an object, or a refusal. */
    private mixed $postTypeAnswer = null;

    /** What wp_insert_post() answers. */
    private mixed $insertAnswer = self::POST_ID;

    /** What wp_delete_post() answers. */
    private mixed $deleteAnswer = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The Logger's only filesystem anchor; nothing here writes a line, but
        // the class names the constant at load.
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ntdst-clusterb-' . getmypid() . '-' . uniqid());
        }

        if (!class_exists('NTDST_Logger', false)) {
            eval(self::loggerClassSource());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->fired = [];
        $this->stored = [];
        $this->postTypeCalls = [];
        $this->taxonomyCalls = [];
        $this->postTypeAnswer = new stdClass();
        $this->insertAnswer = self::POST_ID;
        $this->deleteAnswer = true;

        $GLOBALS['_ntdst_test_log'] = [];
        $GLOBALS['_ntdst_test_wp_query_args'] = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];

        $this->forgetRegisteredModels();
        $this->stubWordPress();
    }

    protected function tearDown(): void
    {
        $this->forgetRegisteredModels();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------------
    // 1. the lifecycle, end to end
    // ------------------------------------------------------------------------

    /**
     * FR-11 / SC-4, as a listener experiences it. One registered model, one
     * create, one update, one delete — six hooks, in that order, each carrying
     * the post type, the id where the old spelling carried one, and the data.
     *
     * The retired six are pinned NEVER across the SAME flow. A rename that
     * added the new name beside the old one would satisfy every positive
     * assertion above and leave the duplicate spelling in place.
     */
    public function testTheGigLifecycleFiresTheSixModelHooksInOrderWithTheirArguments(): void
    {
        $this->watchModelHooks();

        $model = $this->registerGig();
        $this->assertInstanceOf(NTDST_Data_Model::class, $model, 'A declared model comes back from register().');

        $created = ['venue' => 'AB', 'city' => 'Brussels', 'capacity' => 800, 'sold_out' => false];
        $model->create($created);
        $model->update(self::POST_ID, ['venue' => 'Ancienne Belgique', 'sold_out' => true]);
        $model->delete(self::POST_ID, true);

        $this->assertSame(
            [
                'ntdst/model/creating',
                'ntdst/model/created',
                'ntdst/model/updating',
                'ntdst/model/updated',
                'ntdst/model/deleting',
                'ntdst/model/deleted',
            ],
            array_column($this->fired, 0),
            'The six hooks fire once each, in lifecycle order, across one flow.',
        );

        $this->assertHookArgs(0, ['ntdst/model/creating', self::TYPE], 2);
        $this->assertHookArgs(1, ['ntdst/model/created', self::TYPE, self::POST_ID], 3);
        $this->assertHookArgs(2, ['ntdst/model/updating', self::TYPE, self::POST_ID], 3);
        // `updated` carries a fourth argument (the before-state), `deleting`/
        // `deleted` a third (the pre-delete snapshot) — the audit payloads
        // added for ntdst-audit coverage. Appended args, so a listener
        // registered at the old arity keeps working.
        $this->assertHookArgs(3, ['ntdst/model/updated', self::TYPE, self::POST_ID], 4);
        $this->assertHookArgs(4, ['ntdst/model/deleting', self::TYPE, self::POST_ID], 3);
        $this->assertHookArgs(5, ['ntdst/model/deleted', self::TYPE, self::POST_ID], 3);

        // The payload a listener prunes on: the fields the caller wrote.
        $this->assertPayloadCarries($this->fired[0][1][1], ['venue' => 'AB', 'city' => 'Brussels']);
        $this->assertPayloadCarries($this->fired[1][1][2], ['venue' => 'AB', 'city' => 'Brussels']);
        $this->assertPayloadCarries($this->fired[2][1][2], ['venue' => 'Ancienne Belgique']);
        $this->assertPayloadCarries($this->fired[3][1][2], ['venue' => 'Ancienne Belgique']);
    }

    /** The same flow, watched for the old names. Six `->never()`. */
    public function testTheRetiredHookNamesNeverFireAnywhereInTheLifecycle(): void
    {
        foreach (self::RETIRED_HOOKS as $retired) {
            Actions\expectDone($retired)->never();
        }

        $model = $this->registerGig();
        $model->create(['venue' => 'AB']);
        $model->update(self::POST_ID, ['venue' => 'AB']);
        $model->delete(self::POST_ID, true);

        $this->assertTrue(true, 'The `->never()` expectations above are the assertion.');
    }

    /**
     * The declaration reached WordPress whole: the post type, the taxonomy
     * declared WITH the model, and the meta keys for the twelve fields. This is
     * the half that makes the flow above a REAL model rather than a bare one.
     */
    public function testRegisteringTheGigDeclaresItsPostTypeItsTaxonomyAndItsFields(): void
    {
        $this->registerGig();

        $this->assertCount(1, $this->postTypeCalls, 'One model is one register_post_type() call.');
        $this->assertSame(self::TYPE, $this->postTypeCalls[0][0]);

        $this->assertCount(1, $this->taxonomyCalls, 'The taxonomy declared in the config is registered with the model.');
        $this->assertSame('gig_genre', $this->taxonomyCalls[0][0]);
        $this->assertSame(self::TYPE, $this->taxonomyCalls[0][1], 'The taxonomy is attached to THIS post type.');
    }

    // ------------------------------------------------------------------------
    // 2. the failure half of the write path
    // ------------------------------------------------------------------------

    /**
     * FR-11 + INV-4. WordPress refuses the insert: the "before" hook has already
     * fired (a listener may have been asked to prepare), the "after" hook must
     * NOT — nothing was created — and the caller gets the WP_Error rather than
     * an id it would go on to use.
     */
    public function testARefusedInsertFiresCreatingNeverCreatedAndHandsBackTheError(): void
    {
        $this->insertAnswer = new NTDST_ClusterB_Refusal('invalid_post_type', 'no such post type');

        Actions\expectDone('ntdst/model/creating')->once();
        Actions\expectDone('ntdst/model/created')->never();

        $result = $this->registerGig()->create(['venue' => 'AB', 'city' => 'Brussels']);

        $this->assertInstanceOf(WP_Error::class, $result, 'A refused write is returned, never swallowed.');
        $this->assertSame('invalid_post_type', $result->get_error_code());
    }

    /**
     * The delete mirror. `wp_delete_post()` answering false is a delete that did
     * not happen; a `deleted` hook there tells every listener to clean up after
     * a row that is still in the database.
     */
    public function testARefusedDeleteFiresDeletingAndNeverDeleted(): void
    {
        $this->deleteAnswer = false;

        Actions\expectDone('ntdst/model/deleting')->once();
        Actions\expectDone('ntdst/model/deleted')->never();

        $result = $this->registerGig()->delete(self::POST_ID, true);

        $this->assertNotTrue($result, 'A delete WordPress refused does not report success.');
    }

    // ------------------------------------------------------------------------
    // 3. one query API
    // ------------------------------------------------------------------------

    /**
     * FR-4. The chain is the one way to read rows, so it has to answer what the
     * removed second path answered: a row carrying its meta AND its terms —
     * here through a REGISTERED model taken out of the registry, with a declared
     * scope narrowing the query.
     *
     * The meta bag is asserted PROJECTED (`venue`, not `_gig_venue`): a read
     * that returned the raw bag would hand every undeclared key to whoever
     * called get().
     */
    public function testTheChainReadsARegisteredGigWithItsMetaItsTermsAndItsScope(): void
    {
        $this->registerGig();
        $this->seedOneGigRow();

        $rows = (new NTDST_Data_Manager())->get(self::TYPE)
            ->scope('confirmed')
            ->withMeta()
            ->withTerms()
            ->get();

        $this->assertCount(1, $rows, 'One seeded row is one row back.');

        $this->assertArrayHasKey('meta', $rows[0], 'withMeta() puts the meta bag on the row.');
        $this->assertSame('AB', $rows[0]['meta']['venue'] ?? null, 'The bag is read back under the DECLARED name.');
        $this->assertArrayNotHasKey(self::PREFIX . 'venue', $rows[0]['meta'], 'The storage prefix is not the read vocabulary.');

        $this->assertArrayHasKey('terms', $rows[0], 'withTerms() puts the terms on the row.');
        $this->assertSame(
            ['gig_genre' => [['id' => 12, 'name' => 'Jazz', 'slug' => 'jazz']]],
            $rows[0]['terms'],
            'Terms stay grouped by taxonomy and reduced to id/name/slug.',
        );

        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One read is one query.');
        $args = $GLOBALS['_ntdst_test_wp_query_args'][0];
        $this->assertSame(self::TYPE, $args['post_type']);
        $this->assertStringContainsString(
            self::PREFIX . 'status',
            json_encode($args['meta_query'] ?? [], JSON_THROW_ON_ERROR),
            "The model's declared scope narrowed the query it was asked to narrow.",
        );
    }

    /**
     * FR-4's denial half, at the level a consumer meets it: every removed read
     * helper is unreachable on BOTH halves of the layer, and the global front
     * door is gone. `getPostMeta()` is in the list — it left with T04 and is the
     * one name the removal sweep did not pin at the time.
     */
    public function testNoRemovedReadHelperIsReachableOnEitherHalfOfTheDataLayer(): void
    {
        foreach (self::REMOVED_READ_HELPERS as $method) {
            $this->assertFalse(
                method_exists(NTDST_Data_Manager::class, $method),
                "NTDST_Data_Manager::{$method}() was removed by FR-4 and must not be callable.",
            );
            $this->assertFalse(
                method_exists(NTDST_Data_Model::class, $method),
                "NTDST_Data_Model::{$method}() was removed by FR-4 and must not be callable — "
                    . 'moving a removed helper down a class is not removing it.',
            );
        }

        $this->assertFalse(
            function_exists('ntdst_get_formatted_posts'),
            'The global front door onto the second query API is gone with it.',
        );
    }

    // ------------------------------------------------------------------------
    // 4. one logger
    // ------------------------------------------------------------------------

    /**
     * FR-5 + INV-4, as the DATA layer uses the logger. register_post_type()
     * refuses, so registration fails: the layer says so at ERROR level through
     * `ntdst_log()`, names the model, and returns the WP_Error.
     *
     * The channel matters as much as the level — `ntdst_log('data')` is what
     * routes the line, and it is the only routing left now that addHandler()
     * and setMinLevel() are gone.
     */
    public function testAFailedRegistrationIsLoggedAtErrorThroughTheKeptLoggerApi(): void
    {
        $this->postTypeAnswer = new NTDST_ClusterB_Refusal('reserved_name', 'that name is reserved');

        $result = $this->registerGig();

        $this->assertInstanceOf(WP_Error::class, $result, 'A refused registration is returned, not swallowed.');

        $errors = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'] ?? [],
            static fn(array $line): bool => ($line[1] ?? '') === 'error',
        ));

        $this->assertCount(1, $errors, 'A failed registration says so once, at error level.');
        $this->assertSame('data', $errors[0][0], "The line is routed on the layer's own channel.");
        $this->assertStringContainsString(self::TYPE, (string) $errors[0][2], 'The line names the model that failed.');
    }

    /**
     * The other half of "through the kept API only": the three global level
     * helpers FR-5 retired do not exist, so no call site can reach a logger any
     * other way. A deletion that left one of them behind would keep a second
     * path to the log open, and it would be the path with no channel.
     */
    public function testTheRetiredGlobalLogHelpersAreNotDefined(): void
    {
        foreach (['ntdst_log_debug', 'ntdst_log_info', 'ntdst_log_error'] as $retired) {
            $this->assertFalse(
                function_exists($retired),
                "{$retired}() was removed by FR-5; ntdst_log(\$channel) is the API.",
            );
        }

        $this->assertTrue(function_exists('ntdst_log'), 'ntdst_log() is what is left, and it is there.');
    }

    // ------------------------------------------------------------------------
    // 5. the public surface, as one table
    // ------------------------------------------------------------------------

    /**
     * SC-3 at the feature level. The three classes a module touches, pinned
     * TOGETHER as one table, because the promise Cluster B makes is about the
     * shape of the LAYER, not about three separate classes: "declare in Data,
     * log through Logger, and there is one of each".
     *
     * Exact lists, not a set of absences. An absence assertion passes again the
     * day a fourth helper arrives under a new name; a list fails on a name
     * coming back AND on one that never left.
     *
     * The expected lists are FR-4's enumeration of Data's surface and the plan's
     * `services/Logger.php — after T05: exactly these public members` block,
     * transcribed, not read off the classes.
     */
    public function testTheDataLayerAndTheLoggerHaveExactlyTheirDeclaredPublicSurface(): void
    {
        $expected = [
            'NTDST_Data_Manager' => [
                'addScope', 'get', 'getScope', 'isRegistered', 'register', 'registerTaxonomy',
            ],
            'NTDST_Data_Model' => [
                '__construct',
                'all', 'count', 'create', 'delete', 'deleteMeta', 'find', 'first', 'get', 'getMeta',
                'getMetaPrefix', 'getSchema', 'limit', 'orderBy', 'paginate', 'registerRestMeta',
                'restFields', 'scope', 'update', 'updateMeta', 'updateMetaBatch', 'where', 'whereGroup',
                'whereIn', 'whereMissing', 'whereNot', 'whereNotIn', 'whereTax', 'withMeta', 'withTerms',
            ],
            'NTDST_Logger' => [
                '__construct', 'critical', 'debug', 'error', 'flush', 'flushBatchedLogs', 'info', 'warning',
            ],
        ];

        $actual = [];
        foreach (array_keys($expected) as $class) {
            $names = array_map(
                static fn(ReflectionMethod $m): string => $m->getName(),
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            );
            sort($names);
            $actual[$class] = $names;
        }

        $this->assertSame(
            $expected,
            $actual,
            'One query API, one logger: the public surface of the three classes a module '
                . 'touches is exactly what FR-4 and FR-5 leave behind.',
        );
    }

    // ------------------------------------------------------------------------
    // harness
    // ------------------------------------------------------------------------

    /** Declare daan's gig the way a module does — through the Manager. */
    private function registerGig(): mixed
    {
        return (new NTDST_Data_Manager())->register(self::TYPE, [
            'label'        => 'Gigs',
            'meta_prefix'  => self::PREFIX,
            'auto_metabox' => false,
            'fields'       => array_merge(self::GIG_FIELDS, ['status' => ['type' => 'text']]),
            'taxonomies'   => [
                'gig_genre' => ['hierarchical' => false, 'terms' => ['jazz' => 'Jazz']],
            ],
            'scopes'       => [
                'confirmed' => static fn(NTDST_Data_Model $model) => $model->where('status', 'confirmed'),
            ],
        ]);
    }

    /** Record every model hook, in order, with its arguments. */
    private function watchModelHooks(): void
    {
        foreach (['creating', 'created', 'updating', 'updated', 'deleting', 'deleted'] as $stage) {
            $hook = "ntdst/model/{$stage}";
            Actions\expectDone($hook)->once()->whenHappen(function (...$args) use ($hook): void {
                $this->fired[] = [$hook, $args];
            });
        }
    }

    /**
     * @param array<int, mixed> $head the hook name followed by its leading arguments
     */
    private function assertHookArgs(int $index, array $head, int $arity): void
    {
        $hook = array_shift($head);

        $this->assertSame($hook, $this->fired[$index][0] ?? null);
        $this->assertCount(
            $arity,
            $this->fired[$index][1],
            "{$hook} must carry {$arity} arguments — a listener with the wrong arity is as inert as one on a dead name.",
        );

        foreach ($head as $position => $value) {
            $this->assertSame($value, $this->fired[$index][1][$position], "{$hook} argument {$position}.");
        }
    }

    /**
     * @param mixed                $payload
     * @param array<string, mixed> $expected
     */
    private function assertPayloadCarries($payload, array $expected): void
    {
        $this->assertIsArray($payload, 'The data argument is the array the caller wrote.');

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertSame($value, $payload[$key], "The payload carries the caller's '{$key}'.");
        }
    }

    /** No test may inherit another's registry: the models are static. */
    private function forgetRegisteredModels(): void
    {
        $models = new ReflectionProperty(NTDST_Data_Manager::class, 'models');
        $models->setAccessible(true);
        $models->setValue(null, []);
    }

    /**
     * One published gig, its meta and its one term warm in the caches WordPress
     * primes on the query — the state the row formatter reads.
     */
    private function seedOneGigRow(): void
    {
        $GLOBALS['_ntdst_test_wp_query_posts'] = [
            (object) [
                'ID'            => self::POST_ID,
                'post_type'     => self::TYPE,
                'post_title'    => 'AB, Brussels',
                'post_excerpt'  => 'a night',
                'post_content'  => 'a body',
                'post_password' => '',
                'post_name'     => 'ab-brussels',
                'post_date'     => '2026-09-01 20:00:00',
                'post_modified' => '2026-09-01 20:00:00',
                'post_author'   => 3,
            ],
        ];
    }

    /** Brain Monkey at WordPress's edge, and nowhere else. */
    private function stubWordPress(): void
    {
        Functions\when('register_post_type')->alias(function (...$args) {
            $this->postTypeCalls[] = $args;

            return $this->postTypeAnswer;
        });
        Functions\when('register_taxonomy')->alias(function (...$args) {
            $this->taxonomyCalls[] = $args;

            return true;
        });
        Functions\when('register_post_meta')->justReturn(true);
        Functions\when('term_exists')->justReturn(false);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 12]);
        Functions\when('taxonomy_exists')->justReturn(true);

        Functions\when('apply_filters')->returnArg(2);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);

        // sanitizers: plain real-equivalents. This file asks which hook fired
        // with what, and what came back off a read; a tagged value would only
        // obscure both.
        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('sanitize_email')->returnArg(1);
        Functions\when('sanitize_title')->alias(
            static fn($v) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)),
        );
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('wp_validate_boolean')->alias(static fn($v) => (bool) $v);
        Functions\when('wp_strip_all_tags')->alias(static fn($v) => strip_tags((string) $v));
        Functions\when('wp_parse_args')->alias(
            static fn($args, $defaults = []) => array_merge((array) $defaults, (array) $args),
        );
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('maybe_serialize')->alias(static fn($v) => is_scalar($v) ? (string) $v : serialize($v));

        // the row store
        Functions\when('get_post')->alias(static fn($id) => (object) [
            'ID'          => (int) $id,
            'post_type'   => self::TYPE,
            'post_status' => 'publish',
            'post_title'  => 'AB, Brussels',
        ]);
        Functions\when('get_post_type')->justReturn(self::TYPE);
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->stored[$key] = $value;

            return true;
        });
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->stored[$key] ?? '');
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->stored[$key]);

            return true;
        });
        Functions\when('metadata_exists')->alias(fn($t, $id, $key) => array_key_exists($key, $this->stored));

        // the writers
        Functions\when('wp_insert_post')->alias(fn(...$args) => $this->insertAnswer);
        Functions\when('wp_update_post')->alias(static fn($data) => (int) ($data['ID'] ?? self::POST_ID));
        Functions\when('wp_delete_post')->alias(fn(...$args) => $this->deleteAnswer);
        Functions\when('wp_trash_post')->alias(fn(...$args) => $this->deleteAnswer);

        // the read path
        Functions\when('post_password_required')->justReturn(false);
        Functions\when('get_permalink')->justReturn('https://example.test/ab-brussels');
        Functions\when('mysql2date')->alias(static fn($format, $date) => $date);
        Functions\when('get_the_author_meta')->justReturn('A Writer');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_object_taxonomies')->justReturn(['gig_genre']);
        Functions\when('user_can')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_cache_get')->alias(static function ($id = null, $group = '') {
            if ($group === 'post_meta') {
                return [
                    self::PREFIX . 'venue'  => ['AB'],
                    self::PREFIX . 'city'   => ['Brussels'],
                    self::PREFIX . 'status' => ['confirmed'],
                ];
            }

            if ($group === 'gig_genre_relationships') {
                return [(object) ['term_id' => 12, 'name' => 'Jazz', 'slug' => 'jazz']];
            }

            return false;
        });
    }

    /**
     * The class half of services/Logger.php, comments stripped.
     *
     * The file declares ntdst_log() unconditionally and tests/bootstrap.php
     * already declares one for the whole suite, so requiring it here is a
     * redeclare fatal. Only the class is eval'd — the surface is what this file
     * asks about.
     */
    private static function loggerClassSource(): string
    {
        $source = __DIR__ . '/../../services/Logger.php';

        $code = '';
        foreach (token_get_all((string) file_get_contents($source)) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+NTDST_Logger\b/m', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('services/Logger.php no longer declares class NTDST_Logger at the top level.');
        }

        $from = (int) $m[0][1];
        $end = strpos($code, "\n}\n", $from);

        if ($end === false) {
            throw new RuntimeException('Could not find the column-0 closing brace of class NTDST_Logger.');
        }

        return substr($code, $from, $end - $from + 3);
    }
}
