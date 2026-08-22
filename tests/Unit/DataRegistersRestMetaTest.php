<?php // tests/Unit/DataRegistersRestMetaTest.php
// What a declared field looks like once it leaves — and what never leaves at all.
//
// restSchemaFor() is the only place the Data layer turns a field TYPE into a
// REST schema. Two rules are load-bearing, and both are denial rules:
//
//   1. STRICT OPT-IN. A field that did not say `show_in_rest => true` — exactly
//      true, not 'yes', not 1 — has no schema. null, every time.
//   2. A repeater publishes ONLY the sub-fields that opted in, and closes the
//      object behind them (`additionalProperties => false`), so an undeclared
//      sub-field (the rossi `sale_price` shape) cannot ride along inside a
//      parent that WAS declared.
//
// T02 cases are grouped first; T03 (registerRestMeta) appends below them.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Data.php';

final class DataRegistersRestMetaTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        foreach (['sanitize_text_field', 'sanitize_textarea_field', 'esc_url_raw', 'sanitize_email', 'wp_kses_post'] as $fn) {
            Functions\when($fn)->returnArg(1);
        }
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

    /** A single declared field of the given type, ready to ask about. */
    private function declared(string $type): NTDST_Data_Model
    {
        return $this->model(['probe_field' => ['type' => $type, 'show_in_rest' => true]]);
    }

    // ---------------------------------------------------------------- T02 --
    // restSchemaFor(): the denial rules first, then the type table.

    /** A field the model never heard of has no schema. */
    public function testAnUnknownFieldNameHasNoSchema(): void
    {
        $model = $this->model(['venue' => ['type' => 'text', 'show_in_rest' => true]]);

        $this->assertNull($model->restSchemaFor('nonexistent'));
    }

    /** WordPress's default, kept: a field nobody named does not leave. */
    public function testAFieldThatNamedNothingHasNoSchema(): void
    {
        $model = $this->model(['promo_budget' => ['type' => 'float']]);

        $this->assertNull($model->restSchemaFor('promo_budget'));
    }

    public function testShowInRestFalseHasNoSchema(): void
    {
        $model = $this->model(['cost' => ['type' => 'float', 'show_in_rest' => false]]);

        $this->assertNull($model->restSchemaFor('cost'));
    }

    /**
     * Strict `=== true`. A truthy near-miss is a typo, and a typo must not be
     * the thing that publishes a private field.
     *
     * @dataProvider truthyNearMissProvider
     */
    public function testATruthyNearMissDoesNotPublishTheField(mixed $declaration): void
    {
        $model = $this->model(['cost' => ['type' => 'float', 'show_in_rest' => $declaration]]);

        $this->assertNull($model->restSchemaFor('cost'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function truthyNearMissProvider(): array
    {
        return [
            'string yes'   => ['yes'],
            'integer one'  => [1],
            'string one'   => ['1'],
            'string true'  => ['true'],
        ];
    }

    /**
     * The type table, exactly as the spec writes it.
     *
     * @dataProvider scalarTypeProvider
     * @param array<string, mixed> $expected
     */
    public function testATypeMapsToItsSchema(string $type, array $expected): void
    {
        $this->assertSame($expected, $this->declared($type)->restSchemaFor('probe_field'));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function scalarTypeProvider(): array
    {
        $integer = ['type' => 'integer'];
        $string  = ['type' => 'string'];
        $idList  = ['type' => 'array', 'items' => ['type' => 'integer']];

        return [
            'int'           => ['int', $integer],
            'integer'       => ['integer', $integer],
            'signed_int'    => ['signed_int', $integer],
            'image'         => ['image', $integer],
            'file'          => ['file', $integer],

            'float'         => ['float', ['type' => 'number']],
            'double'        => ['double', ['type' => 'number']],

            'bool'          => ['bool', ['type' => 'boolean']],
            'boolean'       => ['boolean', ['type' => 'boolean']],

            'text'          => ['text', $string],
            'textarea'      => ['textarea', $string],
            'html'          => ['html', $string],
            'content'       => ['content', $string],
            'wysiwyg'       => ['wysiwyg', $string],
            'select'        => ['select', $string],
            'date'          => ['date', $string],

            'email'         => ['email', ['type' => 'string', 'format' => 'email']],
            'url'           => ['url', ['type' => 'string', 'format' => 'uri']],

            'array'         => ['array', ['type' => 'array', 'items' => ['type' => 'string']]],

            'gallery'       => ['gallery', $idList],
            'relation'      => ['relation', $idList],
            'post_relation' => ['post_relation', $idList],
            'person'        => ['person', $idList],

            'json'          => ['json', ['type' => 'object', 'additionalProperties' => true]],
        ];
    }

    /**
     * The nesting rule, on the shape that made it necessary: a provenance
     * repeater may leave, the sale price inside it may not.
     */
    public function testARepeaterPublishesOnlyTheSubFieldsThatOptedIn(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year'       => ['type' => 'text', 'show_in_rest' => true],
                    'lot'        => ['type' => 'int', 'show_in_rest' => true],
                    'sale_price' => ['type' => 'float'],
                ],
            ],
        ]);

        $schema = $model->restSchemaFor('provenance');

        $this->assertIsArray($schema);
        $this->assertSame([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'year' => ['type' => 'string'],
                    'lot'  => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ], $schema);

        $this->assertCount(2, $schema['items']['properties']);
        $this->assertFalse($schema['items']['additionalProperties']);
    }

    /** The strict rule applies one level down too. */
    public function testASubFieldTruthyNearMissDoesNotPublishTheSubField(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year'       => ['type' => 'text', 'show_in_rest' => true],
                    'sale_price' => ['type' => 'float', 'show_in_rest' => 1],
                    'buyer'      => ['type' => 'text', 'show_in_rest' => 'yes'],
                ],
            ],
        ]);

        $schema = $model->restSchemaFor('provenance');

        $this->assertIsArray($schema);
        $this->assertSame(['year'], array_keys($schema['items']['properties']));
        $this->assertFalse($schema['items']['additionalProperties']);
    }

    /**
     * The invariant behind both cases, stated so no encoding of the schema can
     * carry an undeclared sub-field NAME out of the model.
     */
    public function testNoUndeclaredSubFieldNameAppearsAnywhereInTheSchema(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year'       => ['type' => 'text', 'show_in_rest' => true],
                    'sale_price' => ['type' => 'float'],
                    'reserve'    => ['type' => 'float', 'show_in_rest' => false],
                ],
            ],
        ]);

        $encoded = json_encode($model->restSchemaFor('provenance'));

        $this->assertStringNotContainsString('sale_price', (string) $encoded);
        $this->assertStringNotContainsString('reserve', (string) $encoded);
        $this->assertStringContainsString('year', (string) $encoded);
    }

    /** A declared repeater with nothing declared inside publishes nothing inside. */
    public function testARepeaterWithNoDeclaredSubFieldsPublishesNoProperties(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'sale_price' => ['type' => 'float'],
                ],
            ],
        ]);

        $schema = $model->restSchemaFor('provenance');

        // Either shape is closed; neither may name the sub-field.
        if ($schema !== null) {
            $this->assertSame([], $schema['items']['properties']);
            $this->assertFalse($schema['items']['additionalProperties']);
        }

        $this->assertStringNotContainsString('sale_price', (string) json_encode($schema));
    }

    /**
     * Same rule as getDefaultSanitizer(): an unknown type is a typo, and a typo
     * must fail loudly rather than default to a permissive schema.
     *
     * The field carries an explicit sanitizer, which is the one path where an
     * unknown type survives construction — so this asks restSchemaFor() itself.
     */
    public function testAnUnknownTypeThrows(): void
    {
        $model = $this->model([
            'blurb' => ['type' => 'wysiwig', 'show_in_rest' => true, 'sanitizer' => 'strval'],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $model->restSchemaFor('blurb');
    }

    /**
     * The layer gains restSchemaFor() and registerRestMeta() — nothing that
     * models a project, a shape, or a public view. Those live elsewhere.
     */
    public function testDataModelGrowsNoProjectShapeOrPublicVocabulary(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NTDST_Data_Model::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach ($methods as $name) {
            foreach (['project', 'shape', 'public'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $name,
                    "NTDST_Data_Model::{$name}() smuggles '{$forbidden}' vocabulary into the Data layer.",
                );
            }
        }
    }
}
