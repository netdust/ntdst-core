<?php // tests/Unit/DataRegistersRestMetaTest.php
// What a declared field looks like once it leaves — and what never leaves at all.
//
// registerRestMeta() is the only place the Data layer turns a field DESCRIPTION
// into something WordPress publishes, and after field-types FR-4 it is the only
// place a caller can observe the answer: the leaf shape comes from
// NTDST_FieldTypes, the structural rule stays in a PRIVATE schemaFor(), and the
// two public 0-reader helpers are deleted. Two rules are load-bearing, and both
// are denial rules:
//
//   1. STRICT OPT-IN. A field that did not say `show_in_rest => true` — exactly
//      true, not 'yes', not 1 — reaches WordPress under no key. Every time.
//   2. A repeater publishes ALL OR NOTHING. WordPress validates a stored row
//      against the closed schema it was given, so a row carrying a key the
//      schema does not name reads back as null and a write that carries it is
//      refused — while a write WITHOUT it silently replaces the row and drops
//      the undeclared sub-field from storage. A repeater therefore publishes
//      only when every sub-field opted in (closed object, every name present);
//      one undeclared sub-field at any depth makes the whole field unpublishable.
//      A `json` blob has no sub-field vocabulary at all, so it never publishes,
//      and neither does an `array` (spec revision 3): its sanitizer keeps a
//      KEYED map of typed scalars, which no leaf schema admits.
//      A repeater that declared NO sub-fields has no vocabulary either. Its
//      schema would be a closed object with zero properties, and WordPress
//      measures the stored value against that schema on every read and write
//      (class-wp-rest-meta-fields.php:556, prepare_value) — so every stored row
//      reads back as null and a write wipes it. Same mechanism as the partial
//      repeater, so the same verdict: unpublishable, absent or empty or not an
//      array at all.
//
// Both refusals are LOUD: a field that said `show_in_rest => true` and cannot be
// published is a declaration the module will never see honoured, so registration
// warns once per model, naming the field.
//
// T02 cases are grouped first; T03 (registerRestMeta) appends below them.
// Written against field-types FR-4, SC-2 and SC-5, and core-shape INV-1.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

final class DataRegistersRestMetaTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // TAGGED stubs, not pass-throughs. T03 asks whether a registration
        // carries the model's OWN sanitizer for that field, and a pass-through
        // stub cannot answer: sanitize_text_field() and sanitize_textarea_field()
        // would both return the probe unchanged, so the wrong wiring would pass.
        // Each tag names the function that produced the value.
        foreach ([
            'sanitize_text_field'     => 'text',
            'sanitize_textarea_field' => 'textarea',
            'esc_url_raw'             => 'url',
            'sanitize_email'          => 'email',
            'wp_kses_post'            => 'html',
        ] as $fn => $tag) {
            Functions\when($fn)->alias(static fn($v) => $tag . ':' . trim((string) $v));
        }

        // The model names absint() by string ('int' => 'absint'); nothing in
        // this process defines it.
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));

        // ntdst_log() is a REAL recorder from tests/bootstrap.php; the entries
        // are process-wide, so each case starts from an empty log.
        $GLOBALS['_ntdst_test_log'] = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $fields */
    private function model(array $fields): NTDST_Data_Model
    {
        return new NTDST_Data_Model('probe', $fields, '_probe_');
    }

    // ---------------------------------------------------------------- T02 --
    // The structural rule, read where it is now observable: the registration.
    //
    // field-types FR-4 deletes restSchemaFor(): schemaFor() is private, keeps
    // ONLY the structural rule (the repeater recursion and the all-or-nothing
    // refusal) and reads each leaf's shape from NTDST_FieldTypes::get($type)
    // ->schema. So the one place a caller can still see what a field publishes
    // is the register_post_meta() call registerRestMeta() makes — `type` plus
    // `show_in_rest` (true for a scalar, ['schema' => …] for an array).
    //
    // Every promise below is core-shape's, word for word. Only the observable
    // moved, and it moved to the stronger one: "has no schema" now reads as
    // "reaches WordPress under NO key", which denies the write surface as well
    // as the read one.

    /** Distinct model names: the unpublishable warning is guarded once per model. */
    private static int $modelSequence = 0;

    /**
     * A model name nobody else in this process has used, so a once-per-model
     * guard cannot swallow a warning another case is asserting.
     *
     * @param array<string, mixed> $fields
     */
    private function freshModel(array $fields): NTDST_Data_Model
    {
        return new NTDST_Data_Model('probe_seq_' . (++self::$modelSequence), $fields, '_probe_');
    }

    /**
     * Declare $fields, register them, and hand back the register_post_meta()
     * call for $field — or null when the field was refused and WordPress was
     * told nothing about it under any key.
     *
     * @param  array<string, mixed> $fields
     * @return array<int, mixed>|null
     */
    private function registrationOf(array $fields, string $field): ?array
    {
        $this->captureRegistrations();
        $this->freshModel($fields)->registerRestMeta('probe_cpt');

        foreach ($this->metaCalls as $call) {
            if (($call[1] ?? null) === '_probe_' . $field) {
                return $call;
            }
        }

        return null;
    }

    /**
     * One declared repeater called `provenance`, the shape every rule below is
     * stated on.
     *
     * @param  array<string, mixed> $subFields
     * @return array<string, mixed>
     */
    private function repeaterFields(array $subFields): array
    {
        return [
            'provenance' => [
                'type'         => 'repeater',
                'show_in_rest' => true,
                'sub_fields'   => $subFields,
            ],
        ];
    }

    /**
     * The closed object a declared repeater published, or null when it
     * published nothing at all.
     *
     * @param array<string, mixed> $subFields
     * @return array<string, mixed>|null
     */
    private function publishedRepeaterSchema(array $subFields): ?array
    {
        $call = $this->registrationOf($this->repeaterFields($subFields), 'provenance');

        return $call === null ? null : ($call[2]['show_in_rest']['schema'] ?? null);
    }

    /**
     * Strict `=== true`. A truthy near-miss is a typo, and a typo must not be
     * the thing that publishes a private field — at the top level (below) or
     * one level down inside a repeater.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function truthyNearMissProvider(): array
    {
        return [
            'string yes'   => ['yes'],
            'integer one'  => [1],
            'string one'   => ['1'],
            'string true'  => ['true'],
        ];
    }

    /** WordPress's default, kept: a field nobody named does not leave. */
    public function testAFieldThatNamedNothingIsNeverPublished(): void
    {
        $this->assertNull($this->registrationOf(['promo_budget' => ['type' => 'float']], 'promo_budget'));
        $this->assertSame([], $this->metaCalls, 'Silence registers nothing at all.');
    }

    public function testShowInRestFalseIsNeverPublished(): void
    {
        $this->assertNull(
            $this->registrationOf(['cost' => ['type' => 'float', 'show_in_rest' => false]], 'cost'),
        );
        $this->assertSame([], $this->metaCalls);
    }

    /**
     * The leaf shape is the REGISTRY's — one table, asked by the layer that
     * registers, never a second table carried inside Data.
     *
     * This is the whole of field-types FR-4's schema half, said at the boundary
     * that matters: what WordPress is handed for a declared field of type X is
     * built out of NTDST_FieldTypes::get(X)->schema and nothing else. A name
     * whose registry shape is null (`array` and `json` — both keep typed
     * scalars that no leaf schema admits — and `repeater`, which has no leaf at
     * all and declares no sub_fields here) publishes nothing.
     *
     * Note what `show_in_rest => true` means for `email` and `url`: WordPress
     * derives the published schema from `type` alone, so the registry's
     * `format` facet does not travel. That is core-shape's shipped answer and
     * it is asserted, not assumed.
     *
     * @dataProvider vocabularyProvider
     */
    public function testAPublishedLeafCarriesTheRegistrysShape(string $type): void
    {
        $registry = NTDST_FieldTypes::get($type)->schema;

        $call = $this->registrationOf(
            ['probe_field' => ['type' => $type, 'show_in_rest' => true]],
            'probe_field',
        );

        if ($registry === null) {
            $this->assertNull(
                $call,
                "'{$type}' has no publishable leaf shape, so it must reach WordPress under no key.",
            );
            $this->assertSame([], $this->metaCalls, "'{$type}' must register nothing at all.");

            return;
        }

        $this->assertNotNull($call, "'{$type}' has a leaf shape in the registry, so it must be registered.");

        $this->assertSame(
            $registry['type'],
            $call[2]['type'] ?? null,
            "'{$type}': the registered `type` must be the registry's, not a second table's.",
        );

        $this->assertSame(
            in_array($registry['type'], ['array', 'object'], true) ? ['schema' => $registry] : true,
            $call[2]['show_in_rest'] ?? null,
            "'{$type}': what WordPress publishes must be the registry's shape.",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function vocabularyProvider(): array
    {
        $rows = [];

        foreach (NTDST_FieldTypes::names() as $name) {
            $rows[$name] = [$name];
        }

        return $rows;
    }

    /**
     * `array` joins it (spec revision 3). Its sanitizer keeps a KEYED map with
     * typed scalars, and the leaf WordPress would be given is a list of
     * strings — so every stored value reads back null against its own published
     * schema, the partial-repeater mechanism one type down. Unpublishable, and
     * said out loud so the module that wrote `show_in_rest => true` can find
     * out that it got nothing.
     */
    public function testADeclaredArrayFieldIsNeverPublishedAndSaysSoOnce(): void
    {
        $this->captureRegistrations();

        $this->namedModel('keyed_map_loud', ['settlement' => ['type' => 'array', 'show_in_rest' => true]])
            ->registerRestMeta('probe_cpt');

        $this->assertSame([], $this->metaCalls, 'A keyed map has no publishable leaf shape.');
        $this->assertStringNotContainsString('settlement', (string) json_encode($this->metaCalls));

        $warnings = $this->logMessages('warning');

        // The model is `keyed_map_loud` and the field is `settlement`: neither
        // spells `array`, so the type assertion can only be met by the reason
        // the warning gives.
        $this->assertCount(1, $warnings, 'A dropped REST declaration warns exactly once.');
        $this->assertStringContainsString('settlement', $warnings[0], 'The warning must name the field.');
        $this->assertStringContainsString('array', $warnings[0], 'The warning must say which type was dropped.');
        $this->assertSame([], $this->logMessages('error'), 'This is a declaration to fix, not a failure.');
    }

    /**
     * ALL OR NOTHING, on the shape that made the rule necessary.
     *
     * Publishing `year` and `lot` while `sale_price` stays out of the schema
     * does not hide the sale price — it BREAKS the field. WordPress validates
     * the stored row against the closed schema, so a row that carries the
     * undeclared key reads back as null (daan, gig 297050), a write carrying it
     * is refused 400, and an admin write of only the declared keys replaces the
     * row and drops the undeclared one from storage.
     *
     * A partially declared repeater is therefore registered nowhere.
     */
    public function testARepeaterWithAnyUndeclaredSubFieldIsNeverPublished(): void
    {
        $this->assertNull(
            $this->publishedRepeaterSchema([
                'year'       => ['type' => 'text', 'show_in_rest' => true],
                'lot'        => ['type' => 'int', 'show_in_rest' => true],
                'sale_price' => ['type' => 'float'], // one silent sub-field is enough
            ]),
            'Two declared sub-fields and one silent one is not a publishable repeater.',
        );
        $this->assertSame([], $this->metaCalls);
    }

    /** `show_in_rest => false` inside is the same refusal, said out loud. */
    public function testARepeaterWithARefusedSubFieldIsNeverPublished(): void
    {
        $this->assertNull($this->publishedRepeaterSchema([
            'year'    => ['type' => 'text', 'show_in_rest' => true],
            'reserve' => ['type' => 'float', 'show_in_rest' => false],
        ]));
        $this->assertSame([], $this->metaCalls);
    }

    /**
     * The only repeater that publishes: every sub-field opted in. The object is
     * closed behind exactly those names, so WordPress accepts the stored row and
     * nothing that was never named can ride along inside it — and each leaf
     * inside it is the registry's shape for that sub-field's type.
     */
    public function testAFullyDeclaredRepeaterPublishesEverySubFieldInAClosedObject(): void
    {
        $this->assertSame([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'year' => NTDST_FieldTypes::get('text')->schema,
                    'lot'  => NTDST_FieldTypes::get('int')->schema,
                ],
                'additionalProperties' => false,
            ],
        ], $this->publishedRepeaterSchema([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'lot'  => ['type' => 'int', 'show_in_rest' => true],
        ]));
    }

    /**
     * THE PUBLISHED SCHEMA IS KEYED THE WAY STORAGE IS KEYED (reviewer IMP-1).
     *
     * A repeater cell is stored under NTDST_FieldTypes::rowKey() of its declared
     * name, so `salePrice` is stored as `saleprice`. If the published object
     * names the property `salePrice`, WordPress measures the stored row against
     * a schema that names a key the row does not have, and closes the object
     * behind it: the stored `saleprice` is an additional property the schema
     * forbids. Every row of that field reads back as null through /wp/v2, and a
     * write is refused — the partial-repeater failure, caused by a capital
     * letter in a declaration.
     *
     * Two names, one rule, and this is the case that proves they are the same
     * rule rather than two functions that agree today.
     */
    public function testAPublishedRepeaterKeysItsSchemaOnTheStoredCellKey(): void
    {
        $schema = $this->publishedRepeaterSchema([
            'salePrice' => ['type' => 'int', 'show_in_rest' => true],
            'title'     => ['type' => 'text', 'show_in_rest' => true],
        ]);

        $this->assertIsArray($schema, 'Every sub-field opted in, so this repeater publishes.');
        $this->assertSame(
            ['saleprice', 'title'],
            array_keys($schema['items']['properties']),
            'The published property is the key the cell is STORED under, or the closed object '
            . 'nulls every row of the field it describes.',
        );
        $this->assertSame(
            NTDST_FieldTypes::get('int')->schema,
            $schema['items']['properties']['saleprice'],
            'And it still carries the registry shape for the type it was declared as.',
        );
        $this->assertFalse($schema['items']['additionalProperties']);
    }

    /**
     * The strict `=== true` rule applies one level down, and its consequence is
     * the parent's: a typo inside a repeater does not quietly drop one column,
     * it makes the repeater unpublishable.
     *
     * @dataProvider truthyNearMissProvider
     */
    public function testASubFieldTruthyNearMissMakesTheWholeRepeaterUnpublishable(mixed $declaration): void
    {
        $this->assertNull($this->publishedRepeaterSchema([
            'year'       => ['type' => 'text', 'show_in_rest' => true],
            'sale_price' => ['type' => 'float', 'show_in_rest' => $declaration],
        ]));
        $this->assertSame([], $this->metaCalls);
    }

    /**
     * A sub-field written as a bare type string (`'qty' => 'int'`) says nothing
     * about REST, so it never opted in — and a config shape that cannot carry a
     * declaration must not be read as one.
     */
    public function testABareStringSubFieldConfigMakesTheRepeaterUnpublishable(): void
    {
        $this->assertNull($this->publishedRepeaterSchema([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'qty'  => 'int',
        ]));
        $this->assertSame([], $this->metaCalls);
    }

    /**
     * The invariant behind every case above, stated so no encoding of the
     * registration can carry an undeclared sub-field NAME out to WordPress —
     * and so the declared shape is proved to carry its own.
     */
    public function testNoUndeclaredSubFieldNameReachesWordPress(): void
    {
        $this->registrationOf($this->repeaterFields([
            'year'       => ['type' => 'text', 'show_in_rest' => true],
            'sale_price' => ['type' => 'float'],
            'reserve'    => ['type' => 'float', 'show_in_rest' => false],
        ]), 'provenance');

        $encoded = (string) json_encode($this->metaCalls);

        $this->assertStringNotContainsString('sale_price', $encoded);
        $this->assertStringNotContainsString('reserve', $encoded);
        $this->assertStringNotContainsString('year', $encoded, 'A partial repeater publishes nothing at all.');
        $this->assertStringNotContainsString('provenance', $encoded);

        $whole = (string) json_encode($this->publishedRepeaterSchema([
            'year' => ['type' => 'text', 'show_in_rest' => true],
        ]));

        $this->assertStringContainsString('year', $whole);
    }

    /**
     * A repeater that declared no sub-fields has nothing to publish, and the
     * empty schema is not harmless. `properties => []` with
     * `additionalProperties => false` names NOTHING and admits nothing, and
     * WordPress measures the stored value against exactly that schema on both
     * sides of the boundary (class-wp-rest-meta-fields.php:556, prepare_value).
     * A stored row therefore reads back as null and a write wipes it — the
     * partial-repeater mechanism with every key undeclared instead of one.
     *
     * So the verdict is the partial repeater's: registered nowhere. Absent,
     * empty, or a value that is not a sub-field list at all — the three ways a
     * declaration arrives with no vocabulary — all reach WordPress under no key.
     *
     * @dataProvider noSubFieldsProvider
     * @param array<string, mixed> $declaration what the field says besides its type
     */
    public function testARepeaterWithNoDeclaredSubFieldsIsNeverPublished(array $declaration): void
    {
        $this->assertNull(
            $this->registrationOf(
                ['provenance' => array_merge(['type' => 'repeater', 'show_in_rest' => true], $declaration)],
                'provenance',
            ),
            'A repeater with no sub-fields would publish a schema that nulls its own stored rows.',
        );
        $this->assertSame([], $this->metaCalls);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function noSubFieldsProvider(): array
    {
        return [
            'sub_fields absent'      => [[]],
            'sub_fields empty array' => [['sub_fields' => []]],
            'sub_fields not a list'  => [['sub_fields' => 'nope']],
        ];
    }

    /**
     * THE COMPOSITE TREE HAS A FLOOR: one object level, and no way to declare a
     * second (Cluster C re-review).
     *
     * Three cases stood here, all three about depth TWO — a nested repeater
     * publishing closed at both levels, an undeclared grandchild taking the
     * top-level field down, an empty inner repeater doing the same. Every one
     * of them declared a repeater inside a repeater, which the vocabulary now
     * refuses at register() (spec FR-4 read against FR-2: `repeater` is
     * `cell = false`, and a `cell = false` type inside `sub_fields` throws).
     * They were pinning the behaviour of a declaration no site can make, and
     * their promises hold unchanged one level up: the all-or-nothing rule
     * (testARepeaterWithAnyUndeclaredSubFieldIsNeverPublished), the closed
     * object (testAFullyDeclaredRepeaterPublishesEverySubFieldInAClosedObject)
     * and the empty vocabulary (testARepeaterWithNoDeclaredSubFieldsIsNeverPublished).
     * The refusal itself is pinned in DataReadsTheVocabularyTest, on both
     * callers.
     *
     * What is left to say is the FLOOR, and it is worth saying: a published
     * repeater is `array → object → registry leaves`, and no property inside it
     * is another composite. That is what makes the closed-schema rule decidable
     * at all — WordPress validates the stored row against this schema, and a
     * tree that could nest without limit could nest past the point where a
     * partial declaration is visible.
     */
    public function testAPublishedRepeaterIsExactlyOneObjectDeepWithRegistryLeaves(): void
    {
        $schema = $this->publishedRepeaterSchema([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'lot'  => ['type' => 'int', 'show_in_rest' => true],
        ]);

        $this->assertIsArray($schema);
        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        $this->assertFalse($schema['items']['additionalProperties'], 'The one object level is closed.');

        foreach ($schema['items']['properties'] as $name => $leaf) {
            $this->assertNotSame(
                'array',
                $leaf['type'] ?? null,
                "Property '{$name}' publishes another composite. A repeater cell is a LEAF: the "
                    . 'vocabulary has no declaration that puts a second object level under this one.',
            );
            $this->assertArrayNotHasKey('properties', $leaf, "Property '{$name}' must be a registry leaf.");
        }

        $this->assertSame(
            [NTDST_FieldTypes::get('text')->schema, NTDST_FieldTypes::get('int')->schema],
            array_values($schema['items']['properties']),
            'And each leaf is the registry\'s shape for its declared type.',
        );
    }

    // -- SC-5: what the Data layer is allowed to be asked ---------------------

    /**
     * A DENY-LIST, not an inventory: the names the surface assertion below
     * subtracts so it can say something about the four that are left. Nothing
     * here is asserted PRESENT — specs/core-trim deletes five of these methods
     * on purpose, and a list that pinned them would fail that spec for doing
     * what it says. What must not grow is the REMAINDER.
     */
    private const CHAIN_AND_CRUD = [
        '__construct',
        'all',
        'attachTerms',
        'count',
        'create',
        'delete',
        'deleteMeta',
        'detachTerms',
        'find',
        'first',
        'get',
        'getMeta',
        'limit',
        'orWhere',
        'orderBy',
        'paginate',
        'scope',
        'syncTerms',
        'update',
        'updateMeta',
        'updateMetaBatch',
        'where',
        'whereDate',
        'whereGroup',
        'whereIn',
        'whereMissing',
        'whereNot',
        'whereNotIn',
        'whereTax',
        'withMeta',
        'withTerms',
    ];

    /**
     * SC-5. Besides the query chain and CRUD, the Data layer answers exactly
     * four questions — and every one of them REPORTS the field description or
     * hands it to WordPress. None of them shapes a response.
     *
     * A fifth public reader is the thing this invariant exists to stop: while a
     * second read of the declaration exists, a consumer can assemble its own
     * exposure beside the one convergence point, and agreeing with it today is
     * not the same as converging on it.
     */
    public function testTheDataModelsPublicSurfaceIsExactlyFourReaders(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NTDST_Data_Model::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $readers = array_values(array_diff($methods, self::CHAIN_AND_CRUD));
        sort($readers);

        $this->assertSame(
            ['getMetaPrefix', 'getSchema', 'registerRestMeta', 'restFields'],
            $readers,
            'Besides the chain and CRUD, NTDST_Data_Model answers exactly these four.',
        );
    }

    // ---------------------------------------------------------------- T03 --
    // registerRestMeta(): what reaches register_post_meta(), and what never does.
    //
    // WordPress puts a `meta` object in a post type's /wp/v2 response only when
    // BOTH halves hold: the type supports `custom-fields`, and the key was
    // registered with show_in_rest. Both halves are asserted here — the
    // per-field registration first, then the `supports` entry register() owes.
    //
    // A registration is a WRITE surface as much as a read one: a /wp/v2 meta
    // write is admitted by the auth_callback and cleaned by the sanitize_callback
    // of the registration itself. Those two callables are therefore asserted
    // BEHAVIOURALLY — called, and judged on what they return — never merely for
    // being callable.

    /** @var list<array<int, mixed>> register_post_meta() calls, in order. */
    private array $metaCalls = [];

    /** @var list<array<int, mixed>> register_post_type() calls, in order. */
    private array $postTypeCalls = [];

    /** @var list<array<int, mixed>> current_user_can() calls, in order. */
    private array $capChecks = [];

    /** Record what registration actually asked WordPress to do. */
    private function captureRegistrations(): void
    {
        $this->metaCalls = [];
        $this->postTypeCalls = [];

        Functions\when('register_post_meta')->alias(function (...$args) {
            $this->metaCalls[] = $args;

            return true;
        });

        Functions\when('register_post_type')->alias(function (...$args) {
            $this->postTypeCalls[] = $args;

            return new stdClass();
        });

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn();
    }

    /**
     * The brief's shape: three fields that opted in — one of them a repeater —
     * and two that did not, one by silence and one by refusal.
     *
     * @return array<string, mixed>
     */
    private function declaredAndSilentFields(): array
    {
        return [
            'venue'      => ['type' => 'text', 'show_in_rest' => true],
            'capacity'   => ['type' => 'int', 'show_in_rest' => true],
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year' => ['type' => 'text', 'show_in_rest' => true],
                    'lot'  => ['type' => 'int', 'show_in_rest' => true],
                ],
            ],
            'promo_budget' => ['type' => 'float'],                          // silent
            'reserve'      => ['type' => 'float', 'show_in_rest' => false], // refused
        ];
    }

    /** @return list<string> the meta keys registration asked for, in order. */
    private function metaKeys(): array
    {
        return array_map(
            static fn(array $args): string => (string) ($args[1] ?? ''),
            $this->metaCalls,
        );
    }

    /**
     * The one call that registered $metaKey — a second call for the same key is
     * as wrong as none.
     *
     * @return array<int, mixed>
     */
    private function callFor(string $metaKey): array
    {
        $matches = array_values(array_filter(
            $this->metaCalls,
            static fn(array $args): bool => ($args[1] ?? null) === $metaKey,
        ));

        $this->assertCount(1, $matches, "Expected exactly one register_post_meta() call for '{$metaKey}'.");

        return $matches[0];
    }

    /**
     * The registration array, with the two callables masked AFTER checking that
     * each is one — so the rest of the payload can be compared whole.
     *
     * @param  array<int, mixed> $call
     * @return array<string, mixed>
     */
    private function payloadOf(array $call): array
    {
        $payload = $call[2] ?? null;
        $this->assertIsArray($payload, 'register_post_meta() must receive an args array.');

        foreach (['sanitize_callback', 'auth_callback'] as $key) {
            $this->assertArrayHasKey($key, $payload, "A registration without a {$key} is not a boundary.");
            $this->assertIsCallable($payload[$key]);
            $payload[$key] = '<callable>';
        }

        ksort($payload);

        return $payload;
    }

    /**
     * Exactly the five keys the contract names — no more. Key ORDER is not part
     * of the contract, so both sides are ksort()ed.
     *
     * @param  mixed $showInRest
     * @return array<string, mixed>
     */
    private function expectedPayload(string $type, $showInRest): array
    {
        $expected = [
            'type'             => $type,
            'single'           => true,
            'sanitize_callback' => '<callable>',
            'auth_callback'    => '<callable>',
            'show_in_rest'     => $showInRest,
        ];

        ksort($expected);

        return $expected;
    }

    /** The model's own sanitizer for a field — what the registration must carry. */
    private function modelSanitizer(NTDST_Data_Model $model, string $field): callable
    {
        $property = new ReflectionProperty(NTDST_Data_Model::class, 'sanitizers');
        $property->setAccessible(true);

        $sanitizers = $property->getValue($model);
        $this->assertArrayHasKey($field, $sanitizers);

        return $sanitizers[$field];
    }

    /** Register a model through the Manager, the way a module declares one. */
    private function registerModel(array $fields, array $extra = []): void
    {
        (new NTDST_Data_Manager())->register('probe_cpt', array_merge([
            'label'        => 'Probe',
            'fields'       => $fields,
            'meta_prefix'  => '_probe_',
            'auto_metabox' => false,
        ], $extra));
    }

    /** @return list<string> `supports` as register_post_type() actually got it. */
    private function supportsReceived(): array
    {
        $this->assertCount(1, $this->postTypeCalls, 'Expected exactly one register_post_type() call.');

        $args = $this->postTypeCalls[0][1] ?? null;
        $this->assertIsArray($args);
        $this->assertArrayHasKey('supports', $args);
        $this->assertIsArray($args['supports']);

        return array_values($args['supports']);
    }

    // -- Denial: what registration must refuse to publish --------------------

    /** Opt-in, kept at the registration boundary: silence and refusal register nothing. */
    public function testAFieldThatStayedSilentIsNeverRegistered(): void
    {
        $this->captureRegistrations();

        $this->model($this->declaredAndSilentFields())->registerRestMeta('probe_cpt');

        $this->assertCount(3, $this->metaCalls, 'Only the declared fields may be registered.');
        $this->assertNotContains('_probe_promo_budget', $this->metaKeys());
        $this->assertNotContains('_probe_reserve', $this->metaKeys());

        $keys = $this->metaKeys();
        sort($keys);
        $this->assertSame(['_probe_capacity', '_probe_provenance', '_probe_venue'], $keys);
    }

    /**
     * Strict `=== true` survives the trip to WordPress. A `'yes'` typo must
     * leave the field unregistered, not publish it as a writable meta key.
     *
     * @dataProvider truthyNearMissProvider
     */
    public function testATruthyNearMissIsNeverRegistered(mixed $declaration): void
    {
        $this->captureRegistrations();

        $this->model(['cost' => ['type' => 'float', 'show_in_rest' => $declaration]])
            ->registerRestMeta('probe_cpt');

        $this->assertSame([], $this->metaCalls);
    }

    /** A model with nothing declared registers nothing. */
    public function testAModelWithNothingDeclaredRegistersNothing(): void
    {
        $this->captureRegistrations();

        $this->model([])->registerRestMeta('probe_cpt');

        $this->assertSame([], $this->metaCalls);
    }

    /**
     * The gate answers about the user WORDPRESS named, never about whoever
     * happens to be current.
     *
     * WordPress mounts the callback as
     * add_filter("auth_post_meta_{$key}_for_{$subtype}", $cb, 10, 6) and calls
     * it as ($allowed, $meta_key, $object_id, $user_id, $cap, $caps) —
     * map_meta_cap() supplies $user_id, and that user is not necessarily the
     * current one (a capability may be mapped for another user entirely).
     * current_user_can() therefore judges the wrong subject; the fourth
     * argument is the subject. user_can($userId, 'edit_post', $postId) is the
     * question, and current_user_can() must not be asked at all.
     *
     * @param bool $sentinel what WordPress answers about that user
     */
    private function stubUserCan(bool $sentinel): void
    {
        $this->capChecks = [];

        Functions\when('user_can')->alias(function ($user, $cap, ...$rest) use ($sentinel) {
            $this->capChecks[] = [$user, $cap, $rest[0] ?? null];

            return $sentinel;
        });

        Functions\when('current_user_can')->alias(function (...$args) {
            $this->fail(
                'The gate asked about the CURRENT user. The write is judged for the user id in '
                . 'the callback\'s fourth argument. current_user_can(' . json_encode($args) . ')',
            );
        });
    }

    /**
     * The write side, refused. The incoming $allowed is TRUE in one of the two
     * calls, so a callback that passes its first argument through would grant
     * the write. The capability answer decides, nothing else.
     */
    public function testTheAuthCallbackRefusesWhenTheNamedUserCannotEditThePost(): void
    {
        $this->captureRegistrations();
        $this->stubUserCan(false);

        $this->model($this->declaredAndSilentFields())->registerRestMeta('probe_cpt');

        $auth = $this->callFor('_probe_venue')[2]['auth_callback'];

        $this->assertSame(false, $auth(true, 'k', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame(false, $auth(false, 'k', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame(
            [[7, 'edit_post', 42], [7, 'edit_post', 42]],
            $this->capChecks,
            "The check is user_can(\$userId, 'edit_post', \$postId) — the user WordPress named.",
        );
    }

    /** And granted on the same authority — against an incoming $allowed of false. */
    public function testTheAuthCallbackAllowsWhenTheNamedUserCanEditThePost(): void
    {
        $this->captureRegistrations();
        $this->stubUserCan(true);

        $this->model($this->declaredAndSilentFields())->registerRestMeta('probe_cpt');

        $auth = $this->callFor('_probe_provenance')[2]['auth_callback'];

        $this->assertSame(true, $auth(false, 'k', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame(true, $auth(true, 'k', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame([[7, 'edit_post', 42], [7, 'edit_post', 42]], $this->capChecks);
    }

    // -- What each declared field registers as -------------------------------

    /**
     * A scalar publishes as `show_in_rest => true`; WordPress infers the rest
     * from `type`. The payload is compared WHOLE: exactly these five keys.
     */
    public function testAScalarFieldRegistersUnderShowInRestTrue(): void
    {
        $this->captureRegistrations();

        $model = $this->model($this->declaredAndSilentFields());
        $model->registerRestMeta('probe_cpt');

        $venue = $this->callFor('_probe_venue');
        $this->assertSame('probe_cpt', $venue[0]);
        $this->assertSame('_probe_venue', $venue[1]);
        $this->assertSame(NTDST_FieldTypes::get('text')->schema['type'], $venue[2]['type']);
        $this->assertSame($this->expectedPayload('string', true), $this->payloadOf($venue));

        $capacity = $this->callFor('_probe_capacity');
        $this->assertSame('probe_cpt', $capacity[0]);
        $this->assertSame(NTDST_FieldTypes::get('int')->schema['type'], $capacity[2]['type']);
        $this->assertSame($this->expectedPayload('integer', true), $this->payloadOf($capacity));
    }

    /**
     * An array value has no shape WordPress can guess, so the whole schema
     * travels — and it is the CLOSED object the structural rule built out of
     * the registry's leaf shapes, which is what keeps an undeclared sub-field
     * out of the response.
     */
    public function testARepeaterRegistersItsWholeSchemaUnderShowInRest(): void
    {
        $this->captureRegistrations();

        $model = $this->model($this->declaredAndSilentFields());
        $model->registerRestMeta('probe_cpt');

        $call = $this->callFor('_probe_provenance');
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'year' => NTDST_FieldTypes::get('text')->schema,
                    'lot'  => NTDST_FieldTypes::get('int')->schema,
                ],
                'additionalProperties' => false,
            ],
        ];

        $this->assertSame($this->expectedPayload('array', ['schema' => $schema]), $this->payloadOf($call));
        $this->assertSame(['year', 'lot'], array_keys($call[2]['show_in_rest']['schema']['items']['properties']));
        $this->assertFalse($call[2]['show_in_rest']['schema']['items']['additionalProperties']);
    }

    /**
     * The object type has no schema to register, so it registers nothing. A
     * `json` blob would otherwise have gone out whole — the daan field that
     * returned `{"k":"v","n":1}` to an anonymous caller, every key of it, none
     * of them ever named.
     */
    public function testADeclaredJsonFieldIsNeverRegistered(): void
    {
        $this->captureRegistrations();

        $this->namedModel('json_only', ['payload' => ['type' => 'json', 'show_in_rest' => true]])
            ->registerRestMeta('probe_cpt');

        $this->assertSame([], $this->metaCalls, 'A blob has no publishable shape.');
        $this->assertStringNotContainsString('payload', (string) json_encode($this->metaCalls));
    }

    // -- Declared but unpublishable: refused, and refused out loud ------------
    //
    // A module that wrote `show_in_rest => true` and gets nothing has no way to
    // find out unless registration says so. The log recorder lives in
    // tests/bootstrap.php: $GLOBALS['_ntdst_test_log'] entries are
    // [channel, level, message]. setUp() empties it for every case.
    //
    // The warning is guarded once per MODEL per process, so each case below
    // registers under its own model name — a shared name would let the first
    // case to run swallow the warning the next one is asserting.

    /** @return list<string> messages ntdst_log() recorded at $level, in order. */
    private function logMessages(string $level): array
    {
        return array_values(array_map(
            static fn(array $entry): string => (string) ($entry[2] ?? ''),
            array_filter(
                $GLOBALS['_ntdst_test_log'] ?? [],
                static fn(array $entry): bool => ($entry[1] ?? '') === $level,
            ),
        ));
    }

    /**
     * A model under its own name, so a once-per-model guard cannot leak between
     * cases.
     *
     * @param array<string, mixed> $fields
     */
    private function namedModel(string $name, array $fields): NTDST_Data_Model
    {
        return new NTDST_Data_Model($name, $fields, '_probe_');
    }

    /** json: refused, and the module is told which field and why. */
    public function testADeclaredJsonFieldWarnsNamingTheFieldAndTheType(): void
    {
        $this->captureRegistrations();

        $this->namedModel('blob_loud', ['payload' => ['type' => 'json', 'show_in_rest' => true]])
            ->registerRestMeta('probe_cpt');

        $warnings = $this->logMessages('warning');

        $this->assertCount(1, $warnings, 'A dropped REST declaration warns exactly once.');
        $this->assertStringContainsString('payload', $warnings[0], 'The warning must name the field.');
        $this->assertStringContainsString('json', $warnings[0], 'The warning must say why it was dropped.');
        $this->assertSame([], $this->logMessages('error'), 'This is a declaration to fix, not a failure.');
    }

    /**
     * A partial repeater: the same refusal, and it must not take the model's
     * other declarations with it.
     */
    public function testAPartialRepeaterIsRefusedLoudlyWhileTheOtherFieldsStillRegister(): void
    {
        $this->captureRegistrations();

        $this->namedModel('partial_loud', [
            'venue'      => ['type' => 'text', 'show_in_rest' => true],
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year'       => ['type' => 'text', 'show_in_rest' => true],
                    'sale_price' => ['type' => 'float'],
                ],
            ],
        ])->registerRestMeta('probe_cpt');

        $this->assertSame(['_probe_venue'], $this->metaKeys(), 'The repeater registers nothing; the scalar still does.');

        $warnings = $this->logMessages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('provenance', $warnings[0], 'The warning must name the field.');
        $this->assertStringContainsString('sale_price', $warnings[0], 'The warning must name the sub-field that blocked it.');
    }

    /**
     * A repeater with no sub-fields: registered nowhere, and said out loud.
     *
     * Registering it would be worse than dropping it. WordPress would accept
     * the key, then measure every stored row against a closed object with no
     * properties — the rows read back null and the next write wipes them. So
     * the registration boundary refuses it exactly like the partial one, the
     * scalar beside it still registers, and the module is told which field it
     * lost and that the reason is the missing sub_fields.
     */
    public function testARepeaterWithNoSubFieldsIsRefusedLoudlyWhileTheScalarStillRegisters(): void
    {
        $this->captureRegistrations();

        $this->namedModel('no_subfields_loud', [
            'venue'      => ['type' => 'text', 'show_in_rest' => true],
            'provenance' => ['type' => 'repeater', 'show_in_rest' => true, 'sub_fields' => []],
        ])->registerRestMeta('probe_cpt');

        $this->assertCount(1, $this->metaCalls, 'Exactly one field is publishable here.');
        $this->assertSame(['_probe_venue'], $this->metaKeys(), 'The empty repeater registers nothing; the scalar does.');
        $this->assertStringNotContainsString(
            'provenance',
            (string) json_encode($this->metaCalls),
            'The refused field must not reach WordPress under any key.',
        );

        $warnings = $this->logMessages('warning');
        $this->assertCount(1, $warnings, 'A dropped REST declaration warns exactly once.');
        $this->assertStringContainsString('provenance', $warnings[0], 'The warning must name the field.');
        $this->assertStringContainsString('sub_fields', $warnings[0], 'The warning must say why: no sub_fields.');
        $this->assertSame([], $this->logMessages('error'), 'This is a declaration to fix, not a failure.');
    }

    /**
     * Once per model per process. Registration runs on every `init`, and a
     * warning on every request is noise nobody reads.
     */
    public function testTheUnpublishableWarningIsEmittedOncePerModelPerProcess(): void
    {
        $this->captureRegistrations();

        $model = $this->namedModel('json_twice', ['payload' => ['type' => 'json', 'show_in_rest' => true]]);

        $model->registerRestMeta('probe_cpt');
        $model->registerRestMeta('probe_cpt');

        $this->assertCount(1, $this->logMessages('warning'), 'The second registration must add nothing.');
        $this->assertSame([], $this->metaCalls);
    }

    /**
     * The registered key is the STORED key. A model registering `venue` while
     * storing `_probe_venue` publishes a key that holds nothing and leaves the
     * real one unregistered.
     */
    public function testTheRegisteredKeyIsThePrefixedStorageKey(): void
    {
        $this->captureRegistrations();

        (new NTDST_Data_Model('probe', ['venue' => ['type' => 'text', 'show_in_rest' => true]], '_house_'))
            ->registerRestMeta('probe_cpt');

        $this->assertSame(['_house_venue'], $this->metaKeys());
    }

    /** No prefix declared, no prefix invented. */
    public function testAModelWithoutAMetaPrefixRegistersTheBareFieldName(): void
    {
        $this->captureRegistrations();

        (new NTDST_Data_Model('probe', ['venue' => ['type' => 'text', 'show_in_rest' => true]], ''))
            ->registerRestMeta('probe_cpt');

        $this->assertSame(['venue'], $this->metaKeys());
    }

    /**
     * A /wp/v2 meta write is cleaned by the registration's own sanitize_callback.
     * If that callback is not the model's sanitizer for the field, a REST write
     * stores something a create() write never could.
     */
    public function testTheSanitizeCallbackIsTheModelsOwnSanitizerForThatField(): void
    {
        $this->captureRegistrations();

        $model = $this->model($this->declaredAndSilentFields());
        $model->registerRestMeta('probe_cpt');

        $probes = [
            '_probe_venue'      => ['venue', '  Salle Wagram  ', 'text:Salle Wagram'],
            '_probe_capacity'   => ['capacity', '12abc', 12],
            '_probe_provenance' => [
                'provenance',
                [['year' => '  1998  ', 'lot' => '7x']],
                [['year' => 'text:1998', 'lot' => 7]],
            ],
        ];

        foreach ($probes as $metaKey => [$field, $dirty, $clean]) {
            $callback = $this->callFor($metaKey)[2]['sanitize_callback'];

            $this->assertSame($clean, $callback($dirty), "The registered sanitizer for '{$field}' does not clean like the declared type.");
            $this->assertSame(
                ($this->modelSanitizer($model, $field))($dirty),
                $callback($dirty),
                "The registered sanitizer for '{$field}' is not the model's own.",
            );
        }
    }

    // -- register(): the custom-fields half of the same promise ---------------

    /**
     * `custom-fields` is what makes WordPress expose `meta` at all. A post type
     * with nothing declared must not gain that surface — undeclared fields are
     * exactly the ones that must stay off /wp/v2.
     */
    public function testRegisterAddsNoCustomFieldsSupportWhenNothingIsDeclared(): void
    {
        $this->captureRegistrations();

        $this->registerModel([
            'promo_budget' => ['type' => 'float'],
            'reserve'      => ['type' => 'float', 'show_in_rest' => false],
        ]);

        $this->assertSame([], $this->metaCalls);
        $this->assertNotContains('custom-fields', $this->supportsReceived());
        $this->assertSame(['title', 'editor', 'thumbnail'], $this->supportsReceived());
    }

    /** Same, for a model that declares no fields at all. */
    public function testRegisterAddsNoCustomFieldsSupportWhenThereAreNoFieldsAtAll(): void
    {
        $this->captureRegistrations();

        $this->registerModel([]);

        $this->assertSame([], $this->metaCalls);
        $this->assertNotContains('custom-fields', $this->supportsReceived());
    }

    /** One declared field turns the surface on — once, and without losing the defaults. */
    public function testRegisterAddsCustomFieldsSupportExactlyOnceWhenAFieldIsDeclared(): void
    {
        $this->captureRegistrations();

        $this->registerModel($this->declaredAndSilentFields());

        $supports = $this->supportsReceived();

        $this->assertSame(1, count(array_keys($supports, 'custom-fields', true)), '`custom-fields` exactly once.');
        $this->assertContains('title', $supports);
        $this->assertContains('editor', $supports);
        $this->assertContains('thumbnail', $supports);
    }

    /**
     * `supports => false` is how a caller says "no title, no editor, no
     * thumbnail" — it was never a statement about meta. A field was NAMED as
     * public on the same registration, and WordPress cannot emit that field
     * without the `custom-fields` support. The declaration wins: supports
     * becomes exactly the one entry the declaration requires, and nothing else
     * comes back.
     */
    public function testRegisterAddsCustomFieldsSupportEvenWhenTheCallerPassedSupportsFalse(): void
    {
        $this->captureRegistrations();

        $this->registerModel(
            ['venue' => ['type' => 'text', 'show_in_rest' => true]],
            ['supports' => false],
        );

        $this->assertSame(['custom-fields'], $this->supportsReceived());
        $this->assertSame(['_probe_venue'], $this->metaKeys());
    }

    /**
     * `supports` given as a bare string is NOT something WordPress accepts.
     * register_post_type() documents the argument as `array|false`, and
     * WP_Post_Type::add_supports() foreaches the value — a string reaches that
     * loop and fatals. So core normalises it to `[$string]` because WordPress
     * would otherwise die, not because WordPress would have understood it.
     *
     * The caller still meant the entry, so normalising keeps it and adds the
     * one the declaration requires. Dropping it would silently remove the title
     * from the editor.
     */
    public function testRegisterKeepsASupportsStringAndAddsCustomFieldsBesideIt(): void
    {
        $this->captureRegistrations();

        $this->registerModel(
            ['venue' => ['type' => 'text', 'show_in_rest' => true]],
            ['supports' => 'title'],
        );

        $this->assertSame(['title', 'custom-fields'], $this->supportsReceived());
    }

    /**
     * A model declared WITHOUT a label registers no post type, and therefore no
     * meta — a write surface with no read surface is the wrong half to open.
     * That is correct, and it is also silent, which is the defect: the module
     * declared `show_in_rest => true` and got nothing, with no way to find out.
     * Refusing is fine; refusing quietly is not — the rule is that the layer
     * fails closed AND loudly.
     */
    public function testALabelLessModelWithDeclaredFieldsRefusesLoudly(): void
    {
        $this->captureRegistrations();
        $GLOBALS['_ntdst_test_log'] = [];

        (new NTDST_Data_Manager())->register('thing', [
            'fields'       => ['venue' => ['type' => 'text', 'show_in_rest' => true]],
            'meta_prefix'  => '_probe_',
            'auto_metabox' => false,
        ]);

        $this->assertSame([], $this->metaCalls, 'No post type, no meta registration.');
        $this->assertSame([], $this->postTypeCalls);

        $warnings = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'] ?? [],
            static fn(array $entry): bool => ($entry[1] ?? '') === 'warning',
        ));

        $this->assertCount(1, $warnings, 'A silently dropped REST declaration must warn exactly once.');
        $this->assertStringContainsString(
            'thing',
            (string) ($warnings[0][2] ?? ''),
            'The warning must name the model whose declared fields were dropped.',
        );
    }

    /**
     * The other silent half of the same promise: the post type itself is not in
     * REST. `custom-fields` and a registered key publish nothing at all if
     * WordPress never mounts a /wp/v2 route for the type, so a module that
     * declared fields on such a type is in exactly the same position as the
     * label-less one — refused, and entitled to know.
     *
     * The meta is still registered (harmless: WordPress emits nothing) and the
     * post type is still created. Only the silence is the defect.
     *
     * @dataProvider notInRestProvider
     * @param array<string, mixed> $extra
     */
    public function testAPostTypeThatIsNotInRestWarnsAboutItsDeclaredFields(string $postType, array $extra): void
    {
        $this->captureRegistrations();

        $manager = new NTDST_Data_Manager();
        $register = fn() => $manager->register($postType, array_merge([
            'label'        => 'Probe',
            'fields'       => ['venue' => ['type' => 'text', 'show_in_rest' => true]],
            'meta_prefix'  => '_probe_',
            'auto_metabox' => false,
        ], $extra));

        $register();

        $warnings = $this->logMessages('warning');
        $this->assertCount(1, $warnings, 'A declaration on a type with no REST route must warn.');
        $this->assertStringContainsString($postType, $warnings[0], 'The warning must name the model.');

        $this->assertSame(['_probe_venue'], $this->metaKeys(), 'The meta registration itself is harmless.');
        $this->assertCount(1, $this->postTypeCalls, 'The post type is still registered.');

        // Registration runs on every init; the warning is once per model.
        $GLOBALS['_ntdst_test_log'] = [];
        $register();

        $this->assertSame([], $this->logMessages('warning'), 'The second registration must add nothing.');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function notInRestProvider(): array
    {
        return [
            'show_in_rest absent' => ['quiet_absent_cpt', []],
            'show_in_rest false'  => ['quiet_false_cpt', ['show_in_rest' => false]],
        ];
    }

    // -- The write gate, on arguments WordPress can actually hand it ----------

    /**
     * map_meta_cap() supplies the subject, and a filter or a broken caller can
     * hand the callback something that is not an id at all. Casting an object
     * to int is not a decision — it is a coin flip that can land on a real user
     * id. A non-scalar subject or object is refused, and the capability is never
     * asked about it.
     *
     * @dataProvider nonScalarSubjectProvider
     */
    public function testTheAuthCallbackRefusesANonScalarUserOrPostWithoutAskingWordPress(
        mixed $userId,
        mixed $postId,
    ): void {
        $this->captureRegistrations();

        Functions\when('user_can')->alias(function (...$args) {
            $this->fail('The gate asked WordPress about a non-scalar id: user_can(' . json_encode($args) . ')');
        });

        Functions\when('current_user_can')->alias(function (...$args) {
            $this->fail('The gate asked about the current user: current_user_can(' . json_encode($args) . ')');
        });

        $this->namedModel('nonscalar_model', ['venue' => ['type' => 'text', 'show_in_rest' => true]])
            ->registerRestMeta('probe_cpt');

        $auth = $this->callFor('_probe_venue')[2]['auth_callback'];

        $this->assertSame(false, $auth(true, '_probe_venue', $postId, $userId, 'edit_post', ['edit_posts']));
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function nonScalarSubjectProvider(): array
    {
        return [
            'object user'      => [new stdClass(), 42],
            'object post'      => [7, new stdClass()],
            'objects for both' => [new stdClass(), new stdClass()],
            'array user'       => [['id' => 7], 42],
            'array post'       => [7, [42]],
            'null user'        => [null, 42],
            'null post'        => [7, null],
        ];
    }

    /** A caller that already listed it gets no second copy, and keeps its own list. */
    public function testRegisterDoesNotDuplicateCustomFieldsSupportTheCallerAlreadyListed(): void
    {
        $this->captureRegistrations();

        $this->registerModel($this->declaredAndSilentFields(), ['supports' => ['title', 'custom-fields']]);

        $supports = $this->supportsReceived();

        $this->assertSame(1, count(array_keys($supports, 'custom-fields', true)));
        $this->assertContains('title', $supports);
    }

    /**
     * The wiring itself: declaring fields on a model is the whole act. Nobody
     * calls register_post_meta() by hand, so register() must.
     */
    public function testRegisterSendsEveryDeclaredFieldToRegisterPostMeta(): void
    {
        $this->captureRegistrations();

        $this->registerModel($this->declaredAndSilentFields());

        $keys = $this->metaKeys();
        sort($keys);

        $this->assertCount(3, $this->metaCalls);
        $this->assertSame(['_probe_capacity', '_probe_provenance', '_probe_venue'], $keys);
        $this->assertSame(
            ['probe_cpt', 'probe_cpt', 'probe_cpt'],
            array_map(static fn(array $call) => $call[0], $this->metaCalls),
            'Meta is registered against the post type that was just registered.',
        );
    }
}
