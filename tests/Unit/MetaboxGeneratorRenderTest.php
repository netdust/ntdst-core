<?php // tests/Unit/MetaboxGeneratorRenderTest.php
// The edit screen renders through the ONE vocabulary — one control per field.
//
// This is the RED contract for field-types T06 (Tier A, stakes high). It asserts
// the Cluster C promise — "every field renders through one renderer keyed by the
// registry's control, in a row or at top level, and an unknown control is a
// fault, never a text box" — plus FR-7.
//
// FIVE PROMISES, in the order this file asserts them:
//
//   1. ONE FIELD, ONE CONTROL. Every control the registry names renders its own
//      markup at top level, under the field's own `ntdst_fields[<field>]` name.
//      Two of these are LIVE BUGS at the time this file is written: a `html`
//      field falls through the old switch's `default:` to a plain text input
//      (the old arm was keyed to the retired name `wysiwyg`), and so its markup
//      is stored back as escaped soup; the `select` arm is the only one that
//      ever read the declaration.
//   2. A ROW IS THE SAME RENDERER. A repeater cell renders through the SAME
//      control arms, under the row naming `ntdst_fields[<field>][<i>][<sub>]`
//      and with no `<label>` wrapper. This too covers a live bug: the row's
//      own switch knew `number` and `integer` but not `int`, the name the
//      vocabulary actually uses, so every declared `int` cell rendered as text.
//   3. AN UNKNOWN CONTROL IS A FAULT. render_control() throws LogicException
//      rather than falling back to a text input. A text box is the failure mode
//      that LOOKS like it worked and silently restores the wrong shape on save.
//      A RETIRED TYPE NAME is a fault too, raised by the registry itself and
//      NOT caught here — the message names what to write instead.
//   4. THE SECOND TYPE LIST IS GONE. MARKER_ONLY_REQUIRED_TYPES was a second
//      table of type names next to the registry's; the native-`required`
//      decision now reads NTDST_FieldType's own `$cell`/`$control`. Any second
//      list is a list that can disagree with the first (INV-8). The NAME is
//      pinned by PackageBootIntegrityTest's removed-symbol sweep, beside
//      render_repeater_media_cell() and sanitize_field() — one sweep for every
//      symbol this release deleted, instead of a bespoke reflection case each
//      (simplicity S17).
//   5. NO TYPE-NAME SWITCH SURVIVES. Pinned against the SOURCE with INV-8's own
//      (A) shapes, because a switch that is merely unreachable is still a second
//      vocabulary waiting for a caller. Every hit must fall into ONE of five
//      NAMED exception families or the guard fails naming the line: the fifth
//      family — a comparison whose subject is a registry-resolved CONTROL — is
//      what separates "the renderer asks the registry" from "the file reads a
//      type name of its own".
//   6. A ROW TEMPLATE THE BROWSER CAN CLONE. The repeater's hidden
//      `<script type="text/html">` template must hold row markup and nothing
//      else: one stray `</script>` inside it ends the template early and spills
//      the rest onto the page (sentinel, this gate).
//
// HOW THIS FILE OBSERVES ALL OF THAT
// Through render_metabox() — the public entry WordPress itself calls for a
// metabox — output-buffered, on a NON-Data post type so the values come from
// get_post_meta() and this file makes no claim about the ORM read path. Only
// promise 3's denial path reaches a private method, and only because an unknown
// control cannot be reached from outside: no declaration can produce one.
//
// The WordPress stubs are MetaboxGeneratorSaveTest's in shape: real-equivalent
// where WordPress's own answer is the point (esc_attr, esc_html, esc_textarea),
// TAGGED where the question is WHICH function ran — wp_editor() cannot be told
// from a textarea by its output alone, so its stub prints a marker naming the
// editor id it was handed. sanitize_key() is a REAL function from
// tests/bootstrap.php and is never stubbed here.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';
require_once __DIR__ . '/../../admin/MetaboxGenerator.php';

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_status = 'publish';
    }
}

final class MetaboxGeneratorRenderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const POST_ID = 297050;

    /** The source this file pins the switches against. */
    private const SOURCE = __DIR__ . '/../../admin/MetaboxGenerator.php';

    private ?NTDST_MetaboxGenerator $generator = null;

    /** @var array<string, mixed> What get_post_meta() answers. */
    private array $meta = [];

    /**
     * One field per CONTROL the registry names — fifteen of them, reached
     * through fifteen DECLARED TYPE NAMES. The declaration is what a developer
     * writes; the control is what the registry answers, and this file asserts
     * the second is what decides the markup.
     */
    private const FIELDS = [
        'venue_city' => 'text',                                        // control: text
        'bio'        => 'textarea',                                    // control: textarea
        'body'       => 'html',                                        // control: html
        'capacity'   => 'int',                                         // control: number
        'price'      => 'float',                                       // control: decimal
        'featured'   => 'bool',                                        // control: checkbox
        'contact'    => 'email',                                       // control: email
        'homepage'   => 'url',                                         // control: url
        'starts_on'  => 'date',                                        // control: date
        'status'     => ['type' => 'select', 'options' => ['draft' => 'Draft', 'live' => 'Live']],
        'payload'    => 'json',                                        // control: json
        'poster'     => 'image',                                       // control: media
        'tags'       => ['type' => 'relation', 'post_type' => 'artist'],
        'shots'      => 'gallery',                                     // control: gallery
        'slots'      => [
            'type'       => 'repeater',
            'sub_fields' => ['label' => 'text', 'qty' => 'int', 'photo' => 'image'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->generator = null;

        // The stored values the edit screen is rendering. Attachment ids stay
        // under 100 so the get_post_type() stub calls them attachments.
        $this->meta = [
            'venue_city' => 'Ghent',
            'bio'        => "line\n\nmore",
            'body'       => '<p>markup</p>',
            'capacity'   => -500,
            'price'      => 12.5,
            'featured'   => 1,
            'contact'    => 'daan@example.org',
            'homepage'   => 'https://example.org',
            'starts_on'  => '2026-08-23',
            'status'     => 'live',
            'payload'    => ['a', 'b'],
            'poster'     => 5,
            'tags'       => [7],
            'shots'      => [8, 9],
            'slots'      => [['label' => 'A', 'qty' => 4, 'photo' => 6]],
        ];

        // ---- real-equivalents: WordPress's own answer IS the point ----
        Functions\when('esc_attr')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_html')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_textarea')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        // esc_url() is WordPress's OWN shape, not a pass-through: it is the only
        // escaper on the preview `<img src>` and on the attachment edit link, and
        // a pass-through stub there would call an unescaped URL safe. Core strips
        // everything outside its allowed character set (which takes `"`, `<` and
        // `>` with it), then encodes `&` and `'`.
        Functions\when('esc_url')->alias(static function ($url) {
            $url = str_replace(' ', '%20', ltrim((string) $url));
            $url = (string) preg_replace('/[^a-z0-9\-~+_.?#=!&;,\/:%@$|*\'()\[\]\x80-\xff]/i', '', $url);

            return str_replace(['&', "'"], ['&#038;', '&#039;'], $url);
        });
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        // TAGGED, so a render that started calling wp_kses_post() on a stored
        // value would be visible instead of invisible: the `html` control hands
        // the editor the RAW value by design, and that is asserted, not assumed.
        Functions\when('wp_kses_post')->alias(static fn($v) => 'kses:' . (string) $v);

        // ---- TAGGED: wp_editor() is invisible in its own output ----
        Functions\when('wp_editor')->alias(static function ($content, $editor_id, $settings = []) {
            echo '<!--wp_editor:' . $editor_id . ':' . ($settings['textarea_name'] ?? '') . '-->';
        });

        // ---- the store ----
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->meta[$key] ?? '');
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

        // ---- inert admin plumbing ----
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('wp_enqueue_media')->justReturn(null);
        Functions\when('add_action')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------- promise 6

    /**
     * The repeater's hidden row template holds ROW MARKUP AND NOTHING ELSE.
     *
     * A `<script type="text/html">` block ends at the first `</script>` the
     * parser sees, wherever it came from. The media picker's assets — a `<style>`
     * and a jQuery `<script>` — are emitted lazily by the FIRST `media` control
     * on the screen, and on a screen whose only media control is a repeater cell
     * with NO rows yet, that first control is the one inside the template. The
     * template then closes on the picker's own `</script>`: the rest of the row
     * markup lands in the visible page, the "Add Row" button clones a truncated
     * template, and the picker inside a new row is wired to nothing.
     *
     * Zero rows is the ordinary case — every repeater starts empty.
     *
     * FIRST IN THIS FILE ON PURPOSE. The picker assets are emitted once per
     * PROCESS (a function static), so any earlier media render in the suite
     * would make this case pass without proving anything. The guard below fails
     * loudly if that day comes, instead of going quietly green.
     */
    public function testTheRepeaterRowTemplateIsNotBrokenByThePickerAssets(): void
    {
        $statics = (new ReflectionMethod('NTDST_MetaboxGenerator', 'render_media_picker_assets'))
            ->getStaticVariables();

        $this->assertFalse(
            $statics['assets_rendered'] ?? true,
            'The media picker assets have already been emitted in this process, so this case can no '
                . 'longer see the bug it exists for. It must run BEFORE any other media render — keep '
                . 'it first in this file.',
        );

        $this->meta = []; // an empty repeater: no rows, only the template

        $html = $this->render(['slots' => [
            'type'       => 'repeater',
            'sub_fields' => ['photo' => 'image'],
        ]]);

        $open = strpos($html, '<script type="text/html"');
        $this->assertNotFalse($open, 'The repeater must emit a row template for its JS to clone.');

        $close = strpos($html, '</script>', $open);
        $this->assertNotFalse($close, 'The row template must be closed.');

        // From the END of the opening tag: the slice is the template's CONTENT,
        // not the tag that opens it. Slicing from $open would put `<script` in
        // every slice and make the assertion below unprovable.
        $start = strpos($html, '>', $open) + 1;
        $template = substr($html, $start, $close - $start);

        $this->assertStringNotContainsString(
            '<script',
            $template,
            'A nested <script> inside the row template ends the template at ITS closing tag: the rest '
                . 'of the row spills onto the page and the clone is truncated.',
        );
        $this->assertStringNotContainsString(
            '<style',
            $template,
            'And its stylesheet rides in with it — inside a text/html template it is inert markup, '
                . 'copied into every cloned row.',
        );
        $this->assertStringContainsString(
            'name="ntdst_fields[slots][__INDEX__][photo]"',
            $template,
            'The template must carry the row it exists to clone — an empty one would pass every other '
                . 'assertion here while cloning nothing.',
        );

        $assets = strpos($html, '.ntdst-repeater-media-select');
        $this->assertNotFalse(
            $assets,
            'The picker still needs its handlers on a screen whose ONLY media control is a repeater '
                . 'cell — hoisting them out of the template must not drop them.',
        );
        $this->assertLessThan(
            $open,
            $assets,
            'The picker assets belong BEFORE the template, not inside it.',
        );
    }

    // ------------------------------------------------------------- promise 1

    /**
     * @dataProvider topLevelControls
     *
     * Each declared field renders the markup of ITS control, and nothing else's.
     */
    public function testEachControlRendersItsOwnMarkupAtTopLevel(string $field, array $expected): void
    {
        $html = $this->renderAll();

        foreach ($expected as $needle) {
            $this->assertStringContainsString(
                $needle,
                $html,
                "Field '{$field}' must render its control's own markup: {$needle}",
            );
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function topLevelControls(): array
    {
        return [
            'text → text input'        => ['venue_city', ['type="text"', 'name="ntdst_fields[venue_city]"', 'value="Ghent"']],
            'textarea → textarea'      => ['bio', ['<textarea', 'name="ntdst_fields[bio]"']],
            'html → the editor'        => ['body', ['<!--wp_editor:ntdst_field_body:ntdst_fields[body]-->']],
            'number → step 1'          => ['capacity', ['type="number"', 'name="ntdst_fields[capacity]"', 'step="1"']],
            'decimal → step 0.01'      => ['price', ['type="number"', 'name="ntdst_fields[price]"', 'step="0.01"']],
            'checkbox → checkbox'      => ['featured', ['type="checkbox"', 'name="ntdst_fields[featured]"']],
            'email → email input'      => ['contact', ['type="email"', 'name="ntdst_fields[contact]"']],
            'url → url input'          => ['homepage', ['type="url"', 'name="ntdst_fields[homepage]"']],
            'date → date input'        => ['starts_on', ['type="date"', 'name="ntdst_fields[starts_on]"']],
            'select → the options'     => ['status', ['<select', 'name="ntdst_fields[status]"', '<option value="draft"', '<option value="live"', 'Draft', 'Live']],
            'json → the code area'     => ['payload', ['ntdst-field-array', '<textarea', 'name="ntdst_fields[payload]"']],
            'media → the picker'       => ['poster', ['ntdst-repeater-media', 'name="ntdst_fields[poster]"', 'ntdst-repeater-media-select']],
            'relation → the picker'    => ['tags', ['ntdst-relation-field', 'data-field-name="tags"', 'name="ntdst_fields[tags][]"']],
            'gallery → the container'  => ['shots', ['ntdst-gallery-field', 'data-field-name="shots"', 'name="ntdst_fields[shots][]"']],
            // No `data-field-name` needle here (simplicity S21): the repeater
            // submits through the row inputs this file already asserts, so the
            // attribute was pinned without a reader. The relation and gallery
            // rows keep theirs — assets/js/metabox-fields.js reads both to build
            // the `ntdst_fields[<field>][]` inputs their pickers post.
            'repeater → the table'     => ['slots', ['ntdst-repeater-table']],
        ];
    }

    /**
     * The `html` control is the editor, never a text input.
     *
     * Called out on its own because this is the live bug the one-renderer
     * change fixes: the old switch's arm was keyed to `wysiwyg`, the name
     * v5.0.0 retired, so a field declared `html` fell through `default:` to a
     * text input. Round-tripping markup through a text input escapes it, and
     * the next save stores the escaped soup as the value.
     */
    public function testAHtmlFieldIsNeverRenderedAsATextInput(): void
    {
        $html = $this->renderAll();

        $this->assertStringNotContainsString(
            'name="ntdst_fields[body]"',
            $html,
            'A `html` field must not render a plain named input — the editor owns the name.',
        );
    }

    // ------------------------------------------------------------- promise 2

    /**
     * Each declared cell renders ITS control, under the row naming.
     *
     * The live bug this covers: the row's own switch knew `number` and
     * `integer` — two names the vocabulary RETIRED — and not `int`, the name it
     * actually uses, so every declared `int` cell fell through to a text input.
     * The cell and the top-level field of the same type must resolve to the
     * same control, because they are the same declaration.
     *
     * @dataProvider repeaterCells
     */
    public function testEachCellRendersItsOwnControlUnderTheRowNaming(string $pattern, string $why): void
    {
        $this->assertMatchesRegularExpression($pattern, $this->repeaterTable(), $why);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function repeaterCells(): array
    {
        return [
            'text cell → a text input' => [
                '/<input[^>]*type="text"[^>]*name="ntdst_fields\[slots\]\[0\]\[label\]"/',
                'A `text` cell is a text input under the row naming.',
            ],
            'int cell → a number input' => [
                '/<input[^>]*type="number"[^>]*name="ntdst_fields\[slots\]\[0\]\[qty\]"/',
                'An `int` cell must resolve to the `number` control, like the top-level field of the same type.',
            ],
            'image cell → the media picker' => [
                '/name="ntdst_fields\[slots\]\[0\]\[photo\]"[^>]*class="ntdst-repeater-media-input"/',
                'An `image` cell is the media picker\'s hidden input, not a text box — same row naming.',
            ],
        ];
    }

    public function testARepeaterRowCarriesNoLabelWrapper(): void
    {
        $this->assertStringNotContainsString(
            '<label',
            $this->repeaterTable(),
            'A row cell is a table cell: the column header is the label, so the control renders bare.',
        );
    }

    // ------------------------------------------------------------- promise 3

    public function testAnUnknownControlIsAFaultNotATextBox(): void
    {
        $method = new ReflectionMethod('NTDST_MetaboxGenerator', 'render_control');
        $method->setAccessible(true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Unknown control 'nope'.");

        ob_start();

        try {
            $method->invoke($this->generator(), 'nope', 'ntdst_field_x', 'ntdst_fields[x]', '', [], false, 'x', '');
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A retired type name reaching the render side is the REGISTRY's fatal,
     * uncaught: the message names what to write instead.
     */
    public function testARetiredTypeNameOnTheRenderSideNamesItsReplacement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown field type 'wysiwyg'. Use 'html'.");

        $this->render(['legacy_body' => 'wysiwyg']);
    }

    // ------------------------------------------------------------- promise 4

    /**
     * Native `required` lands only on a control the browser can focus AND that
     * carries the value — and that question is answered by the registry entry,
     * not by a hand-kept list of type names.
     *
     * `text` is focusable and carries its value: native.
     * `bool` → `checkbox`: native `required` there means "must be TICKED".
     * `image` → `media`: the value is in a hidden input behind a picker button.
     * `html` is `cell: false` and hides its textarea behind the editor.
     */
    public function testNativeRequiredFollowsTheRegistryEntryNotATypeList(): void
    {
        $html = $this->render([
            'venue_city' => ['type' => 'text', 'required' => true],
            'featured'   => ['type' => 'bool', 'required' => true],
            'poster'     => ['type' => 'image', 'required' => true],
            'body'       => ['type' => 'html', 'required' => true],
        ]);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="ntdst_fields\[venue_city\]"[^>]* required aria-required="true"/',
            $html,
            'A `text` field the browser can focus and validate gets the native attribute.',
        );

        foreach (['featured' => 'checkbox', 'poster' => 'media', 'body' => 'html'] as $field => $control) {
            $this->assertDoesNotMatchRegularExpression(
                '/<[^>]*name="ntdst_fields\[' . $field . '\][^"]*"[^>]* required[ >]/',
                $html,
                "The `{$control}` control must never carry native `required`: a constraint on a control the "
                    . 'browser cannot focus makes the whole post form permanently unsubmittable.',
            );

            $this->assertStringContainsString(
                'aria-required="true"',
                $html,
                "The `{$control}` control still exposes the constraint to assistive tech.",
            );
        }
    }

    /**
     * The readonly display routes through the CONTROL: only `decimal` is
     * formatted, and `select`/`json` keep their own editable arms.
     */
    public function testTheReadonlyDisplayRoutesThroughTheControl(): void
    {
        $html = $this->render([
            'price'    => ['type' => 'float', 'readonly' => true],
            'capacity' => ['type' => 'int', 'readonly' => true],
            'status'   => ['type' => 'select', 'readonly' => true, 'options' => ['live' => 'Live']],
        ]);

        $this->assertStringContainsString('<strong>12.50</strong>', $html, 'The `decimal` control formats to two places.');
        $this->assertStringContainsString('<strong>-500</strong>', $html, 'The `number` control shows the stored value, sign and all.');
        $this->assertStringContainsString('<select', $html, 'A readonly `select` is still a select, disabled.');
    }

    // ------------------------------------------------------------- promise 5

    /**
     * INV-8's own check (A), run in-repo against this one file — and every hit
     * must be one of FIVE NAMED EXCEPTIONS or the guard fails naming the line.
     *
     * The vocabulary has one home. This file is where the second one always
     * grew back: two switches over type names, a marker-only list, a row walk
     * that knew `integer`. The earlier version of this case grepped for
     * `case '…':` and `switch ($type)` and was therefore blind to every shape
     * the code actually used next — a `match` head, an `in_array()` over a
     * hand-written pair, a `===` against a name in the save path (invariant
     * audit I4).
     *
     * WHAT IS SEARCHED (INV-8 (A), amended at the Cluster B and C audits):
     * a `switch (`/`match (` head; `case '<name>'`; a quoted `'<name>' ,`/`=>`
     * list shape; a `===`/`!==` against a quoted name in EITHER quote style
     * (the picker JS is double-quoted); and `in_array(`/`array_key_exists(`
     * over one. The NAMES are derived — the 17 canonical, the 13 retired, every
     * `control` the table names, plus `callback` — because a hand-typed list
     * here would be exactly the second table this guards against.
     *
     * THE FIVE EXCEPTION FAMILIES, by what the line MEANS (not by line number,
     * which moves on the next edit — the ledger's five families, tasks.md T09):
     *   1. THE RENDERER ITSELF — `match ($control)` and its arms. This is the
     *      convergence point, not a bypass of it.
     *   2. A CONTROL-NAME COMPARISON ON A REGISTRY-RESOLVED SUBJECT
     *      (`$control`, `->control`): a render- or save-capability question
     *      about what the REGISTRY answered. The subject is what makes it legal
     *      — the same question asked of a raw declared name is the defect this
     *      wave fixes, and it stays a failure here.
     *   3. THE `callback` RENDER DIRECTIVE — a field that renders itself. It has
     *      no registry entry and must not grow one, so it is answered before the
     *      registry is asked, on both the render and the save side.
     *   4. THE MEDIA WIDGET'S `image` VERSUS `file` QUESTION, in PHP and in its
     *      picker JS: two type names share the `media` control and the widget
     *      must still tell them apart to scope the wp.media library.
     *   5. THE PICKER JS'S OWN wp.media EVENT NAMES (`frame.on("select")`) —
     *      jQuery vocabulary that happens to collide with a type name.
     */
    public function testNoTypeNameSwitchSurvivesInTheSource(): void
    {
        $names = $this->vocabularyWords();
        $alternation = implode('|', $names);

        $shapes = [
            '/(switch|(^|[^A-Za-z0-9_])match) *\(/',
            "/case ['\"]({$alternation})['\"]/",
            "/['\"]({$alternation})['\"] *(,|=>)/",
            "/[!=]== *['\"]({$alternation})['\"]/",
            "/(in_array|array_key_exists) *\(.*['\"]({$alternation})['\"]/",
        ];

        // Keyed by the family each line belongs to, so a failure reads as
        // "this line is not one of the five" rather than as a regex count.
        $exceptions = [
            'the one renderer (match ($control) and its arms)' => '/^match \((\$[a-z_]*control|[^)]*->control)\)|^\'[a-z]+\' *=> \$this->[a-z_]+\(/',
            // The SUBJECT is what makes it legal: what the registry answered,
            // never the raw declared name. `in_array($type, …)` fails here, and
            // that is the invariant audit's CRITICAL.
            'a control-name comparison on a registry-resolved subject' => '/(\$[a-z_]*control|->control) *(!==|===)|(in_array|array_key_exists) *\( *[^,]*(\$[a-z_]*control|->control)/',
            'the `callback` render directive'                  => "/'callback'/",
            'the media widget\'s image-versus-file question'    => '/\$is_image\b|mediaType === "/',
            'the picker JS\'s own wp.media event names'         => '/\.on\("[a-z]+"/',
        ];

        $unexplained = [];

        foreach (file(self::SOURCE) as $n => $line) {
            $code = trim($line);

            // A comment may discuss a type name; a line of code may not.
            if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue;
            }

            $hit = false;
            foreach ($shapes as $shape) {
                if (preg_match($shape, $line) === 1) {
                    $hit = true;
                    break;
                }
            }

            if (!$hit) {
                continue;
            }

            foreach ($exceptions as $family) {
                if (preg_match($family, $code) === 1) {
                    continue 2;
                }
            }

            $unexplained[] = 'admin/MetaboxGenerator.php:' . ($n + 1) . ' → ' . $code;
        }

        $this->assertSame(
            [],
            $unexplained,
            "A type name is read here, outside the five named exceptions (INV-8):\n"
                . implode("\n", $unexplained)
                . "\n\nAsk NTDST_FieldTypes for the entry and key the decision on what it answers — "
                . 'a list of type names kept in this file is a second vocabulary, and it drifts.',
        );
    }

    /**
     * The words INV-8 (A) looks for: the whole vocabulary, everything it
     * retired, every control it names, and the one render directive that has no
     * entry. DERIVED, so this file carries no second copy of the table.
     *
     * @return list<string>
     */
    private function vocabularyWords(): array
    {
        $registry = new ReflectionClass(NTDST_FieldTypes::class);
        $retired = $registry->getConstant('RETIRED');

        $this->assertIsArray($retired, 'NTDST_FieldTypes::RETIRED is the retired-name table — the check needs it.');

        $words = array_merge(NTDST_FieldTypes::names(), array_keys($retired), ['callback']);

        foreach (NTDST_FieldTypes::names() as $name) {
            $words[] = NTDST_FieldTypes::get($name)->control;
        }

        return array_values(array_unique($words));
    }

    // -------------------------------------------- promise 6 (Cluster C feature)

    /**
     * A hostile STORED value goes through every control and breaks out of none.
     *
     * Written for the Cluster C feature gate, independently of T06 (threat rows
     * #1 and #5). The value is the classic attribute break-out — a quote to close
     * the attribute, a bracket to close the tag, then a script — and it is put
     * where a stored value actually lands: a `text`, an `email`, a `url`, a
     * `date`, a `number`, a `select` OPTION (value and label both), a `json`
     * textarea, a `html` field, and a repeater CELL. A row cell matters on its
     * own here: before this cluster the row had its own switch, so a cell could
     * be escaped by a different rule than the top-level field of the same type.
     *
     * `wp_editor()` is the one exception, and it is deliberate: it is HANDED the
     * markup un-escaped, because escaping it there is what turns stored content
     * into visible soup and stores the soup back on the next save. So the test
     * asserts the value was PASSED to the editor and never echoed into the page
     * around it.
     */
    public function testEveryControlEscapesAHostileValue(): void
    {
        $hostile = '"><script>x</script>';
        $escaped = htmlspecialchars($hostile, ENT_QUOTES);

        $handedToTheEditor = null;
        Functions\when('wp_editor')->alias(static function ($content, $editor_id, $settings = []) use (&$handedToTheEditor) {
            $handedToTheEditor = $content;
            echo '<!--wp_editor:' . $editor_id . '-->';
        });

        // The picker paths carry values this screen does NOT store: an
        // attachment's title, its alt text, its thumbnail URL, the edit link,
        // a related post's title. Every one of them is content someone else can
        // write — a contributor uploading a file, an author naming a post — so
        // they are made hostile too, and the `<img src>`/`<a href>` pair is the
        // only place on this screen escaped by esc_url() rather than esc_attr().
        Functions\when('get_the_title')->justReturn($hostile);
        Functions\when('wp_get_attachment_image_url')->justReturn('https://example.org/' . $hostile);
        Functions\when('admin_url')->alias(static fn($p = '') => 'https://example.org/wp-admin/' . $p . $hostile);
        Functions\when('get_posts')->alias(static fn(array $args = []) => array_map(
            static fn($id) => (object) ['ID' => (int) $id, 'post_title' => $hostile],
            $args['post__in'] ?? [],
        ));

        $this->meta = [
            'venue_city' => $hostile,
            'contact'    => $hostile,
            'homepage'   => $hostile,
            'starts_on'  => $hostile,
            'capacity'   => $hostile,
            'status'     => $hostile,
            'payload'    => $hostile,
            'body'       => $hostile,
            'poster'     => 5,
            'tags'       => [7],
            'shots'      => [8],
            'slots'      => [['label' => $hostile, 'qty' => $hostile, 'photo' => 0]],

            // What the gallery reads for its alt-text indicator.
            '_wp_attachment_image_alt' => $hostile,
        ];

        $html = $this->render([
            'venue_city' => 'text',
            'contact'    => 'email',
            'homepage'   => 'url',
            'starts_on'  => 'date',
            'capacity'   => 'int',
            'status'     => ['type' => 'select', 'options' => [$hostile => $hostile]],
            'payload'    => 'json',
            'body'       => 'html',
            'poster'     => 'image',
            'tags'       => ['type' => 'relation', 'post_type' => 'artist'],
            'shots'      => 'gallery',
            'slots'      => [
                'type'       => 'repeater',
                'sub_fields' => ['label' => 'text', 'qty' => 'int', 'photo' => 'image'],
            ],
        ]);

        $this->assertStringNotContainsString(
            $hostile,
            $html,
            'The stored value must never reach the page as it is stored: an unescaped `"` closes the '
                . 'attribute and an unescaped `<` opens a tag, and the edit screen runs as an administrator.',
        );
        $this->assertStringNotContainsString(
            '<script>x</script>',
            $html,
            'And the payload itself must not survive anywhere in the markup.',
        );

        // Every control that carries the value in an attribute neutralises both
        // characters, under its own name — top level AND inside the row.
        foreach ([
            'ntdst_fields[venue_city]',
            'ntdst_fields[contact]',
            'ntdst_fields[homepage]',
            'ntdst_fields[starts_on]',
            'ntdst_fields[capacity]',
            'ntdst_fields[slots][0][label]',
            'ntdst_fields[slots][0][qty]',
        ] as $name) {
            $this->assertStringContainsString(
                'name="' . $name . '" value="' . $escaped . '"',
                $html,
                "The control named `{$name}` must emit the hostile value with `\"` and `<` neutralised.",
            );
        }

        $this->assertStringContainsString(
            '<option value="' . $escaped . '"',
            $html,
            'A `select` OPTION is an attribute too — and its keys come from the declaration, which a '
                . 'site or a filter can write.',
        );
        $this->assertStringContainsString(
            $escaped,
            $html,
            'The `json` textarea holds the value as TEXT: `<` escaped, or the textarea ends early.',
        );

        $this->assertSame(
            $hostile,
            $handedToTheEditor,
            'wp_editor() is handed the value UN-escaped by design — escaping it there is what turns '
                . 'stored content into soup and saves the soup back. Un-escaped is not un-cleaned: the '
                . 'value was wp_kses_post()\'d on the way IN, and the tagged stub here would show a '
                . 'second clean on the way out.',
        );

        // The two attributes esc_url() owns. A URL is not an ordinary attribute
        // value: esc_attr() would leave `javascript:` intact, and esc_url() is
        // what strips the characters that close the attribute.
        foreach (['<img src="', '<a href="'] as $opening) {
            $this->assertStringContainsString($opening, $html, "The gallery must render {$opening}…");
        }

        $this->assertDoesNotMatchRegularExpression(
            '/(src|href)="[^"]*[<>]/',
            $html,
            'A URL that still carries `<` or `>` has not been through esc_url() — and both of them '
                . 'break out of the attribute they sit in.',
        );
    }

    // ------------------------------------------------------------- harness

    /** A generator with no hooks mounted — this file drives the entry directly. */
    private function generator(): NTDST_MetaboxGenerator
    {
        return $this->generator ??= (new ReflectionClass('NTDST_MetaboxGenerator'))->newInstanceWithoutConstructor();
    }

    /** The whole fifteen-control screen, rendered once. */
    private function renderAll(): string
    {
        return $this->render(self::FIELDS);
    }

    /**
     * Render a metabox through the PUBLIC entry, on a post type with no Data
     * model — so the values come from get_post_meta() and this file makes no
     * claim about the ORM read path.
     */
    private function render(array $fields): string
    {
        $post = new WP_Post();
        $post->ID = self::POST_ID;
        $post->post_type = 'note';

        ob_start();

        try {
            $this->generator()->render_metabox($post, [
                'args' => ['model_name' => 'note', 'fields' => $fields],
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Every rendered row carries its OWN index as `data-index`, and the hidden
     * template carries the `__INDEX__` placeholder — the two halves the "Add
     * Row" script reads.
     *
     * This is the PHP half of a JS invariant: indices are never REUSED. The
     * script used to number a new row with the current row COUNT, so on a table
     * whose middle row had been removed (rows 0, 2 left, count 2) the new row
     * came in as 2 as well — two rows posting the same key, and PHP keeps the
     * last one. Silent data loss on an ordinary edit. The fix derives the next
     * index from the DOM: the highest `data-index` on the table, plus one.
     *
     * There is no JS runner in this project, so this pins what the FIX READS
     * rather than the fix itself — if this attribute ever stops being emitted
     * per row, the script silently goes back to guessing. The behaviour itself
     * is a /shakeout probe.
     */
    public function testEveryRepeaterRowCarriesItsOwnIndexForTheAddRowScript(): void
    {
        $this->meta['slots'] = [
            ['label' => 'A', 'qty' => 1, 'photo' => 6],
            ['label' => 'B', 'qty' => 2, 'photo' => 7],
            ['label' => 'C', 'qty' => 3, 'photo' => 8],
        ];

        $table = $this->repeaterTable();

        $this->assertSame(
            3,
            preg_match_all('/<tr class="ntdst-repeater-row" data-index="(\\d+)">/', $table, $m),
            'Each stored row renders one row element carrying an index.',
        );
        $this->assertSame(
            ['0', '1', '2'],
            $m[1],
            'A row is numbered by its own position, and no two rows share a number.',
        );

        $html = $this->render(['slots' => self::FIELDS['slots']]);
        $this->assertStringContainsString(
            'data-index="__INDEX__"',
            $html,
            'The hidden template numbers itself through the placeholder the script substitutes.',
        );
    }

    /** Just the repeater's table — the rows, without the rest of the screen. */
    private function repeaterTable(): string
    {
        $html = $this->render(['slots' => self::FIELDS['slots']]);

        $open = strpos($html, '<table class="ntdst-repeater-table">');
        $close = strpos($html, '</table>', $open === false ? 0 : $open);

        $this->assertNotFalse($open, 'The `repeater` control renders a table.');
        $this->assertNotFalse($close, 'The repeater table is closed.');

        return substr($html, $open, $close - $open);
    }
}
