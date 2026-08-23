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
//      answer is `'0'` is KEPT, and every PICKER absent from the POST — relation,
//      gallery, repeater — is the empty list, because absent is how the screen
//      says "emptied". Which fields those are is the REGISTRY's answer (their
//      `control`), never a name matched in this file: a declaration the save
//      cannot resolve stops the save instead of being skipped.
//   4. A SAVE THAT CANNOT COMPLETE IS A NOTICE, NEVER A WHITE SCREEN AND NEVER A
//      SILENT HALF. The unslash/sanitize loop runs INSIDE the save's try, so an
//      unresolvable declaration surfaces as the editor-facing notice with
//      NOTHING written; a row the model cannot find is refused rather than
//      forked into a second post.
//   5. THE BROWSER DOES NOT CHOOSE WHAT IS WRITTEN. Only DECLARED fields reach
//      the store, on either branch: `ntdst_fields[post_author]` is not a field,
//      and neither is `_thumbnail_id`. A `callback` field is declared and still
//      not written — it renders itself and the consumer owns its storage.
//      (The deleted sanitize_field() is pinned by name in
//      PackageBootIntegrityTest's removed-symbol sweep, with the rest of the
//      symbols this release removed.)
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
 * The model the edit screen saves into — a RECORDER standing in for the ORM.
 *
 * It OVERRIDES update()/create(): nothing here writes a post or a meta row, so
 * no case in this file observes storage through the model. What it observes is
 * the CALL — the array the metabox handed the model, before anything touched it
 * (promise 1, visible only from the model's side) — and then what the model's
 * OWN sanitize chain makes of that array, by calling the shipped
 * sanitizeData(): registry entry first, declared `sanitizer` override on its
 * output. So promise 2 is counted against the shipped closures and never
 * against a hand-written imitation of them.
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

    /** What find() answers. FALSE is a row this model cannot see (R-S4). */
    public static bool $findsTheRow = true;

    public static function reset(): void
    {
        self::$updateCalls = [];
        self::$createCalls = [];
        self::$sanitized = [];
        self::$findsTheRow = true;
    }

    public function find(int $id, $status = 'publish')
    {
        // The row exists — the edit screen is editing it. Returned as a plain
        // object so this file makes no claim about the ORM's read path, which
        // is not what T05 promises.
        return self::$findsTheRow
            ? (object) ['ID' => $id, 'post_type' => $this->post_type]
            : false;
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

    /** Every do_action() call, in order: [hook, ...args] — the extensibility contract. */
    private array $hooks = [];

    /** Every wp_update_post() call: the array of post COLUMNS it was handed. */
    private array $postWrites = [];

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
        $this->hooks = [];
        $this->postWrites = [];
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
        Functions\when('do_action')->alias(function (...$args) {
            $this->hooks[] = $args;

            return null;
        });
        Functions\when('apply_filters')->returnArg(2);

        // The post COLUMNS. A metabox writes META; anything that reaches
        // wp_update_post() from a posted field is a column an editor named
        // themselves (post_status, post_author).
        Functions\when('wp_update_post')->alias(function ($data = [], $wpError = false) {
            $this->postWrites[] = (array) $data;

            return (int) (((array) $data)['ID'] ?? self::POST_ID);
        });

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
        // $sanitized is what the model's OWN chain made of the payload — the
        // recorder called the shipped sanitizeData() on it. It is not a stored
        // value: nothing in this file writes one through the model.
        $this->assertArrayHasKey(
            'ghost',
            MetaboxSaveRecordingModel::$sanitized,
            'A field the metabox declares but the MODEL does not still reaches the model, and the '
                . 'model still cleans it — the metabox must not drop it and must not clean it either.',
        );
        $this->assertSame(
            'text:boo',
            MetaboxSaveRecordingModel::$sanitized['ghost'],
            'The model gives an undeclared key the text answer, once — so it can never be handled '
                . 'exactly as it was posted.',
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

    // The `'0'` / `'1'` pair that stood here is FieldTypesTest's question — the
    // vocabulary's answer for `bool`, asserted against WordPress's own
    // wp_validate_boolean() there. What this file owes is that the metabox asks
    // the vocabulary at all, and the case above ('false' → FALSE, the answer the
    // metabox's deleted `(bool) $value` arm got wrong) is the one that shows it
    // (simplicity S18).

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

    /**
     * A picker with every item removed posts NOTHING, so ABSENT must mean
     * CLEARED — for every control that is built out of the inputs it emits.
     *
     * `relation`, `gallery` and `repeater` all submit one input PER PICKED ITEM
     * and, when the field is emptied, only their container. If absent meant
     * "unchanged", the editor could never empty one of these fields from the
     * screen: the old value would stand for ever, whatever the editor did.
     */
    public function testEveryPickerClearedInTheEditorReachesADataModelAsTheEmptyList(): void
    {
        $this->registerGig();
        $this->submit('gig', ['venue_city' => 'x']);

        $this->save('gig');

        $payload = MetaboxSaveRecordingModel::$updateCalls[0][1] ?? [];

        foreach (['tags' => 'relation', 'slots' => 'repeater'] as $field => $control) {
            $this->assertSame(
                [],
                $payload[$field] ?? null,
                "A `{$control}` field absent from the POST is the empty list, not a missing key: "
                    . 'a missing key leaves the stored value standing and the field can never be emptied.',
            );
        }
    }

    public function testEveryPickerClearedInTheEditorDeletesTheMetaOnANonDataPostType(): void
    {
        $this->registerNote();
        $this->submit('note', ['title_line' => 'x']);

        $this->save('note');

        foreach (['related' => 'relation', 'covers' => 'gallery', 'slots' => 'repeater'] as $field => $control) {
            $this->assertContains(
                $field,
                $this->metaDeletes,
                "An emptied `{$control}` field is DELETED rather than stored as a serialized empty array.",
            );
            $this->assertSame(
                [],
                array_values(array_filter($this->metaWrites, static fn(array $w): bool => $w[0] === $field)),
                "And `{$field}` is never written.",
            );
        }
    }

    /**
     * The SAME clearing rule when the form posts no `ntdst_fields` at all.
     *
     * The nonce has already proved the edit form was submitted. A screen whose
     * every field is a picker and whose every picker was emptied posts exactly
     * this: a nonce and nothing else. The old `empty($fields_data)` early return
     * treated it as "nothing to do", so the one save that MUST clear was the one
     * save that returned first (sentinel, this gate).
     *
     * A non-array `ntdst_fields` is the same request with a hostile shape
     * (`?ntdst_fields=x`): it must clear the same way and raise no PHP warning —
     * `failOnWarning` in phpunit.xml is what makes that half enforceable.
     *
     * @dataProvider fieldlessPosts
     */
    public function testAFormWithNoFieldsAtAllStillClearsEveryPicker(mixed $posted): void
    {
        $this->registerNote();
        $_POST = ['ntdst_note_nonce' => 'a-valid-looking-nonce'];

        if ($posted !== null) {
            $_POST['ntdst_fields'] = $posted;
        }

        $this->save('note');

        foreach (['related', 'covers', 'slots'] as $field) {
            $this->assertContains(
                $field,
                $this->metaDeletes,
                "The form was submitted — the nonce proves it — so an absent `{$field}` is a cleared "
                    . 'one. An early return here is a picker that can never be emptied.',
            );
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function fieldlessPosts(): array
    {
        return [
            'no ntdst_fields key at all' => [null],
            'an empty ntdst_fields'      => [[]],
            'a non-array ntdst_fields'   => ['x'],
        ];
    }

    /**
     * A declaration the save path CANNOT RESOLVE stops the save; it is never
     * quietly skipped (invariant audit, CRITICAL).
     *
     * The clearing rule above has to ask what CONTROL a declared field draws,
     * and until this wave it asked the raw declared NAME instead — so a field
     * declared with a retired alias ('person', which v5.0.0 folded into
     * 'relation') simply did not match, and an emptied picker was silently left
     * standing while the rest of the screen saved around it. Keyed on the
     * registry's answer, the same declaration throws inside the save's try and
     * becomes the editor-facing notice: nothing written, nothing deleted,
     * and the editor is told.
     *
     * The declaration is injected past register(), which now refuses it at init
     * (reviewer S-5) — the save path must fail closed on its own anyway, because
     * the next retired name is a `fields` filter away.
     */
    public function testAnUnresolvableDeclarationStopsTheSaveInsteadOfSkippingTheField(): void
    {
        $this->registerRaw('legacy', [
            'title_line' => 'text',
            'people'     => 'person', // retired: 'relation'
        ]);
        $this->submit('legacy', ['title_line' => 'kept?']);

        $generator = $this->generator();
        $generator->save_metabox_data(self::POST_ID, $this->wpPost('legacy'));

        $this->assertSame([], $this->metaWrites, 'A refused declaration writes nothing — not even the fields it could resolve.');
        $this->assertSame([], $this->metaDeletes, 'And deletes nothing: a half-cleared post is worse than a refused save.');
        $this->assertStringContainsString(
            'Saving failed',
            $this->noticeFor($generator),
            'The editor is TOLD. Skipping the field silently is how a cleared picker comes back.',
        );
    }

    /**
     * A `callback` field is SKIPPED by the save, on both branches.
     *
     * `callback` is a render directive, not a vocabulary entry: the field draws
     * itself and the consumer's own code owns whatever it stores. The render
     * side already answers it before the registry is asked; the save side did
     * not, so a `callback` field that posted anything under `ntdst_fields[…]`
     * reached NTDST_FieldTypes::get('callback'), threw, and killed the whole
     * save — every other field on the screen lost with it (simplicity I13, live
     * on two fleet sites).
     *
     * Skipped, not stored and not a throw: the declaration says this field is
     * not the metabox's to write. It is registered through the PUBLIC register()
     * here on purpose — `'type' => 'callback'` is live on two fleet sites, so
     * the declaration gate this wave adds must accept it as it accepts a type.
     */
    public function testACallbackFieldIsSkippedBySaveOnANonDataPostType(): void
    {
        $this->registerNote(['summary' => ['type' => 'callback', 'callback' => 'strval']]);
        $this->submit('note', ['title_line' => 'x', 'summary' => 'whatever the widget posted']);

        $generator = $this->generator();
        $generator->save_metabox_data(self::POST_ID, $this->wpPost('note'));

        $this->assertSame('text:x', $this->meta['title_line'] ?? null, 'The rest of the screen still saves.');
        $this->assertArrayNotHasKey(
            'summary',
            $this->meta,
            'A `callback` field is the consumer\'s to store. The metabox has no type for it and must not invent one.',
        );
        $this->assertStringNotContainsString(
            'Saving failed',
            $this->noticeFor($generator),
            'And it is not a fault: `callback` is a legal declaration, so the save must not fail on it.',
        );
    }

    public function testACallbackFieldIsSkippedBeforeADataModelSeesIt(): void
    {
        $this->registerGig();
        $this->registerRaw('gig', ['venue_city' => 'text', 'summary' => ['type' => 'callback']]);
        $this->submit('gig', ['venue_city' => 'x', 'summary' => 'whatever the widget posted']);

        $this->save('gig');

        $this->assertArrayNotHasKey(
            'summary',
            MetaboxSaveRecordingModel::$updateCalls[0][1] ?? [],
            'The model never declared a `callback` field — handing it one makes it an unregistered key '
                . 'the model warns about and then stores as text.',
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
        // Past register(), which refuses this declaration at init now (S-5).
        // The save path owes its own refusal: see registerRaw().
        $this->registerRaw('legacy', [
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

    /**
     * A Data model that cannot SEE the row it is being asked to save refuses,
     * and the editor is told (reviewer S-4).
     *
     * The old branch fell through to create() — which cannot honour a post_id —
     * so a save of a post the model does not recognise FORKED a second,
     * published row and logged an unregistered `post_id` key on the way. The
     * screen said "saved" and the editor was looking at the wrong post.
     * "This row is not mine" is a refusal, never a create.
     */
    public function testASaveForARowTheModelCannotFindRefusesInsteadOfForkingANewPost(): void
    {
        $this->registerGig();
        MetaboxSaveRecordingModel::$findsTheRow = false;
        $this->submit('gig', ['venue_city' => 'x']);

        $generator = $this->generator();
        $generator->save_metabox_data(self::POST_ID, $this->wpPost('gig'));

        $this->assertSame([], MetaboxSaveRecordingModel::$createCalls, 'A save must never create a SECOND post.');
        $this->assertSame([], MetaboxSaveRecordingModel::$updateCalls, 'And there is nothing to update.');
        $this->assertSame([], $this->metaWrites, 'Nothing is written.');
        $this->assertStringContainsString(
            'Saving failed',
            $this->noticeFor($generator),
            'The editor is told: a silent fork reads as "saved" while the typing went to another post.',
        );
    }

    /**
     * The saved hook receives WHAT WAS POSTED on the Data branch — under a name
     * that says so (reviewer I-2).
     *
     * The payload the metabox hands `ntdst/metabox_saved/{model}` on that branch
     * is the unslashed POST, not the model's cleaned answer: the model cleaned
     * what it STORED, and the hook sees what the editor TYPED. A listener that
     * echoes it, writes it somewhere else, or mails it is handling raw input.
     * This is a documented BREAKING row for 5.0.0, and it is pinned here so the
     * rename that makes it honest cannot quietly change it into something else.
     */
    public function testTheSavedHookOnTheDataBranchCarriesThePostedValues(): void
    {
        $this->registerGig();
        $this->submit('gig', ['venue_city' => '  <b>x</b>  ']);

        $this->save('gig');

        $fired = array_values(array_filter(
            $this->hooks,
            static fn(array $call): bool => ($call[0] ?? '') === 'ntdst/metabox_saved/gig',
        ));

        $this->assertCount(1, $fired, 'The saved hook fires exactly once on a genuine save.');
        $this->assertSame(self::POST_ID, $fired[0][1] ?? null, 'It names the post it saved.');
        $this->assertSame(
            '  <b>x</b>  ',
            $fired[0][2]['venue_city'] ?? null,
            'The payload is the POSTED value, unslashed and uncleaned — the model is what cleaned '
                . 'the stored one. A listener reading this is reading raw input.',
        );
    }

    // ------------------------------------------------------- the denial paths

    /**
     * A posted key the SCREEN never declared never reaches the store — on
     * either branch (sentinel, this gate).
     *
     * `$_POST['ntdst_fields']` was walked verbatim, so the browser decided which
     * keys the save wrote. On a Data model those keys go into update(), and the
     * model maps `post_status`, `post_author` and `post_parent` onto wp_posts
     * COLUMNS: a contributor who can edit their own draft could publish it, or
     * hand it to another author, by adding one input to the form. On a plain
     * post type they become meta keys, `_thumbnail_id` among them — the featured
     * image, set from a field that does not exist.
     *
     * The declaration is the allow-list. It is the only thing on this screen
     * that the site — not the browser — wrote.
     */
    public function testAPostedKeyOutsideTheDeclaredSetNeverReachesTheStore(): void
    {
        $forged = [
            'post_status'        => 'publish',
            'post_author'        => '1',
            'post_parent'        => '7',
            '_thumbnail_id'      => '4242',
            '_wp_page_template'  => 'evil.php',
        ];

        $this->registerGig();
        $this->submit('gig', ['venue_city' => 'pwned?'] + $forged);

        $this->save('gig');

        $payload = MetaboxSaveRecordingModel::$updateCalls[0][1] ?? [];

        foreach (array_keys($forged) as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $payload,
                "`{$key}` was posted through the metabox and is not a declared field. Handing it to "
                    . 'the model lets the browser choose what the save writes — post columns included.',
            );
        }

        // The recorder does not forward to wp_update_post(), so this only says
        // the SAVE PATH wrote no column of its own. The escalation itself — the
        // REAL model mapping `post_status`/`post_author` onto wp_posts — is
        // asserted against the real model in
        // MetaboxReadsTheVocabularyTest::testAPostedKeyOutsideTheDeclaredSetNeverBecomesAPostColumn().
        $this->assertSame([], $this->postWrites, 'The save path itself writes no post column.');
        $this->assertSame('pwned?', $payload['venue_city'] ?? null, 'The declared field still saves.');

        // The same POST against a post type with no model: the keys would be
        // meta rows, and `_thumbnail_id` is the featured image.
        $this->registerNote();
        $this->submit('note', ['title_line' => 'x'] + $forged);

        $this->save('note');

        foreach (array_keys($forged) as $key) {
            $this->assertArrayNotHasKey($key, $this->meta, "`{$key}` must never be written as post meta by this save.");
        }
    }

    /**
     * A DECLARED key is stored exactly as it was declared.
     *
     * The allow-list above is what makes the stored key safe: after
     * array_intersect_key() every key the save writes came from the site's own
     * `fields` array, so there is nothing left for a key rule to clean. Folding
     * the key here — sanitize_key(), which lowercases — would rewrite the meta
     * key of every camelCase declaration on the fleet: `venueCity` stops being
     * the row that already holds the site's data, the screen reads the new
     * empty row, and the old value is invisible and un-deletable. A migration
     * with no migration.
     *
     * The repeater ROW key is a different question with a different answer
     * (NTDST_FieldTypes::rowKey(), the vocabulary's) — that one is a key the
     * BROWSER echoes back, and it is not this.
     */
    public function testADeclaredKeyIsStoredExactlyAsItWasDeclared(): void
    {
        $this->modelTable()->setValue(null, []);
        $this->register('note', ['venueCity' => 'text', '_internal_note' => 'text']);
        $this->submit('note', ['venueCity' => 'Ghent', '_internal_note' => 'x']);

        $this->save('note');

        $this->assertSame(
            'text:Ghent',
            $this->meta['venueCity'] ?? null,
            'A declared `venueCity` is stored under `venueCity`. Lowercasing it invents a SECOND meta '
                . 'key beside the one the site already wrote, and orphans the data in the first.',
        );
        $this->assertArrayNotHasKey(
            'venuecity',
            $this->meta,
            'And the folded key must not exist at all — two keys for one field is the migration nobody ran.',
        );
        $this->assertSame(
            'text:x',
            $this->meta['_internal_note'] ?? null,
            'An underscore-prefixed declaration is the site\'s own too: declared is declared.',
        );
    }

    /**
     * Every way a save is REFUSED, in one place: nothing is written, nothing is
     * deleted, and no model is ever reached (simplicity S19).
     *
     * @dataProvider refusedSaves
     */
    public function testARefusedSaveWritesNothing(string $why, Closure $arrange, string $postType): void
    {
        $arrange($this);

        $this->save($postType);

        $this->assertSame([], $this->metaWrites, "{$why}: nothing may be written.");
        $this->assertSame([], $this->metaDeletes, "{$why}: nothing may be deleted — a clear is a write.");
        $this->assertSame([], MetaboxSaveRecordingModel::$updateCalls, "{$why}: the model is never reached.");
        $this->assertSame([], MetaboxSaveRecordingModel::$createCalls, "{$why}: and nothing is created.");
    }

    /** @return array<string, array{0: string, 1: Closure, 2: string}> */
    public static function refusedSaves(): array
    {
        return [
            'a forged nonce' => [
                'A forged request is not a save',
                static function (self $test): void {
                    Functions\when('wp_verify_nonce')->justReturn(false);
                    $test->registerNote();
                    $test->submit('note', ['title_line' => '<b>x</b>', 'related' => ['1']]);
                },
                'note',
            ],
            'no nonce at all' => [
                'No nonce is not a save',
                static function (self $test): void {
                    $test->registerNote();
                    $_POST = ['ntdst_fields' => ['title_line' => '<b>x</b>', 'related' => ['1']]];
                },
                'note',
            ],
            'an actor who cannot edit the post' => [
                'A refused actor never reaches the store',
                static function (self $test): void {
                    Functions\when('current_user_can')->justReturn(false);
                    $test->registerGig();
                    $test->submit('gig', ['venue_city' => '<b>x</b>']);
                },
                'gig',
            ],
            'a post type with no metabox' => [
                'A post type this generator never registered is not its business',
                static function (self $test): void {
                    $test->submit('unknown', ['title_line' => '<b>x</b>']);
                },
                'unknown',
            ],
        ];
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
        $rows = ['type' => 'repeater', 'sub_fields' => ['label' => 'text', 'qty' => 'int']];

        $schema = [
            'venue_city' => ['type' => 'text', 'sanitizer' => $this->countingSanitizer('venue_city')],
            'bio'        => ['type' => 'textarea', 'sanitizer' => $this->countingSanitizer('bio')],
            'discount'   => ['type' => 'int', 'sanitizer' => $this->countingSanitizer('discount')],
            'tags'       => ['type' => 'relation', 'sanitizer' => $this->countingSanitizer('tags')],
            'slots'      => $rows + ['sanitizer' => $this->countingSanitizer('slots')],
        ];

        foreach ($overrides as $field => $config) {
            $schema[$field] = $config;
        }

        $this->register('gig', [
            'venue_city' => 'text',
            'bio'        => 'textarea',
            'discount'   => 'int',
            'tags'       => 'relation',
            'slots'      => $rows,
            'ghost'      => 'text',
        ]);

        $this->modelTable()->setValue(null, [
            'gig' => new MetaboxSaveRecordingModel('gig', $schema, '_gig_'),
        ]);
    }

    /** A post type with a metabox and NO model — WordPress's own meta table. */
    private function registerNote(array $extraFields = []): void
    {
        $this->modelTable()->setValue(null, []);

        $this->register('note', [
            'title_line' => 'text',
            'body'       => 'textarea',
            'featured'   => 'bool',
            'discount'   => 'int',
            'related'    => 'relation',
            'covers'     => 'gallery',
            'slots'      => [
                'type'       => 'repeater',
                'sub_fields' => ['label' => 'text', 'qty' => 'int'],
            ],
        ] + $extraFields);
    }

    /**
     * A declaration written straight into the generator's table, past register().
     *
     * register() refuses what the vocabulary refuses (reviewer S-5), so a
     * retired name cannot be registered through it any more — and the SAVE path
     * still has to fail closed when one reaches it. The two guards land in the
     * same wave, so the save-path rule cannot be proved through the front door;
     * it is proved here, where a `ntdst/{model}/fields` filter, a cached older
     * registration, or the next name this vocabulary retires would put it.
     */
    private function registerRaw(string $type, array $fields): void
    {
        $models = new ReflectionProperty('NTDST_MetaboxGenerator', 'registered_models');
        $models->setAccessible(true);

        $table = $models->getValue($this->generator());
        $table[$type] = ['fields' => $fields];

        $models->setValue($this->generator(), $table);
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
