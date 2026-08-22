<?php // tests/Unit/DataRegistersRestMetaTest.php
// What a declared field looks like once it leaves — and what never leaves at all.
//
// restSchemaFor() is the only place the Data layer turns a field TYPE into a
// REST schema. Two rules are load-bearing, and both are denial rules:
//
//   1. STRICT OPT-IN. A field that did not say `show_in_rest => true` — exactly
//      true, not 'yes', not 1 — has no schema. null, every time.
//   2. A repeater publishes ALL OR NOTHING. WordPress validates a stored row
//      against the closed schema it was given, so a row carrying a key the
//      schema does not name reads back as null and a write that carries it is
//      refused — while a write WITHOUT it silently replaces the row and drops
//      the undeclared sub-field from storage. A repeater therefore publishes
//      only when every sub-field opted in (closed object, every name present);
//      one undeclared sub-field at any depth makes the whole field unpublishable.
//      A `json` blob has no sub-field vocabulary at all, so it never publishes.
//
// Both refusals are LOUD: a field that said `show_in_rest => true` and cannot be
// published is a declaration the module will never see honoured, so registration
// warns once per model, naming the field.
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
     * @param array<string, mixed>|null $expected
     */
    public function testATypeMapsToItsSchema(string $type, ?array $expected): void
    {
        $this->assertSame($expected, $this->declared($type)->restSchemaFor('probe_field'));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>|null}> */
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

            // A blob has no sub-field vocabulary, so nothing inside it was ever
            // named. Publishing it would hand every key of every stored row to
            // an anonymous caller — the daan `{"k":"v","n":1}` read. Unpublishable.
            'json'          => ['json', null],
        ];
    }

    /**
     * Stated on its own, because it is a denial and not a table row: a declared
     * `json` field has NO schema. `['type'=>'object','additionalProperties'=>true]`
     * is the opposite of the rule the layer exists for — it names nothing and
     * admits everything.
     */
    public function testADeclaredJsonFieldHasNoSchema(): void
    {
        $model = $this->model([
            'payload' => ['type' => 'json', 'show_in_rest' => true],
        ]);

        $this->assertNull($model->restSchemaFor('payload'));
    }

    /**
     * A repeater with a shape helper, so each case below reads as its own rule.
     *
     * @param array<string, mixed> $subFields
     */
    private function repeater(array $subFields): NTDST_Data_Model
    {
        return $this->model([
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => $subFields,
            ],
        ]);
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
     * A partially declared repeater is therefore not publishable at all.
     */
    public function testARepeaterWithAnyUndeclaredSubFieldHasNoSchema(): void
    {
        $model = $this->repeater([
            'year'       => ['type' => 'text', 'show_in_rest' => true],
            'lot'        => ['type' => 'int', 'show_in_rest' => true],
            'sale_price' => ['type' => 'float'], // one silent sub-field is enough
        ]);

        $this->assertNull(
            $model->restSchemaFor('provenance'),
            'Two declared sub-fields and one silent one is not a publishable repeater.',
        );
    }

    /** `show_in_rest => false` inside is the same refusal, said out loud. */
    public function testARepeaterWithARefusedSubFieldHasNoSchema(): void
    {
        $model = $this->repeater([
            'year'    => ['type' => 'text', 'show_in_rest' => true],
            'reserve' => ['type' => 'float', 'show_in_rest' => false],
        ]);

        $this->assertNull($model->restSchemaFor('provenance'));
    }

    /**
     * The only repeater that publishes: every sub-field opted in. The object is
     * closed behind exactly those names, so WordPress accepts the stored row and
     * nothing that was never named can ride along inside it.
     */
    public function testAFullyDeclaredRepeaterPublishesEverySubFieldInAClosedObject(): void
    {
        $model = $this->repeater([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'lot'  => ['type' => 'int', 'show_in_rest' => true],
        ]);

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
        ], $model->restSchemaFor('provenance'));
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
        $model = $this->repeater([
            'year'       => ['type' => 'text', 'show_in_rest' => true],
            'sale_price' => ['type' => 'float', 'show_in_rest' => $declaration],
        ]);

        $this->assertNull($model->restSchemaFor('provenance'));
    }

    /**
     * A sub-field written as a bare type string (`'qty' => 'int'`) says nothing
     * about REST, so it never opted in — and a config shape that cannot carry a
     * declaration must not be read as one.
     */
    public function testABareStringSubFieldConfigMakesTheRepeaterUnpublishable(): void
    {
        $model = $this->repeater([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'qty'  => 'int',
        ]);

        $this->assertNull($model->restSchemaFor('provenance'));
    }

    /**
     * The invariant behind every case above, stated so no encoding of the schema
     * can carry an undeclared sub-field NAME out of the model — and so the
     * declared shape is proved to carry its own.
     */
    public function testNoUndeclaredSubFieldNameAppearsAnywhereInTheSchema(): void
    {
        $partial = $this->repeater([
            'year'       => ['type' => 'text', 'show_in_rest' => true],
            'sale_price' => ['type' => 'float'],
            'reserve'    => ['type' => 'float', 'show_in_rest' => false],
        ]);

        $encoded = (string) json_encode($partial->restSchemaFor('provenance'));

        $this->assertStringNotContainsString('sale_price', $encoded);
        $this->assertStringNotContainsString('reserve', $encoded);
        $this->assertStringNotContainsString('year', $encoded, 'A partial repeater publishes nothing at all.');

        $whole = (string) json_encode($this->repeater([
            'year' => ['type' => 'text', 'show_in_rest' => true],
        ])->restSchemaFor('provenance'));

        $this->assertStringContainsString('year', $whole);
    }

    /**
     * A repeater with no sub-fields at all has nothing undeclared in it, so it
     * publishes — as a closed object with no properties. Asserted
     * unconditionally: "whatever it returns is fine" is not a contract.
     */
    public function testARepeaterWithZeroSubFieldsPublishesAClosedEmptyObject(): void
    {
        $schema = $this->repeater([])->restSchemaFor('provenance');

        $this->assertIsArray($schema);
        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        $this->assertSame([], $schema['items']['properties']);
        $this->assertFalse($schema['items']['additionalProperties']);
    }

    /**
     * Depth is not an exemption. A nested repeater follows the same rule, so a
     * grandchild-complete tree publishes closed at BOTH levels.
     */
    public function testANestedRepeaterPublishesClosedAtEveryLevelWhenEveryNameOptedIn(): void
    {
        $model = $this->repeater([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'lots' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'lot_number' => ['type' => 'int', 'show_in_rest' => true],
                ],
            ],
        ]);

        $schema = $model->restSchemaFor('provenance');

        $this->assertIsArray($schema);
        $this->assertSame(['year', 'lots'], array_keys($schema['items']['properties']));
        $this->assertFalse($schema['items']['additionalProperties']);

        $inner = $schema['items']['properties']['lots'];
        $this->assertSame('array', $inner['type']);
        $this->assertSame(['lot_number'], array_keys($inner['items']['properties']));
        $this->assertFalse($inner['items']['additionalProperties']);
    }

    /** And one undeclared grandchild takes the TOP-LEVEL field down with it. */
    public function testOneUndeclaredGrandchildMakesTheTopLevelFieldUnpublishable(): void
    {
        $model = $this->repeater([
            'year' => ['type' => 'text', 'show_in_rest' => true],
            'lots' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'lot_number'   => ['type' => 'int', 'show_in_rest' => true],
                    'hammer_price' => ['type' => 'float'], // silent, two levels down
                ],
            ],
        ]);

        $this->assertNull(
            $model->restSchemaFor('provenance'),
            'An undeclared grandchild breaks the rows of the field that contains it.',
        );
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
        $this->assertSame($model->restSchemaFor('venue')['type'], $venue[2]['type']);
        $this->assertSame($this->expectedPayload('string', true), $this->payloadOf($venue));

        $capacity = $this->callFor('_probe_capacity');
        $this->assertSame('probe_cpt', $capacity[0]);
        $this->assertSame($model->restSchemaFor('capacity')['type'], $capacity[2]['type']);
        $this->assertSame($this->expectedPayload('integer', true), $this->payloadOf($capacity));
    }

    /**
     * An array value has no shape WordPress can guess, so the whole schema
     * travels — and it is the CLOSED one restSchemaFor() built, which is what
     * keeps an undeclared sub-field out of the response.
     */
    public function testARepeaterRegistersItsWholeSchemaUnderShowInRest(): void
    {
        $this->captureRegistrations();

        $model = $this->model($this->declaredAndSilentFields());
        $model->registerRestMeta('probe_cpt');

        $call = $this->callFor('_probe_provenance');
        $schema = $model->restSchemaFor('provenance');

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

        $this->namedModel('json_loud', ['payload' => ['type' => 'json', 'show_in_rest' => true]])
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
     * A typo in a type is a mistake in ONE field. It must not throw out of
     * registration: `init` would abort and the whole post type would disappear
     * from the site — a far worse outcome than one unpublished field.
     *
     * The field carries its own `sanitizer`, which is the one path where an
     * unknown type survives construction.
     */
    public function testAnUnknownTypeUnpublishesOneFieldAndLeavesTheRestRegistered(): void
    {
        $this->captureRegistrations();

        $model = $this->namedModel('typo_model', [
            'venue'    => ['type' => 'text', 'show_in_rest' => true],
            'capacity' => ['type' => 'int', 'show_in_rest' => true],
            'blurb'    => ['type' => 'wysiwig', 'show_in_rest' => true, 'sanitizer' => 'strval'],
        ]);

        $model->registerRestMeta('probe_cpt'); // must not throw

        $keys = $this->metaKeys();
        sort($keys);

        $this->assertSame(['_probe_capacity', '_probe_venue'], $keys, 'One bad type unpublishes one field.');

        $errors = $this->logMessages('error');
        $this->assertCount(1, $errors, 'The typo is a defect, so it is logged as an error.');
        $this->assertStringContainsString('blurb', $errors[0], 'The error must name the field that failed.');
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
     * `supports` given as a string is what WordPress itself accepts, and the
     * caller meant it: keep the entry, add the one the declaration requires.
     * Dropping it would silently remove the title from the editor.
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
