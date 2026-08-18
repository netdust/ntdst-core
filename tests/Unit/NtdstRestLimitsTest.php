<?php // tests/Unit/NtdstRestLimitsTest.php
// T05 + T06 — the two abuse controls on the resource surface.
//
// T05 (caps): WordPress parses a JSON body at its DEFAULT depth of 512 and
// applies NO body-size cap of its own. A route that accepts a write verb is
// therefore reachable with a payload large enough or nested deeply enough to
// cost real memory before the handler ever sees it. The caps are opt-in per
// route and are enforced BEFORE the consumer's handler runs — the tests assert
// the handler's invocation COUNT, not merely the status, because a 413 returned
// after the handler already ran is not a control.
//
// T06 (limiter): /ntdst/v1/action has been rate limited since v2.4 via
// support/RateLimiter.php. If ntdst_rest() does not delegate to that same
// primitive, the new REST surface becomes the one unthrottled way into the
// site — a gap, not a duplicate. The parked registrar carried NO rate limiting
// at all, so there is nothing to port; this wires up what the package already
// has, exactly as NTDST_Actions::checkRateLimit() does.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

// The shared WP_REST_Request stub (defined in NtdstRestTest, first file loaded)
// has no get_body()/set_body(). EXTEND it rather than invent a parallel double,
// so what the caps are driven with really IS a WP_REST_Request — the caps read
// the raw body, which is the thing WP parses at depth 512 with no size cap.
if (!class_exists('WP_REST_Request', false)) {
    class WP_REST_Request
    {
        public function __construct(private array $params = []) {}
        public function get_json_params(): array { return $this->params; }
    }
}

final class NtdstCapRequest extends WP_REST_Request
{
    private string $body = '';

    public function set_body(string $body): void { $this->body = $body; }
    public function get_body(): string { return $this->body; }
}

final class NtdstRestLimitsTest extends TestCase
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
        Functions\when('did_action')->justReturn(1); // register immediately
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
        $this->assertArrayHasKey($key, $this->registered, "control: {$key} must be registered before it can be driven.");

        return $this->registered[$key];
    }

    private function allow(): callable
    {
        return static fn() => true;
    }

    // ── T05 — body-size and JSON-depth caps ──────────────────────────────

    public function testOversizedBodyIsRefusedBeforeTheHandlerRuns(): void
    {
        $ran = 0;
        ntdst_rest('cap/v1')->post('/thing', function () use (&$ran) {
            $ran++;
            return ['ok' => true];
        }, ['permission' => $this->allow(), 'max_body_bytes' => 100]);

        $request = new NtdstCapRequest();
        $request->set_body(str_repeat('x', 5000));

        $result = ($this->args('/cap/v1/thing')['callback'])($request);

        $this->assertInstanceOf(WP_Error::class, $result, 'An oversized body must be refused.');
        $this->assertSame(413, $result->get_error_data()['status'] ?? null, 'Payload Too Large.');
        $this->assertSame(0, $ran, 'The handler must never run — a cap applied after the handler is not a control.');
    }

    public function testBodyWithinTheCapReachesTheHandler(): void
    {
        // Positive control: without this, a wrapper that refused EVERYTHING
        // would pass the test above.
        $ran = 0;
        ntdst_rest('cap/v1')->post('/small', function () use (&$ran) {
            $ran++;
            return ['ok' => true];
        }, ['permission' => $this->allow(), 'max_body_bytes' => 5000]);

        $request = new NtdstCapRequest();
        $request->set_body(str_repeat('x', 10));

        $result = ($this->args('/cap/v1/small')['callback'])($request);

        $this->assertSame(1, $ran, 'A body under the cap must reach the handler.');
        $this->assertSame(['ok' => true], $result);
    }

    public function testOverNestedJsonIsRefusedBeforeTheHandlerRuns(): void
    {
        $ran = 0;
        ntdst_rest('cap/v1')->post('/deep', function () use (&$ran) {
            $ran++;
            return ['ok' => true];
        }, ['permission' => $this->allow(), 'max_json_depth' => 5]);

        // 40 levels of nesting — well past the declared cap, well under WP's 512.
        $deep = '';
        for ($i = 0; $i < 40; $i++) {
            $deep .= '{"a":';
        }
        $deep .= '1';
        $deep .= str_repeat('}', 40);

        $request = new NtdstCapRequest();
        $request->set_body($deep);

        $result = ($this->args('/cap/v1/deep')['callback'])($request);

        $this->assertInstanceOf(WP_Error::class, $result, 'Over-nested JSON must be refused.');
        $this->assertSame(400, $result->get_error_data()['status'] ?? null, 'Bad Request.');
        $this->assertSame(0, $ran, 'The handler must never run.');
    }

    public function testJsonWithinTheDepthCapReachesTheHandler(): void
    {
        $ran = 0;
        ntdst_rest('cap/v1')->post('/shallow', function () use (&$ran) {
            $ran++;
            return ['ok' => true];
        }, ['permission' => $this->allow(), 'max_json_depth' => 10]);

        $request = new NtdstCapRequest();
        $request->set_body('{"a":{"b":1}}');

        $result = ($this->args('/cap/v1/shallow')['callback'])($request);

        $this->assertSame(1, $ran, 'JSON within the depth cap must reach the handler.');
    }

    public function testARouteThatDeclaresNoCapsIsUnrestricted(): void
    {
        // Caps are OPT-IN. A route that declares none must behave exactly as
        // WordPress would — this class does not impose a policy nobody asked for.
        $ran = 0;
        ntdst_rest('cap/v1')->post('/uncapped', function () use (&$ran) {
            $ran++;
            return ['ok' => true];
        }, ['permission' => $this->allow()]);

        $request = new NtdstCapRequest();
        $request->set_body(str_repeat('x', 100000));

        ($this->args('/cap/v1/uncapped')['callback'])($request);

        $this->assertSame(1, $ran, 'With no cap declared, the handler runs as WP would run it.');
    }

    // ── T06 — one shared limiter, no second implementation ───────────────

    public function testBurstPastTheThresholdIs429(): void
    {
        ntdst_rest('rate/v1')->get('/thing', fn() => ['ok' => true], [
            'permission' => $this->allow(),
            'rate_limit' => 3,
            'rate_window' => 60,
        ]);

        $permission = $this->args('/rate/v1/thing')['permission_callback'];

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            // A DISTINCT request object each time: the permission memo is
            // per-request, so a burst is five requests, not one repeated.
            $results[] = $permission(new WP_REST_Request());
        }

        $this->assertTrue($results[0], 'The first call within the limit must be allowed.');
        $this->assertInstanceOf(WP_Error::class, $results[4], 'A burst past the threshold must be refused.');
        $this->assertSame(429, $results[4]->get_error_data()['status'] ?? null, 'Too Many Requests.');
    }

    public function testARouteThatDeclaresNoRateLimitIsNotThrottled(): void
    {
        ntdst_rest('rate/v1')->get('/free', fn() => ['ok' => true], ['permission' => $this->allow()]);

        $permission = $this->args('/rate/v1/free')['permission_callback'];

        for ($i = 0; $i < 25; $i++) {
            $last = $permission(new WP_REST_Request());
        }

        $this->assertTrue($last, 'Rate limiting is opt-in per route, like the caps.');
    }

    public function testRestDoesNotReimplementTheLimiterOrTheIpResolver(): void
    {
        // FR-4 is a delegation requirement, and delegation is only provable by
        // reading the file: a second limiter would pass every behavioural test
        // above while creating exactly the duplication v3 exists to remove.
        $source = file_get_contents(__DIR__ . '/../../api/Rest.php');

        $this->assertStringContainsString(
            'NTDST_RateLimiter::attempt',
            $source,
            'api/Rest.php must delegate to support/RateLimiter.php, as NTDST_Actions::checkRateLimit() does.',
        );

        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'X-Forwarded-For'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "api/Rest.php must not resolve client IPs itself — support/ClientIp.php is the one resolver.",
            );
        }
    }
}
