<?php // tests/Unit/MetaboxGeneratorSaveTest.php
// The edit screen saves through the ONE vocabulary — and sanitizes exactly once.
//
// This is the RED contract for field-types T05 (Tier A, stakes high). It asserts
// the Cluster C promise — "a metabox save of a Data model reaches the model
// unsanitized and is sanitized exactly once by the model; a save on a non-Data
// post type is sanitized exactly once by the registry" — plus FR-5 (an int keeps
// its sign on EVERY write path, the edit screen included) and SC-4.
//
// FIVE PROMISES, in the order this file asserts them:
//
//   1. THE MODEL GETS WHAT THE EDITOR TYPED. On a Data-model post type the save
//      path unslashes and hands the value STRAIGHT to update(). It does not
//      clean it first: the model's own registry-bound sanitizer is the one
//      answer, and a value cleaned on the way in and cleaned again inside the
//      model is a value cleaned by two tables that can disagree (INV-8).
//   2. ONCE MEANS ONCE, AND NEVER ZERO. The registry entry for each field runs
//      exactly one time per submitted field — counted, because a value that has
//      been sanitized twice by IDEMPOTENT functions looks identical to one
//      sanitized once. A field the model does not declare runs no model
//      sanitizer at all, and is still never stored as it was posted.
//   3. THE VOCABULARY'S OWN ANSWERS, NOT THE METABOX'S. `int '-500'` keeps its
//      sign (absint() was the bug), `bool 'false'` is FALSE (WordPress's word:
//      the old `(bool) $value` arm answered true), a repeater row whose only
//      answer is `'0'` is KEPT and a row with no answer at all is dropped, and
//      a relation absent from the POST is the empty list.
//   4. A SANITIZER THAT THROWS IS A NOTICE, NEVER A WHITE SCREEN. The
//      unslash/sanitize loop runs INSIDE the save's try, so an unknown type in
//      a field declaration surfaces as the editor-facing save-error notice with
//      NOTHING written — not a fatal that loses every field on the screen.
//   5. THE SECOND TABLE IS GONE. NTDST_MetaboxGenerator has no sanitize_field()
//      of its own; PackageBootIntegrityTest's removed-symbol sweep pins the name.
//
// HOW THIS FILE OBSERVES ALL OF THAT
// Through save_metabox_data() — the callback WordPress itself fires on
// save_post — and through render_save_error_notice(), which is what the editor
// actually SEES after a failed save. Nothing private is reached; the generator
// is built without its constructor only so the test does not mount four hooks.
//
// The WordPress stubs are DataReadsTheVocabularyTest's, verbatim in shape:
// TAGGED where the question is WHICH function ran (a pass-through stub cannot
// tell sanitize_text_field() from sanitize_textarea_field(), so the wrong wiring
// would pass), real-equivalent where WordPress's own answer is the point, and
// COUNTED because promise 2 is a question about how many times. sanitize_key()
// and wp_unslash() are REAL functions from tests/bootstrap.php and are never
// stubbed here — wp_unslash() had to become one for this file: Monkey-patching
// it defines it process-wide, which flipped support/ClientIp.php's
// function_exists() guard and broke six later REST test files.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';
require_once __DIR__ . '/../../admin/MetaboxGenerator.php';

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->msg; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_status = 'publish';
    }
}

defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);

/**
 * The model the edit screen saves into — a RECORDER in front of the real thing.
 *
 * update() records the array it was handed BEFORE anything touches it (that is
 * promise 1, and it can only be seen from the model's side of the call), then
 * runs the model's REAL sanitize chain — registry entry first, declared
 * `sanitizer` override on its output — so promise 2 is counted against the
 * shipped closures rather than against a hand-written imitation of them.
 *
 * Everything is static because NTDST_Data_Manager::get() hands out a CLONE:
 * an instance property recorded on the clone is invisible to the test that
 * built the original.
 */
final class MetaboxSaveRecordingModel extends NTDST_Data_Model
{
    /** @var list<array{0: int, 1: array<string, mixed>}> */
    public static array $updateCalls = [];

    /** @var list<array<string, mixed>> */
    public static array $createCalls = [];

    /** What the model's own sanitize chain made of the last update(). */
    public static array $sanitized = [];

    public static function reset(): void
    {
        self::$updateCalls = [];
        self::$createCalls = [];
        self::$sanitized = [];
    }

    public function find(int $id, $status = 'publish')
    {
        // The row exists — the edit screen is editing it. Returned as a plain
        // object so this file makes no claim about the ORM's read path, which
        // is not what T05 promises.
        return (object) ['ID' => $id, 'post_type' => $this->post_type];
    }

    public function update(int $id, array $data)
    {
        self::$updateCalls[] = [$id, $data];
        self::$sanitized = $this->sanitizeData($data);

        return (object) ['ID' => $id];
    }

    public function create(array $data)
    {
        self::$createCalls[] = $data;

        return (object) ['ID' => 1];
    }
}

final class MetaboxGeneratorSaveTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The post on daan's edit screen (Cluster C's observable). */
    private const POST_ID = 297050;

    /** The post meta this process pretends to have. Keys as the metabox writes them. */
    private array $meta = [];

    /** Every update_post_meta() call, in order: [key, value]. */
    private array $metaWrites = [];

    /** Every delete_post_meta() call, in order: key. */
    private array $metaDeletes = [];

    /** Transients, so the save-error notice can be read back the way the editor reads it. */
    private array $transients = [];

    /** How many times each WordPress sanitizer ran. Promise 2 is a counting question. */
    private array $calls = [];

    /** How many times each field's declared `sanitizer` override ran. */
    private array $spy = [];

    /** The manager's model table, restored in tearDown — it is STATIC and process-wide. */
    private mixed $modelsBackup = null;

    /** One generator per test — its own field declarations, no bleed between cases. */
    private ?NTDST_MetaboxGenerator $generator = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->meta = [];
        $this->metaWrites = [];
        $this->metaDeletes = [];
        $this->transients = [];
        $this->calls = [];
        $this->spy = [];
        $_POST = [];
        $_GET = [];
        $GLOBALS['_ntdst_test_log'] = [];
        MetaboxSaveRecordingModel::reset();
        $this->generator = null;

        $this->modelsBackup = $this->modelTable()->getValue();

        // ---- sanitizers: tagged once, and counted ----
        $tagged = static function (string $tag, callable $inner): Closure {
            return static function ($value) use ($tag, $inner) {
                $raw = is_scalar($value) ? (string) $value : '';
                if (trim($raw) === '') {
                    return '';
                }
                if (str_starts_with($raw, $tag . ':')) {
                    return $raw;
                }

                return $tag . ':' . $inner($raw);
            };
        };

        $strip = static fn(string $raw): string => trim(strip_tags($raw));

        $this->stub('sanitize_text_field', $tagged('text', $strip));
        $this->stub('sanitize_textarea_field', $tagged('textarea', $strip));
        $this->stub('sanitize_email', $tagged('email', static fn(string $raw): string => (string) preg_replace('/[^A-Za-z0-9.@_+\-]/', '', $raw)));
        $this->stub('wp_kses_post', $tagged('kses', static fn(string $raw): string => (string) preg_replace('@</?(script|style)[^>]*>@i', '', $raw)));
        $url = $tagged('url', static fn(string $raw): string => $raw);
        $this->stub('esc_url_raw', static function ($value) use ($url) {
            $raw = ltrim((string) $value);

            return stripos($raw, 'javascript:') === 0 ? '' : $url($value);
        });

        // ---- real-equivalents: WordPress's own algorithm, no tag ----
        $this->stub('absint', static fn($value) => abs((int) $value));
        $this->stub('wp_validate_boolean', static function ($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value) && 'false' === strtolower($value)) {
                return false;
            }

            return (bool) $value;
        });
        Functions\when('esc_html')->alias(static fn($text) => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('get_post_type')->alias(
            static fn($post = null) => ((int) $post >= 1 && (int) $post <= 99) ? 'attachment' : 'post',
        );
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('maybe_serialize')->alias(static fn($v) => is_scalar($v) ? (string) $v : serialize($v));

        // ---- the gate: open unless a test closes it ----
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);

        // ---- hooks: recorded, never fired ----
        Functions\when('add_action')->justReturn(true);
        Functions\when('remove_action')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('apply_filters')->returnArg(2);

        // ---- the store ----
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[$key] = $value;
            $this->metaWrites[] = [$key, $value];

            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->meta[$key]);
            $this->metaDeletes[] = $key;

            return true;
        });
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->meta[$key] ?? '');
        Functions\when('metadata_exists')->alias(fn($type, $id, $key) => array_key_exists($key, $this->meta));
        Functions\when('get_post')->alias(static fn($id) => (object) [
            'ID'          => (int) $id,
            'post_type'   => 'gig',
            'post_status' => 'publish',
        ]);

        // ---- the editor-facing channel ----
        Functions\when('set_transient')->alias(function ($key, $value, $expiry = 0) {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn($key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function ($key) {
            unset($this->transients[$key]);

            return true;
        });
    }

    protected function tearDown(): void
    {
        $this->modelTable()->setValue(null, $this->modelsBackup);
        $_POST = [];
        $_GET = [];

        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------- promise 1

    public function testADataModelIsHandedThePostedValueExactlyAsTheEditorTypedIt(): void
    {
        $this->registerGig();
        $this->submit('gig', ['venue_city' => '  <b>x</b>  ']);

        $this->save('gig');

        $this->assertCount(1, MetaboxSaveRecordingModel::$updateCalls, 'The save must reach the model exactly once.');

        [$id, $data] = MetaboxSaveRecordingModel::$updateCalls[0];

        $this->assertSame(self::POST_ID, $id);
        $this->assertSame(
            '  <b>x</b>  ',
            $data['venue_city'] ?? null,
            'The metabox must hand a Data model the UNSLASHED post value, unchanged: '
                . 'the model is the one place that cleans it, and a value cleaned twice is cleaned by two tables.',
        );
        $this->assertSame(
            'text:x',
            MetaboxSaveRecordingModel::$sanitized['venue_city'] ?? null,
            'And the model must still clean it — once must never become zero.',
        );
    }

    // ------------------------------------------------------------- promise 2

    public function testADataModelFieldIsSanitizedExactlyOnceOnTheWayIn(): void
    {
        $this->registerGig();
        $this->submit('gig', [
            'venue_city' => '  <b>x</b>  ',
            'bio'        => "  <i>line</i>\n\nmore  ",
        ]);

        $this->save('gig');

        $this->assertSame(
            1,
            $this->calls['sanitize_textarea_field'] ?? 0,
            "A `textarea` field's registry entry must run ONCE per save. Twice means the metabox "
                . 'cleaned it before the model did — the second table T05 deletes.',
        );
        $this->assertSame(
            1,
            $this->calls['sanitize_text_field'] ?? 0,
            "A `text` field's registry entry must run ONCE per save.",
        );
        $this->assertSame(
            1,
            $this->spy['venue_city'] ?? 0,
            "A field's declared `sanitizer` composes on the registry's output, exactly once per submitted field.",
        );
        $this->assertSame(
            1,
            $this->spy['bio'] ?? 0,
            "A field's declared `sanitizer` composes on the registry's output, exactly once per submitted field.",
        );
    }

    public function testAFieldTheModelDoesNotDeclareRunsNoModelSanitizer(): void
    {
        $this->registerGig();
        $this->submit('gig', ['venue_city' => '  <b>x</b>  ', 'ghost' => '<b>boo</b>']);

        $this->save('gig');

        $this->assertSame(1, $this->spy['venue_city'] ?? 0, 'A declared field runs its own sanitizer once.');
        $this->assertSame(
            0,
            $this->spy['ghost'] ?? 0,
            'A field the model does not declare has no declared sanitizer to run — it must not borrow another field\'s.',
        );
        $this->assertArrayHasKey(
            'ghost',
            MetaboxSaveRecordingModel::$sanitized,
            'An undeclared key still reaches the model and is still cleaned there — never stored as it was posted.',
        );
        $this->assertSame(
            'text:boo',
            MetaboxSaveRecordingModel::$sanitized['ghost'],
            'An undeclared key gets the text answer, from the model, once.',
        );
    }

    // ------------------------------------------------------------- promise 3

    public function testAnIntFieldSavedFromTheEditScreenKeepsItsSign(): void
    {
        // FR-5, reviewer IMP-2 / sentinel C1: absint() on the save path stripped
        // the sign, and a discount in cents is a negative int. "Every write path"
        // includes the edit screen — on BOTH branches.
        $this->registerGig();
        $this->submit('gig', ['discount' => '-500']);

        $this->save('gig');

        $this->assertSame(
            '-500',
            MetaboxSaveRecordingModel::$updateCalls[0][1]['discount'] ?? null,
            'A Data model is handed the posted string unchanged; the model casts it.',
        );
        $this->assertSame(
            -500,
            MetaboxSaveRecordingModel::$sanitized['discount'] ?? null,
            'And the registry casts a signed int to a signed int.',
        );

        $this->registerNote();
        $this->submit('note', ['discount' => '-500']);

        $this->save('note');

        $this->assertSame(
            -500,
            $this->meta['discount'] ?? null,
            'A non-Data post type stores the SAME answer: absint() is not the vocabulary\'s word for `int`.',
        );
    }

    public function testABoolFieldSavedAsTheStringFalseStoresFalse(): void
    {
        $this->registerNote();
        $this->submit('note', ['featured' => 'false']);

        $this->save('note');

        $this->assertFalse(
            $this->meta['featured'] ?? null,
            "WordPress's word for `bool`: the exact string 'false' is FALSE. `(bool) 'false'` answered true.",
        );
    }

    public function testABoolFieldSavedAsZeroStoresFalseAndOneStoresTrue(): void
    {
        $this->registerNote();
        $this->submit('note', ['featured' => '0']);
        $this->save('note');
        $this->assertFalse($this->meta['featured'] ?? null, "'0' is false.");

        $this->submit('note', ['featured' => '1']);
        $this->save('note');
        $this->assertTrue($this->meta['featured'] ?? null, "'1' is true.");
    }

    public function testANonDataPostTypeStoresTheRegistrySanitizedValueOncePerField(): void
    {
        $this->registerNote();
        $this->submit('note', ['title_line' => '<b>x</b>', 'body' => "  <i>y</i>  "]);

        $this->save('note');

        $this->assertSame('text:x', $this->meta['title_line'] ?? null, 'The registry cleans a `text` field.');
        $this->assertSame('textarea:y', $this->meta['body'] ?? null, 'The registry cleans a `textarea` field.');
        $this->assertSame(1, $this->calls['sanitize_text_field'] ?? 0, 'Once per field — there is no model here to clean it again.');
        $this->assertSame(1, $this->calls['sanitize_textarea_field'] ?? 0, 'Once per field.');
    }

    public function testARelationFieldClearedInTheEditorReachesADataModelAsTheEmptyList(): void
    {
        // A relation with every item removed posts NOTHING. Absent must mean
        // "cleared", or the editor can never empty the field.
        $this->registerGig();
        $this->submit('gig', ['venue_city' => 'x']);

        $this->save('gig');

        $this->assertSame(
            [],
            MetaboxSaveRecordingModel::$updateCalls[0][1]['tags'] ?? null,
            'A relation absent from the POST is the empty list, not a missing key.',
        );
    }

    public function testARelationFieldClearedInTheEditorDeletesTheMetaOnANonDataPostType(): void
    {
        $this->registerNote();
        $this->submit('note', ['title_line' => 'x']);

        $this->save('note');

        $this->assertContains(
            'related',
            $this->metaDeletes,
            'An empty list is DELETED rather than stored as a serialized empty array.',
        );
        $this->assertSame(
            [],
            array_values(array_filter($this->metaWrites, static fn(array $w): bool => $w[0] === 'related')),
            'And it is never written.',
        );
    }

    public function testARepeaterRowWhoseOnlyAnswerIsZeroIsKept(): void
    {
        // `'0'` is an answer. array_filter()'s drop-falsy rule would delete the
        // row; the metabox's "not '' and not null" rule is the one that survives.
        $this->registerNote();
        $this->submit('note', ['slots' => [
            ['label' => '', 'qty' => '0'],
        ]]);

        $this->save('note');

        $this->assertSame(
            [['label' => '', 'qty' => 0]],
            $this->meta['slots'] ?? null,
            "A row whose only filled cell is '0' is kept, and the cell is the sub-field's DECLARED type — "
                . 'a cell sanitized as text stores the string and loses the type on the next re-save.',
        );
    }

    public function testARepeaterRowWithNoAnswerAtAllIsDropped(): void
    {
        $this->registerNote();
        $this->submit('note', ['slots' => [
            ['label' => '', 'qty' => ''],
        ]]);

        $this->save('note');

        $this->assertContains(
            'slots',
            $this->metaDeletes,
            'Every row empty means no rows, and no rows is a delete.',
        );
    }

    // ------------------------------------------------------------- promise 4

    public function testAnUnknownFieldTypeSurfacesAsASaveErrorAndWritesNothing(): void
    {
        // A field declaration naming a retired alias ('wysiwyg' → 'html') is a
        // FAULT, never a text box (Cluster C behaviour). The registry throws for
        // it, and the save path must catch that where the editor can see it:
        // a fatal here white-screens the post and loses every other field on
        // the screen.
        $this->register('legacy', [
            'title_line' => 'text',
            'body'       => 'wysiwyg',
        ]);
        $this->submit('legacy', ['title_line' => 'kept?', 'body' => '<p>hi</p>']);

        $generator = $this->generator();
        $generator->save_metabox_data(self::POST_ID, $this->wpPost('legacy'));

        $this->assertSame(
            [],
            $this->metaWrites,
            'A refused type stops the whole save: a half-written post is worse than a refused one.',
        );
        $this->assertStringContainsString(
            'Saving failed',
            $this->noticeFor($generator),
            'And the editor is TOLD. A silent transient-less failure reads as "saved" on the screen.',
        );
    }

    public function testAThrowingSanitizerSurfacesAsASaveErrorInsteadOfAFatal(): void
    {
        $this->registerGig(['venue_city' => [
            'type'      => 'text',
            'sanitizer' => static function ($value): string {
                throw new RuntimeException('the DB is on fire in table wp_postmeta');
            },
        ]]);
        $this->submit('gig', ['venue_city' => 'x']);

        $generator = $this->generator();
        $generator->save_metabox_data(self::POST_ID, $this->wpPost('gig'));

        $notice = $this->noticeFor($generator);

        $this->assertStringContainsString('Saving failed', $notice, 'The throw becomes the editor-facing notice.');
        $this->assertStringNotContainsString(
            'wp_postmeta',
            $notice,
            'The message stays GENERIC: a DB-layer throw carries table names that do not belong on an edit screen.',
        );
    }

    // ------------------------------------------------------------- promise 5

    public function testTheMetaboxGeneratorHasNoSanitizerOfItsOwn(): void
    {
        $this->assertFalse(
            (new ReflectionClass('NTDST_MetaboxGenerator'))->hasMethod('sanitize_field'),
            'The metabox\'s private type switch was a SECOND vocabulary beside NTDST_FieldTypes '
                . '(INV-8): `bool` meant one thing on this path and another on the model path.',
        );
    }

    // ------------------------------------------------------- the denial paths

    public function testASaveWithABadNonceWritesNothing(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);

        $this->registerNote();
        $this->submit('note', ['title_line' => '<b>x</b>']);

        $this->save('note');

        $this->assertSame([], $this->metaWrites, 'A forged request writes nothing.');
        $this->assertSame([], $this->metaDeletes, 'And deletes nothing.');
    }

    public function testASaveWithNoNonceAtAllWritesNothing(): void
    {
        $this->registerNote();
        $_POST = ['ntdst_fields' => ['title_line' => '<b>x</b>']];

        $this->save('note');

        $this->assertSame([], $this->metaWrites, 'No nonce is not a save.');
    }

    public function testASaveByAUserWhoCannotEditThePostWritesNothing(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->registerGig();
        $this->submit('gig', ['venue_city' => '<b>x</b>']);

        $this->save('gig');

        $this->assertSame([], MetaboxSaveRecordingModel::$updateCalls, 'A refused actor never reaches the model.');
        $this->assertSame([], $this->metaWrites, 'Nor the meta table.');
    }

    public function testASaveOfAPostTypeWithNoMetaboxWritesNothing(): void
    {
        $this->submit('unknown', ['title_line' => '<b>x</b>']);

        $this->save('unknown');

        $this->assertSame([], $this->metaWrites, 'A post type this generator never registered is not its business.');
    }

    // ------------------------------------------------------------- harness

    /** A generator with no hooks mounted — this file drives the callback directly. */
    private function generator(): NTDST_MetaboxGenerator
    {
        return $this->generator ??= (new ReflectionClass('NTDST_MetaboxGenerator'))->newInstanceWithoutConstructor();
    }

    private function wpPost(string $type): WP_Post
    {
        $post = new WP_Post();
        $post->ID = self::POST_ID;
        $post->post_type = $type;

        return $post;
    }

    private function save(string $type): void
    {
        $this->generator()->save_metabox_data(self::POST_ID, $this->wpPost($type));
    }

    /** What the edit screen POSTs: the model's nonce, and the fields array. */
    private function submit(string $model, array $fields): void
    {
        $_POST = [
            "ntdst_{$model}_nonce" => 'a-valid-looking-nonce',
            'ntdst_fields'         => $fields,
        ];
    }

    /** The metabox's own field declarations for a post type. */
    private function register(string $type, array $fields): void
    {
        $this->generator()->register($type, ['fields' => $fields]);
    }

    /**
     * A Data-model post type: the metabox declares the fields, and the ORM
     * holds a model with the same fields, each carrying a counting no-op
     * `sanitizer` override (which composes on the registry, never replaces it).
     */
    private function registerGig(array $overrides = []): void
    {
        $schema = [
            'venue_city' => ['type' => 'text', 'sanitizer' => $this->countingSanitizer('venue_city')],
            'bio'        => ['type' => 'textarea', 'sanitizer' => $this->countingSanitizer('bio')],
            'discount'   => ['type' => 'int', 'sanitizer' => $this->countingSanitizer('discount')],
            'tags'       => ['type' => 'relation', 'sanitizer' => $this->countingSanitizer('tags')],
        ];

        foreach ($overrides as $field => $config) {
            $schema[$field] = $config;
        }

        $this->register('gig', [
            'venue_city' => 'text',
            'bio'        => 'textarea',
            'discount'   => 'int',
            'tags'       => 'relation',
            'ghost'      => 'text',
        ]);

        $this->modelTable()->setValue(null, [
            'gig' => new MetaboxSaveRecordingModel('gig', $schema, '_gig_'),
        ]);
    }

    /** A post type with a metabox and NO model — WordPress's own meta table. */
    private function registerNote(): void
    {
        $this->modelTable()->setValue(null, []);

        $this->register('note', [
            'title_line' => 'text',
            'body'       => 'textarea',
            'featured'   => 'bool',
            'discount'   => 'int',
            'related'    => 'relation',
            'slots'      => [
                'type'       => 'repeater',
                'sub_fields' => ['label' => 'text', 'qty' => 'int'],
            ],
        ]);
    }

    /** A no-op override that only counts: the registry's answer passes straight through. */
    private function countingSanitizer(string $field): Closure
    {
        return function ($value) use ($field) {
            $this->spy[$field] = ($this->spy[$field] ?? 0) + 1;

            return $value;
        };
    }

    /** A WordPress sanitizer stub that also records HOW MANY TIMES it ran. */
    private function stub(string $name, callable $answer): void
    {
        Functions\when($name)->alias(function (...$args) use ($name, $answer) {
            $this->calls[$name] = ($this->calls[$name] ?? 0) + 1;

            return $answer(...$args);
        });
    }

    /** What the editor sees on the next admin request after a failed save. */
    private function noticeFor(NTDST_MetaboxGenerator $generator): string
    {
        $_GET['post'] = (string) self::POST_ID;

        ob_start();
        $generator->render_save_error_notice();

        return (string) ob_get_clean();
    }

    private function modelTable(): ReflectionProperty
    {
        $property = new ReflectionProperty('NTDST_Data_Manager', 'models');
        $property->setAccessible(true);

        return $property;
    }
}
