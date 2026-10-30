<?php // tests/Unit/NtdstRestAbilityExecuteTest.php
// The `ability` option on ntdst_rest() (5.5.0), the dispatch half: what the
// ability's two callbacks do once WordPress holds them. The permission is the
// route's resolved permission and never its guard; executing is one
// rest_do_request() of a request built from the ability's input.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestAbilityExecuteTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RestApiInitHarness;

    /** The fake WP route table: '/ns/route' => every arg-array handed to register_rest_route(). */
    private array $routeTable = [];

    /** wp_register_ability() calls: full name => args. */
    private array $registered = [];

    /** did_action('wp_abilities_api_init'). */
    private int $abilitiesInitDid = 0;

    /** doing_action('wp_abilities_api_init'). */
    private bool $abilitiesInitDoing = false;

    /** @var list<WP_REST_Request> Every request handed to rest_do_request(). */
    private array $dispatched = [];

    /** What the next rest_do_request() answers. */
    private ?WP_REST_Response $nextResponse = null;

    /** The limiter's storage: key => value. */
    private array $transients = [];

    /** @var list<string> Every capability put to current_user_can(). */
    private array $asked = [];

    private bool $can = false;

    /** Every call the route handler received. */
    private array $handled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->routeTable         = [];
        $this->registered         = [];
        $this->abilitiesInitDid   = 0;
        $this->abilitiesInitDoing = false;
        $this->dispatched         = [];
        $this->nextResponse       = new WP_REST_Response(null, 200);
        $this->transients         = [];
        $this->asked              = [];
        $this->can                = false;
        $this->handled            = [];

        $this->resetRestHarness();

        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $key = '/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/');
                $this->routeTable[$key][] = $args;
                return true;
            },
        );

        Functions\when('rest_get_server')->alias(function () {
            if ($this->restApiInitDid === 0) {
                $this->fireRestApiInit();
            }

            $table = $this->routeTable;
            return new class($table) {
                public function __construct(private array $table) {}
                public function get_routes(): array { return $this->table; }
            };
        });

        Functions\when('rest_do_request')->alias(function (WP_REST_Request $r) {
            $this->dispatched[] = $r;
            return $this->nextResponse;
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

        Functions\when('_doing_it_wrong')->justReturn(null);

        Functions\when('wp_register_ability')->alias(function ($name, $args = []) {
            $this->registered[(string) $name] = $args;
            return true;
        });

        Functions\when('wp_register_ability_category')->justReturn(true);

        // WordPress 6.8.2's list, read from wp-includes/rest-api.php.
        Functions\when('rest_get_allowed_schema_keywords')->justReturn([
            'title', 'description', 'default', 'type', 'format', 'enum', 'items', 'properties',
            'additionalProperties', 'patternProperties', 'minProperties', 'maxProperties',
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
            'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'anyOf', 'oneOf',
        ]);

        Functions\when('current_user_can')->alias(function ($capability) {
            $this->asked[] = (string) $capability;
            return $this->can;
        });
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('apply_filters')->alias(fn($hook, $value, ...$rest) => $value);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof WP_Error);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v, $ttl = 0) {
            $this->transients[$k] = $v;
            return true;
        });
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

    /** Mount the bridge, fire both abilities hooks, and hand back one ability's args. */
    private function ability(string $name): array
    {
        $this->fire('wp_abilities_api_categories_init');
        $this->fire('wp_abilities_api_init');

        $this->assertArrayHasKey($name, $this->registered, "control: {$name} must be registered.");

        return $this->registered[$name];
    }

    private function fire(string $hook): void
    {
        $byPriority = $this->hooked[$hook] ?? [];
        ksort($byPriority);

        $abilities = $hook === 'wp_abilities_api_init';

        if ($abilities) {
            $this->abilitiesInitDid   = 1;
            $this->abilitiesInitDoing = true;
        }

        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback();
            }
        }

        if ($abilities) {
            $this->abilitiesInitDoing = false;
        }
    }

    private function handler(): Closure
    {
        return function (...$args) {
            $this->handled[] = $args;
            return ['ok' => true];
        };
    }

    private static function schema(): array
    {
        return ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
    }

    /** "The option, as a consumer writes it", with a recording handler. */
    private function declareAttendance(): void
    {
        NTDST_Rest::mountAbilities();

        ntdst_rest('stride/v1')->post('/admin/attendance', $this->handler(), [
            'permission' => 'stride_manage',
            'rate_limit' => 300,
            'args'       => [
                'session_id' => ['type' => 'integer', 'required' => true, 'description' => 'Session post ID'],
                'user_id'    => ['type' => 'integer', 'required' => true],
                'status'     => ['type' => 'string', 'default' => 'present'],
            ],
            'schema'     => static fn(): array => self::schema(),
            'ability'    => [
                'name'        => 'mark-attendance',
                'label'       => 'Mark attendance',
                'description' => 'Marks one user present, absent or excused for one session.',
                'destructive' => false,
                'idempotent'  => true,
            ],
        ]);
    }

    private function attendance(): array
    {
        $this->declareAttendance();

        return $this->ability('stride-v1/mark-attendance');
    }

    private function declareRead(string $route, string $name, array $options = []): void
    {
        NTDST_Rest::mountAbilities();

        ntdst_rest('stride/v1')->get($route, $this->handler(), $options + [
            'permission' => 'stride_view',
            'schema'     => static fn(): array => self::schema(),
            'ability'    => ['name' => $name, 'label' => $name, 'description' => 'A read.'],
        ]);
    }

    // =====================================================================
    // Authorization
    // =====================================================================

    public function testAnUnauthorizedExecutionIsRefusedBeforeTheHandlerRuns(): void
    {
        $permission = $this->attendance()['permission_callback'];

        $this->assertFalse($permission(['session_id' => 1, 'user_id' => 2]));
        $this->assertSame([], $this->dispatched);
        $this->assertSame([], $this->handled);
    }

    public function testThePermissionCallbackChargesNothing(): void
    {
        $this->can  = true;
        $permission = $this->attendance()['permission_callback'];

        $this->assertTrue($permission(['session_id' => 1, 'user_id' => 2]));
        $this->assertSame([], $this->transients);
        $this->assertSame(['stride_manage'], $this->asked);

        // Control: the route's guard, given the same verb, does charge — so a
        // permission_callback that WAS the guard would fail the line above.
        $guard = $this->routeTable['/stride/v1/admin/attendance'][0]['permission_callback'];
        $this->assertTrue($guard(new WP_REST_Request('POST', '/stride/v1/admin/attendance')));
        $this->assertNotSame([], $this->transients, 'control: the guard charges the limiter.');
    }

    public function testAPublicReadIsAnonymousAndReadonly(): void
    {
        Functions\when('__return_true')->justReturn(true);
        NTDST_Rest::mountAbilities();

        ntdst_rest('stride/v1')->get('/prices', $this->handler(), [
            'schema'  => static fn(): array => self::schema(),
            'ability' => ['name' => 'get-prices', 'label' => 'Get prices', 'description' => 'Reads the prices.'],
        ])->public();

        $args = $this->ability('stride-v1/get-prices');

        $this->assertTrue($args['meta']['annotations']['readonly']);
        $this->assertTrue($args['permission_callback']([]));
        $this->assertSame([], $this->asked);
    }

    public function testTheShorthandPermissionIsCalledAsAFunction(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        NTDST_Rest::mountAbilities();

        ntdst_rest('stride/v1')->get('/me', $this->handler(), [
            'schema'  => static fn(): array => self::schema(),
            'ability' => ['name' => 'get-me', 'label' => 'Get me', 'description' => 'Reads the current user.'],
        ]);

        $this->assertFalse($this->ability('stride-v1/get-me')['permission_callback']([]));
    }

    // =====================================================================
    // Dispatch
    // =====================================================================

    public function testExecuteIsOneDispatchAndReturnsTheResponsesData(): void
    {
        $this->nextResponse = new WP_REST_Response(['ok' => true], 200);
        $execute            = $this->attendance()['execute_callback'];
        $input              = ['session_id' => 7, 'user_id' => 3, 'status' => 'absent'];

        $this->assertSame(['ok' => true], $execute($input));
        $this->assertCount(1, $this->dispatched);

        $request = $this->dispatched[0];
        $this->assertSame('POST', $request->get_method());
        $this->assertSame('/stride/v1/admin/attendance', $request->get_route());
        $this->assertSame($input, $request->get_body_params());
        $this->assertSame([], $request->get_query_params());
    }

    public function testAnErrorResponseComesBackAsAWpErrorWithTheRoutesStatus(): void
    {
        $this->nextResponse = new WP_REST_Response(['code' => 'invalid_data', 'message' => 'Ongeldige gegevens.'], 400);
        $execute            = $this->attendance()['execute_callback'];

        $result = $execute(['session_id' => 7, 'user_id' => 3]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_data', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function testAReadSendsItsInputAsQueryParams(): void
    {
        $this->declareRead('/admin/stats', 'get-stats', [
            'args' => ['fresh' => ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean']],
        ]);

        $this->ability('stride-v1/get-stats')['execute_callback'](['fresh' => '1']);

        $request = $this->dispatched[0];
        $this->assertSame('GET', $request->get_method());
        $this->assertSame(['fresh' => '1'], $request->get_query_params());
        $this->assertSame([], $request->get_body_params());
    }

    public function testAPathParameterIsEncodedNotInterpolated(): void
    {
        $this->declareRead('/admin/editions/(?P<id>\d+)', 'get-edition');
        $execute = $this->ability('stride-v1/get-edition')['execute_callback'];

        $execute(['id' => '12']);
        $execute(['id' => '12/../x']);

        $this->assertSame('/stride/v1/admin/editions/12', $this->dispatched[0]->get_route());
        $this->assertSame('/stride/v1/admin/editions/12%2F..%2Fx', $this->dispatched[1]->get_route());
        $this->assertArrayNotHasKey('id', $this->dispatched[1]->get_query_params());
    }
}
