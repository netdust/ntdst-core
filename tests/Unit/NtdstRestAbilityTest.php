<?php // tests/Unit/NtdstRestAbilityTest.php
// The `ability` option on ntdst_rest() (5.5.0): a route declared with it also
// becomes a WordPress ability. This file pins the declaration half — the
// refusals, the record, the boot-time mount. The walks that register from the
// record are Task 3's, so $this->registered / $this->categories stay empty here.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestAbilityTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RestApiInitHarness;

    /** The fake WP route table: '/ns/route' => every arg-array handed to register_rest_route(). */
    private array $routeTable = [];

    /** Every _doing_it_wrong() call: [function, message, version]. */
    private array $wrongs = [];

    /** wp_register_ability() calls: full name => args. */
    private array $registered = [];

    /** wp_register_ability_category() calls: slug => args. */
    private array $categories = [];

    /** did_action('wp_abilities_api_init'). */
    private int $abilitiesInitDid = 0;

    /** doing_action('wp_abilities_api_init'). */
    private bool $abilitiesInitDoing = false;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->routeTable         = [];
        $this->wrongs             = [];
        $this->registered         = [];
        $this->categories         = [];
        $this->abilitiesInitDid   = 0;
        $this->abilitiesInitDoing = false;

        $this->resetRestHarness();

        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $key = '/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/');
                $this->routeTable[$key][] = $args;
                return true;
            },
        );

        Functions\when('rest_get_server')->alias(function () {
            $table = $this->routeTable;
            return new class($table) {
                public function __construct(private array $table) {}
                public function get_routes(): array { return $this->table; }
            };
        });

        Functions\when('add_action')->alias(function ($hook, $cb = null, $priority = 10, $args = 1) {
            $this->hooked[(string) $hook][(int) $priority][] = $cb;
            return true;
        });

        Functions\when('did_action')->alias(
            fn($hook = null) => (string) $hook === 'wp_abilities_api_init' ? $this->abilitiesInitDid : $this->restApiInitDid,
        );

        Functions\when('doing_action')->alias(function ($hook = null) {
            if ((string) $hook === 'wp_abilities_api_init') {
                return $this->abilitiesInitDoing;
            }

            return ($hook === null || (string) $hook === 'rest_api_init') && $this->restApiInitDoing;
        });

        Functions\when('_doing_it_wrong')->alias(function ($function = '', $message = '', $version = '') {
            $this->wrongs[] = [(string) $function, (string) $message, (string) $version];
        });

        Functions\when('wp_register_ability')->alias(function ($name, $args = []) {
            $this->registered[(string) $name] = $args;
            return true;
        });

        Functions\when('wp_register_ability_category')->alias(function ($slug, $args = []) {
            $this->categories[(string) $slug] = $args;
            return true;
        });

        Functions\when('current_user_can')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('apply_filters')->alias(fn($hook, $value, ...$rest) => $value);
        Functions\when('get_current_user_id')->justReturn(0);
    }

    protected function tearDown(): void
    {
        $this->forgetRecordedHooks();

        unset($GLOBALS['wp_filter']['rest_api_init']);

        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function mount(): void
    {
        NTDST_Rest::mountAbilities();
    }

    /** The `reason` of every ntdst_log('api') entry at this level. */
    private function logReasons(string $level): array
    {
        $reasons = [];

        foreach ($GLOBALS['_ntdst_test_log'] ?? [] as $entry) {
            if (($entry[0] ?? '') === 'api' && ($entry[1] ?? '') === $level) {
                $reasons[] = (string) ($entry[3]['reason'] ?? '');
            }
        }

        return $reasons;
    }

    private function assertOneReasonContains(string $level, string $id): void
    {
        $reasons = $this->logReasons($level);

        $this->assertCount(1, $reasons, "exactly one '{$level}' line: " . implode(' | ', $reasons));
        $this->assertStringContainsString($id, $reasons[0]);
    }

    private static function schema(): array
    {
        return ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
    }

    /** A write declaration that passes every rule. */
    private static function validWrite(array $ability = []): array
    {
        return [
            'permission' => 'edit_posts',
            'schema'     => self::schema(),
            'ability'    => $ability + [
                'name'        => 'x',
                'label'       => 'X',
                'description' => 'd',
                'destructive' => false,
                'idempotent'  => true,
            ],
        ];
    }

    private static function handler(): callable
    {
        return static fn() => ['ok' => true];
    }

    // =====================================================================
    // Denial — a declaration that cannot become an honest ability has no route
    // =====================================================================

    public function testAWriteVerbWithoutItsAnnotationsIsRefused(): void
    {
        $this->mount();

        ntdst_rest('ab/v1')->post('/x', self::handler(), [
            'permission' => 'edit_posts',
            'schema'     => self::schema(),
            'ability'    => ['name' => 'x', 'label' => 'X', 'description' => 'd'],
        ]);
        $this->fireRestApiInit();

        $this->assertArrayNotHasKey('/ab/v1/x', $this->routeTable, 'a write ability without its annotations must not register its route.');
        $this->assertOneReasonContains('error', 'ability:write-annotations');
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}> */
    public static function refusalProvider(): array
    {
        $valid = self::validWrite();
        $with  = static fn(mixed $ability): array => ['ability' => $ability] + $valid;
        $block = $valid['ability'];

        $noDescription = $block;
        unset($noDescription['description']);

        $noDestructive = $block;
        unset($noDestructive['destructive']);

        $noSchema = $valid;
        unset($noSchema['schema']);

        return [
            'shape'           => ['ability:shape', 'ab/v1', $with('x'), false],
            'unknown'         => ['ability:unknown', 'ab/v1', $with(['readonly' => true] + $block), false],
            'namespace'       => ['ability:namespace', 'my_ns/v1', $valid, false],
            'name-slash'      => ['ability:name', 'ab/v1', $with(['name' => 'a/b'] + $block), false],
            'name-uppercase'  => ['ability:name', 'ab/v1', $with(['name' => 'Mark'] + $block), false],
            'name-missing'    => ['ability:name', 'ab/v1', $with(array_diff_key($block, ['name' => 1])), false],
            'label-empty'     => ['ability:label', 'ab/v1', $with(['label' => ''] + $block), false],
            'description'     => ['ability:description', 'ab/v1', $with($noDescription), false],
            'annotations'     => ['ability:write-annotations', 'ab/v1', $with($noDestructive), false],
            'annotation-type' => ['ability:write-annotations', 'ab/v1', $with(['idempotent' => 'yes'] + $block), false],
            'output-schema'   => ['ability:output-schema', 'ab/v1', $noSchema, false],
            'empty-output'    => ['ability:output-schema', 'ab/v1', ['ability' => ['output_schema' => []] + $block] + $noSchema, false],
            'bad-output-empty'  => ['ability:output-schema', 'ab/v1', $with(['output_schema' => []] + $block), false],
            'bad-output-string' => ['ability:output-schema', 'ab/v1', $with(['output_schema' => 'foo'] + $block), false],
            'category'        => ['ability:category', 'ab/v1', $with(['category' => 'Bad Cat'] + $block), false],
            'duplicate'       => ['ability:duplicate', 'ab/v1', $valid, true],
        ];
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testEachRefusalNamesItsReason(string $id, string $namespace, array $options, bool $declaredTwice): void
    {
        $this->mount();

        $rest = ntdst_rest($namespace);

        if ($declaredTwice) {
            $rest->post('/first', self::handler(), $options);
        }

        $rest->post('/x', self::handler(), $options);
        $this->fireRestApiInit();

        $this->assertArrayNotHasKey("/{$namespace}/x", $this->routeTable, "{$id} must refuse the route.");
        $this->assertOneReasonContains('error', $id);
    }

    // =====================================================================
    // A valid declaration
    // =====================================================================

    public function testAValidDeclarationRegistersTheRouteWithItsGuard(): void
    {
        $this->mount();

        ntdst_rest('stride/v1')->get('/admin/stats', self::handler(), [
            'permission' => 'stride_view',
            'args'       => ['from' => ['type' => 'string']],
            'schema'     => self::schema(),
            'ability'    => ['name' => 'view-stats', 'label' => 'View stats', 'description' => 'Reads the stats.'],
        ]);
        $this->fireRestApiInit();

        $this->assertArrayHasKey('/stride/v1/admin/stats', $this->routeTable);
        $args = $this->routeTable['/stride/v1/admin/stats'][0];

        $this->assertInstanceOf(Closure::class, $args['permission_callback'], 'a capability registers its guard closure.');
        $this->assertArrayNotHasKey('ability', $args, '"ability" is consumed, never forwarded to register_rest_route().');
        $this->assertSame(['from' => ['type' => 'string']], $args['args']);
        $this->assertSame([], $this->logReasons('error'));
        $this->assertSame([], $this->wrongs);
        $this->assertSame([], $this->registered, 'nothing registers inline; the walk does.');
    }

    public function testAReadVerbIgnoresDestructiveAndIdempotent(): void
    {
        $this->mount();

        ntdst_rest('ab/v1')->get('/read', self::handler(), [
            'permission' => 'edit_posts',
            'schema'     => self::schema(),
            'ability'    => ['name' => 'read', 'label' => 'Read', 'description' => 'd', 'destructive' => 'whatever'],
        ]);
        $this->fireRestApiInit();

        $this->assertArrayHasKey('/ab/v1/read', $this->routeTable);
        $this->assertSame([], $this->logReasons('error'));
    }

    public function testANamespaceWhoseSlugIsNotAnAbilityPrefixIsRefused(): void
    {
        $this->mount();

        ntdst_rest('my_ns/v1')->post('/x', self::handler(), self::validWrite());
        $this->fireRestApiInit();

        $this->assertArrayNotHasKey('/my_ns/v1/x', $this->routeTable);
        $this->assertOneReasonContains('error', 'ability:namespace');
    }

    public function testADuplicateFullNameRefusesTheSecondDeclaration(): void
    {
        $this->mount();

        $rest = ntdst_rest('ab/v1');
        $rest->post('/one', self::handler(), self::validWrite());
        $rest->post('/two', self::handler(), self::validWrite());
        $this->fireRestApiInit();

        $this->assertArrayHasKey('/ab/v1/one', $this->routeTable);
        $this->assertArrayNotHasKey('/ab/v1/two', $this->routeTable);
        $this->assertOneReasonContains('error', 'ability:duplicate');
    }

    // =====================================================================
    // Timing — the bridge down, or reached too late
    // =====================================================================

    public function testADeclarationAfterTheHookIsNoticedNotLost(): void
    {
        $this->mount();
        $this->abilitiesInitDid   = 1;
        $this->abilitiesInitDoing = false;

        $this->declareInHookState('after', function (): void {
            ntdst_rest('ab/v1')->post('/late', self::handler(), self::validWrite());
        });

        $this->assertArrayHasKey('/ab/v1/late', $this->routeTable, 'the route still registers.');
        $this->assertSame([], $this->logReasons('error'));
        $this->assertOneReasonContains('warning', 'abilities:after-init');
    }

    public function testWithoutTheBridgeMountedTheOptionIsInertAndNoticed(): void
    {
        $inert = ['permission' => 'edit_posts', 'ability' => ['name' => 'Not/Valid']];

        ntdst_rest('ab/v1')->post('/inert', self::handler(), $inert);
        $this->fireRestApiInit();

        $this->assertArrayHasKey('/ab/v1/inert', $this->routeTable, 'the route registers; the block is not even read.');
        $this->assertCount(1, $this->wrongs);
        $this->assertStringContainsString('abilities:unavailable', $this->wrongs[0][1]);
        $this->assertSame('5.5.0', $this->wrongs[0][2]);
        $this->assertSame([], $this->logMessages('api', 'warning'), 'debug only: no api-log line.');
        $this->assertSame([], $this->logMessages('api', 'error'));

        ntdst_rest('ab/v1')->post('/inert', self::handler(), $inert);

        $this->assertCount(2, $this->routeTable['/ab/v1/inert']);
        $this->assertCount(1, $this->wrongs, 'refuse() says it once.');
    }

    public function testMountingAfterTheAbilitiesHookRefusesLoudly(): void
    {
        $this->abilitiesInitDid   = 1;
        $this->abilitiesInitDoing = false;

        $this->mount();

        $this->assertArrayNotHasKey('wp_abilities_api_categories_init', $this->hooked);
        $this->assertArrayNotHasKey('wp_abilities_api_init', $this->hooked);
        $this->assertOneReasonContains('warning', 'abilities:mounted-late');

        ntdst_rest('ab/v1')->post('/x', self::handler(), self::validWrite());
        $this->fireRestApiInit();

        $this->assertCount(2, $this->wrongs);
        $this->assertStringContainsString('abilities:mounted-late', $this->wrongs[0][1]);
        $this->assertStringContainsString('abilities:unavailable', $this->wrongs[1][1]);
    }

    public function testMountHooksBothWalksOnce(): void
    {
        $this->mount();
        $this->mount();

        foreach (['wp_abilities_api_categories_init', 'wp_abilities_api_init'] as $hook) {
            $callbacks = array_merge(...array_values($this->hooked[$hook] ?? [[]]));

            $this->assertCount(1, $callbacks, "one closure on {$hook}.");
            $this->assertInstanceOf(Closure::class, $callbacks[0]);
        }
    }
}
