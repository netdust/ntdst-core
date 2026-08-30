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
// The entry also owns the READ: `read` is the storage decode (null = the
// sanitizer is already the cast), so how a value is written and how it is read
// are one row of one table. A read is a cast or a decode — never a second
// sanitization, never a lookup — and the stubs below FAIL the test if a read
// calls wp_kses_post() or get_post_type().
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
//   - absint                — real-equivalent (sanitize_key() is a REAL
//                            function from tests/bootstrap.php);
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

    /** A repeater whose declared cell name is not its own sanitize_key(). */
    private const CAMEL_CASE_CELL = [
        'sub_fields' => [
            'subTitle' => ['type' => 'int'],
        ],
    ];

    /** A repeater whose cells sanitize junk to '' — a row of nothing. */
    private const JUNK_CELLS = [
        'sub_fields' => [
            'link'  => ['type' => 'url'],
            'title' => ['type' => 'text'],
        ],
    ];

    /** A repeater with name/role cells — Stride's `speakers` shape (Ruling 106). */
    private const SPEAKERS = [
        'sub_fields' => [
            'name' => ['type' => 'text'],
            'role' => ['type' => 'text'],
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

        // Real-equivalents — WordPress's own algorithm, no tag. sanitize_key()
        // is one of these and lives in tests/bootstrap.php as a real function:
        // it is the key rule every stored cell already went through, not a
        // question this file asks.
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
        // testDateKeepsBothHalvesOnOneClock() moves the process clock.
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

        // Five, and the two new ones are the RULES that used to live in the
        // callers: what type a declaration names (declaredType) and which
        // declarations the vocabulary refuses outright (assertDeclarations).
        // Both were copied into NTDST_Data_Model and NTDST_MetaboxGenerator,
        // and a copy of a rule is a second vocabulary (INV-8).
        $this->assertSame(['assertDeclarations', 'declaredType', 'get', 'names', 'rowKey'], $public);
        $this->assertTrue($class->isFinal(), 'NTDST_FieldTypes must be final.');

        foreach ($public as $method) {
            $this->assertTrue(
                $class->getMethod($method)->isStatic(),
                "NTDST_FieldTypes::{$method}() must be static — the table is the class.",
            );
        }
    }

    /**
     * THE DECLARATION RULE, and there is one of it: the type a declaration NAMES.
     *
     * A bare string IS the type; an array says so under `type`; a declaration
     * with neither is `text`, because a label-only field is the commonest
     * declaration on the fleet and it must not fatal at init.
     *
     * Four readers asked this question and each carried its own answer — the
     * model's constructor, its sanitizer binding, the metabox render and the
     * metabox save — which is a second vocabulary in four places (INV-8). One
     * of them defaulted a missing type to `'string'`, a name v5.0.0 retired.
     *
     * @dataProvider declarationProvider
     */
    public function testTheDeclarationRuleIsTheVocabularysAndDefaultsToText(mixed $declaration, string $expected): void
    {
        $this->assertTrue(
            method_exists(NTDST_FieldTypes::class, 'declaredType'),
            'NTDST_FieldTypes::declaredType() must be the one answer to "what type does this '
                . 'declaration name" — every reader of a `fields` array asks it.',
        );

        $this->assertSame($expected, NTDST_FieldTypes::declaredType($declaration));
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function declarationProvider(): array
    {
        return [
            'a bare string is the type'      => ['int', 'int'],
            'an array says so under type'    => [['type' => 'int', 'min' => 0], 'int'],
            'no type at all is text'         => [['label' => 'Notes'], 'text'],
            'an empty type is text'          => [['type' => ''], 'text'],
            'a null type is text'            => [['type' => null], 'text'],
            'an empty declaration is text'   => [[], 'text'],
            'an empty string is text'        => ['', 'text'],
            'null is text'                   => [null, 'text'],
        ];
    }

    /**
     * A JUNK type is not quietly rewritten to `text`: it is carried to get(),
     * which refuses it by name.
     *
     * "Someone wrote no type" and "someone wrote `'type' => 123`" are different
     * mistakes, and only the first one is legal. A rule that answered `text` for
     * both would silently store a number field as text on every site that
     * typo'd a declaration.
     */
    public function testAJunkTypeStillReachesTheVocabularyAndIsRefusedByName(): void
    {
        $this->assertTrue(method_exists(NTDST_FieldTypes::class, 'declaredType'), 'declaredType() is missing.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown field type '123'.");

        NTDST_FieldTypes::get(NTDST_FieldTypes::declaredType(['type' => 123]));
    }

    /**
     * The key rule is the vocabulary's, and there is one of it.
     *
     * A repeater cell is STORED under this answer, so whoever declares a cell,
     * whoever sanitizes a row, and whoever refuses two names that collide must
     * all be asking the same question. While each of them carries its own copy,
     * a cell can be declared under one key and stored under another — and then
     * the re-save loses the cell's type (an int comes back as text) or, worse,
     * two declarations quietly become one.
     */
    public function testTheKeyRuleIsTheVocabularysAndAnswersOnceForEveryName(): void
    {
        $this->assertTrue(
            method_exists(NTDST_FieldTypes::class, 'rowKey'),
            'NTDST_FieldTypes::rowKey() must be the one key rule: the declaration walk, the row '
            . 'sanitizer and the collision check all ask it, so a cell cannot be declared under '
            . 'one key and stored under another.',
        );

        $this->assertSame('subtitle', NTDST_FieldTypes::rowKey('SubTitle'));
        $this->assertSame('already_clean', NTDST_FieldTypes::rowKey('already_clean'));
        $this->assertSame('bevil', NTDST_FieldTypes::rowKey('<b>evil'));
        $this->assertSame('', NTDST_FieldTypes::rowKey(''));

        // Idempotent, because the re-save arrives with the key the first pass
        // produced: rowKey(rowKey(x)) === rowKey(x).
        foreach (['SubTitle', 'already_clean', '<b>evil'] as $name) {
            $once = NTDST_FieldTypes::rowKey($name);
            $this->assertSame($once, NTDST_FieldTypes::rowKey($once), "rowKey() is not idempotent on '{$name}'.");
        }
    }

    /** Threat row #6 — an entry handed out cannot be edited in place. */
    public function testTheValueObjectIsReadonly(): void
    {
        $class = new ReflectionClass(NTDST_FieldType::class);
        $this->assertTrue($class->isFinal(), 'NTDST_FieldType must be final.');

        foreach (['name', 'sanitize', 'schema', 'control', 'cell', 'read'] as $property) {
            $this->assertTrue(
                $class->hasProperty($property),
                "NTDST_FieldType::\${$property} is missing — an entry says how it is written AND how it is read.",
            );
            $this->assertTrue(
                $class->getProperty($property)->isReadOnly(),
                "NTDST_FieldType::\${$property} must be readonly.",
            );
        }

        $this->assertCount(
            6,
            $class->getProperties(),
            'An entry is six facets: name, sanitize, schema, control, cell, read.',
        );

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
                'valid' => ['a' => 'x'], 'validAnswer' => ['a' => 'textarea:x'],
                'hostile' => '{"a":"<b>x"}', 'hostileAnswer' => ['a' => 'textarea:x'],
                'empty' => '', 'emptyAnswer' => [],
                'schema' => null, 'control' => 'json', 'cell' => true,
            ]],
            'json' => [[
                'name' => 'json', 'config' => [],
                'valid' => '{"k":"v"}', 'validAnswer' => ['k' => 'textarea:v'],
                'hostile' => '{"k":"<b>v"}', 'hostileAnswer' => ['k' => 'textarea:v'],
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

    // ----------------------------------------------------------- the read

    /**
     * The read answer for one stored value: the entry's own `read` closure, or
     * the entry's sanitizer when it declares none — an `int`'s sanitizer IS the
     * cast a read owes, so `int`, `float` and `bool` need no second closure.
     *
     * This is the WHOLE of Data::readValue()'s rule, asked of the registry
     * directly. A type owns how it is written and how it is read, in one row of
     * one table; the model may not carry a second copy of the read side.
     *
     * @param array<string, mixed> $config
     */
    private function read(string $name, mixed $stored, array $config = []): mixed
    {
        $this->assertTrue(
            (new ReflectionClass(NTDST_FieldType::class))->hasProperty('read'),
            'NTDST_FieldType must carry a `read` closure — fn(mixed $stored, array $config): mixed, '
            . 'null meaning "the sanitizer is the cast". Without it the read side lives outside the '
            . 'vocabulary and can disagree with it.',
        );

        $entry = NTDST_FieldTypes::get($name);

        return ($entry->read ?? $entry->sanitize)($stored, $config);
    }

    /**
     * `read` is the sixth facet of an entry, and it is OPTIONAL: an entry whose
     * sanitizer already casts declares none. Asserted through reflection rather
     * than by construction, so a missing parameter reads as a failing assertion
     * instead of an ArgumentCountError with no sentence in it.
     */
    public function testAnEntryDeclaresItsReadAsAnOptionalSixthParameter(): void
    {
        $parameters = (new ReflectionClass(NTDST_FieldType::class))->getConstructor()->getParameters();

        $this->assertCount(
            6,
            $parameters,
            'NTDST_FieldType::__construct() must take six facets; the sixth is `read`.',
        );

        $read = $parameters[5];

        $this->assertSame('read', $read->getName(), 'The sixth parameter is named `read` — entries pass it by name.');
        $this->assertTrue($read->isOptional(), 'An entry whose sanitizer casts declares no read.');
        $this->assertNull($read->getDefaultValue(), 'The default is null: "the sanitizer is the cast".');
        $this->assertSame('?Closure', (string) $read->getType());
    }

    /**
     * WHAT A STORED VALUE READS BACK AS — one row per type (reviewer IMP-3,
     * simplicity I2).
     *
     * A read is a CAST or a DECODE, never a second sanitization and never a
     * lookup. Three reasons, all of them load-bearing:
     *
     *   1. A value stored outside this model — by an importer, by WP-CLI, by the
     *      site's previous plugin — is not this model's to rewrite. Re-cleaning
     *      it on the way out means the value the database holds and the value the
     *      template prints are different strings, and nobody can tell which one
     *      is on the page.
     *   2. The write side already ran. `wp_kses_post()` per html field per row is
     *      paid on every list screen and every REST list response, for an answer
     *      that cannot change.
     *   3. A lookup per field per row is a query per field per row. `image` reads
     *      back the id it stored; whether that attachment still exists is the
     *      write side's question (and the template's), not the reader's.
     *
     * The stub for `wp_kses_post()` and `get_post_type()` FAILS the test if it is
     * called, so "does not re-sanitize" and "does not look up" are observations
     * rather than beliefs.
     *
     * @dataProvider readProvider
     * @param array<string, mixed> $t
     */
    public function testEachTypeReadsItsStoredValueBack(array $t): void
    {
        Functions\when('wp_kses_post')->alias(
            fn() => $this->fail("read('{$t['name']}') called wp_kses_post(): a read never re-sanitizes."),
        );
        Functions\when('get_post_type')->alias(
            fn() => $this->fail("read('{$t['name']}') called get_post_type(): a read never looks anything up."),
        );

        foreach ($t['reads'] as $index => [$stored, $expected]) {
            $this->assertSame(
                $expected,
                $this->read($t['name'], $stored, $t['config']),
                "{$t['name']}: stored value #{$index} did not read back as itself.",
            );
        }
    }

    /**
     * The empty state and the shape nobody expected. A meta key that was never
     * written arrives as null, and a key WordPress hands back as something else
     * entirely (an object out of a serialized row, an array where a scalar was
     * declared) must not become a TypeError on a page load — it reads as the
     * type's empty answer, the same one an unwritten key gives.
     *
     * @dataProvider readProvider
     * @param array<string, mixed> $t
     */
    public function testAnUnexpectedStoredValueReadsAsTheTypesEmptyAnswer(array $t): void
    {
        foreach ([null, new stdClass(), ['a' => 'b']] as $stored) {
            if ($stored === ['a' => 'b'] && in_array($t['name'], ['array', 'json', 'repeater'], true)) {
                continue; // an array IS the stored shape for these three
            }

            $this->assertSame(
                $t['emptyRead'],
                $this->read($t['name'], $stored, $t['config']),
                "{$t['name']}: an unexpected stored value must read as the type's empty answer.",
            );
        }
    }

    /**
     * One labelled row per type: stored value → what a consumer reads back.
     *
     * Every stored value here is one the WRITE side would have produced, or one
     * that was written around it — the tagged stubs make the difference visible,
     * because a read that re-sanitized would return 'text:…' instead of what was
     * stored.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function readProvider(): array
    {
        return [
            // The three whose sanitizer IS the cast: no read closure needed.
            'int' => [[
                'name' => 'int', 'config' => [], 'emptyRead' => 0,
                'reads' => [['-3', -3], ['42', 42]],
            ]],
            'float' => [[
                'name' => 'float', 'config' => [], 'emptyRead' => 0.0,
                'reads' => [['1.5', 1.5], ['-2.25', -2.25]],
            ]],
            'bool' => [[
                'name' => 'bool', 'config' => [], 'emptyRead' => false,
                'reads' => [['1', true], ['false', false], ['', false]],
            ]],
            // The string family: read back byte for byte. The stored text below
            // is daan's, from gig 297050 — a newline and a percent-encoded
            // slash, both of which sanitize_text_field() would rewrite.
            'text' => [[
                'name' => 'text', 'config' => [], 'emptyRead' => '',
                'reads' => [["a\nb 100%2F50", "a\nb 100%2F50"]],
            ]],
            'textarea' => [[
                'name' => 'textarea', 'config' => [], 'emptyRead' => '',
                'reads' => [["line1\nline2  <b>x</b>", "line1\nline2  <b>x</b>"]],
            ]],
            // wp_kses_post() ran on the way in. Running it again on the way out
            // is a per-row cost for an answer that cannot change — and the stub
            // fails the test if it is called.
            'html' => [[
                'name' => 'html', 'config' => [], 'emptyRead' => '',
                'reads' => [['<p>a</p><script>x</script>', '<p>a</p><script>x</script>']],
            ]],
            'email' => [[
                'name' => 'email', 'config' => [], 'emptyRead' => '',
                'reads' => [['a@b.c<script>', 'a@b.c<script>']],
            ]],
            'url' => [[
                'name' => 'url', 'config' => [], 'emptyRead' => '',
                'reads' => [['https://netdust.be/x?a=1&b=2', 'https://netdust.be/x?a=1&b=2']],
            ]],
            // Re-parsing a date on every read is strtotime() per field per row,
            // and the write side already refused a non-date. A legacy datetime
            // reads back as the string that is stored, not as today's format.
            'date' => [[
                'name' => 'date', 'config' => [], 'emptyRead' => '',
                'reads' => [['2026-08-22', '2026-08-22'], ['2026-08-22 14:00', '2026-08-22 14:00']],
            ]],
            'select' => [[
                'name' => 'select', 'config' => [], 'emptyRead' => '',
                'reads' => [['option-a <b>', 'option-a <b>']],
            ]],
            // Decode only. The keys were sanitized on the way in; re-running
            // sanitize_key() on a read can only rename what is stored.
            'array' => [[
                'name' => 'array', 'config' => [], 'emptyRead' => [],
                'reads' => [
                    ['{"a":"<b>x"}', ['a' => '<b>x']],
                    [['a' => '<b>x'], ['a' => '<b>x']],
                    ['not json', []],
                ],
            ]],
            'json' => [[
                'name' => 'json', 'config' => [], 'emptyRead' => [],
                'reads' => [
                    ['{"a":"<b>x"}', ['a' => '<b>x']],
                    [['a' => '<b>x'], ['a' => '<b>x']],
                    ['"scalar"', []],
                ],
            ]],
            // A list of ids reads by the SAME rule that wrote it — a non-scalar
            // never becomes an id (absint(['a']) is 1, a real post on every
            // site), a 0 is not an id, and the answer is a re-indexed list so it
            // does not serialize as a JSON object.
            'relation' => [[
                'name' => 'relation', 'config' => [], 'emptyRead' => [],
                'reads' => [
                    ['["1","2"]', [1, 2]],
                    [['1', '2'], [1, 2]],
                    ['["1", 0, "x", {"b":1}]', [1]],
                ],
            ]],
            'gallery' => [[
                'name' => 'gallery', 'config' => [], 'emptyRead' => [],
                'reads' => [
                    ['["1","2"]', [1, 2]],
                    [['1', '2'], [1, 2]],
                    ['["1", 0, "x", {"b":1}]', [1]],
                ],
            ]],
            // The id that is stored, with no lookup — 1000 is a post id the
            // write side would have refused, and a read that "fixed" it would
            // buy a query per field per row to do it.
            'image' => [[
                'name' => 'image', 'config' => [], 'emptyRead' => 0,
                'reads' => [['7', 7], ['1000', 1000]],
            ]],
            'file' => [[
                'name' => 'file', 'config' => [], 'emptyRead' => 0,
                'reads' => [['7', 7], ['1000', 1000]],
            ]],
            // Rows, and nothing else. No cell is re-sanitized (the cells were
            // cleaned by their declared types on the way in) and NOTHING is
            // unserialized: WordPress's own maybe_unserialize() already ran
            // before the value reached the model, so a string that is still
            // serialized here is not a value this model wrote.
            'repeater' => [[
                'name' => 'repeater', 'config' => self::REPEATER, 'emptyRead' => [],
                'reads' => [
                    ['[{"t":"a"},"junk",{"t":"b"}]', [['t' => 'a'], ['t' => 'b']]],
                    [[['t' => 'a'], 'junk'], [['t' => 'a']]],
                    ['[{"t":"<b>x"}]', [['t' => '<b>x']]],
                    ['a:1:{i:0;a:1:{s:1:"t";s:1:"a";}}', []],
                ],
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

    /**
     * I-1 — the saturation promise is about a numeric STRING and nothing else.
     *
     * `(int) '99999999999999999999'` is PHP's string-to-int conversion, and
     * that one clamps at the platform maximum. `(int) 1.0e30` is a float cast
     * out of range, which PHP leaves UNDEFINED: it is 5076964154930102272 on
     * this build and another number on another. A REST write carries a JSON
     * number as a float, so that is the path a consumer meets it on — README
     * may promise saturation for the string and must not promise it there.
     */
    public function testIntSaturatesANumericStringAndPromisesNothingForAnOversizedFloat(): void
    {
        $this->assertSame(PHP_INT_MAX, $this->sanitize('int', '99999999999999999999'));

        $this->assertIsInt($this->sanitize('int', 1.0e30));
        $this->assertNotSame(
            PHP_INT_MAX,
            $this->sanitize('int', 1.0e30),
            'An oversized FLOAT does not saturate; the entry casts and PHP decides.',
        );
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
     * Both halves of the date read the SAME clock. strtotime() parses in the
     * process timezone, so the formatter must write in the process timezone —
     * gmdate() reads a different clock and loses a day east of UTC.
     *
     * WordPress forces the process clock to UTC (wp-settings.php:73), so the
     * pairing only shows itself once something moves that clock: a plugin, a
     * WP-CLI command, or a consumer that calls date_default_timezone_set().
     * The test moves it, because a mismatch that only a plugin can trigger is
     * still a stored date that drifts.
     */
    public function testDateKeepsBothHalvesOnOneClock(): void
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

    /**
     * array accepts the JSON string the metabox textarea posts. `array` and
     * `json` sanitize the same (see build()'s docblock) — a string leaf goes
     * through sanitize_textarea_field(), the same fix and the same reason as
     * json's (Stride Ruling 56): newlines survive, markup does not.
     */
    public function testArrayAcceptsAJsonStringAndSanitizesKeysAndLeaves(): void
    {
        $this->assertSame(['ab' => 'textarea:v'], $this->sanitize('array', '{"A<b>!":"v"}'));
        $this->assertSame([], $this->sanitize('array', 'not json'));
        $this->assertSame(
            ['n' => 5, 'f' => 1.5, 'b' => true, 'z' => null],
            $this->sanitize('array', ['n' => 5, 'f' => 1.5, 'b' => true, 'z' => null]),
        );
        $this->assertSame(
            ['outer' => ['inner' => 'textarea:x']],
            $this->sanitize('array', ['outer' => ['inner' => '<i>x</i>']]),
        );
    }

    /** json decodes, then sanitizes what it decoded — or stores nothing. */
    public function testJsonDecodesAndRefusesWhatIsNotAnObject(): void
    {
        $this->assertSame([], $this->sanitize('json', 'not json'));
        $this->assertSame([], $this->sanitize('json', '"scalar"'));
        $this->assertSame(['k' => 'textarea:v'], $this->sanitize('json', ['k' => '<b>v']));
    }

    /**
     * A string leaf is a textarea's answer, not a text input's: WordPress's
     * sanitize_text_field() COLLAPSES "\n" and "\r" to a single space, so a
     * multiline note stored as `json` (Stride Ruling 56: an admin note's
     * `content`) came back flattened on every read. sanitize_textarea_field()
     * strips the same tags and percent-encoding but keeps the newlines — it is
     * WordPress's own textarea rule, and it composes on top of a consumer's own
     * sanitize_textarea_field() instead of overwriting it (api/Data.php
     * docblock: the registry sanitizer runs after the declared one).
     *
     * Keys are still sanitize_key()'d, and typed scalars (bool/int/float/null)
     * still pass through untouched — this only changes what a STRING leaf goes
     * through.
     */
    public function testJsonStringLeavesKeepNewlinesViaSanitizeTextareaField(): void
    {
        $this->assertSame(
            ['note' => "textarea:regel 1.\n\nregel 3."],
            $this->sanitize('json', ['note' => "regel 1.\n\nregel 3."]),
        );

        // Markup is still stripped — sanitize_textarea_field() strips tags too.
        $this->assertSame(
            ['note' => "textarea:regel 1.\n\nregel 3."],
            $this->sanitize('json', ['note' => "<b>regel 1.</b>\n\n<i>regel 3.</i>"]),
        );

        // Typed scalars are untouched by either sanitizer.
        $this->assertSame(
            ['b' => true, 'n' => 3, 'f' => 1.5, 'z' => null],
            $this->sanitize('json', ['b' => true, 'n' => 3, 'f' => 1.5, 'z' => null]),
        );
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
     * A cell is sanitized by the type its DECLARATION names, whatever the
     * declared key looks like. The stored key is the sanitized one, so a
     * declaration whose name is not already its own sanitize_key() —
     * 'subTitle' — must still be found; otherwise the cell silently falls
     * through to text and an int field stores 'text:12'.
     *
     * The second pass is the register_post_meta() re-save: it arrives with
     * the SANITIZED key, so the declaration must be reachable from that key
     * too, or the type is lost on every REST write (FR-2: every sanitizer is
     * idempotent).
     */
    public function testACellIsFoundByItsDeclarationWhateverTheKeysCasing(): void
    {
        $once = $this->sanitize('repeater', [['subTitle' => '12<b>']], self::CAMEL_CASE_CELL);

        $this->assertSame([['subtitle' => 12]], $once);
        $this->assertSame(
            $once,
            $this->sanitize('repeater', $once, self::CAMEL_CASE_CELL),
            'the re-save arrives with the sanitized key and must still find the declaration',
        );
    }

    /**
     * A row of nothing but junk drops on the FIRST pass. A row survives when
     * any POSTED cell is not '' / null AND any SANITIZED cell is not '' /
     * null: '0' and 'false' are answers (they sanitize to 0 and false), but a
     * refused URL is not — it sanitizes to '', and keeping the row means the
     * second pass drops it, so sanitize(sanitize(x)) !== sanitize(x) and the
     * REST re-save deletes a row the edit screen just showed.
     */
    public function testAJunkOnlyRowDropsOnTheFirstPass(): void
    {
        $rows = [
            ['link' => 'javascript:x', 'title' => ''],
            ['link' => 'https://netdust.be/x', 'title' => ''],
        ];

        $once = $this->sanitize('repeater', $rows, self::JUNK_CELLS);

        $this->assertSame([['link' => 'url:https://netdust.be/x', 'title' => '']], $once);
        $this->assertSame(
            $once,
            $this->sanitize('repeater', $once, self::JUNK_CELLS),
            'a row kept on pass 1 and dropped on pass 2 is a row the REST re-save deletes',
        );

        // The falsy answers are still answers — 0 and false are not nothing.
        $this->assertSame(
            [['qty' => 0, 'featured' => false, 'title' => '']],
            $this->sanitize('repeater', [['qty' => '0', 'featured' => '', 'title' => '']], self::FALSY_CELLS),
        );
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

    /**
     * repeater() decodes the stored JSON-string shape before its is_array()
     * check — the same decode() the `json` type runs at toArray() (:569-572).
     * A legacy/raw write (or update_post_meta() through
     * register_post_meta()'s sanitize_callback) hands the repeater the JSON
     * STRING the metabox posts and history stored (Stride Ruling 106:
     * converting an existing json field to `repeater` silently reduced every
     * stored `speakers` string to []). A string that decodes to a list of rows
     * proceeds through the normal per-cell sanitizing; anything else — garbage,
     * a JSON object, a JSON scalar — still answers [].
     */
    public function testRepeaterDecodesTheStoredJsonStringShape(): void
    {
        $json = '[{"name":"<b>Ann</b>","role":"host"},{"name":"Bo","role":"guest"}]';

        $this->assertSame(
            [
                ['name' => 'text:Ann', 'role' => 'text:host'],
                ['name' => 'text:Bo', 'role' => 'text:guest'],
            ],
            $this->sanitize('repeater', $json, self::SPEAKERS),
        );

        // Garbage still answers [] — decode() already refuses it.
        $this->assertSame([], $this->sanitize('repeater', 'not json', self::SPEAKERS));
        $this->assertSame([], $this->sanitize('repeater', '"just a string"', self::SPEAKERS));

        // An already-array input is unchanged behaviour.
        $this->assertSame(
            [['name' => 'text:Ann', 'role' => 'text:host']],
            $this->sanitize('repeater', [['name' => '<b>Ann</b>', 'role' => 'host']], self::SPEAKERS),
        );
    }
}
