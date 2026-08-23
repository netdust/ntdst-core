<?php // tests/Unit/MetaboxReadsTheVocabularyTest.php
// FEATURE tests for Cluster C — "the metabox reads the vocabulary".
//
// Written independently of the three task tests that already pin the TASKS
// (MetaboxGeneratorSaveTest = T05's save contract, MetaboxGeneratorRenderTest =
// T06's render contract, DataReadsTheVocabularyTest = Cluster B's read
// contract). This file asserts the thing the CLUSTER promised, which none of
// them can see on its own: the SAME vocabulary answers on the way out to the
// screen, on the way back in from the editor, and on the way out again — for
// every one of the seventeen names, in one continuous trip.
//
//   Behaviour (cluster-c-behaviour.md): "a metabox save of a Data model reaches
//   the model unsanitized and is sanitized exactly once by the model; a save on
//   a non-Data post type is sanitized exactly once by the registry; every field
//   renders through one renderer keyed by the registry's control, in a row or at
//   top level, and an unknown control is a fault, never a text box."
//
// SIX FEATURES, in the order this file asserts them:
//
//   1. THE ROUND TRIP (SC-4, spec FR-5/FR-6/FR-7). A model declaring one field
//      per TYPE renders a screen; the names that screen EMITS are the shape the
//      browser posts back; the save stores the vocabulary's own answer for each;
//      find() reads each back unchanged; and a second render of the stored values
//      emits those same values — no double-escaping, no markup lost from `html`.
//      The posted shape is HARVESTED from the render rather than hand-written,
//      because "the screen and the save agree" is exactly the promise, and a
//      hand-written $_POST would assume it instead of proving it.
//   2. ONCE, NEVER TWICE, NEVER ZERO (threat row #3) — at cluster level, with
//      BOTH cleaners visible: the model's bound sanitizer AND the callback
//      register_post_meta() hands WordPress, which update_metadata() fires on
//      the same write. A published field is therefore cleaned TWICE on a real
//      metabox save and must answer the same both times (idempotence is what
//      makes twice harmless); an unpublished field is cleaned exactly once.
//      Never zero, on either side.
//   3. THE DENIAL PATH (threat row #5, FR-3). A post type with a metabox and no
//      Data model that still declares a RETIRED name is a loud fault on both
//      halves of the screen's life: the render raises the registry's
//      InvalidArgumentException naming the replacement instead of drawing a text
//      box, and the save writes NOTHING and tells the editor — with the
//      actionable detail in the log, not on the edit screen.
//   4. HOSTILE VALUES THROUGH EVERY CONTROL (threat rows #1/#5) — asserted in
//      MetaboxGeneratorRenderTest::testEveryControlEscapesAHostileValue(), which
//      already owns the real-escaping harness. See that file.
//   5. THE NONCE IS THE POST'S. The pair a screen emits is HARVESTED and posted
//      back: the screen for THIS post is what saves it, and the token another
//      post's screen minted saves nothing here (sentinel, Cluster C gate).
//   6. THE DECLARATION IS THE ALLOW-LIST. A posted key the screen never declared
//      cannot become a wp_posts COLUMN — `ntdst_fields[post_status]` and
//      `ntdst_fields[post_author]` are the escalation, and they are asserted
//      against the REAL model, which is what maps them (sentinel, Cluster C gate).
//
// HOW THIS FILE OBSERVES ALL OF THAT
// Through the two PUBLIC entries WordPress itself calls — render_metabox() and
// save_metabox_data() — with a REAL NTDST_Data_Model and the REAL
// NTDST_FieldTypes table behind them. Nothing between the screen and the store
// is mocked; Brain Monkey stands only at WordPress's own edge (the sanitizers,
// the meta table, the post row). sanitize_key() and wp_unslash() are REAL
// functions from tests/bootstrap.php and are never stubbed here.
//
// WHY THE EXPECTED VALUES ARE DERIVED FROM THE REGISTRY
// The per-type ANSWERS are FieldTypesTest's subject and are not re-litigated
// here: this file asserts that what the screen stored IS what the vocabulary
// says, by asking the vocabulary. Where the answer is the whole point of the
// cluster — a signed int, a false bool, `html` that keeps its markup — the
// literal is asserted as well, so the derivation cannot pass by agreeing with
// itself.
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

final class MetaboxReadsTheVocabularyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The post on daan's edit screen (Cluster C's observable). */
    private const POST_ID = 297050;

    /** The model's meta prefix — every stored key wears it. */
    private const PREFIX = '_gig_';

    /**
     * One declared field per TYPE — all seventeen of them (D4/FR-2), with the
     * repeater carrying a `text`, an `int` and an `image` cell.
     *
     * This is ONE array, used as the metabox's field declaration AND as the
     * model's schema, because a screen whose declaration disagrees with the
     * model's is the defect the cluster removes.
     */
    private const FIELDS = [
        'capacity'   => ['type' => 'int'],
        'price'      => ['type' => 'float'],
        'featured'   => ['type' => 'bool'],
        'venue_city' => ['type' => 'text'],
        'bio'        => ['type' => 'textarea'],
        'body'       => ['type' => 'html'],
        'contact'    => ['type' => 'email'],
        'homepage'   => ['type' => 'url'],
        'starts_on'  => ['type' => 'date'],
        'status'     => ['type' => 'select', 'options' => ['draft' => 'Draft', 'live' => 'Live']],
        'meta_map'   => ['type' => 'array'],
        'payload'    => ['type' => 'json'],
        'tags'       => ['type' => 'relation', 'post_type' => 'artist'],
        'shots'      => ['type' => 'gallery'],
        'poster'     => ['type' => 'image'],
        'rider'      => ['type' => 'file'],
        'slots'      => [
            'type'       => 'repeater',
            'sub_fields' => ['label' => 'text', 'qty' => 'int', 'photo' => 'image'],
        ],
    ];

    /**
     * Plausible editor input for each of the seventeen — what a human actually
     * types or picks, as the browser posts it: everything a string, ids as
     * strings from the pickers, a repeater with one real row and one empty row
     * (the blank template row the editor always submits).
     */
    private const TYPED = [
        'capacity'   => '-500',
        'price'      => '12.5',
        'featured'   => 'false',
        'venue_city' => '  <b>Ghent</b>  ',
        'bio'        => "<i>line</i>\n\nmore",
        'body'       => '<p>a</p><script>x</script>',
        'contact'    => 'daan@example.org<script>',
        'homepage'   => 'https://example.org/gig',
        'starts_on'  => '2026-08-23',
        'status'     => 'live',
        'meta_map'   => '{"a":"<b>x"}',
        'payload'    => '{"k":"<b>v"}',
        'tags'       => ['1', '2'],
        'shots'      => ['8', '9'],
        'poster'     => '5',
        'rider'      => '6',
        'slots'      => [
            ['label' => '  <b>A</b>  ', 'qty' => '-3', 'photo' => '7'],
            ['label' => '', 'qty' => '', 'photo' => ''],
        ],
    ];

    /** The post meta this process pretends to have. Keys as stored (prefixed). */
    private array $meta = [];

    /** Every update_post_meta() call, in order: [key, value]. */
    private array $metaWrites = [];

    /** Every delete_post_meta() call, in order: key. */
    private array $metaDeletes = [];

    /** Every register_post_meta() call: metaKey => args. */
    private array $registrations = [];

    /** Transients, so the editor-facing notice can be read the way the editor reads it. */
    private array $transients = [];

    /** How many times each WordPress sanitizer ran. "Once" is a counting question. */
    private array $calls = [];

    /** How many times each field's declared `sanitizer` ran — one count per FIELD. */
    private array $spy = [];

    /** What wp_editor() was HANDED: editor id => [content, textarea_name]. */
    private array $editors = [];

    /** The manager's model table — STATIC and process-wide; restored in tearDown. */
    private mixed $modelsBackup = null;

    private ?NTDST_MetaboxGenerator $generator = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->meta = [];
        $this->metaWrites = [];
        $this->metaDeletes = [];
        $this->registrations = [];
        $this->transients = [];
        $this->calls = [];
        $this->spy = [];
        $this->editors = [];
        $this->generator = null;
        $_POST = [];
        $_GET = [];
        $GLOBALS['_ntdst_test_log'] = [];

        $this->modelsBackup = $this->modelTable()->getValue();

        // ---- sanitizers: TAGGED, so a pass-through cannot pose as the right
        // one, and COUNTED, because two idempotent cleans read exactly like one.
        $tagged = static function (string $tag, callable $inner): Closure {
            return static function ($value) use ($tag, $inner) {
                $raw = is_scalar($value) ? (string) $value : '';
                if (trim($raw) === '') {
                    return '';
                }
                if (str_starts_with($raw, $tag . ':')) {
                    return $raw; // idempotent, like WordPress's own
                }

                return $tag . ':' . $inner($raw);
            };
        };

        $strip = static fn(string $raw): string => trim(strip_tags($raw));

        $this->stub('sanitize_text_field', $tagged('text', $strip));
        $this->stub('sanitize_textarea_field', $tagged('textarea', $strip));
        $this->stub('sanitize_email', $tagged('email', static fn(string $raw): string => (string) preg_replace('/[^A-Za-z0-9.@_+\-]/', '', $raw)));
        // WordPress strips the TAG and keeps what was between them; anything
        // stronger here would credit the vocabulary with a refusal
        // wp_kses_post() does not make.
        $this->stub('wp_kses_post', $tagged('kses', static fn(string $raw): string => (string) preg_replace('@</?(script|style)[^>]*>@i', '', $raw)));
        $url = $tagged('url', static fn(string $raw): string => $raw);
        $this->stub('esc_url_raw', static function ($value) use ($url) {
            $raw = ltrim((string) $value);

            return stripos($raw, 'javascript:') === 0 ? '' : $url($value);
        });

        // ---- real-equivalents: WordPress's own algorithm is the point ----
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
        Functions\when('sanitize_title')->alias(
            static fn($value) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)),
        );

        // ---- escaping: htmlspecialchars, so double-escaping is VISIBLE ----
        Functions\when('esc_attr')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_html')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_textarea')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_url')->alias(static fn($u) => (string) $u);

        // ---- TAGGED: wp_editor() is invisible in its own output. It is given
        // the value; whether it ESCAPES it is the editor's business, so this
        // stub records what it was handed and echoes only a marker.
        Functions\when('wp_editor')->alias(function ($content, $editor_id, $settings = []) {
            $this->editors[(string) $editor_id] = [$content, $settings['textarea_name'] ?? ''];
            echo '<!--wp_editor:' . $editor_id . ':' . ($settings['textarea_name'] ?? '') . '-->';
        });

        // ---- the gate: open unless a test closes it ----
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('user_can')->justReturn(true);

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
        Functions\when('wp_insert_post')->justReturn(self::POST_ID);
        Functions\when('wp_update_post')->alias(static fn($data) => (int) ($data['ID'] ?? self::POST_ID));
        Functions\when('wp_delete_post')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('maybe_serialize')->alias(static fn($v) => is_scalar($v) ? (string) $v : serialize($v));

        // The row on the edit screen.
        Functions\when('get_post')->alias(static fn($id) => (object) [
            'ID'          => (int) $id,
            'post_type'   => 'gig',
            'post_status' => 'publish',
            'post_title'  => 'a gig',
        ]);
        // find() reads through NTDST_Data_Manager::getPostMeta(), which prefers
        // core's post_meta cache — one value per key, the way WordPress holds it.
        Functions\when('wp_cache_get')->alias(fn($id = null, $group = '') => $group === 'post_meta'
            ? array_map(static fn($v) => [$v], $this->meta)
            : false);

        // What WordPress is TOLD about each published field — the second cleaner.
        Functions\when('register_post_meta')->alias(function ($postType, $key, $args) {
            $this->registrations[$key] = $args;

            return true;
        });

        // ---- attachments, relations, admin plumbing ----
        Functions\when('get_post_type')->alias(
            static fn($post = null) => ((int) $post >= 1 && (int) $post <= 99) ? 'attachment' : 'post',
        );
        Functions\when('get_the_title')->alias(static fn($id) => 'Attachment ' . (int) $id);
        Functions\when('wp_get_attachment_image_url')->alias(static fn($id, $size = '') => "https://example.org/{$id}.jpg");
        Functions\when('get_posts')->alias(static fn(array $args = []) => array_map(
            static fn($id) => (object) ['ID' => (int) $id, 'post_title' => 'Post ' . (int) $id],
            $args['post__in'] ?? [],
        ));
        Functions\when('get_users')->justReturn([]);
        Functions\when('admin_url')->alias(static fn($p = '') => 'https://example.org/wp-admin/' . $p);
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('wp_enqueue_media')->justReturn(null);

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

    // ==================================================== 1. THE ROUND TRIP

    /**
     * The screen names every declared field, and those names ARE the posted shape.
     *
     * A field that renders under no `ntdst_fields[...]` name can never be saved
     * from the screen, whatever the save path does with it — and the two render
     * switches this cluster deleted had exactly that hole for `html` in a row.
     * The `html` control's name lives in wp_editor()'s `textarea_name`, which is
     * why the harvest reads it too.
     */
    public function testTheScreenEmitsASubmitNameForEveryOneOfTheSeventeenTypes(): void
    {
        $html = $this->renderScreen();

        $this->assertSame(
            array_keys(self::FIELDS),
            $this->submitNames($html),
            'Every declared type must render under its own ntdst_fields[<field>] name — '
                . 'and nothing else may appear there. A field with no name on the screen '
                . 'is a field the editor cannot save.',
        );
    }

    /**
     * SC-4, the whole trip: what the editor typed into the names the screen
     * emitted is stored as the VOCABULARY's answer for that type — for all
     * seventeen, on the Data-model branch, through update().
     */
    public function testEveryTypeStoresTheVocabularysOwnAnswerForWhatTheEditorTyped(): void
    {
        $this->roundTrip();

        foreach (self::FIELDS as $field => $config) {
            $expected = (NTDST_FieldTypes::get($config['type'])->sanitize)(self::TYPED[$field], $config);

            $this->assertSame(
                $expected,
                $this->stored($field),
                "Field '{$field}' (type `{$config['type']}`) must be stored as the registry's own "
                    . 'answer for what the editor typed. A different answer here is a SECOND '
                    . 'vocabulary on the save path (INV-8).',
            );
        }
    }

    /**
     * The four answers the cluster exists to fix, asserted as LITERALS so the
     * derivation above cannot pass by agreeing with itself.
     */
    public function testTheAnswersThatUsedToBeWrongAreRightFromTheEditScreen(): void
    {
        $this->roundTrip();

        $this->assertSame(-500, $this->stored('capacity'), 'FR-5: an `int` keeps its sign from the edit screen; absint() was the bug.');
        $this->assertFalse($this->stored('featured'), "WordPress's word for `bool`: the exact string 'false' is FALSE.");
        $this->assertSame('kses:<p>a</p>x', $this->stored('body'), '`html` keeps its markup and loses the script TAG — it is not a text field.');
        $this->assertSame([1, 2], $this->stored('tags'), 'A `relation` picked in the editor stores integer ids.');
        $this->assertSame(
            [['label' => 'text:A', 'qty' => -3, 'photo' => 7]],
            $this->stored('slots'),
            'A repeater keeps the one real row (each cell answered by its OWN declared type) and drops the empty one.',
        );
    }

    /**
     * Once — never twice, never zero — counted per FIELD across the whole screen.
     *
     * Every field carries a declared `sanitizer` that only counts and returns
     * what it was given; it composes on the registry's output, so its count IS
     * the number of times that field was cleaned by the model. Seventeen fields,
     * seventeen ones. The four WordPress functions that only ONE field each can
     * reach are counted directly as well: a metabox that cleaned before handing
     * over would show two.
     */
    public function testEveryFieldOnTheScreenIsCleanedExactlyOncePerSave(): void
    {
        $this->roundTrip();

        foreach (array_keys(self::FIELDS) as $field) {
            $this->assertSame(
                1,
                $this->spy[$field] ?? 0,
                "Field '{$field}' must be cleaned exactly ONCE on a Data-model save. "
                    . 'Zero means nothing sanitized it; two means the metabox cleaned it before the model did.',
            );
        }

        // And the same answer from WordPress's side of the boundary: each of
        // these three functions is reachable from exactly ONE declared field on
        // this screen, and each of those three types declares its own `read`
        // decoder — so every call inside the save window is a CLEAN, and two
        // would mean the metabox cleaned before the model did.
        foreach (['wp_kses_post' => 'html', 'sanitize_email' => 'email', 'esc_url_raw' => 'url'] as $fn => $type) {
            $this->assertNotNull(
                NTDST_FieldTypes::get($type)->read,
                "This count only means 'cleans' while `{$type}` decodes with something other than its "
                    . 'sanitizer; if that changes, count something else rather than loosening this.',
            );
            $this->assertSame(
                1,
                $this->calls[$fn] ?? 0,
                "`{$fn}` is reachable from exactly one declared field on this screen, so it must "
                    . 'run exactly once per save.',
            );
        }
    }

    /**
     * The Cluster B read contract, from the metabox's side: what the screen
     * stored, find() hands back — unchanged, for every type.
     */
    public function testFindReadsBackExactlyWhatTheEditScreenStored(): void
    {
        $this->roundTrip();

        $read = $this->model()->find(self::POST_ID, 'any');

        $this->assertIsObject($read, 'find() must return the row, not an error.');

        foreach (array_keys(self::FIELDS) as $field) {
            $this->assertSame(
                $this->stored($field),
                $read->fields[$field] ?? null,
                "Field '{$field}' must read back as it was stored: a read that re-answers the value "
                    . 'is a third table beside the write and the schema.',
            );
        }
    }

    /**
     * The re-entry: open the edit screen again, on the values the save stored,
     * and the screen shows those values back — escaped exactly once.
     *
     * Escaping twice is the failure that LOOKS fine on the first save and eats
     * the value on the second: `&lt;` becomes `&amp;lt;`, the editor saves that
     * literally, and the content degrades one save at a time.
     */
    public function testASecondRenderShowsTheStoredValuesEscapedExactlyOnce(): void
    {
        $this->roundTrip();

        $html = $this->renderScreen();

        // Values that live in a `value="…"` attribute: decoded, they must be
        // byte-identical to what is in the meta table.
        foreach (['capacity', 'price', 'venue_city', 'contact', 'homepage', 'starts_on', 'poster', 'rider'] as $field) {
            $this->assertSame(
                (string) $this->stored($field),
                $this->attributeValue($html, "ntdst_fields[{$field}]"),
                "Field '{$field}' must come back onto the screen as the value that is stored — "
                    . 'escaped for the attribute, and escaped once.',
            );
        }

        $this->assertSame(
            'text:A',
            $this->attributeValue($html, 'ntdst_fields[slots][0][label]'),
            'A repeater cell comes back with its stored value too — the row is the same renderer.',
        );

        $this->assertStringNotContainsString(
            '&amp;lt;',
            $html,
            'Double-escaping: a stored `&lt;` re-escaped to `&amp;lt;` degrades the value on every re-save.',
        );
        $this->assertStringNotContainsString(
            '&amp;quot;',
            $html,
            'Double-escaping of a stored quote, same failure.',
        );
    }

    /**
     * `html` survives the trip as MARKUP.
     *
     * The editor is handed the stored value un-escaped — that is the control's
     * design, and the reason `html` is not a text input: a text input would
     * render `&lt;p&gt;a&lt;/p&gt;` into a `value` and store that soup back.
     */
    public function testTheHtmlFieldGoesBackToTheEditorAsMarkupNotAsEscapedSoup(): void
    {
        $this->roundTrip();

        $html = $this->renderScreen();

        $this->assertArrayHasKey('ntdst_field_body', $this->editors, 'A `html` field renders through the editor.');

        [$content, $name] = $this->editors['ntdst_field_body'];

        $this->assertSame(
            $this->stored('body'),
            $content,
            'The editor is handed the STORED value itself — un-escaped, markup intact.',
        );
        $this->assertSame(
            'ntdst_fields[body]',
            $name,
            'And it posts back under the field\'s own name, so the trip can be made again.',
        );
        $this->assertStringNotContainsString(
            '<p>a</p>',
            $html,
            'The value is PASSED to the editor, never echoed into the page around it.',
        );
    }

    // ============================================ 2. ONCE, NEVER TWICE, NEVER ZERO

    /**
     * A PUBLISHED field is cleaned twice on a real metabox save, and both
     * cleanings answer the same thing.
     *
     * The two cleaners on a `show_in_rest` field's write are:
     *   1. the model's own bound sanitizer, inside update() — the metabox hands
     *      the value over uncleaned (FR-6), so this is the FIRST clean; and
     *   2. the `sanitize_callback` the model gave register_post_meta(), which
     *      WordPress fires from update_metadata() → sanitize_meta() on the very
     *      same write.
     *
     * That is why FR-2 requires every sanitizer to be idempotent: twice is
     * unavoidable on a published field, so twice must be indistinguishable from
     * once. This test drives cleaner 2 the way update_metadata() does and pins
     * both halves — the count, and the identical answer.
     */
    public function testAPublishedFieldIsCleanedTwiceAndBothCleansAgree(): void
    {
        $model = $this->publishingModel();
        $model->registerRestMeta('gig');

        $this->submit('gig', ['venue_city' => '  <b>Ghent</b>  ', 'internal' => '  <b>note</b>  ']);
        $this->save('gig');

        $afterModel = $this->stored('venue_city');

        $this->assertSame('text:Ghent', $afterModel, 'The model cleans it on the way in — never zero.');
        $this->assertSame(1, $this->spy['venue_city'] ?? 0, 'Cleaner 1: the model, exactly once.');

        $this->assertArrayHasKey(
            self::PREFIX . 'venue_city',
            $this->registrations,
            'A published field is registered with WordPress, and that registration carries cleaner 2.',
        );

        $callback = $this->registrations[self::PREFIX . 'venue_city']['sanitize_callback'] ?? null;

        $this->assertIsCallable($callback, 'register_post_meta() must hand WordPress a sanitize_callback.');

        // WordPress calls it as sanitize_meta( $meta_key, $meta_value, 'post' ).
        $afterWordPress = $callback($afterModel, self::PREFIX . 'venue_city', 'post');

        $this->assertSame(
            2,
            $this->spy['venue_city'] ?? 0,
            'Cleaner 2 is real: WordPress fires the registered callback on the SAME write, so a '
                . 'published field is cleaned twice per metabox save.',
        );
        $this->assertSame(
            $afterModel,
            $afterWordPress,
            'And twice must equal once. A non-idempotent sanitizer silently rewrites every published '
                . 'value on every save — the failure that only shows up on the third or fourth edit.',
        );
    }

    /**
     * An UNPUBLISHED field is cleaned exactly once: there is no registration,
     * so there is no second cleaner — and the one that runs is not optional.
     */
    public function testAnUnpublishedFieldIsCleanedExactlyOnce(): void
    {
        $model = $this->publishingModel();
        $model->registerRestMeta('gig');

        $this->submit('gig', ['venue_city' => 'x', 'internal' => '  <b>note</b>  ']);
        $this->save('gig');

        $this->assertArrayNotHasKey(
            self::PREFIX . 'internal',
            $this->registrations,
            'A field without show_in_rest is never registered, so WordPress has no callback to fire.',
        );
        $this->assertSame(1, $this->spy['internal'] ?? 0, 'Exactly once — the model\'s.');
        $this->assertSame(
            'text:note',
            $this->stored('internal'),
            'And once is never zero: a private field is cleaned just as hard as a published one.',
        );
    }

    // ==================================================== 3. THE DENIAL PATH

    /**
     * A retired name on a post type with a metabox and NO Data model is a loud
     * fault on the render side, and never a text box.
     *
     * `wysiwyg` is the name daan actually declared before this spec. The screen
     * must not quietly draw a single-line input for it: a text box is the
     * failure that looks like it worked, and it stores escaped markup back over
     * the real content on the next save.
     */
    public function testARetiredTypeOnANonDataPostTypeIsALoudFaultAndNeverATextBox(): void
    {
        try {
            $this->renderNative('legacy', ['title_line' => 'text', 'body' => 'wysiwyg']);
            $this->fail('A retired type name must refuse to render.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                "Unknown field type 'wysiwyg'. Use 'html'.",
                $e->getMessage(),
                'The refusal names the replacement: a developer must be able to act on it without reading core.',
            );
        }

        $partial = $this->lastRender;

        $this->assertStringContainsString(
            'name="ntdst_fields[title_line]"',
            $partial,
            'The screen was really rendering — the fault is the vocabulary\'s, not an empty page.',
        );
        $this->assertStringNotContainsString(
            'name="ntdst_fields[body]"',
            $partial,
            'And no control was drawn for the refused field. A text box here is the silent wrong answer.',
        );
    }

    /**
     * The same declaration on the SAVE side: nothing is written, the editor is
     * told, and the actionable detail goes to the log rather than to the screen.
     */
    public function testARetiredTypeOnANonDataPostTypeWritesNothingAndTellsTheEditor(): void
    {
        $this->modelTable()->setValue(null, []); // no Data model for 'legacy'

        $declaration = ['title_line' => 'text', 'body' => 'wysiwyg'];

        // The FIRST refusal is now at registration, for a plain post type as for
        // a model (reviewer S-5): the fleet's `wysiwyg` fields never reach an
        // editor at all. The save path keeps its own refusal underneath, because
        // a `fields` filter can still hand it a name it cannot resolve — so the
        // declaration goes in past register() to prove that half.
        try {
            $this->generator()->register('legacy', ['fields' => $declaration]);
            $this->fail('A retired name must be refused when the metabox is registered.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(
                "Use 'html'.",
                $e->getMessage(),
                'The registration refusal names the replacement, exactly as the model\'s does.',
            );
        }

        $models = new ReflectionProperty('NTDST_MetaboxGenerator', 'registered_models');
        $models->setAccessible(true);
        $models->setValue($this->generator(), ['legacy' => ['fields' => $declaration]]);

        $this->submit('legacy', ['title_line' => 'kept?', 'body' => '<p>hi</p>']);
        $this->save('legacy');

        $this->assertSame([], $this->metaWrites, 'A refused type stops the whole save — not half of it.');
        $this->assertSame([], $this->metaDeletes, 'And deletes nothing on the way out.');

        $notice = $this->notice();

        $this->assertStringContainsString('Saving failed', $notice, 'The editor is TOLD; a silent failure reads as "saved".');
        $this->assertStringNotContainsString('wysiwyg', $notice, 'The edit screen is not where a type vocabulary is debugged.');

        $logged = implode(' | ', array_map(
            static fn(array $entry): string => $entry[2] . ' ' . json_encode($entry[3]),
            $GLOBALS['_ntdst_test_log'] ?? [],
        ));

        $this->assertStringContainsString(
            "Use 'html'.",
            $logged,
            'The actionable message must reach the LOG, or the generic notice leaves nobody able to fix it.',
        );
    }

    /**
     * THE NONCE IS THE POST'S, not the post type's.
     *
     * One nonce per model per request meant one nonce for every post of that
     * model: a token minted on the screen for post A verified the save of post
     * B. The window is small — a nonce lives 12–24 hours — but inside it the
     * token is a general-purpose "save any gig" credential, and it travels in
     * the page of anything the holder can already edit.
     *
     * The round trip is HARVESTED, not assumed: whatever pair the screen emits
     * is the pair posted back, so this pins the promise (the screen for THIS
     * post is what saves it) and not a naming convention.
     */
    public function testTheNonceAScreenEmitsSavesThatPostAndNoOther(): void
    {
        $this->modelTable()->setValue(null, []);

        // A model name of its own: render_metabox() remembers, per PROCESS,
        // which models it has already given a nonce, so a name another case
        // used would be silently nonce-less here.
        $fields = ['title_line' => 'text'];
        $this->generator()->register('per_post', ['fields' => $fields]);

        $minted = [];
        Functions\when('wp_nonce_field')->alias(function ($action, $name = '_wpnonce') use (&$minted) {
            $minted[] = [$name, 'nonce:' . $action];
            echo '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">';
        });
        // WordPress's own answer: a nonce verifies against the ACTION it was
        // minted for, and against no other.
        Functions\when('wp_verify_nonce')->alias(
            static fn($value, $action = -1) => $value === 'nonce:' . $action ? 1 : false,
        );

        $this->render('per_post', $fields, self::POST_ID);
        $this->render('per_post', $fields, self::POST_ID + 1);

        $this->assertCount(
            2,
            $minted,
            'Each post\'s edit screen must carry its OWN nonce. One per post type is one credential '
                . 'that saves every post of that type.',
        );
        $this->assertNotSame($minted[0][1], $minted[1][1], 'And the two must not be the same token.');
        $this->assertStringContainsString(
            (string) self::POST_ID,
            $minted[0][1],
            'The action names the post it belongs to, or the two screens cannot differ.',
        );

        // The other post's nonce, replayed against this one: refused.
        [$foreignName, $foreignValue] = $minted[1];
        $_POST = [$foreignName => $foreignValue, 'ntdst_fields' => ['title_line' => 'pwned']];
        $this->save('per_post', self::POST_ID);

        $this->assertSame([], $this->metaWrites, 'A nonce minted for another post saves nothing here.');

        // This post's own nonce, replayed as the browser would: accepted.
        [$name, $value] = $minted[0];
        $_POST = [$name => $value, 'ntdst_fields' => ['title_line' => 'typed']];
        $this->save('per_post', self::POST_ID);

        $this->assertSame(
            'text:typed',
            $this->meta['title_line'] ?? null,
            'And the pair the screen actually emitted must save — the harvest is what makes this a '
                . 'round trip instead of a convention.',
        );
    }

    /**
     * A posted key the screen never declared cannot become a POST COLUMN.
     *
     * The metabox walked `$_POST['ntdst_fields']` verbatim into update(), and
     * NTDST_Data_Model maps `post_status`, `post_author` and `post_parent` onto
     * wp_posts columns. So one extra input in the edit form — a name the site
     * never declared — published a draft or handed it to another author, with
     * the real model, on a real save. Asserted here against the REAL model for
     * that reason: the columns are its half of the escalation.
     */
    public function testAPostedKeyOutsideTheDeclaredSetNeverBecomesAPostColumn(): void
    {
        $columns = [];
        Functions\when('wp_update_post')->alias(function ($data = [], $wpError = false) use (&$columns) {
            $columns[] = (array) $data;

            return self::POST_ID;
        });

        $this->registerGig();
        $this->submit('gig', [
            'venue_city'  => 'Ghent',
            'post_status' => 'publish',
            'post_author' => '1',
        ]);

        $this->save('gig');

        foreach ($columns as $written) {
            foreach (['post_status', 'post_author'] as $column) {
                $this->assertArrayNotHasKey(
                    $column,
                    $written,
                    "A metabox save wrote the `{$column}` COLUMN from a posted field name. The edit "
                        . 'form is not an allow-list; the declaration is.',
                );
            }
        }

        $this->assertSame(
            'text:Ghent',
            $this->stored('venue_city'),
            'And the declared field still saves — the refusal is scoped to what was never declared.',
        );
    }

    // ========================================================= the harness

    /** A generator with no hooks mounted — this file drives the entries directly. */
    private function generator(): NTDST_MetaboxGenerator
    {
        return $this->generator ??= (new ReflectionClass('NTDST_MetaboxGenerator'))->newInstanceWithoutConstructor();
    }

    /**
     * The full trip: render the screen, take the names it emitted as the shape
     * the browser posts, post plausible input into them, and save.
     */
    private function roundTrip(): void
    {
        $this->registerGig();

        $emitted = $this->submitNames($this->renderScreen());

        $this->assertSame(
            array_keys(self::TYPED),
            $emitted,
            'The posted shape is the shape the SCREEN emitted; if they differ this test is testing fiction.',
        );

        $this->submit('gig', array_intersect_key(self::TYPED, array_flip($emitted)));

        // The counted window is the SAVE. Opening the screen already ran the
        // read side, and for the types whose decode IS their cast (`int`,
        // `float`, `bool` declare no separate `read`) that is a legitimate
        // second call to the same WordPress function — one that says nothing
        // about how many times the save cleans.
        $this->calls = [];

        $this->save('gig');

        // The screen is re-opened in the same request in some tests; the editor
        // capture belongs to the render that is about to happen, not the one before.
        $this->editors = [];
    }

    /** Declare the seventeen fields on BOTH sides: the metabox and the ORM. */
    private function registerGig(): void
    {
        $this->generator()->register('gig', ['fields' => self::FIELDS]);

        $schema = [];
        foreach (self::FIELDS as $field => $config) {
            $schema[$field] = $config + ['sanitizer' => $this->countingSanitizer($field)];
        }

        $this->modelTable()->setValue(null, ['gig' => new NTDST_Data_Model('gig', $schema, self::PREFIX)]);
    }

    /** A model with one PUBLISHED field and one private one. */
    private function publishingModel(): NTDST_Data_Model
    {
        $this->generator()->register('gig', ['fields' => [
            'venue_city' => ['type' => 'text'],
            'internal'   => ['type' => 'text'],
        ]]);

        $model = new NTDST_Data_Model('gig', [
            'venue_city' => ['type' => 'text', 'show_in_rest' => true, 'sanitizer' => $this->countingSanitizer('venue_city')],
            'internal'   => ['type' => 'text', 'sanitizer' => $this->countingSanitizer('internal')],
        ], self::PREFIX);

        $this->modelTable()->setValue(null, ['gig' => $model]);

        return $model;
    }

    private function model(): NTDST_Data_Model
    {
        return ntdst_data()->get('gig');
    }

    /** Render the gig edit screen through the PUBLIC entry, output-buffered. */
    private function renderScreen(): string
    {
        return $this->render('gig', self::FIELDS);
    }

    /** Render a post type with a metabox and no Data model. */
    private function renderNative(string $type, array $fields): string
    {
        $this->modelTable()->setValue(null, []);

        return $this->render($type, $fields);
    }

    /**
     * The markup the LAST render emitted — kept on the instance so a render
     * that FAULTS half-way can still be inspected: what the screen had already
     * drawn when the vocabulary refused is the whole question in the denial test.
     */
    private string $lastRender = '';

    private function render(string $type, array $fields, int $postId = self::POST_ID): string
    {
        $post = new WP_Post();
        $post->ID = $postId;
        $post->post_type = $type;

        $this->lastRender = '';

        ob_start();

        try {
            $this->generator()->render_metabox($post, [
                'args' => ['model_name' => $type, 'fields' => $fields],
            ]);
        } finally {
            $this->lastRender = (string) ob_get_clean();
        }

        return $this->lastRender;
    }

    /**
     * The declared fields the rendered screen can actually submit, in
     * declaration order.
     *
     * Three shapes count, because the screen really has three:
     *   - a control that carries its own `name="ntdst_fields[<field>]"`;
     *   - the `html` control, whose name rides in wp_editor()'s `textarea_name`;
     *   - the JS-driven pickers (`relation`, `gallery`, `repeater`), which emit
     *     one input PER PICKED ITEM and, when the field is empty, only their
     *     `data-field-name="<field>"` container — which is exactly why an empty
     *     relation posts nothing and the save path has to read "absent" as
     *     "cleared".
     * A declared field that appears under none of the three cannot be saved
     * from the screen at all.
     *
     * @return list<string>
     */
    private function submitNames(string $html): array
    {
        $found = [];

        preg_match_all('/name="ntdst_fields\[([a-z0-9_]+)\]/', $html, $matches);
        foreach ($matches[1] as $name) {
            $found[$name] = true;
        }

        preg_match_all('/data-field-name="([a-z0-9_]+)"/', $html, $containers);
        foreach ($containers[1] as $name) {
            $found[$name] = true;
        }

        foreach ($this->editors as [$content, $name]) {
            if (preg_match('/^ntdst_fields\[([a-z0-9_]+)\]$/', (string) $name, $m) === 1) {
                $found[$m[1]] = true;
            }
        }

        return array_values(array_intersect(array_keys(self::FIELDS), array_keys($found)));
    }

    /** The decoded `value="…"` of the input whose name attribute is $name. */
    private function attributeValue(string $html, string $name): ?string
    {
        $needle = 'name="' . htmlspecialchars($name, ENT_QUOTES) . '"';

        foreach (explode('<', $html) as $tag) {
            if (!str_contains($tag, $needle)) {
                continue;
            }
            if (preg_match('/value="([^"]*)"/', $tag, $m) === 1) {
                return htmlspecialchars_decode($m[1], ENT_QUOTES);
            }
        }

        return null;
    }

    private function stored(string $field): mixed
    {
        return $this->meta[self::PREFIX . $field] ?? null;
    }

    /** What the edit screen POSTs: the model's nonce, and the fields array. */
    private function submit(string $model, array $fields): void
    {
        $_POST = [
            "ntdst_{$model}_nonce" => 'a-valid-looking-nonce',
            'ntdst_fields'         => $fields,
        ];
    }

    private function save(string $type, int $postId = self::POST_ID): void
    {
        $post = new WP_Post();
        $post->ID = $postId;
        $post->post_type = $type;

        $this->generator()->save_metabox_data($postId, $post);
    }

    /** What the editor sees on the next admin request after a failed save. */
    private function notice(): string
    {
        $_GET['post'] = (string) self::POST_ID;

        ob_start();
        $this->generator()->render_save_error_notice();

        return (string) ob_get_clean();
    }

    /** A no-op override that only counts: the registry's answer passes through. */
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

    private function modelTable(): ReflectionProperty
    {
        $property = new ReflectionProperty('NTDST_Data_Manager', 'models');
        $property->setAccessible(true);

        return $property;
    }
}
