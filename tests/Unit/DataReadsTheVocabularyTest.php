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
//      at init.
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
        Functions\when('sanitize_key')->alias(
            static fn($value) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)),
        );
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

    // ------------------------------------------- 1. refusal at registration

    /**
     * FR-3/FR-5: `signed_int` folded into a signed `int`, and a bare type string
     * is a declaration like any other. threat #7: the fatal names the field so
     * the site owner knows WHICH declaration to fix, and the canonical so they
     * know what to write.
     */
    public function testARetiredAliasWrittenAsABareTypeStringIsRefusedAtRegistration(): void
    {
        try {
            $this->model(['n' => 'integer']);
            $this->fail("Expected InvalidArgumentException for the retired type 'integer'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'n'", $e->getMessage(), 'The fatal must name the field.');
            $this->assertStringContainsString("Use 'int'.", $e->getMessage(), 'The fatal must name the canonical.');
        }
    }

    public function testARetiredAliasInTheTypeKeyIsRefusedAtRegistration(): void
    {
        try {
            $this->model(['n' => ['type' => 'integer']]);
            $this->fail("Expected InvalidArgumentException for the retired type 'integer'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'n'", $e->getMessage());
            $this->assertStringContainsString("Use 'int'.", $e->getMessage());
        }
    }

    /** FR-5: `signed_int` is not a name. The one field on stride that used it renames. */
    public function testSignedIntIsNoLongerAName(): void
    {
        try {
            $this->model(['n' => ['type' => 'signed_int']]);
            $this->fail("Expected InvalidArgumentException for 'signed_int'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'n'", $e->getMessage());
            $this->assertStringContainsString("Use 'int'.", $e->getMessage());
        }
    }

    /** D5: the vocabulary throws for anything invented, and lists what it knows. */
    public function testAnInventedTypeNamesTheFieldAndTheKnownSet(): void
    {
        try {
            $this->model(['n' => ['type' => 'gubbins']]);
            $this->fail("Expected InvalidArgumentException for the invented type 'gubbins'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'n'", $e->getMessage());
            $this->assertStringContainsString('Known:', $e->getMessage(), 'An invention gets the known set, not a canonical.');
            $this->assertStringNotContainsString("Use '", $e->getMessage());
        }
    }

    /**
     * threat #6: a field that brings its own `sanitizer` is exactly the door an
     * attacker would use, so the registry is asked FIRST — the declaration is
     * type-checked whether or not it also carries a callable.
     */
    public function testAFieldWithItsOwnSanitizerStillHasItsTypeChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->model(['n' => ['type' => 'gubbins', 'sanitizer' => static fn($v) => $v]]);
    }

    /** The two `'string'` defaults become `'text'`; `'string'` itself is retired. */
    public function testStringIsARetiredNameThatPointsAtText(): void
    {
        try {
            $this->model(['n' => ['type' => 'string']]);
            $this->fail("Expected InvalidArgumentException for the retired type 'string'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'n'", $e->getMessage());
            $this->assertStringContainsString("Use 'text'.", $e->getMessage());
        }
    }

    /**
     * threat #5 / SC-3. A `cell = false` type inside `sub_fields` renders today
     * as a single-line text input and stores whatever that box held. It is
     * refused at register() instead, naming the field AND the sub-field.
     *
     * @dataProvider cellLessTypeProvider
     */
    public function testATypeThatCannotLiveInARowIsRefusedInsideSubFields(string $type): void
    {
        try {
            $this->model([
                'provenance' => [
                    'type'       => 'repeater',
                    'sub_fields' => [
                        'notes' => ['type' => $type],
                    ],
                ],
            ]);
            $this->fail("Expected InvalidArgumentException for the '{$type}' sub-field.");
        } catch (InvalidArgumentException $e) {
            // FR-4 quotes this sentence for `html`; the same shape carries every
            // cell-less name.
            $this->assertStringContainsString(
                "Field 'provenance' sub-field 'notes': '{$type}' cannot be a repeater sub-field",
                $e->getMessage(),
            );
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

    /** threat #7 at sub-field depth: the retired name is refused at init, not at save. */
    public function testARetiredNameInsideSubFieldsIsRefusedAtRegistration(): void
    {
        try {
            $this->model([
                'provenance' => [
                    'type'       => 'repeater',
                    'sub_fields' => ['notes' => ['type' => 'wysiwyg']],
                ],
            ]);
            $this->fail("Expected InvalidArgumentException for the retired sub-field type 'wysiwyg'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('provenance', $e->getMessage(), 'Name the field.');
            $this->assertStringContainsString('notes', $e->getMessage(), 'Name the sub-field.');
            $this->assertStringContainsString("Use 'html'.", $e->getMessage(), 'Name the canonical.');
        }
    }

    /**
     * FR-4 says EVERY sub-field resolves through get(). A nested repeater's
     * sub-fields are sub-fields, and its rows are rendered as cells too — so
     * depth does not buy a cell-less type a way in.
     */
    public function testACellLessTypeIsRefusedAtDepthTwo(): void
    {
        try {
            $this->model([
                'provenance' => [
                    'type'       => 'repeater',
                    'sub_fields' => [
                        'rows' => [
                            'type'       => 'repeater',
                            'sub_fields' => ['body' => ['type' => 'html']],
                        ],
                    ],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for the html sub-field at depth 2.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('body', $e->getMessage(), 'Name the sub-field that is refused.');
            $this->assertStringContainsString("'html' cannot be a repeater sub-field", $e->getMessage());
        }
    }

    /**
     * The Cluster A collision ruling. A row is stored under sanitize_key() of
     * its cell name, so `SubTitle` and `subTitle` are ONE stored key — the
     * declaration map keeps whichever was declared last and the other field
     * silently loses its type on every write. Two names, one key, no way to
     * tell them apart: refused at register().
     */
    public function testTwoSubFieldNamesThatSanitizeToOneKeyAreRefused(): void
    {
        try {
            $this->model([
                'provenance' => [
                    'type'       => 'repeater',
                    'sub_fields' => [
                        'SubTitle' => ['type' => 'text'],
                        'subTitle' => ['type' => 'int'],
                    ],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for two sub-field names that sanitize to one key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('provenance', $e->getMessage(), 'Name the field.');
            $this->assertStringContainsString('subtitle', $e->getMessage(), 'Name the key they collide on.');
        }
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

    /** The boundary either side of the sign: junk and a non-scalar are still 0. */
    public function testAnIntFieldStillRefusesJunkAndNonScalarsToZero(): void
    {
        $model = $this->model(['price' => ['type' => 'int']]);

        $model->update(1, ['price' => 'abc']);
        $this->assertSame(0, $this->storedValue('price'));

        $model->update(1, ['price' => ['x']]);
        $this->assertSame(0, $this->storedValue('price'), 'A posted array is not a number.');
    }

    // ------------------------------------------ 3. the bound sanitizer is the registry's

    /** FR-2: bool is wp_validate_boolean(), so the exact string "false" is false. */
    public function testTheBoundSanitizerForBoolIsWordPressOwnAnswer(): void
    {
        $model = $this->model(['flag' => ['type' => 'bool']]);

        $model->update(1, ['flag' => 'false']);

        $this->assertSame(false, $this->storedValue('flag'));
    }

    /** The tag names which WordPress function ran: text, not textarea, not kses. */
    public function testTheBoundSanitizerForTextIsTheRegistrysText(): void
    {
        $model = $this->model(['headline' => ['type' => 'text']]);

        $model->update(1, ['headline' => '  <b>x</b>  ']);

        $this->assertSame('text:x', $this->storedValue('headline'));
    }

    /**
     * A declared repeater's rows follow the registry's row rule: each cell is
     * sanitized by its DECLARED sub-field type (an int cell keeps its sign), an
     * empty media pick stores '' and not 0 (0 reads as a real attachment id),
     * and a row with nothing in it is dropped.
     */
    public function testARepeaterRowFollowsTheRegistrysRowRule(): void
    {
        $model = $this->model([
            'provenance' => [
                'type'       => 'repeater',
                'sub_fields' => [
                    'qty'   => ['type' => 'int'],
                    'pic'   => ['type' => 'image'],
                    'title' => ['type' => 'text'],
                ],
            ],
        ]);

        $model->update(1, ['provenance' => [
            ['qty' => '-3', 'pic' => '', 'title' => ' <b>t</b> '],
            ['qty' => '', 'pic' => '', 'title' => ''],
        ]]);

        $this->assertSame(
            [['qty' => -3, 'pic' => '', 'title' => 'text:t']],
            $this->storedValue('provenance'),
        );
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

    // ------------------------------------------------- 6. the helpers are gone

    /**
     * FR-4: the model's private type tables leave with the vocabulary. A
     * surviving helper is a second table that can disagree with the registry
     * (INV-8), and getDefaultSanitizer() is the one the whole spec exists to
     * delete.
     *
     * @dataProvider removedHelperProvider
     */
    public function testTheModelHasNoSanitizerHelperOfItsOwn(string $method): void
    {
        $this->assertFalse(
            (new ReflectionClass(NTDST_Data_Model::class))->hasMethod($method),
            "NTDST_Data_Model::{$method}() is a second type table; the registry is the only one.",
        );
    }

    /** @return array<string, array{string}> */
    public static function removedHelperProvider(): array
    {
        return [
            'getDefaultSanitizer'  => ['getDefaultSanitizer'],
            'sanitizeBoolean'      => ['sanitizeBoolean'],
            'sanitizeJson'         => ['sanitizeJson'],
            'sanitizeNestedArray'  => ['sanitizeNestedArray'],
            'sanitizeDate'         => ['sanitizeDate'],
            'sanitizeAttachmentId' => ['sanitizeAttachmentId'],
            'sanitizeRepeater'     => ['sanitizeRepeater'],
        ];
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
