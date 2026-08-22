<?php // tests/Unit/FieldTypesTest.php
// The vocabulary's one table — what every field value on every site passes through.
//
// This is the RED contract for field-types T01 (split test-author). It asserts
// the PROMISE, never an implementation: for each of the 17 canonical names the
// registry answers with one sanitizer, one REST leaf shape, one admin control
// and one cell verdict; the sanitizer survives a NAMED hostile input and is
// idempotent on it (register_post_meta() applies it a second time on a REST
// write); and every other name — a retired alias or an invention — is refused
// loudly, naming the canonical or the known set.
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
//   - get_post_type         — ids 1..99 are attachments, everything else is not.
//
// TWO CONTRACT READINGS THIS FILE PINS, both reported to the ledger with it:
//   1. bool "<b>1" is TRUE. The plan's threat row #1 expects false; WordPress's
//      wp_validate_boolean() returns true (only the exact string "false" is
//      false), and FR-2 binds bool to wp_validate_boolean. The WordPress answer
//      wins — see testBoolIsWordPresssOwnAnswerNotOurs().
//   2. repeater->schema is NULL. FR-4 keeps the repeater's shape STRUCTURAL:
//      schemaFor() recurses over sub_fields and builds the closed object itself,
//      so the repeater has no leaf shape for the registry to hold. `json`'s null
//      means "never publishable"; the repeater's means "not a leaf".
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
        Functions\when('wp_kses_post')->alias(
            $tagged('kses', static fn(string $raw): string => (string) preg_replace('@<script[^>]*?>.*?</script>@si', '', $raw)),
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
     * A retired name is refused, and the message says what to write instead.
     *
     * @dataProvider retiredNameProvider
     */
    public function testARetiredNameNamesItsCanonical(string $retired, string $canonical): void
    {
        try {
            NTDST_FieldTypes::get($retired);
            $this->fail("Expected InvalidArgumentException for the retired name '{$retired}'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Unknown field type '{$retired}'.", $e->getMessage());
            $this->assertStringContainsString("Use '{$canonical}'.", $e->getMessage());
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

    /** The message a consumer reads at init, to the character. */
    public function testTheRetiredMessageIsOneSentenceAndTheCanonical(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown field type 'integer'. Use 'int'.");

        NTDST_FieldTypes::get('integer');
    }

    /** An invented name gets the vocabulary, not a canonical it never had. */
    public function testAnInventedNameGetsTheKnownSet(): void
    {
        try {
            NTDST_FieldTypes::get('wysiwig');
            $this->fail('Expected InvalidArgumentException for an invented name.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Unknown field type 'wysiwig'.", $e->getMessage());
            $this->assertStringContainsString('Known: int, float', $e->getMessage());
            $this->assertStringNotContainsString("Use '", $e->getMessage());
        }
    }

    /** The empty type name is an invention like any other. */
    public function testTheEmptyNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Known: int, float');

        NTDST_FieldTypes::get('');
    }

    /** The set is closed on the exact spelling — 'Int' is not 'int'. */
    public function testTheVocabularyIsCaseSensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Known: int, float');

        NTDST_FieldTypes::get('Int');
    }

    /**
     * Threat row #6 — a plugin cannot inject a type whose sanitizer is a no-op.
     * There is no filter and no registration method: two readers, nothing else.
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

    public function testNoRetiredNameSurvivesInTheVocabulary(): void
    {
        $retired = array_column(self::retiredNameProvider(), 0);

        $this->assertSame([], array_intersect($retired, NTDST_FieldTypes::names()));
    }

    /** @dataProvider entryProvider */
    public function testEachTypeIsNamedByItsOwnEntry(string $name): void
    {
        $type = NTDST_FieldTypes::get($name);

        $this->assertInstanceOf(NTDST_FieldType::class, $type);
        $this->assertSame($name, $type->name);
        $this->assertInstanceOf(Closure::class, $type->sanitize);
    }

    /**
     * Threat row #1 — the named hostile input per type, plus the valid and the
     * empty answer around it.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed> $config
     */
    public function testEachTypeAnswersOnValidHostileAndEmptyInput(
        string $name,
        array $config,
        mixed $valid,
        mixed $validAnswer,
        mixed $hostile,
        mixed $hostileAnswer,
        mixed $empty,
        mixed $emptyAnswer,
    ): void {
        $this->assertSame($validAnswer, $this->sanitize($name, $valid, $config), "{$name}: valid input");
        $this->assertSame($hostileAnswer, $this->sanitize($name, $hostile, $config), "{$name}: hostile input");
        $this->assertSame($emptyAnswer, $this->sanitize($name, $empty, $config), "{$name}: empty input");
    }

    /**
     * Threat row #1 — register_post_meta() applies the sanitizer a second time
     * on every REST write, so the second pass must change nothing.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed> $config
     */
    public function testEachTypeIsIdempotentOnItsHostileInput(
        string $name,
        array $config,
        mixed $valid,
        mixed $validAnswer,
        mixed $hostile,
    ): void {
        $once = $this->sanitize($name, $hostile, $config);

        $this->assertSame($once, $this->sanitize($name, $once, $config), "{$name}: sanitize(sanitize(x)) !== sanitize(x)");
    }

    /**
     * Threat row #2 — the leaf shape /wp/v2 publishes, byte-identical to
     * core-shape FR-2's table.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed>|null $schema
     */
    public function testEachTypePublishesItsLeafShape(
        string $name,
        array $config,
        mixed $valid,
        mixed $validAnswer,
        mixed $hostile,
        mixed $hostileAnswer,
        mixed $empty,
        mixed $emptyAnswer,
        ?array $schema,
    ): void {
        $this->assertSame($schema, NTDST_FieldTypes::get($name)->schema, "{$name}: REST leaf shape");
    }

    /**
     * FR-2 / threat row #5 — the admin input, and whether the type may live in
     * a repeater row at all.
     *
     * @dataProvider entryProvider
     * @param array<string, mixed>|null $schema
     */
    public function testEachTypeDrawsItsControlAndKnowsItsCellVerdict(
        string $name,
        array $config,
        mixed $valid,
        mixed $validAnswer,
        mixed $hostile,
        mixed $hostileAnswer,
        mixed $empty,
        mixed $emptyAnswer,
        ?array $schema,
        string $control,
        bool $cell,
    ): void {
        $type = NTDST_FieldTypes::get($name);

        $this->assertSame($control, $type->control, "{$name}: admin control");
        $this->assertSame($cell, $type->cell, "{$name}: may render in a repeater row");
    }

    /**
     * name · config · valid in/out · hostile in/out · empty in/out · schema · control · cell
     *
     * @return array<string, array<int, mixed>>
     */
    public static function entryProvider(): array
    {
        return [
            'int' => [
                'int', [], '42', 42, '12<script>', 12, '', 0,
                ['type' => 'integer'], 'number', true,
            ],
            'float' => [
                'float', [], '3.5', 3.5, '1.5e3<b>', 1500.0, '', 0.0,
                ['type' => 'number'], 'number', true,
            ],
            'bool' => [
                'bool', [], '1', true, 'false', false, '', false,
                ['type' => 'boolean'], 'checkbox', true,
            ],
            'text' => [
                'text', [], 'Hello', 'text:Hello', "<b>x</b>\n", 'text:x', '', '',
                ['type' => 'string'], 'text', true,
            ],
            'textarea' => [
                'textarea', [], "line1\nline2", "textarea:line1\nline2", "<script>a</script>\nb", "textarea:a\nb", '', '',
                ['type' => 'string'], 'textarea', true,
            ],
            'html' => [
                'html', [], '<p>a</p>', 'kses:<p>a</p>', '<p>a</p><script>x</script>', 'kses:<p>a</p>', '', '',
                ['type' => 'string'], 'html', false,
            ],
            'email' => [
                'email', [], 'a@b.com', 'email:a@b.com', 'a@b.c<script>', 'email:a@b.cscript', '', '',
                ['type' => 'string', 'format' => 'email'], 'email', true,
            ],
            'url' => [
                'url', [], 'https://netdust.be/x', 'url:https://netdust.be/x', 'javascript:alert(1)', '', '', '',
                ['type' => 'string', 'format' => 'uri'], 'url', true,
            ],
            'date' => [
                'date', [], '2026-08-22', '2026-08-22', '2026-13-45', '', '', '',
                ['type' => 'string'], 'date', true,
            ],
            'select' => [
                'select', [], 'option-a', 'text:option-a', '<b>x', 'text:x', '', '',
                ['type' => 'string'], 'select', true,
            ],
            'array' => [
                'array', [], ['a' => 'x'], ['a' => 'text:x'], '{"a":"<b>x"}', ['a' => 'text:x'], '', [],
                ['type' => 'array', 'items' => ['type' => 'string']], 'textarea', true,
            ],
            'json' => [
                'json', [], '{"k":"v"}', ['k' => 'text:v'], '{"k":"<b>v"}', ['k' => 'text:v'], '', [],
                null, 'textarea', true,
            ],
            'relation' => [
                'relation', [], ['1', '2'], [1, 2], ['1', 'x', '-3'], [1, 3], '', [],
                ['type' => 'array', 'items' => ['type' => 'integer']], 'relation', false,
            ],
            'gallery' => [
                'gallery', [], ['1', '2'], [1, 2], ['1', 'x', '-3'], [1, 3], '', [],
                ['type' => 'array', 'items' => ['type' => 'integer']], 'gallery', false,
            ],
            'image' => [
                'image', [], '7', 7, '7<b>', 7, '', 0,
                ['type' => 'integer'], 'media', true,
            ],
            'file' => [
                'file', [], '7', 7, '7<b>', 7, '', 0,
                ['type' => 'integer'], 'media', true,
            ],
            'repeater' => [
                'repeater',
                self::REPEATER,
                [['title' => 'Hi', 'pic' => '7']],
                [['title' => 'text:Hi', 'pic' => 7]],
                [['title' => '<b>x</b>', 'pic' => '']],
                [['title' => 'text:x', 'pic' => '']],
                '',
                [],
                null,
                'repeater',
                false,
            ],
        ];
    }

    // ------------------------------------------------------- per-type edges

    /** FR-5 — an int keeps its sign; an array is not a number. */
    public function testIntIsSignedAndRefusesAnArray(): void
    {
        $this->assertSame(-5, $this->sanitize('int', '-5'));
        $this->assertSame(-250, $this->sanitize('int', -250));
        $this->assertSame(0, $this->sanitize('int', [1]));
        $this->assertSame(0, $this->sanitize('int', []));
    }

    /**
     * bool is WordPress's word, so WordPress's answer stands: only the exact
     * string "false" is false. The plan's threat row #1 expects "<b>1" to be
     * false; wp_validate_boolean() says true, and FR-2 binds bool to it.
     */
    public function testBoolIsWordPresssOwnAnswerNotOurs(): void
    {
        $this->assertFalse($this->sanitize('bool', 'false'));
        $this->assertFalse($this->sanitize('bool', 'FALSE'));
        $this->assertFalse($this->sanitize('bool', '0'));
        $this->assertFalse($this->sanitize('bool', false));
        $this->assertTrue($this->sanitize('bool', '<b>1'));
        $this->assertTrue($this->sanitize('bool', true));
    }

    /** A javascript: URL never survives, whatever its casing. */
    public function testUrlRefusesAJavascriptScheme(): void
    {
        $this->assertSame('', $this->sanitize('url', 'javascript:alert(1)'));
        $this->assertSame('', $this->sanitize('url', 'JavaScript:alert(1)'));
        $this->assertSame('', $this->sanitize('url', ' javascript:alert(1)'));
    }

    /** A date field holds a date in Y-m-d, or nothing at all. */
    public function testDateStoresYmdOrNothing(): void
    {
        $this->assertSame('2026-08-22', $this->sanitize('date', '22 August 2026'));
        $this->assertSame('2026-08-22', $this->sanitize('date', '2026-08-22'));
        $this->assertSame('', $this->sanitize('date', 'not a date'));
        $this->assertSame('', $this->sanitize('date', '   '));
    }

    /** array accepts the JSON string the metabox textarea posts. */
    public function testArrayAcceptsAJsonStringAndSanitizesKeysAndLeaves(): void
    {
        $this->assertSame(['a' => 'text:x'], $this->sanitize('array', '{"a":"<b>x"}'));
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
        $this->assertSame(['k' => 'text:v'], $this->sanitize('json', '{"k":"<b>v"}'));
        $this->assertSame([], $this->sanitize('json', 'not json'));
        $this->assertSame([], $this->sanitize('json', '"scalar"'));
        $this->assertSame([], $this->sanitize('json', '   '));
        $this->assertSame(['k' => 'text:v'], $this->sanitize('json', ['k' => '<b>v']));
    }

    /**
     * relation stores a LIST of ids: a single pick is wrapped, junk absints to
     * 0 and is dropped, and the keys are re-indexed — a gap-keyed array leaves
     * as a JSON object and stops matching `array of integer`.
     *
     * absint('-3') is 3. That is WordPress's answer, pinned as WordPress's.
     */
    public function testRelationStoresAReindexedListOfIds(): void
    {
        $this->assertSame([7], $this->sanitize('relation', '7'));
        $this->assertSame([1, 3], $this->sanitize('relation', ['1', 'x', '-3']));
        $this->assertSame([], $this->sanitize('relation', ''));
        $this->assertSame([], $this->sanitize('relation', []));
    }

    /** gallery is a multi-pick: a scalar is not a gallery (today's rule). */
    public function testGalleryTakesOnlyAList(): void
    {
        $this->assertSame([], $this->sanitize('gallery', '7'));
        $this->assertSame([1, 3], $this->sanitize('gallery', ['1', 'x', '-3']));
        $this->assertSame([], $this->sanitize('gallery', ''));
    }

    /**
     * An image/file field holds an attachment id that EXISTS and is an
     * attachment — 1000 is a post, and a post id in a media field is a 0.
     */
    public function testImageAndFileStoreOnlyARealAttachmentId(): void
    {
        foreach (['image', 'file'] as $name) {
            $this->assertSame(7, $this->sanitize($name, '7<b>'), $name);
            $this->assertSame(0, $this->sanitize($name, 'x'), $name);
            $this->assertSame(0, $this->sanitize($name, '1000'), $name);
            $this->assertSame(0, $this->sanitize($name, ''), $name);
        }
    }

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

    /** A key the repeater never declared is still sanitized — as text (today). */
    public function testRepeaterSanitizesAnUndeclaredKeyAsText(): void
    {
        $this->assertSame(
            [['title' => 'text:a', 'extra' => 'text:y']],
            $this->sanitize('repeater', [['title' => 'a', 'extra' => '<b>y</b>']], self::REPEATER),
        );

        // No sub_fields at all: every cell is text, which is what a repeater
        // without a declared vocabulary has always stored.
        $this->assertSame(
            [['a' => 'text:x']],
            $this->sanitize('repeater', [['a' => '<b>x</b>']], []),
        );
    }

    /** Anything that is not a list of rows stores nothing. */
    public function testRepeaterRefusesWhatIsNotRows(): void
    {
        $this->assertSame([], $this->sanitize('repeater', '', self::REPEATER));
        $this->assertSame([], $this->sanitize('repeater', [], self::REPEATER));
        $this->assertSame([], $this->sanitize('repeater', ['not-a-row'], self::REPEATER));
    }
}
