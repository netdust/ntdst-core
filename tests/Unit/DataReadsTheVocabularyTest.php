<?php // tests/Unit/DataReadsTheVocabularyTest.php
// The model reads the one vocabulary — and refuses everything outside it.
//
// This is the RED contract for field-types T03 (Tier A, stakes high: after this
// task the registry is the sanitizer for every create()/update()/updateMeta()
// and for every REST write). It asserts the PROMISE from spec FR-4 (revision 3),
// FR-5 and SC-3, and the plan's threat rows #4, #5, #6 and #7 — never an
// implementation shape.
//
// FOUR PROMISES, in the order this file asserts them:
//
//   1. REFUSAL AT REGISTRATION (threat #5, #7). Every field AND every sub-field
//      resolves through NTDST_FieldTypes::get() while the model is being
//      constructed — which is register() time on a site. A retired alias, an
//      invention, a `cell = false` type inside `sub_fields`, or two sub-field
//      names that sanitize to one key, is a fatal that NAMES the field (and the
//      sub-field, and the canonical to write instead). Not a text box, not a
//      silent fall-through to sanitize_text_field(), and not at save time —
//      at init. The rules are NTDST_FieldTypes::assertDeclarations()'s, and one
//      provider drives them through BOTH callers: the model's constructor and
//      NTDST_MetaboxGenerator::register() (reviewer S-5).
//   2. int KEEPS ITS SIGN (FR-5, threat #4) on every write path: update(),
//      create(), updateMeta(), updateMetaBatch(). absint() was the bug; a
//      discount in cents is a negative int.
//   3. A DECLARED `sanitizer` COMPOSES, IT NEVER REPLACES (FR-4 rev 3,
//      threat #6). The registry runs FIRST and the declared callable runs on
//      its OUTPUT, so a no-op override — or a `ntdst/{model}/fields` filter
//      that injects one — can tighten a field's sanitization and can never
//      switch wp_kses_post() off.
//   4. THE READ SIDE ANSWERS THE SAME VOCABULARY. formatMeta()'s private type
//      match is gone; a stored value reads back through the registry, so a
//      bool reads back bool and a negative int reads back negative.
//
// HOW THIS FILE OBSERVES ALL OF THAT
// Through the PUBLIC path only: construction (register()), update(), create(),
// updateMeta(), updateMetaBatch(), find() and registerRestMeta(). formatMeta()
// is protected and is reached through find() — get_post()/wp_cache_get() are
// stubbed, so no reflection and no test-only subclass is needed to watch the
// read side.
//
// The WordPress stubs are the ones FieldTypesTest and DataRegistersRestMetaTest
// already use: TAGGED where the question is WHICH function ran (a pass-through
// stub cannot tell sanitize_text_field() from sanitize_textarea_field(), so the
// wrong wiring would pass), real-equivalent where WordPress's own answer is the
// point. A tag is applied ONCE, because the real functions are idempotent and
// register_post_meta() applies them a second time on every REST write.
//
// One consequence worth naming: a value that is ALREADY STORED has already been
// sanitized, so the read-side cases seed the store with tagged values — that is
// what the database holds after a write, and it is what keeps those cases
// honest whether the read casts through the registry or not.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->msg; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

final class DataReadsTheVocabularyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The post meta store this process pretends to have. Keys are PREFIXED. */
    private array $stored = [];

    /** Every register_post_meta() call, in order: [postType, key, args]. */
    private array $registrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->stored = [];
        $this->registrations = [];
        $GLOBALS['_ntdst_test_log'] = [];

        // ---- sanitizers: tagged once (FieldTypesTest's harness, verbatim) ----
        $tagged = static function (string $tag, callable $inner): Closure {
            return static function ($value) use ($tag, $inner) {
                $raw = (string) $value;
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

        Functions\when('sanitize_text_field')->alias($tagged('text', $strip));
        Functions\when('sanitize_textarea_field')->alias($tagged('textarea', $strip));
        Functions\when('sanitize_email')->alias(
            $tagged('email', static fn(string $raw): string => (string) preg_replace('/[^A-Za-z0-9.@_+\-]/', '', $raw)),
        );
        // WordPress strips the TAG and keeps what was between them. Anything
        // stronger here would credit the vocabulary with a refusal
        // wp_kses_post() does not make.
        Functions\when('wp_kses_post')->alias(
            $tagged('kses', static fn(string $raw): string => (string) preg_replace('@</?(script|style)[^>]*>@i', '', $raw)),
        );
        $url = $tagged('url', static fn(string $raw): string => $raw);
        Functions\when('esc_url_raw')->alias(static function ($value) use ($url) {
            $raw = ltrim((string) $value);

            return stripos($raw, 'javascript:') === 0 ? '' : $url($value);
        });

        // ---- real-equivalents: WordPress's own algorithm, no tag ----
        // sanitize_key() is a REAL function from tests/bootstrap.php.
        Functions\when('sanitize_title')->alias(
            static fn($value) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)),
        );
        Functions\when('absint')->alias(static fn($value) => abs((int) $value));
        Functions\when('wp_validate_boolean')->alias(static function ($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value) && 'false' === strtolower($value)) {
                return false;
            }

            return (bool) $value;
        });
        Functions\when('get_post_type')->alias(
            static fn($post = null) => ((int) $post >= 1 && (int) $post <= 99) ? 'attachment' : 'post',
        );

        // ---- the store ----
        Functions\when('get_post')->alias(static fn($id) => (object) [
            'ID'          => (int) $id,
            'post_type'   => 'p',
            'post_status' => 'publish',
            'post_title'  => 'a row',
        ]);
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->stored[$key] = $value;

            return true;
        });
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->stored[$key] ?? '');
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->stored[$key]);

            return true;
        });
        Functions\when('metadata_exists')->alias(fn($type, $id, $key) => array_key_exists($key, $this->stored));
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('wp_update_post')->alias(static fn($data) => (int) ($data['ID'] ?? 42));
        Functions\when('wp_delete_post')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('maybe_serialize')->alias(static fn($v) => is_scalar($v) ? (string) $v : serialize($v));

        // find() reads through NTDST_Data_Manager::getPostMeta(), which prefers
        // core's post_meta cache — one value per key, the way WordPress holds it.
        Functions\when('wp_cache_get')->alias(fn($id = null, $group = '') => $group === 'post_meta'
            ? array_map(static fn($v) => [$v], $this->stored)
            : false);

        Functions\when('register_post_meta')->alias(function ($postType, $key, $args) {
            $this->registrations[] = [$postType, $key, $args];

            return true;
        });
        Functions\when('user_can')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------- harness

    /** @param array<string, mixed> $schema */
    private function model(array $schema): NTDST_Data_Model
    {
        return new NTDST_Data_Model('p', $schema, '_p_');
    }

    private function storedValue(string $field): mixed
    {
        return $this->stored['_p_' . $field] ?? null;
    }

    /** What find() hands a consumer for one field — the read side. */
    private function readBack(NTDST_Data_Model $model, string $field): mixed
    {
        $post = $model->find(1);
        $this->assertIsObject($post, 'find() must return the row, not an error.');

        return $post->fields[$field] ?? null;
    }

    /**
     * The types that may not sit in a repeater row, DERIVED from the registry
     * rather than copied out of it — `cell = false` is the vocabulary's own
     * verdict and this file must not carry a second copy of it (INV-8).
     *
     * `repeater` is the one exception: it is cell = false because a row cannot
     * RENDER a repeater control, but a repeater nested in a repeater is legal
     * and its rows are sanitized recursively (pinned at the Cluster A gate).
     *
     * @return array<string, array{string}>
     */
    public static function cellLessTypeProvider(): array
    {
        $rows = [];
        foreach (NTDST_FieldTypes::names() as $name) {
            if ($name !== 'repeater' && NTDST_FieldTypes::get($name)->cell === false) {
                $rows[$name] = [$name];
            }
        }

        return $rows;
    }

    /**
     * Every warning the real ntdst_log() recorder took on the `data` channel.
     * tests/bootstrap.php stores [channel, level, message, context] — the
     * context matters here, because "Unregistered key(s) passed to …" puts the
     * operation in the message and the KEYS in the context.
     *
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    private function warnings(): array
    {
        $entries = array_filter(
            $GLOBALS['_ntdst_test_log'] ?? [],
            static fn(array $entry): bool => ($entry[0] ?? '') === 'data' && ($entry[1] ?? '') === 'warning',
        );

        return array_values(array_map(
            static fn(array $entry): array => [
                'message' => (string) ($entry[2] ?? ''),
                'context' => (array) ($entry[3] ?? []),
            ],
            $entries,
        ));
    }

    // ------------------------------------------- 1. refusal at registration

    /**
     * EVERY declaration the vocabulary refuses, refused while the MODEL is
     * being constructed — which is register() time on a site.
     *
     * One provider, driven twice: here through the model's constructor, and in
     * the case below through NTDST_MetaboxGenerator::register(). The rules
     * themselves live in NTDST_FieldTypes::assertDeclarations() (reviewer S-5),
     * because until this wave the metabox validated NOTHING: the same
     * declaration that fatally refused to register as a model was accepted by a
     * plain post type and only surfaced — as a save-time notice, or a text box —
     * once an editor had typed into it.
     *
     * threat #5, #7: the message NAMES the field, the sub-field and the
     * canonical to write instead, because on a site that fatal is the whole bug
     * report.
     *
     * @param array<string, mixed> $fields
     * @param list<string>         $mustSay
     * @param list<string>         $mustNotSay
     *
     * @dataProvider refusedDeclarationProvider
     */
    public function testAModelRefusesEveryDeclarationTheVocabularyRefuses(
        array $fields,
        array $mustSay,
        array $mustNotSay = [],
    ): void {
        try {
            $this->model($fields);
            $this->fail('Expected InvalidArgumentException for a declaration outside the vocabulary.');
        } catch (InvalidArgumentException $e) {
            $this->assertRefusal($e, $mustSay, $mustNotSay);
        }
    }

    /**
     * THE SAME declaration, THE SAME message, from a metabox registration on a
     * post type with no model at all (reviewer S-5).
     *
     * A `fields` array is a `fields` array: the plain-post-type half of the
     * fleet declares one too, and it reached the save path unchecked. A retired
     * name there became a "Saving failed" notice AFTER the editor had typed a
     * screenful; a `cell = false` sub-field became a text input that stored the
     * escaped soup over the real markup. Both are init-time faults, on both
     * paths, and they are the same fault — so they must carry the same words.
     *
     * @param array<string, mixed> $fields
     * @param list<string>         $mustSay
     * @param list<string>         $mustNotSay
     *
     * @dataProvider refusedDeclarationProvider
     */
    public function testAMetaboxRegistrationRefusesItWithTheSameMessage(
        array $fields,
        array $mustSay,
        array $mustNotSay = [],
    ): void {
        $generator = (new ReflectionClass('NTDST_MetaboxGenerator'))->newInstanceWithoutConstructor();

        try {
            $generator->register('legacy', ['fields' => $fields]);
            $this->fail(
                'NTDST_MetaboxGenerator::register() accepted a declaration the vocabulary refuses. '
                    . 'The rules belong to NTDST_FieldTypes::assertDeclarations(), and both callers ask it.',
            );
        } catch (InvalidArgumentException $e) {
            $this->assertRefusal($e, $mustSay, $mustNotSay);
        }
    }

    /**
     * The declarations the vocabulary refuses, and the words the refusal owes.
     *
     * The cell-less rows are DERIVED from the registry (`cell = false`), so this
     * file carries no second copy of that verdict — the case below pins what the
     * derivation currently derives to, or an empty provider would make both
     * cases above vacuously green.
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2?: list<string>}>
     */
    public static function refusedDeclarationProvider(): array
    {
        $rows = [
            // FR-3/FR-5: `signed_int` folded into a signed `int`, and a bare
            // type string is a declaration like any other.
            'a retired alias as a bare string' => [
                ['n' => 'integer'],
                ["Field 'n'", "Use 'int'"],
            ],
            'a retired alias under type' => [
                ['n' => ['type' => 'integer']],
                ["Field 'n'", "Use 'int'"],
            ],
            // D5: an invention gets the known set, never a canonical.
            'an invented name' => [
                ['n' => ['type' => 'gubbins']],
                ["Field 'n'", 'Known:'],
                ["Use '"],
            ],
            // threat #6: a field that brings its own `sanitizer` is exactly the
            // door an attacker would use, so the type is checked first anyway.
            'an invented name beside a sanitizer' => [
                ['n' => ['type' => 'gubbins', 'sanitizer' => 'strval']],
                ["Field 'n'", 'Known:'],
            ],
            'a retired name inside sub_fields' => [
                ['provenance' => ['type' => 'repeater', 'sub_fields' => ['notes' => ['type' => 'wysiwyg']]]],
                ['provenance', 'notes', "Use 'html'"],
            ],
            // FR-4: depth buys a cell-less type no way in — a nested repeater's
            // rows are rendered as cells too.
            'a cell-less type at depth two' => [
                ['provenance' => [
                    'type'       => 'repeater',
                    'sub_fields' => ['rows' => [
                        'type'       => 'repeater',
                        'sub_fields' => ['body' => ['type' => 'html']],
                    ]],
                ]],
                ['body', "'html' cannot be a repeater sub-field"],
            ],
            // A row is stored under rowKey() of its cell name, so two names that
            // fold into one key are one cell: the second declaration silently
            // takes the first one's type.
            'two sub-field names that fold to one key' => [
                ['provenance' => ['type' => 'repeater', 'sub_fields' => [
                    'SubTitle' => ['type' => 'text'],
                    'subTitle' => ['type' => 'int'],
                ]]],
                ['provenance', 'subtitle'],
            ],
            // Nothing runs a sub-field's `sanitizer`: the row walk cleans each
            // cell by its DECLARED TYPE and never looks for a callable, so the
            // declaration means the author believes a cell is being tightened
            // while it is not. "Quietly does nothing" is the worst answer a
            // security declaration can get.
            'a sub-field that declares a sanitizer' => [
                ['rows' => ['type' => 'repeater', 'sub_fields' => [
                    'title' => ['type' => 'text', 'sanitizer' => 'strval'],
                ]]],
                ["Field 'rows' sub-field 'title': a sub-field cannot declare a 'sanitizer'."],
            ],
        ];

        // threat #5 / SC-3: a `cell = false` type inside `sub_fields` renders as
        // a single-line text input and stores whatever that box held.
        foreach (self::cellLessTypeProvider() as [$type]) {
            $rows["a cell-less '{$type}' sub-field"] = [
                ['provenance' => ['type' => 'repeater', 'sub_fields' => ['notes' => ['type' => $type]]]],
                ["Field 'provenance' sub-field 'notes': '{$type}' cannot be a repeater sub-field"],
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $mustSay
     * @param list<string> $mustNotSay
     */
    private function assertRefusal(InvalidArgumentException $e, array $mustSay, array $mustNotSay): void
    {
        foreach ($mustSay as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $e->getMessage(),
                'The refusal must say what is wrong and what to write instead — it is the whole bug report.',
            );
        }

        foreach ($mustNotSay as $fragment) {
            $this->assertStringNotContainsString($fragment, $e->getMessage());
        }
    }

    /**
     * The refused list is derived, and this is what it derives to today: a
     * hard-coded list here would be the second table INV-8 forbids, and an empty
     * provider would make the case above vacuously green.
     */
    public function testTheRefusedSubFieldTypesAreTheCellLessOnesApartFromRepeater(): void
    {
        $this->assertSame(
            ['html', 'relation', 'gallery'],
            array_keys(self::cellLessTypeProvider()),
            'Derived from NTDST_FieldTypes::get()->cell — if this changes, the vocabulary changed.',
        );
        $this->assertFalse(
            NTDST_FieldTypes::get('repeater')->cell,
            'A repeater is cell = false for RENDERING; nesting is still legal (next case).',
        );
    }

    /** Cluster A pinned recursion: a repeater inside a repeater is a legal declaration. */
    public function testANestedRepeaterIsAllowed(): void
    {
        $model = $this->model([
            'provenance' => [
                'type'       => 'repeater',
                'sub_fields' => [
                    'title' => ['type' => 'text'],
                    'rows'  => [
                        'type'       => 'repeater',
                        'sub_fields' => ['qty' => ['type' => 'int']],
                    ],
                ],
            ],
        ]);

        $model->update(1, ['provenance' => [
            ['title' => ' <b>a</b> ', 'rows' => [['qty' => '-2']]],
        ]]);

        $this->assertSame(
            [['title' => 'text:a', 'rows' => [['qty' => -2]]]],
            $this->storedValue('provenance'),
            'A nested repeater sanitizes its grandchildren through their declared types.',
        );
    }

    // ------------------------------------------------- 2. int keeps its sign

    /** SC-3 / FR-5, the update() path — absint() was the bug. */
    public function testUpdateStoresANegativeInt(): void
    {
        $model = $this->model(['price' => ['type' => 'int']]);

        $model->update(1, ['price' => '-250']);

        $this->assertSame(-250, $this->storedValue('price'));
    }

    /** FR-5, the updateMeta() path — its own call site, its own binding. */
    public function testUpdateMetaStoresANegativeInt(): void
    {
        $model = $this->model(['price' => ['type' => 'int']]);

        $model->updateMeta(1, 'price', '-7');

        $this->assertSame(-7, $this->storedValue('price'));
    }

    /** FR-5, the updateMetaBatch() path — the second call site. */
    public function testUpdateMetaBatchStoresANegativeInt(): void
    {
        $model = $this->model(['price' => ['type' => 'int']]);

        $model->updateMetaBatch(1, ['price' => '-9']);

        $this->assertSame(-9, $this->storedValue('price'));
    }

    /** FR-5, the create() path — "a negative value written through ANY path". */
    public function testCreateStoresANegativeInt(): void
    {
        $model = $this->model(['price' => ['type' => 'int']]);

        $model->create(['price' => '-3']);

        $this->assertSame(-3, $this->storedValue('price'));
    }

    // ------------------------------------------ 3. the bound sanitizer is the registry's

    /** The tag names which WordPress function ran: text, not textarea, not kses. */
    public function testTheBoundSanitizerForTextIsTheRegistrysText(): void
    {
        $model = $this->model(['headline' => ['type' => 'text']]);

        $model->update(1, ['headline' => '  <b>x</b>  ']);

        $this->assertSame('text:x', $this->storedValue('headline'));
    }

    /**
     * THE UNDECLARED KEY, through the two meta paths (reviewer S-5).
     *
     * `updateMeta()` and `updateMetaBatch()` take a key by name, so a typo — or
     * a module writing a key this model never declared — arrives here and not at
     * create()/update(). Those two answer it already: they WARN, naming the key,
     * and store it through sanitize_text_field(). The meta paths give the same
     * answer, because the alternative is the one that bites: a key this model
     * does not declare going into the database exactly as it was posted, while
     * the same key through create() is cleaned. One model, two answers, and the
     * safe one depending on which method a caller happened to reach for.
     *
     * @dataProvider undeclaredMetaPathProvider
     */
    public function testAnUndeclaredKeyThroughAMetaPathIsWarnedAndStoredAsText(string $path): void
    {
        $model = $this->model(['headline' => ['type' => 'text']]);

        $path === 'updateMeta'
            ? $model->updateMeta(1, 'undeclared', '  <b>x</b>  ')
            : $model->updateMetaBatch(1, ['undeclared' => '  <b>x</b>  ']);

        $this->assertSame(
            'text:x',
            $this->storedValue('undeclared'),
            "{$path}(): a key this model does not declare is still not stored raw — "
            . 'create() and update() clean it with sanitize_text_field(), and so does this path.',
        );

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings, "{$path}(): an unregistered key warns exactly once.");
        $this->assertStringContainsString(
            'Unregistered key',
            $warnings[0]['message'],
            "{$path}(): the warning must say what went wrong.",
        );
        $this->assertStringContainsString(
            'undeclared',
            $warnings[0]['message'] . ' ' . (string) json_encode($warnings[0]['context']),
            "{$path}(): the warning must name the key, or nobody can find the typo.",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function undeclaredMetaPathProvider(): array
    {
        return [
            'updateMeta'      => ['updateMeta'],
            'updateMetaBatch' => ['updateMetaBatch'],
        ];
    }

    /** A DECLARED key through the same paths is still the field's own sanitizer, and silent. */
    public function testADeclaredKeyThroughAMetaPathIsNotWarnedAbout(): void
    {
        $model = $this->model(['headline' => ['type' => 'text']]);

        $model->updateMeta(1, 'headline', '  <b>x</b>  ');
        $model->updateMetaBatch(1, ['headline' => '  <b>y</b>  ']);

        $this->assertSame('text:y', $this->storedValue('headline'));
        $this->assertSame([], $this->warnings(), 'A declared key is not a typo.');
    }

    // ------------------------------------------------- 4. the override composes

    /**
     * threat #6, the attack itself: a `ntdst/{model}/fields` filter (or a
     * consumer) declares `'sanitizer' => fn($v) => $v` on a html field. Before
     * spec rev 3 that REPLACED wp_kses_post() with nothing on every write path,
     * REST included. The registry runs first, so the script tag is gone whatever
     * the override does.
     */
    public function testANoOpOverrideOnAHtmlFieldStillStripsScript(): void
    {
        $model = $this->model([
            'body' => ['type' => 'html', 'sanitizer' => static fn($v) => $v],
        ]);

        $model->update(1, ['body' => '<p>a</p><script>x</script>']);

        $stored = (string) $this->storedValue('body');
        $this->assertStringNotContainsString('<script', $stored, 'The registry ran, and it ran FIRST.');
        $this->assertSame('kses:<p>a</p>x', $stored, "wp_kses_post()'s own answer, unweakened.");
    }

    /** The override may TIGHTEN: it applies on top of the registry's output. */
    public function testATighteningOverrideAppliesOnTopOfTheRegistrysOutput(): void
    {
        $model = $this->model([
            'headline' => ['type' => 'text', 'sanitizer' => static fn($v) => strtoupper((string) $v)],
        ]);

        $model->update(1, ['headline' => '  <b>x</b>  ']);

        $this->assertSame('TEXT:X', $this->storedValue('headline'));
    }

    /** The composition order, watched from inside: the override sees the registry's OUTPUT. */
    public function testTheOverrideReceivesTheRegistrysOutput(): void
    {
        $seen = 'never called';
        $model = $this->model([
            'headline' => [
                'type'      => 'text',
                'sanitizer' => static function ($v) use (&$seen) {
                    $seen = $v;

                    return $v;
                },
            ],
        ]);

        $model->update(1, ['headline' => '  <b>x</b>  ']);

        $this->assertSame('text:x', $seen, 'The declared callable is handed the sanitized value, never the raw one.');
    }

    // ---------------------------------------------------- 5. the read side

    /** A stored "1" for a bool field reads back as a bool, not as a string. */
    public function testAStoredBooleanStringReadsBackAsBool(): void
    {
        $model = $this->model(['flag' => ['type' => 'bool']]);

        $this->stored['_p_flag'] = '1';
        $this->assertSame(true, $this->readBack($model, 'flag'));

        $this->stored['_p_flag'] = 'false';
        $this->assertSame(false, $this->readBack($model, 'flag'));
    }

    /** FR-5 on the read side: a stored "-3" reads back as -3, not as 3 and not as "-3". */
    public function testAStoredNegativeIntReadsBackAsANegativeInt(): void
    {
        $model = $this->model(['price' => ['type' => 'int']]);

        $this->stored['_p_price'] = '-3';

        $this->assertSame(-3, $this->readBack($model, 'price'));
    }

    /**
     * A json field is stored as a JSON string by the metabox and reads back as
     * an array. The seeded value is already sanitized, which is what the
     * database holds after a write.
     */
    public function testAStoredJsonStringReadsBackAsAnArray(): void
    {
        $model = $this->model(['blob' => ['type' => 'json']]);

        $this->stored['_p_blob'] = '{"k":"text:v","n":3}';

        $this->assertSame(['k' => 'text:v', 'n' => 3], $this->readBack($model, 'blob'));
    }

    /** A relation holds post ids: WordPress hands back the stored strings, the model hands back ints. */
    public function testAStoredRelationReadsBackAsInts(): void
    {
        $model = $this->model(['related' => ['type' => 'relation']]);

        $this->stored['_p_related'] = ['1', '2'];

        $this->assertSame([1, 2], $this->readBack($model, 'related'));
    }

    /** A repeater reads back as its rows — intact, in order, nothing flattened. */
    public function testAStoredRepeaterReadsBackWithItsRowsIntact(): void
    {
        $model = $this->model([
            'provenance' => [
                'type'       => 'repeater',
                'sub_fields' => ['qty' => ['type' => 'int'], 'title' => ['type' => 'text']],
            ],
        ]);

        $this->stored['_p_provenance'] = [
            ['qty' => 3, 'title' => 'text:a'],
            ['qty' => -1, 'title' => 'text:b'],
        ];

        $this->assertSame(
            [['qty' => 3, 'title' => 'text:a'], ['qty' => -1, 'title' => 'text:b']],
            $this->readBack($model, 'provenance'),
        );
    }

    /** The empty state: a declared field with nothing stored reads back as its type's empty answer. */
    public function testAFieldWithNothingStoredReadsBackAsItsTypesEmptyAnswer(): void
    {
        $model = $this->model([
            'flag'     => ['type' => 'bool'],
            'price'    => ['type' => 'int'],
            'headline' => ['type' => 'text'],
        ]);

        $post = $model->find(1);

        $this->assertSame(false, $post->fields['flag']);
        $this->assertSame(0, $post->fields['price']);
        $this->assertSame('', $post->fields['headline']);
    }

    /**
     * THE READ DOES NOT REWRITE WHAT IS STORED (reviewer IMP-3).
     *
     * The value seeded here is one this model's own sanitizer would never have
     * produced: a newline and a percent-encoded slash, from a gig imported into
     * daan before ntdst declared the field. A read that re-sanitized would
     * silently show a DIFFERENT string on the page from the one the database
     * holds — and the editor who fixes the page would be fixing a value that was
     * never wrong.
     *
     * The stub makes it an observation: sanitize_text_field() fails the test if
     * the read calls it at all.
     */
    public function testAValueStoredOutsideThisModelIsNotRewrittenOnRead(): void
    {
        $model = $this->model(['headline' => ['type' => 'text']]);

        $stored = "a\nb 100%2F50";
        $this->stored['_p_headline'] = $stored;

        Functions\when('sanitize_text_field')->alias(
            fn($value) => $this->fail('A read must not re-sanitize: sanitize_text_field() was called on find().'),
        );

        $this->assertSame($stored, $this->readBack($model, 'headline'));
    }

    /**
     * A repeater whose rows were stored as the JSON string the metabox posts
     * reads back as ROWS — and a value in the list that is not a row is not a
     * row on the way out either. Nothing in the list is re-sanitized: the cells
     * were cleaned by their declared types on the way in.
     */
    public function testAStoredRepeaterJsonStringReadsBackAsRowsOnly(): void
    {
        $model = $this->model([
            'provenance' => [
                'type'       => 'repeater',
                'sub_fields' => ['t' => ['type' => 'text']],
            ],
        ]);

        $this->stored['_p_provenance'] = '[{"t":"a"},"junk",{"t":"b"}]';

        $this->assertSame(
            [['t' => 'a'], ['t' => 'b']],
            $this->readBack($model, 'provenance'),
        );
    }

    // ------------------------------- 6. the model decodes nothing of its own

    /**
     * The read side lives in the vocabulary, so the model keeps no decoder and
     * no key rule of its own (simplicity I2, auditor R1/R2). Each of these was a
     * second answer that could disagree with the registry's: `rowKey()` decided
     * what a cell is stored under while NTDST_FieldTypes::declarations() decided
     * the same thing, and the two decoders below decided what a stored value
     * means while the entry that wrote it was right there.
     *
     * @dataProvider removedReadHelperProvider
     */
    public function testTheModelKeepsNoReaderOrKeyRuleOfItsOwn(string $method): void
    {
        $this->assertFalse(
            (new ReflectionClass(NTDST_Data_Model::class))->hasMethod($method),
            "NTDST_Data_Model::{$method}() is a second answer to a question the vocabulary already "
            . 'answers; the registry is the only one.',
        );
    }

    /** @return array<string, array{string}> */
    public static function removedReadHelperProvider(): array
    {
        return [
            'rowKey'              => ['rowKey'],
            'formatRepeaterField' => ['formatRepeaterField'],
            'decodeArrayField'    => ['decodeArrayField'],
            // The DECLARATION rules went the same way (reviewer S-5): what type
            // a declaration names, and which declarations are refused outright,
            // are the vocabulary's questions — asked by the model's constructor
            // AND by NTDST_MetaboxGenerator::register(), which had no answer of
            // its own at all.
            'assertRowTypes'      => ['assertRowTypes'],
            'declaredType'        => ['declaredType'],
        ];
    }

    /**
     * NOTHING ON THE READ PATH UNSERIALIZES.
     *
     * WordPress's own maybe_unserialize() has already run by the time a meta
     * value reaches this model, so a string that is STILL serialized here is not
     * a value this model wrote — it is a value some other writer put in the row,
     * and unserialize() on it is object instantiation from stored bytes. The
     * legacy `@unserialize()` in the repeater reader is the shape this pins
     * away; `maybe_unserialize()` (WordPress's, which refuses objects) is not
     * the same call and is not what this refuses.
     *
     * @dataProvider unserializeFreeFileProvider
     */
    public function testNoFileOnTheReadPathUnserializesAStoredValue(string $relativePath): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $hits = [];

        foreach (file($path) as $number => $line) {
            $code = trim($line);
            if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue; // a comment may discuss it; a call may not
            }
            if (preg_match('/(?<!maybe_)unserialize\s*\(/', $line) === 1) {
                $hits[] = $relativePath . ':' . ($number + 1) . ' → ' . $code;
            }
        }

        $this->assertSame([], $hits, "A stored value is never unserialized:\n" . implode("\n", $hits));
    }

    /** @return array<string, array{0: string}> */
    public static function unserializeFreeFileProvider(): array
    {
        return [
            'api/FieldTypes.php' => ['api/FieldTypes.php'],
            'api/Data.php'       => ['api/Data.php'],
        ];
    }

    /**
     * A stored PHP-serialized string is not a repeater. It reads back as
     * nothing — the empty state — rather than as the rows those bytes describe.
     */
    public function testAStoredSerializedStringIsNotDecodedIntoRows(): void
    {
        $model = $this->model([
            'provenance' => [
                'type'       => 'repeater',
                'sub_fields' => ['t' => ['type' => 'text']],
            ],
        ]);

        $this->stored['_p_provenance'] = 'a:1:{i:0;a:1:{s:1:"t";s:1:"a";}}';

        $this->assertSame([], $this->readBack($model, 'provenance'));
    }

    // -------------------------------------- 7. the REST write goes through it too

    /**
     * WordPress calls a meta sanitize_callback with THREE arguments
     * ($value, $meta_key, $object_type). The registry's own closure takes
     * ($value, $config), so handing it to register_post_meta() raw would make
     * every REST write a TypeError — or, worse, quietly sanitize against the
     * meta key as if it were the field config. The registration therefore keeps
     * a ONE-ARGUMENT wrapper that names the field itself.
     */
    public function testTheRegisteredSanitizeCallbackIsAOneArgumentWrapper(): void
    {
        $model = $this->model(['headline' => ['type' => 'text', 'show_in_rest' => true]]);

        $model->registerRestMeta('p');

        $this->assertCount(1, $this->registrations, 'The declared field registers.');
        $callback = $this->registrations[0][2]['sanitize_callback'];
        $this->assertIsCallable($callback);
        $this->assertSame(
            1,
            (new ReflectionFunction(Closure::fromCallable($callback)))->getNumberOfParameters(),
            'One parameter: WordPress passes three and the second is a meta key, not a config.',
        );

        $this->assertSame('text:x', $callback('  <b>x</b>  '));
        $this->assertSame(
            'text:x',
            $callback('  <b>x</b>  ', '_p_headline', 'post'),
            "WordPress's own three-argument call must not error.",
        );
    }

    // --------------------------------------------------- 8. the default type

    /**
     * A field declared with no `type` at all is TEXT. The default used to be
     * `'string'`, which is now a retired name — so leaving it would fatal every
     * label-only declaration on the fleet at init.
     */
    public function testAFieldWithNoTypeIsText(): void
    {
        $model = $this->model(['headline' => ['label' => 'Title']]);

        $model->update(1, ['headline' => '  <b>x</b>  ']);

        $this->assertSame('text:x', $this->storedValue('headline'), 'No type declared means text.');
    }
}
