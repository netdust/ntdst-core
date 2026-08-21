<?php // tests/Unit/NtdstRestChargeTest.php
// `charge()` — a consumer billing its own refusals to the route's own bucket.
//
// THE PROBLEM IT SOLVES (F11, raised by todai-client's intake). A guard that
// must inspect a request BEFORE WordPress decodes it — a JSON depth bound, a
// content-type gate — has to filter `rest_pre_dispatch`, because
// `has_valid_params()` runs WP's default-depth `json_decode()` before any
// permission callback. But budget is spent inside the permission callback, and
// a filter that short-circuits `dispatch()` means it never runs. So every
// refusal was FREE: measured on that consumer's public write route, 100
// rejected requests carrying ~100 MB moved the bucket by zero, and a
// legitimate POST straight after still returned 201.
//
// The consumer could not fix it either, while `bucket()` was private and the
// key was built inline: the only options were hand-copying the key formula or
// opening a second bucket, and a second bucket meters nothing.
//
// `charge()` is the whole fix, and it is a method rather than a route option
// because the CONSUMER owns the decision to refuse. Core owning that hook
// meant core owning a priority, a route matcher and a do-not-stomp rule — all
// of which the consumer already has, in the filter it had to write anyway.
//
//   add_filter('rest_pre_dispatch', function ($result, $server, $request) {
//       if ($result !== null || $this->allows($request)) {
//           return $result;
//       }
//
//       ntdst_rest('todai/v1')->charge('/submissions', 'POST', $request);
//
//       return new WP_Error('forbidden', '…', ['status' => 403]);
//   }, 10, 3);
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestChargeTest extends TestCase
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
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.77'];

        Functions\when('register_rest_route')->alias(
            function (string $ns, string $route, array $args) {
                $this->registered['/' . trim($ns, '/') . $route] = $args;
                return true;
            },
        );
        Functions\when('did_action')->justReturn(1);
        Functions\when('add_action')->justReturn(true);
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('apply_filters')->alias(static fn($hook, $value = null, ...$rest) => $value);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function buckets(): array
    {
        return array_filter(
            $this->transients,
            static fn($k) => str_starts_with($k, 'ntdst_rest_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function testARefusalMovesTheBucket(): void
    {
        $rest = ntdst_rest('ch1/v1');
        $rest->post('/thing', fn() => [], ['permission' => static fn() => true, 'rate_limit' => 5]);

        $this->assertSame([], $this->buckets(), 'Fixture is wrong: something was already charged.');

        $rest->charge('/thing', 'POST');

        $this->assertSame(
            [1],
            array_values($this->buckets()),
            'A refusal spent nothing, so a caller can be refused without limit.',
        );
    }

    /**
     * The property F1 was actually about.
     *
     * Billing SOMETHING is not the fix — billing the same bucket the permission
     * callback bills is. A separate counter would move on every refusal and
     * still leave the real budget untouched, so the flood would read as metered
     * while remaining free.
     */
    public function testARefusalAndAPermittedRequestShareOneBucket(): void
    {
        $rest = ntdst_rest('ch2/v1');
        $rest->post('/thing', fn() => [], ['permission' => static fn() => true, 'rate_limit' => 5]);

        $rest->charge('/thing', 'POST');
        $viaCharge = array_keys($this->buckets());

        // The permission callback the route actually registered.
        ($this->registered['/ch2/v1/thing']['permission_callback'])(new NtdstChargeRequest('POST'));
        $afterGuard = array_keys($this->buckets());

        $this->assertSame($viaCharge, $afterGuard, 'charge() and guard() bill different buckets.');
        $this->assertSame([2], array_values($this->buckets()), 'The two spends did not accumulate.');
    }

    public function testChargeReportsWhenTheBudgetIsGone(): void
    {
        $rest = ntdst_rest('ch3/v1');
        $rest->post('/thing', fn() => [], ['permission' => static fn() => true, 'rate_limit' => 2]);

        $this->assertTrue($rest->charge('/thing', 'POST'));
        $this->assertTrue($rest->charge('/thing', 'POST'));
        $this->assertFalse(
            $rest->charge('/thing', 'POST'),
            'charge() kept reporting success past the limit, so a caller cannot be told to stop.',
        );
    }

    /**
     * Nothing to spend is not a refusal. A route with no declared limit must
     * not make a consumer's guard behave as though the caller were throttled.
     */
    public function testAnUndeclaredLimitChargesNothingAndReportsTrue(): void
    {
        $rest = ntdst_rest('ch4/v1');
        $rest->post('/thing', fn() => [], ['permission' => static fn() => true]);

        $this->assertTrue($rest->charge('/thing', 'POST'));
        $this->assertSame([], $this->buckets());
    }
}

/** Minimal request double — the shape guard() actually calls. */
final class NtdstChargeRequest
{
    public function __construct(private string $method) {}

    public function get_method(): string
    {
        return $this->method;
    }
}
