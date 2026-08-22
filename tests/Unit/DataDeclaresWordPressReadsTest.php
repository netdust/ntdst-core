<?php // tests/Unit/DataDeclaresWordPressReadsTest.php
// Cluster 1, the FEATURE: "Data declares, WordPress reads".
//
// The promise the cluster made:
//
//   a field declared `show_in_rest => true` appears on WordPress's own
//   /wp/v2/<type> endpoint for anonymous callers, and a field not declared
//   never does — including a sub-field inside a declared repeater.
//
// The HTTP half of that promise (SC-1, the daan curl) belongs to the
// integration gate. This file drives the half that is observable here: what a
// module DECLARES, run end to end through NTDST_Data_Manager::register(), and
// what WordPress is consequently told. WordPress is the only thing stubbed —
// register_post_type() and register_post_meta() are captured, and everything
// between the declaration and those two calls is the real code.
//
// Written from the cluster behaviour and the spec's criteria, independent of
// the implementation. A case that fails here is a finding, not a bug in the
// test.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Data.php';

final class DataDeclaresWordPressReadsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<array<int, mixed>> register_post_meta() calls, in order. */
    private array $metaCalls = [];

    /** @var list<array<int, mixed>> register_post_type() calls, in order. */
    private array $postTypeCalls = [];

    /** @var list<array<int, mixed>> capability questions the write gate asked. */
    private array $capChecks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The sanitizers the declared types name by string. Tagged rather than
        // pass-through so a mis-wired sanitizer cannot pass silently.
        foreach ([
            'sanitize_text_field'     => 'text',
            'sanitize_textarea_field' => 'textarea',
            'esc_url_raw'             => 'url',
            'sanitize_email'          => 'email',
            'wp_kses_post'            => 'html',
        ] as $fn => $tag) {
            Functions\when($fn)->alias(static fn($v) => $tag . ':' . trim((string) $v));
        }

        Functions\when('absint')->alias(static fn($v) => abs((int) $v));

        $this->metaCalls = [];
        $this->postTypeCalls = [];
        $this->capChecks = [];

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

        $this->forgetRegisteredModels();
        $GLOBALS['_ntdst_test_log'] = [];
    }

    protected function tearDown(): void
    {
        $this->forgetRegisteredModels();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** The Manager keeps its models statically; no test may inherit another's. */
    private function forgetRegisteredModels(): void
    {
        $models = new ReflectionProperty(NTDST_Data_Manager::class, 'models');
        $models->setAccessible(true);
        $models->setValue(null, []);
    }

    /**
     * Declare a model the way a module does — through the Manager, not by
     * hand-calling register_post_meta().
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $extra
     */
    private function declareModel(string $postType, array $fields, array $extra = []): void
    {
        (new NTDST_Data_Manager())->register($postType, array_merge([
            'label'        => 'Gig',
            'fields'       => $fields,
            'meta_prefix'  => '_gig_',
            'auto_metabox' => false,
        ], $extra));
    }

    /** @return list<string> the meta keys WordPress was asked to register. */
    private function metaKeys(): array
    {
        $keys = array_map(static fn(array $args): string => (string) ($args[1] ?? ''), $this->metaCalls);
        sort($keys);

        return $keys;
    }

    /**
     * The single register_post_meta() call for $metaKey.
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

    /** @return list<string> `supports` as register_post_type() actually received it. */
    private function supportsReceived(): array
    {
        $this->assertCount(1, $this->postTypeCalls, 'Expected exactly one register_post_type() call.');

        $args = $this->postTypeCalls[0][1] ?? null;
        $this->assertIsArray($args);
        $this->assertArrayHasKey('supports', $args);
        $this->assertIsArray($args['supports'], '`supports` must reach WordPress as an array.');

        return array_values($args['supports']);
    }

    /**
     * Everything a registration hands WordPress, encoded — callables masked so
     * the encode itself cannot fail. This is the shape a name can hide in.
     *
     * @param array<int, mixed> $call
     */
    private function encodeRegistration(array $call): string
    {
        array_walk_recursive($call, static function (&$value): void {
            if ($value instanceof Closure || (is_string($value) && is_callable($value))) {
                $value = '<callable>';
            }
        });

        return (string) json_encode($call);
    }

    // -- 1. Nesting, two levels down -----------------------------------------

    /**
     * The behaviour says "including a sub-field inside a declared repeater",
     * and a repeater's sub-field may itself be a repeater. Depth is not a
     * special case: EVERY object the schema opens must be closed behind the
     * names that opted in, or an undeclared grandchild rides out inside a
     * declared parent — which is the disclosure the cluster exists to prevent.
     */
    public function testAnUndeclaredGrandchildNeverLeavesInsideADeclaredRepeater(): void
    {
        $this->declareModel('gig', [
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year' => ['type' => 'text', 'show_in_rest' => true],
                    'lots' => [
                        'type' => 'repeater',
                        'show_in_rest' => true,
                        'sub_fields' => [
                            'lot_number'    => ['type' => 'int', 'show_in_rest' => true],
                            'hammer_price'  => ['type' => 'float'], // silent — must never leave
                        ],
                    ],
                ],
            ],
        ]);

        $call = $this->callFor('_gig_provenance');
        $schema = $call[2]['show_in_rest']['schema'] ?? null;

        $this->assertIsArray($schema, 'A declared repeater must travel as a full schema.');

        // Level 1 — the declared repeater itself.
        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        $this->assertSame(['year', 'lots'], array_keys($schema['items']['properties']));
        $this->assertFalse(
            $schema['items']['additionalProperties'],
            'The outer object must be closed.',
        );

        // Level 2 — the declared repeater INSIDE it, closed on the same rule.
        $inner = $schema['items']['properties']['lots'];
        $this->assertSame('array', $inner['type']);
        $this->assertSame('object', $inner['items']['type']);
        $this->assertSame(['lot_number'], array_keys($inner['items']['properties']));
        $this->assertFalse(
            $inner['items']['additionalProperties'],
            'The nested object must be closed too — depth is not an exemption.',
        );

        // And the invariant behind both, stated so no encoding can carry the
        // undeclared name out of the model.
        $this->assertStringNotContainsString('hammer_price', $this->encodeRegistration($call));
    }

    // -- 2. A realistic model: the gig ---------------------------------------

    /**
     * The daan gig, roughly as it is declared in production: eight fields of
     * mixed types, two of which said `show_in_rest => true`. Everything else
     * stayed silent — including a repeater, because a repeater's declared
     * sub-fields must not publish a parent nobody declared.
     *
     * @return array<string, mixed>
     */
    private function gigFields(): array
    {
        return [
            'venue_city'    => ['type' => 'text', 'show_in_rest' => true],
            'venue_country' => ['type' => 'text', 'show_in_rest' => true],

            'door_time'     => ['type' => 'date'],
            'capacity'      => ['type' => 'int'],
            'contract_url'  => ['type' => 'url'],
            'promoter_mail' => ['type' => 'email'],
            'rider_notes'   => ['type' => 'textarea'],
            'settlement'    => [
                'type' => 'repeater', // silent parent, declared children
                'sub_fields' => [
                    'line_item' => ['type' => 'text', 'show_in_rest' => true],
                    'amount'    => ['type' => 'float', 'show_in_rest' => true],
                ],
            ],
        ];
    }

    /** Two fields opted in, so WordPress hears about two — and only two. */
    public function testOnlyTheTwoDeclaredFieldsOfARealModelReachWordPress(): void
    {
        $this->declareModel('gig', $this->gigFields());

        $this->assertCount(2, $this->metaCalls, 'Exactly the declared fields are registered.');
        $this->assertSame(['_gig_venue_city', '_gig_venue_country'], $this->metaKeys());

        $this->assertSame(
            ['gig', 'gig'],
            array_map(static fn(array $call) => $call[0], $this->metaCalls),
            'Meta is registered against the post type that was just registered.',
        );
    }

    /** Silence covers a repeater's children too: no parent, no publication. */
    public function testASilentRepeatersDeclaredSubFieldsPublishNothing(): void
    {
        $this->declareModel('gig', $this->gigFields());

        $encoded = implode('', array_map(fn(array $c) => $this->encodeRegistration($c), $this->metaCalls));

        $this->assertNotContains('_gig_settlement', $this->metaKeys());
        $this->assertStringNotContainsString('line_item', $encoded);
        $this->assertStringNotContainsString('amount', $encoded);
    }

    /**
     * The other half of the same promise: WordPress emits `meta` only when the
     * post type supports `custom-fields`. Declared fields turn it on, once, and
     * the type keeps the supports it asked for.
     */
    public function testADeclaringModelGainsCustomFieldsSupportExactlyOnce(): void
    {
        $this->declareModel('gig', $this->gigFields());

        $supports = $this->supportsReceived();

        $this->assertSame(1, count(array_keys($supports, 'custom-fields', true)));
        $this->assertContains('title', $supports);
        $this->assertContains('editor', $supports);
    }

    // -- 3. The undeclared probe, as a unit mirror of SC-1 --------------------

    /**
     * SC-1 seeds `internal_promo_budget` on a real gig and reads /wp/v2 to see
     * that it is absent. This is that probe one layer down: an undeclared field
     * sitting beside declared ones never becomes a registered meta key, so
     * WordPress has nothing to emit and nothing to accept a write for.
     */
    public function testAnUndeclaredProbeFieldIsNeverRegistered(): void
    {
        $fields = $this->gigFields();
        $fields['internal_promo_budget'] = ['type' => 'int'];

        $this->declareModel('gig', $fields);

        foreach ($this->metaCalls as $call) {
            $this->assertNotSame('_gig_internal_promo_budget', $call[1]);
            $this->assertStringNotContainsString('internal_promo_budget', $this->encodeRegistration($call));
        }

        $this->assertNotContains('_gig_internal_promo_budget', $this->metaKeys());
        $this->assertCount(2, $this->metaCalls);
    }

    // -- 4. The write gate the registration opens ----------------------------

    /**
     * Registering a key opens a /wp/v2 WRITE surface as well as a read one, and
     * the auth_callback is the only thing standing on it. WordPress calls it as
     * ($allowed, $meta_key, $object_id, $user_id, $cap, $caps) — $user_id is
     * the user the write is being judged for, and it is NOT necessarily the
     * current user (map_meta_cap() supplies it). The gate must therefore ask
     * about THAT user, and must ignore the incoming $allowed entirely.
     */
    public function testTheWriteGateRefusesTheNamedUserWhoCannotEditThePost(): void
    {
        $this->stubUserCan(false);
        $this->declareModel('gig', $this->gigFields());

        $auth = $this->callFor('_gig_venue_city')[2]['auth_callback'];

        $this->assertSame(false, $auth(true, '_gig_venue_city', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame(false, $auth(false, '_gig_venue_city', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame(
            [[7, 'edit_post', 42], [7, 'edit_post', 42]],
            $this->capChecks,
            "The gate must ask user_can(\$userId, 'edit_post', \$postId) about the user WordPress named.",
        );
    }

    /** Granted on the same authority, and on nothing else. */
    public function testTheWriteGateAllowsTheNamedUserWhoCanEditThePost(): void
    {
        $this->stubUserCan(true);
        $this->declareModel('gig', $this->gigFields());

        $auth = $this->callFor('_gig_venue_country')[2]['auth_callback'];

        $this->assertSame(true, $auth(false, '_gig_venue_country', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame(true, $auth(true, '_gig_venue_country', 42, 7, 'edit_post', ['edit_posts']));
        $this->assertSame([[7, 'edit_post', 42], [7, 'edit_post', 42]], $this->capChecks);
    }

    /** user_can() records and answers; current_user_can() answers the wrong question. */
    private function stubUserCan(bool $sentinel): void
    {
        Functions\when('user_can')->alias(function ($user, $cap, ...$rest) use ($sentinel) {
            $this->capChecks[] = [$user, $cap, $rest[0] ?? null];

            return $sentinel;
        });

        Functions\when('current_user_can')->alias(function (...$args) {
            $this->fail(
                'The write gate asked about the CURRENT user. WordPress judges the write for the '
                . 'user id in the callback\'s fourth argument. current_user_can(' . json_encode($args) . ')',
            );
        });
    }

    // -- 5. Empty state -------------------------------------------------------

    /**
     * A model that declares nothing must not grow a meta surface at all: no
     * registrations, and `supports` left exactly as it arrived. `custom-fields`
     * on a type with no registered keys is what exposes raw post meta.
     */
    public function testAModelWithZeroFieldsRegistersNothingAndLeavesSupportsUntouched(): void
    {
        $this->declareModel('gig', []);

        $this->assertSame([], $this->metaCalls);
        $this->assertSame(['title', 'editor', 'thumbnail'], $this->supportsReceived());
        $this->assertNotContains('custom-fields', $this->supportsReceived());
    }

    /** Same, when the caller brought its own supports list. */
    public function testAModelWithZeroFieldsKeepsTheCallersOwnSupportsList(): void
    {
        $this->declareModel('gig', [], ['supports' => ['title']]);

        $this->assertSame([], $this->metaCalls);
        $this->assertSame(['title'], $this->supportsReceived());
    }
}
