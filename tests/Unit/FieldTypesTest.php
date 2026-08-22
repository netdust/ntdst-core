<?php // tests/Unit/FieldTypesTest.php
// The vocabulary's one table — what every field value on every site passes through.
//
// This is the RED contract for field-types T01 and for the Cluster A gate fix
// wave (spec revisions 2 and 3). It asserts the PROMISE, never an
// implementation: for each of the 17 canonical names the registry answers with
// one sanitizer, one REST leaf shape, one admin control and one cell verdict;
// the sanitizer survives a NAMED hostile input, refuses a non-scalar where it
// expects a scalar, and is idempotent on BOTH its valid and its hostile answer
// (register_post_meta() applies it a second time on every REST write); and
// every other name — a retired alias or an invention — is refused loudly,
// naming the canonical or the known set.
//
// HOW THE WORDPRESS FUNCTIONS ARE STUBBED
// Every stub is TAGGED, the way DataRegistersRestMetaTest tags them: the value
// carries the name of the function that produced it, so "the entry called
// sanitize_text_field" and "the entry called sanitize_textarea_field" are
// different answers instead of the same pass-through. The tags are applied ONCE
// (a value that already carries its tag is returned unchanged), because the
// real functions are idempotent and a stub that is not would fake a failure of
// the idempotence promise. Where WordPress's own answer is the point, the stub
// reproduces WordPress's rule rather than a tag:
//   - wp_validate_boolean  — copied from wp-includes/functions.php:7739, so
//                            "false" => false is WordPress's answer, not ours;
//   - absint / sanitize_key — real-equivalent;
//   - sanitize_email        — strips the characters WordPress strips, so
//                            "a@b.c<script>" => "a@b.cscript" is WordPress's;
//   - wp_kses_post          — strips TAGS ONLY and keeps their content, which
//                            is what WordPress does: "<p>a</p><script>x</script>"
//                            keeps the "x". A stub that deleted the script
//                            BODY would credit this table with a refusal
//                            WordPress never makes (spec rev 3 / plan row #1);
//   - get_post_type         — ids 1..99 are attachments, everything else is not.
//
// THREE CONTRACT READINGS THIS FILE PINS, all reported to the ledger with it:
//   1. bool "<b>1" is TRUE. WordPress's wp_validate_boolean() returns true
//      (only the exact string "false" is false), and FR-2 binds bool to it —
//      see testBoolIsWordPresssOwnAnswerNotOurs(). A NON-SCALAR is the one
//      place bool leaves WordPress: an array is not a truthy value here, it is
//      the empty answer (spec rev 3).
//   2. repeater->schema is NULL. FR-4 keeps the repeater's shape STRUCTURAL:
//      schemaFor() recurses over sub_fields and builds the closed object
//      itself, so the repeater has no leaf shape for the registry to hold.
//      `array`/`json`'s null means "never publishable"; the repeater's means
//      "not a leaf".
//   3. A repeater row survives when ANY POSTED cell is not '' and not null.
//      The rule reads the posted row, not the sanitized one — `int ''`
//      sanitizes to 0, and a row of nothing but empty strings must still drop.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';

final class FieldTypesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The whole vocabulary, in D4 order. */
    private const D4 = [
        'int', 'float', 'bool', 'text', 'textarea', 'html', 'email', 'url', 'date',
        'select', 'array', 'json', 'relation', 'gallery', 'image', 'file', 'repeater',
    ];

    /** A repeater declaration with one text cell and one media cell. */
    private const REPEATER = [
        'sub_fields' => [
            'title' => ['type' => 'text'],
            'pic'   => ['type' => 'image'],
        ],
    ];

    /** A repeater whose cells sanitize to FALSY values — 0, false, ''. */
    private const FALSY_CELLS = [
        'sub_fields' => [
            'qty'      => ['type' => 'int'],
            'featured' => ['type' => 'bool'],
            'title'    => ['type' => 'text'],
        ],
    ];

    /** A repeater inside a repeater — canonical sub-field names only. */
    private const NESTED_REPEATER = [
        'sub_fields' => [
            'title' => ['type' => 'text'],
            'rows'  => [
                'type'       => 'repeater',
                'sub_fields' => [
                    'qty'   => ['type' => 'int'],
                    'label' => ['type' => 'text'],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Tag once, never twice: the real functions are idempotent.
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
        // stronger here would be this test crediting the table with a refusal
        // wp_kses_post() does not make.
        Functions\when('wp_kses_post')->alias(
            $tagged('kses', static fn(string $raw): string => (string) preg_replace('@</?(script|style)[^>]*>@i', '', $raw)),
        );

        // esc_url_raw refuses a javascript: URL before it can be tagged.
        $url = $tagged('url', static fn(string $raw): string => $raw);
        Functions\when('esc_url_raw')->alias(static function ($value) use ($url) {
            $raw = ltrim((string) $value);

            return stripos($raw, 'javascript:') === 0 ? '' : $url($value);
        });

        // Real-equivalents — WordPress's own algorithm, no tag.
        Functions\when('sanitize_key')->alias(
            static fn($value) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)),
        );
        Functions\when('absint')->alias(static fn($value) => abs((int) $value));

        // wp-includes/functions.php:7739, verbatim. Only the exact string
        // "false" is false; everything else is PHP's own truthiness.
        Functions\when('wp_validate_boolean')->alias(static function ($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value) && 'false' === strtolower($value)) {
                return false;
            }

            return (bool) $value;
        });

        // An attachment id is 1..99 in this process; 100+ exists but is a post.
        Functions\when('get_post_type')->alias(
            static fn($post = null) => ((int) $post >= 1 && (int) $post <= 99) ? 'attachment' : 'post',
        );
    }

    protected function tearDown(): void
    {
        // testDateNormalizesInTheSitesTimezone() moves the process clock.
        date_default_timezone_set('UTC');
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function sanitize(string $name, mixed $value, array $config = []): mixed
    {
        $type = NTDST_FieldTypes::get($name);

        return ($type->sanitize)($value, $config);
    }

    // ---------------------------------------------------------------- denial

    /**
     * A retired name is refused, and the message says what to write instead —
     * FR-3's sentence to the character, because it is what a consumer reads in
     * a fatal at init.
     *
     * @dataProvider retiredNameProvider
     */
    public function testARetiredNameNamesItsCanonical(string $retired, string $canonical): void
    {
        try {
            NTDST_FieldTypes::get($retired);
            $this->fail("Expected InvalidArgumentException for the retired name '{$retired}'.");
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                "Unknown field type '{$retired}'. Use '{$canonical}'.",
                $e->getMessage(),
            );
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function retiredNameProvider(): array
    {
        return [
            'integer'       => ['integer', 'int'],
            'signed_int'    => ['signed_int', 'int'],
            'number'        => ['number', 'int'],
            'double'        => ['double', 'float'],
            'decimal'       => ['decimal', 'float'],
            'boolean'       => ['boolean', 'bool'],
            'string'        => ['string', 'text'],
            'longtext'      => ['longtext', 'textarea'],
            'wysiwyg'       => ['wysiwyg', 'html'],
            'content'       => ['content', 'html'],
            'datetime'      => ['datetime', 'date'],
            'person'        => ['person', 'relation'],
            'post_relation' => ['post_relation', 'relation'],
        ];
    }

    /**
     * An invented name gets the vocabulary, not a canonical it never had. The
     * empty name and the wrong casing are inventions like any other — the set
     * is closed on the exact spelling, so 'Int' is not 'int'.
     *
     * @dataProvider inventedNameProvider
     */
    public function testAnInventedNameGetsTheKnownSet(string $invented): void
    {
        try {
            NTDST_FieldTypes::get($invented);
            $this->fail("Expected InvalidArgumentException for the invented name '{$invented}'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Unknown field type '{$invented}'.", $e->getMessage());
            $this->assertStringContainsString('Known: int, float', $e->getMessage());
            $this->assertStringNotContainsString("Use '", $e->getMessage());
        }
    }

    /** @return array<string, array{0: string}> */
    public static function inventedNameProvider(): array
    {
        return [
            'a misspelling'   => ['wysiwig'],
            'the empty name'  => [''],
            'the wrong case'  => ['Int'],
        ];
    }

    /**
     * Threat row #6, the registry's half — a plugin cannot inject a type whose
     * sanitizer is a no-op, because there is no filter and no registration
     * method: two readers, nothing else. The row's OTHER half — a per-field
     * `sanitizer` that composes after the registry instead of replacing it —
     * is T03's contract, not this table's; nothing here discharges it.
     */
    public function testTheTableIsAClosedSet(): void
    {
        $class = new ReflectionClass(NTDST_FieldTypes::class);

        $public = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($public);

        $this->assertSame(['get', 'names'], $public);
        $this->assertTrue($class->isFinal(), 'NTDST_FieldTypes must be final.');
        $this->assertTrue($class->getMethod('get')->isStatic());
        $this->assertTrue($class->getMethod('names')->isStatic());
    }

    /** Threat row #6 — an entry handed out cannot be edited in place. */
    public function testTheValueObjectIsReadonly(): void
    {
        $class = new ReflectionClass(NTDST_FieldType::class);
        $this->assertTrue($class->isFinal(), 'NTDST_FieldType must be final.');

        foreach (['name', 'sanitize', 'schema', 'control', 'cell'] as $property) {
            $this->assertTrue(
                $class->getProperty($property)->isReadOnly(),
                "NTDST_FieldType::\${$property} must be readonly.",
            );
        }

        $type = new NTDST_FieldType('probe', static fn($v, $c) => $v, null, 'text', true);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $type->name = 'other';
    }

    // ------------------------------------------------------------ the 17

    public function testNamesAreTheSeventeenInD4Order(): void
    {
        $this->assertSame(self::D4, NTDST_FieldTypes::names());
    }

    /**
     * Threat row #1 — the named hostile input per type, plus the valid and the
     * empty answer around it.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed> $t
     */
    public function testEachTypeAnswersOnValidHostileAndEmptyInput(array $t): void
    {
        $this->assertSame($t['validAnswer'], $this->sanitize($t['name'], $t['valid'], $t['config']), "{$t['name']}: valid input");
        $this->assertSame($t['hostileAnswer'], $this->sanitize($t['name'], $t['hostile'], $t['config']), "{$t['name']}: hostile input");
        $this->assertSame($t['emptyAnswer'], $this->sanitize($t['name'], $t['empty'], $t['config']), "{$t['name']}: empty input");
    }

    /**
     * Threat row #1 — register_post_meta() applies the sanitizer a second time
     * on every REST write, so the second pass must change nothing. Asserted on
     * the VALID answer as well as the hostile one: several hostile answers are
     * the empty answer, and re-sanitizing '' can never fail.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed> $t
     */
    public function testEachTypeIsIdempotentOnItsValidAndHostileAnswer(array $t): void
    {
        foreach (['valid', 'hostile'] as $kind) {
            $once = $this->sanitize($t['name'], $t[$kind], $t['config']);

            $this->assertSame(
                $once,
                $this->sanitize($t['name'], $once, $t['config']),
                "{$t['name']}: sanitize(sanitize(x)) !== sanitize(x) on the {$kind} input",
            );
        }
    }

    /**
     * Threat row #2 — the leaf shape /wp/v2 publishes, byte-identical to
     * core-shape FR-2's table. A null means the type is not publishable as a
     * leaf: `json` and `array` keep typed scalars no leaf schema admits, and
     * the repeater's shape is built structurally by schemaFor().
     *
     * @dataProvider entryProvider
     * @param array<string, mixed> $t
     */
    public function testEachTypePublishesItsLeafShape(array $t): void
    {
        $this->assertSame($t['schema'], NTDST_FieldTypes::get($t['name'])->schema, "{$t['name']}: REST leaf shape");
    }

    /**
     * FR-2 / threat row #5 — the entry is named by itself, draws one admin
     * control, and knows whether it may live in a repeater row at all. The
     * control key carries RENDERING INTENT, never a type name: `float` is a
     * `decimal` (a number input with a step), `array` and `json` are both the
     * JSON-encoded code textarea, so the renderer never asks what type it is
     * drawing.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed> $t
     */
    public function testEachTypeIsNamedByItselfAndDrawsItsControl(array $t): void
    {
        $type = NTDST_FieldTypes::get($t['name']);

        $this->assertInstanceOf(NTDST_FieldType::class, $type);
        $this->assertSame($t['name'], $type->name);
        $this->assertInstanceOf(Closure::class, $type->sanitize);
        $this->assertSame($t['control'], $type->control, "{$t['name']}: admin control");
        $this->assertSame($t['cell'], $type->cell, "{$t['name']}: may render in a repeater row");
    }

    /**
     * One labelled row per type: name · config · valid in/out · hostile in/out ·
     * empty in/out · schema · control · cell.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function entryProvider(): array
    {
        return [
            'int' => [[
                'name' => 'int', 'config' => [],
                'valid' => '42', 'validAnswer' => 42,
                'hostile' => '12<script>', 'hostileAnswer' => 12,
                'empty' => '', 'emptyAnswer' => 0,
                'schema' => ['type' => 'integer'], 'control' => 'number', 'cell' => true,
            ]],
            'float' => [[
                'name' => 'float', 'config' => [],
                'valid' => '3.5', 'validAnswer' => 3.5,
                'hostile' => '1.5e3<b>', 'hostileAnswer' => 1500.0,
                'empty' => '', 'emptyAnswer' => 0.0,
                'schema' => ['type' => 'number'], 'control' => 'decimal', 'cell' => true,
            ]],
            'bool' => [[
                'name' => 'bool', 'config' => [],
                'valid' => '1', 'validAnswer' => true,
                'hostile' => 'false', 'hostileAnswer' => false,
                'empty' => '', 'emptyAnswer' => false,
                'schema' => ['type' => 'boolean'], 'control' => 'checkbox', 'cell' => true,
            ]],
            'text' => [[
                'name' => 'text', 'config' => [],
                'valid' => 'Hello', 'validAnswer' => 'text:Hello',
                'hostile' => "<b>x</b>\n", 'hostileAnswer' => 'text:x',
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string'], 'control' => 'text', 'cell' => true,
            ]],
            'textarea' => [[
                'name' => 'textarea', 'config' => [],
                'valid' => "line1\nline2", 'validAnswer' => "textarea:line1\nline2",
                'hostile' => "<script>a</script>\nb", 'hostileAnswer' => "textarea:a\nb",
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string'], 'control' => 'textarea', 'cell' => true,
            ]],
            'html' => [[
                'name' => 'html', 'config' => [],
                'valid' => '<p>a</p>', 'validAnswer' => 'kses:<p>a</p>',
                'hostile' => '<p>a</p><script>x</script>', 'hostileAnswer' => 'kses:<p>a</p>x',
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string'], 'control' => 'html', 'cell' => false,
            ]],
            'email' => [[
                'name' => 'email', 'config' => [],
                'valid' => 'a@b.com', 'validAnswer' => 'email:a@b.com',
                'hostile' => 'a@b.c<script>', 'hostileAnswer' => 'email:a@b.cscript',
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string', 'format' => 'email'], 'control' => 'email', 'cell' => true,
            ]],
            'url' => [[
                'name' => 'url', 'config' => [],
                'valid' => 'https://netdust.be/x', 'validAnswer' => 'url:https://netdust.be/x',
                'hostile' => 'javascript:alert(1)', 'hostileAnswer' => '',
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string', 'format' => 'uri'], 'control' => 'url', 'cell' => true,
            ]],
            'date' => [[
                'name' => 'date', 'config' => [],
                'valid' => '2026-08-22', 'validAnswer' => '2026-08-22',
                'hostile' => '2026-13-45', 'hostileAnswer' => '',
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string'], 'control' => 'date', 'cell' => true,
            ]],
            'select' => [[
                'name' => 'select', 'config' => [],
                'valid' => 'option-a', 'validAnswer' => 'text:option-a',
                'hostile' => '<b>x', 'hostileAnswer' => 'text:x',
                'empty' => '', 'emptyAnswer' => '',
                'schema' => ['type' => 'string'], 'control' => 'select', 'cell' => true,
            ]],
            'array' => [[
                'name' => 'array', 'config' => [],
                'valid' => ['a' => 'x'], 'validAnswer' => ['a' => 'text:x'],
                'hostile' => '{"a":"<b>x"}', 'hostileAnswer' => ['a' => 'text:x'],
                'empty' => '', 'emptyAnswer' => [],
                'schema' => null, 'control' => 'json', 'cell' => true,
            ]],
            'json' => [[
                'name' => 'json', 'config' => [],
                'valid' => '{"k":"v"}', 'validAnswer' => ['k' => 'text:v'],
                'hostile' => '{"k":"<b>v"}', 'hostileAnswer' => ['k' => 'text:v'],
                'empty' => '', 'emptyAnswer' => [],
                'schema' => null, 'control' => 'json', 'cell' => true,
            ]],
            'relation' => [[
                'name' => 'relation', 'config' => [],
                'valid' => ['1', '2'], 'validAnswer' => [1, 2],
                'hostile' => ['1', 'x', '-3'], 'hostileAnswer' => [1, 3],
                'empty' => '', 'emptyAnswer' => [],
                'schema' => ['type' => 'array', 'items' => ['type' => 'integer']], 'control' => 'relation', 'cell' => false,
            ]],
            'gallery' => [[
                'name' => 'gallery', 'config' => [],
                'valid' => ['1', '2'], 'validAnswer' => [1, 2],
                'hostile' => ['1', 'x', '-3'], 'hostileAnswer' => [1, 3],
                'empty' => '', 'emptyAnswer' => [],
                'schema' => ['type' => 'array', 'items' => ['type' => 'integer']], 'control' => 'gallery', 'cell' => false,
            ]],
            'image' => [[
                'name' => 'image', 'config' => [],
                'valid' => '7', 'validAnswer' => 7,
                'hostile' => '7<b>', 'hostileAnswer' => 7,
                'empty' => '', 'emptyAnswer' => 0,
                'schema' => ['type' => 'integer'], 'control' => 'media', 'cell' => true,
            ]],
            'file' => [[
                'name' => 'file', 'config' => [],
                'valid' => '7', 'validAnswer' => 7,
                'hostile' => '7<b>', 'hostileAnswer' => 7,
                'empty' => '', 'emptyAnswer' => 0,
                'schema' => ['type' => 'integer'], 'control' => 'media', 'cell' => true,
            ]],
            'repeater' => [[
                'name' => 'repeater', 'config' => self::REPEATER,
                'valid' => [['title' => 'Hi', 'pic' => '7']],
                'validAnswer' => [['title' => 'text:Hi', 'pic' => 7]],
                'hostile' => [['title' => '<b>x</b>', 'pic' => '']],
                'hostileAnswer' => [['title' => 'text:x', 'pic' => '']],
                'empty' => '', 'emptyAnswer' => [],
                'schema' => null, 'control' => 'repeater', 'cell' => false,
            ]],
        ];
    }

    // -------------------------------------------- the non-scalar is refused

    /**
     * Threat row #1 (spec rev 3) — an array reaches these entries from the
     * metabox the moment a posted field name collides with a checkbox group.
     * Every scalar entry answers with its EMPTY answer: no TypeError, no
     * "Array to string conversion" warning (phpunit.xml fails on either).
     *
     * bool leaves WordPress here on purpose: wp_validate_boolean() would call
     * a non-empty array true, and "the visitor posted something shaped wrong"
     * must never mean "yes".
     *
     * @dataProvider scalarEntryProvider
     */
    public function testAScalarEntryRefusesAnArrayToItsEmptyAnswer(string $name, mixed $emptyAnswer): void
    {
        $this->assertSame($emptyAnswer, $this->sanitize($name, ['a' => 'b']), "{$name}: a keyed array in");
        $this->assertSame($emptyAnswer, $this->sanitize($name, []), "{$name}: an empty array in");
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function scalarEntryProvider(): array
    {
        return [
            'int'      => ['int', 0],
            'float'    => ['float', 0.0],
            'bool'     => ['bool', false],
            'text'     => ['text', ''],
            'textarea' => ['textarea', ''],
            'html'     => ['html', ''],
            'email'    => ['email', ''],
            'url'      => ['url', ''],
            'date'     => ['date', ''],
            'select'   => ['select', ''],
            'image'    => ['image', 0],
            'file'     => ['file', 0],
        ];
    }

    /**
     * An object with no __toString is the other non-scalar — a decoded JSON
     * body hands one to update() without ever touching the metabox. Every
     * entry, list types included, answers with its empty answer.
     *
     * @dataProvider objectRefusalProvider
     */
    public function testEveryEntryRefusesAnObjectWithoutToString(string $name, mixed $emptyAnswer): void
    {
        $this->assertSame($emptyAnswer, $this->sanitize($name, new stdClass()), "{$name}: an object in");
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function objectRefusalProvider(): array
    {
        return self::scalarEntryProvider() + [
            'relation' => ['relation', []],
            'gallery'  => ['gallery', []],
            'array'    => ['array', []],
            'json'     => ['json', []],
        ];
    }

    // ------------------------------------------------------- per-type edges

    /** FR-5 — an int keeps its sign; an array is not a number. */
    public function testIntIsSignedAndRefusesAnArray(): void
    {
        $this->assertSame(-5, $this->sanitize('int', '-5'));
        $this->assertSame(0, $this->sanitize('int', [1]));
    }

    /** float refuses an array to 0.0, the way int refuses one to 0. */
    public function testFloatRefusesAnArrayLikeIntDoes(): void
    {
        $this->assertSame(0.0, $this->sanitize('float', [1]));
        $this->assertSame(0.0, $this->sanitize('float', []));
        $this->assertSame(0.0, $this->sanitize('float', $this->sanitize('float', [1])));
    }

    /**
     * A stored float must be a NUMBER on the next read. INF and NAN are
     * neither JSON-encodable nor round-trippable: json_encode() fails on them,
     * so one overflowing post would empty a whole REST response.
     */
    public function testFloatRefusesANonFiniteValue(): void
    {
        $this->assertSame(0.0, $this->sanitize('float', '1e999'));
        $this->assertSame(0.0, $this->sanitize('float', '-1e999'));
        $this->assertSame(0.0, $this->sanitize('float', NAN));
        $this->assertSame(0.0, $this->sanitize('float', INF));
    }

    /**
     * bool is WordPress's word, so WordPress's answer stands for a STRING:
     * only the exact string "false" is false. The plan's threat row #1 once
     * expected "<b>1" to be false; wp_validate_boolean() says true, and FR-2
     * binds bool to it.
     */
    public function testBoolIsWordPresssOwnAnswerNotOurs(): void
    {
        $this->assertFalse($this->sanitize('bool', 'FALSE'));
        $this->assertTrue($this->sanitize('bool', '<b>1'));
    }

    /** A javascript: URL never survives, whatever its casing or padding. */
    public function testUrlRefusesAJavascriptScheme(): void
    {
        $this->assertSame('', $this->sanitize('url', 'JavaScript:alert(1)'));
        $this->assertSame('', $this->sanitize('url', ' javascript:alert(1)'));
    }

    /**
     * A date is normalized in the SITE's timezone: strtotime() reads the
     * posted string in it, so the formatter must write in it too. Paired with
     * gmdate(), every site east of UTC loses a day on each save.
     */
    public function testDateNormalizesInTheSitesTimezone(): void
    {
        date_default_timezone_set('Europe/Brussels');

        $this->assertSame('2026-08-22', $this->sanitize('date', '2026-08-22'));
        $this->assertSame('2026-08-22', $this->sanitize('date', $this->sanitize('date', '2026-08-22')));
        $this->assertSame('2026-08-22', $this->sanitize('date', '22 August 2026'));
        $this->assertSame('', $this->sanitize('date', 'not a date'));
    }

    /**
     * A year outside 0000-9999 is refused. Beyond it the formatter writes five
     * digits, which the next pass re-parses as a different date — the stored
     * value would drift on every REST write.
     */
    public function testDateRefusesAYearOutsideFourDigits(): void
    {
        $this->assertSame('', $this->sanitize('date', '@253402300800'));
        $this->assertSame('', $this->sanitize('date', '+1000000 years'));
        $this->assertSame('2026-08-22', $this->sanitize('date', '2026-08-22'));
    }

    /** array accepts the JSON string the metabox textarea posts. */
    public function testArrayAcceptsAJsonStringAndSanitizesKeysAndLeaves(): void
    {
        $this->assertSame(['ab' => 'text:v'], $this->sanitize('array', '{"A<b>!":"v"}'));
        $this->assertSame([], $this->sanitize('array', 'not json'));
        $this->assertSame(
            ['n' => 5, 'f' => 1.5, 'b' => true, 'z' => null],
            $this->sanitize('array', ['n' => 5, 'f' => 1.5, 'b' => true, 'z' => null]),
        );
        $this->assertSame(
            ['outer' => ['inner' => 'text:x']],
            $this->sanitize('array', ['outer' => ['inner' => '<i>x</i>']]),
        );
    }

    /** json decodes, then sanitizes what it decoded — or stores nothing. */
    public function testJsonDecodesAndRefusesWhatIsNotAnObject(): void
    {
        $this->assertSame([], $this->sanitize('json', 'not json'));
        $this->assertSame([], $this->sanitize('json', '"scalar"'));
        $this->assertSame(['k' => 'text:v'], $this->sanitize('json', ['k' => '<b>v']));
    }

    /** relation wraps a single pick; gallery is a multi-pick and refuses one. */
    public function testRelationWrapsAScalarAndGalleryDoesNot(): void
    {
        $this->assertSame([7], $this->sanitize('relation', '7'));
        $this->assertSame([], $this->sanitize('gallery', '7'));
    }

    /**
     * No id is forged out of a non-scalar element. absint(['a']) is 1 — a
     * posted `relation[][x]=y` would otherwise attach post 1 (Hello World, or
     * whatever id 1 is on that site) to the field.
     */
    public function testNoIdIsForgedFromANonScalarElement(): void
    {
        $this->assertSame([3], $this->sanitize('relation', [['a'], ['b'], '3']));
        $this->assertSame([3], $this->sanitize('gallery', [['a'], '0', '3']));
        $this->assertSame([3], $this->sanitize('relation', [new stdClass(), '3']));
        $this->assertSame(0, $this->sanitize('image', ['a' => 'b']));
        $this->assertSame(0, $this->sanitize('file', ['a' => 'b']));
    }

    /**
     * An image/file field holds an attachment id that EXISTS and is an
     * attachment — 1000 is a post, and a post id in a media field is a 0.
     */
    public function testImageAndFileStoreOnlyARealAttachmentId(): void
    {
        foreach (['image', 'file'] as $name) {
            $this->assertSame(0, $this->sanitize($name, '1000'), $name);
        }
    }

    // ------------------------------------------------------- repeater rules

    /** An empty media cell stores '' — 0 reads as a real id to every consumer. */
    public function testRepeaterKeepsAnEmptyMediaCellEmptyAndDropsAnEmptyRow(): void
    {
        $rows = [
            ['title' => 'Kept', 'pic' => ''],
            ['title' => '', 'pic' => '7'],
            ['title' => '', 'pic' => ''],
        ];

        $this->assertSame(
            [
                ['title' => 'text:Kept', 'pic' => ''],
                ['title' => '', 'pic' => 7],
            ],
            $this->sanitize('repeater', $rows, self::REPEATER),
        );
    }

    /**
     * A row survives when ANY posted cell is not '' and not null — the rule
     * the metabox has always applied. A quantity of "0" and a checkbox posted
     * as "false" are answers, not blanks; dropping the row because every
     * sanitized cell happens to be falsy silently deletes a saved row.
     */
    public function testARepeaterRowSurvivesWhenAnyPostedCellIsNotEmpty(): void
    {
        $rows = [
            ['qty' => '0', 'featured' => '', 'title' => ''],
            ['qty' => '', 'featured' => 'false', 'title' => ''],
            ['qty' => '', 'featured' => '', 'title' => ''],
        ];

        $this->assertSame(
            [
                ['qty' => 0, 'featured' => false, 'title' => ''],
                ['qty' => 0, 'featured' => false, 'title' => ''],
            ],
            $this->sanitize('repeater', $rows, self::FALSY_CELLS),
        );
    }

    /**
     * A cell key is sanitized the way nested() sanitizes a map key: an
     * undeclared key is still stored (today's rule), so an unsanitized one
     * would put attacker-chosen bytes in a meta value that the metabox echoes
     * back as an input name. Numeric keys stay integers.
     */
    public function testRepeaterSanitizesItsRowKeysAndStillStoresAnUndeclaredOne(): void
    {
        $out = $this->sanitize('repeater', [['title' => 'a', '<b>evil' => '<i>y</i>']], self::REPEATER);

        $this->assertArrayNotHasKey('<b>evil', $out[0]);
        $this->assertArrayHasKey('bevil', $out[0]);
        $this->assertSame('text:y', $out[0]['bevil']);
        $this->assertSame('text:a', $out[0]['title']);

        $this->assertSame([[3 => 'text:y']], $this->sanitize('repeater', [[3 => 'y']], self::REPEATER));

        // No sub_fields at all: every cell is text, which is what a repeater
        // without a declared vocabulary has always stored.
        $this->assertSame([['a' => 'text:x']], $this->sanitize('repeater', [['a' => '<b>x</b>']], []));
    }

    /**
     * A repeater inside a repeater recurses BY DECLARED TYPE: the inner row's
     * `int` cell is an int, not a text cell that happens to look like one.
     * The registry passes each sub-field its own config, so the inner
     * repeater's `sub_fields` reach the inner rows.
     *
     * (Whether a `repeater` may be declared as a sub-field at all is
     * register()'s question — T03's walk — not this table's.)
     */
    public function testANestedRepeaterSanitizesByTheInnerDeclaredType(): void
    {
        $posted = [[
            'title' => '<b>Outer</b>',
            'rows'  => [['qty' => '12<b>', 'label' => '<i>x</i>']],
        ]];

        $expected = [[
            'title' => 'text:Outer',
            'rows'  => [['qty' => 12, 'label' => 'text:x']],
        ]];

        $this->assertSame($expected, $this->sanitize('repeater', $posted, self::NESTED_REPEATER));
        $this->assertSame($expected, $this->sanitize('repeater', $expected, self::NESTED_REPEATER));
    }

    /** Anything that is not a list of rows stores nothing. */
    public function testRepeaterRefusesWhatIsNotRows(): void
    {
        $this->assertSame([], $this->sanitize('repeater', ['not-a-row'], self::REPEATER));
    }
}
