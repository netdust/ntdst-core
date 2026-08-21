<?php // tests/Unit/NtdstRestAuthBeforeLimitTest.php
// Class D — a caller who can never pass a route's permission must not be able
// to make the site WRITE storage by asking.
//
// api/Actions.php settled this once (M2) and recorded why: charging the limiter
// ahead of the auth gate meant every doomed anonymous request left wp_options
// rows behind, reaped only by a daily cron. Rest.php charged first too. These
// tests are the denial path for the reorder — they fail against the old order.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestAuthBeforeLimitTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, array<string, mixed>> */
    private array $registered = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->registered = [];
        $this->transients = [];

        Functions\when('register_rest_route')->alias(
            function (string $ns, string $route, array $args) {
                $this->registered['/' . trim($ns, '/') . $route] = $args;
                return true;
            },
        );
        Functions\when('did_action')->justReturn(1);
        Functions\when('add_action')->justReturn(true);
        Functions\when('_doing_it_wrong')->justReturn(null);
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
        Monkey\tearDown();
        parent::tearDown();
    }

    private function args(string $key): array
    {
        $this->assertArrayHasKey($key, $this->registered, "control: {$key} must be registered.");

        return $this->registered[$key];
    }

    private function request(string $method): NtdstAuthOrderRequest
    {
        return new NtdstAuthOrderRequest($method);
    }

    /** T1 — the refused caller writes nothing. */
    public function testARefusedCallerWritesNoTransient(): void
    {
        ntdst_rest('authorder/v1')->get('/denied', fn() => ['ok' => true], [
            'permission'  => static fn(): bool => false,
            'rate_limit'  => 3,
            'rate_window' => 60,
        ]);

        $permission = $this->args('/authorder/v1/denied')['permission_callback'];

        $this->assertFalse($permission($this->request('GET')), 'control: the route must refuse.');
        $this->assertSame(
            [],
            $this->transients,
            'A caller who cannot pass this permission must not make the site write storage.',
        );
    }

    /** T2 — repeating the refusal still writes nothing, however many times. */
    public function testRepeatedRefusalsNeverAccumulateStorage(): void
    {
        ntdst_rest('authorder/v1')->get('/denied-burst', fn() => ['ok' => true], [
            'permission'  => static fn(): bool => false,
            'rate_limit'  => 3,
            'rate_window' => 60,
        ]);

        $permission = $this->args('/authorder/v1/denied-burst')['permission_callback'];

        for ($i = 0; $i < 10; $i++) {
            // Distinct request objects: the guard memoizes per request, so ten
            // objects are ten requests rather than one repeated.
            $this->assertFalse($permission($this->request('GET')));
        }

        $this->assertSame([], $this->transients, 'Ten doomed requests must leave zero rows.');
    }

    /** T3 — the authorized caller is still charged; the reorder must not disarm the limiter. */
    public function testAnAuthorizedCallerIsStillCharged(): void
    {
        ntdst_rest('authorder/v1')->get('/allowed', fn() => ['ok' => true], [
            'permission'  => static fn(): bool => true,
            'rate_limit'  => 3,
            'rate_window' => 60,
        ]);

        $permission = $this->args('/authorder/v1/allowed')['permission_callback'];

        $this->assertTrue($permission($this->request('GET')));
        $this->assertNotSame([], $this->transients, 'An allowed caller must still spend budget.');
    }

    /** T4 — the sibling-verb guard survives the reorder. */
    public function testAnUnmatchedVerbStillSpendsNothing(): void
    {
        ntdst_rest('authorder/v1')->post('/verb', fn() => ['ok' => true], [
            'permission'  => static fn(): bool => true,
            'rate_limit'  => 3,
            'rate_window' => 60,
        ]);

        $permission = $this->args('/authorder/v1/verb')['permission_callback'];

        // WordPress calls every sibling's permission to build the Allow header.
        $this->assertTrue($permission($this->request('GET')));
        $this->assertSame([], $this->transients, 'A GET must not drain the POST route budget.');
    }
}

final class NtdstAuthOrderRequest
{
    public function __construct(private string $method) {}

    public function get_method(): string
    {
        return $this->method;
    }
}
