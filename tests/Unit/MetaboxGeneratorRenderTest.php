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
//      list is a list that can disagree with the first (INV-8).
//   5. NO TYPE-NAME SWITCH SURVIVES. The two render switches are gone, and
//      render_repeater_media_cell() is folded or deleted — pinned against the
//      SOURCE, because a switch that is merely unreachable is still a second
//      vocabulary waiting for a caller.
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
        Functions\when('esc_url')->alias(static fn($u) => (string) $u);
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('wp_kses_post')->returnArg(1);

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
            'repeater → the table'     => ['slots', ['ntdst-repeater-table', 'data-field-name="slots"']],
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

    public function testARepeaterRowRendersEachCellThroughTheSameControls(): void
    {
        $row = $this->repeaterTable();

        $this->assertStringContainsString('name="ntdst_fields[slots][0][label]"', $row, 'A `text` cell keeps the row naming.');
        $this->assertStringContainsString('name="ntdst_fields[slots][0][qty]"', $row, 'An `int` cell keeps the row naming.');
        $this->assertStringContainsString('name="ntdst_fields[slots][0][photo]"', $row, 'A media cell keeps the row naming.');
    }

    /**
     * An `int` cell is a number input.
     *
     * The live bug: the row's own switch knew `number` and `integer` — two
     * names the vocabulary RETIRED — and not `int`, the name it actually uses.
     * Every declared `int` cell rendered through `default:` as text.
     */
    public function testAnIntCellRendersAsANumberInputNotText(): void
    {
        $row = $this->repeaterTable();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="number"[^>]*name="ntdst_fields\[slots\]\[0\]\[qty\]"/',
            $row,
            'An `int` cell must resolve to the `number` control, like the top-level field of the same type.',
        );
    }

    public function testAMediaCellRendersThePickerNotAText(): void
    {
        $row = $this->repeaterTable();

        $this->assertStringContainsString('ntdst-repeater-media-select', $row, 'An `image` cell is the media picker.');
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

    public function testTheSecondTypeListIsGone(): void
    {
        $this->assertFalse(
            (new ReflectionClass('NTDST_MetaboxGenerator'))->hasConstant('MARKER_ONLY_REQUIRED_TYPES'),
            'MARKER_ONLY_REQUIRED_TYPES was a second table of type names beside the registry; '
                . 'the native-`required` decision reads NTDST_FieldType $cell/$control now.',
        );
    }

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

    public function testNoTypeNameSwitchSurvivesInTheSource(): void
    {
        $source = file_get_contents(self::SOURCE);

        $this->assertIsString($source);

        $this->assertSame(
            0,
            preg_match_all("/case '[a-z_]+':|switch \(\\\$(type|field_type)\)/", $source, $unused),
            'Both render switches must be gone: one renderer keyed by the registry\'s control, '
                . 'and no arm keyed to a type NAME outside it.',
        );
    }

    public function testTheRepeaterMediaCellIsFoldedOrDeleted(): void
    {
        $source = file_get_contents(self::SOURCE);

        $this->assertIsString($source);

        $this->assertSame(
            0,
            preg_match_all('/function render_repeater_media_cell/', $source, $unused),
            'render_repeater_media_cell() served BOTH switches; with one renderer it is the `media` arm.',
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
