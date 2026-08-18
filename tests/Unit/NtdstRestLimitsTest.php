<?php // tests/Unit/NtdstRestLimitsTest.php
// T06 — rate limiting on the resource surface.
//
// /ntdst/v1/action has been limited since v2.4. Without the same delegation
// here, resource routes would be the one unthrottled way into the site.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

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

    private function allow(): callable
    {
        return static fn() => true;
    }

    private function request(string $method): NtdstMethodRequest
    {
        return new NtdstMethodRequest($method);
    }

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
            // Distinct request objects: the memo is per request, so a burst is
            // five requests rather than one repeated.
            $results[] = $permission($this->request('GET'));
        }

        $this->assertTrue($results[0]);
        $this->assertInstanceOf(WP_Error::class, $results[4], 'A burst past the threshold must be refused.');
        $this->assertSame(429, $results[4]->get_error_data()['status'] ?? null);
    }

    public function test429CarriesARetrySignal(): void
    {
        ntdst_rest('rate/v1')->get('/retry', fn() => [], [
            'permission' => $this->allow(),
            'rate_limit' => 1,
            'rate_window' => 45,
        ]);

        $permission = $this->args('/rate/v1/retry')['permission_callback'];
        $permission($this->request('GET'));
        $second = $permission($this->request('GET'));

        $this->assertSame(45, $second->get_error_data()['retry_after'] ?? null, 'A client cannot back off without one.');
    }

    public function testReadsDoNotDrainTheWriteRoutesBudget(): void
    {
        // WP calls EVERY sibling handler's permission_callback on one request,
        // to build the Allow header. With the method missing from the bucket
        // key, ordinary GET polling exhausted the POST limit and locked a
        // partner out of writing without them ever writing.
        ntdst_rest('rate/v1')
            ->get('/shared', fn() => [], ['permission' => $this->allow(), 'rate_limit' => 60])
            ->post('/shared', fn() => [], ['permission' => $this->allow(), 'rate_limit' => 10]);

        $get = $this->args('/rate/v1/shared')['permission_callback'];

        // The registry holds one entry per route string, so drive the GET
        // handler as WP would across 20 separate requests.
        for ($i = 0; $i < 20; $i++) {
            $get($this->request('GET'));
        }

        $write = $this->args('/rate/v1/shared')['permission_callback'];
        $this->assertTrue(
            $write($this->request('POST')),
            'Reads must not spend the write budget.',
        );
    }

    public function testASiblingHandlerDoesNotSpendBudgetForAMethodItDidNotMatch(): void
    {
        ntdst_rest('rate/v1')->post('/sibling', fn() => [], [
            'permission' => $this->allow(),
            'rate_limit' => 1,
        ]);

        $permission = $this->args('/rate/v1/sibling')['permission_callback'];

        // A GET arrives; the POST handler's permission still runs for the Allow
        // header, but must not consume POST's single unit.
        $permission($this->request('GET'));

        $this->assertTrue(
            $permission($this->request('POST')),
            'The one POST unit must still be available after an unrelated GET.',
        );
    }

    public function testARouteThatDeclaresNoRateLimitIsNotThrottled(): void
    {
        ntdst_rest('rate/v1')->get('/free', fn() => [], ['permission' => $this->allow()]);

        $permission = $this->args('/rate/v1/free')['permission_callback'];

        for ($i = 0; $i < 25; $i++) {
            $last = $permission($this->request('GET'));
        }

        $this->assertTrue($last, 'Rate limiting is opt-in per route.');
    }

    public function testUnknownOptionKeysRefuseTheRouteInsteadOfSilentlyDroppingTheControl(): void
    {
        // A typo'd cap or limit is a control the author believes is on. Silent
        // acceptance ships an unprotected route that reviews as protected.
        ntdst_rest('opt/v1')->post('/typo', fn() => [], [
            'permission' => $this->allow(),
            'rate_limits' => 10,
        ]);

        $this->assertArrayNotHasKey('/opt/v1/typo', $this->registered);
    }

    public function testWordPressOwnOptionsArePassedThrough(): void
    {
        // Narrowing WP's API would send consumers back to raw
        // register_rest_route(), which is what this service exists to prevent.
        $schema = static fn() => ['type' => 'object'];
        ntdst_rest('opt/v1')->get('/through', fn() => [], [
            'permission' => $this->allow(),
            'show_in_index' => false,
            'schema' => $schema,
        ]);

        $args = $this->args('/opt/v1/through');
        $this->assertFalse($args['show_in_index']);
        $this->assertSame($schema, $args['schema']);
        $this->assertArrayNotHasKey('permission', $args, 'Framework-only options must not leak to WP.');
    }

    public function testANonCallableHandlerIsRefused(): void
    {
        ntdst_rest('opt/v1')->get('/bad-handler', 'no_such_function', ['permission' => $this->allow()]);

        $this->assertArrayNotHasKey('/opt/v1/bad-handler', $this->registered);
    }

    public function testRestDoesNotReimplementTheLimiterOrTheIpResolver(): void
    {
        // Delegation is only provable by reading: a hand-rolled counter would
        // pass every behavioural test here against the stubbed transients.
        $source = file_get_contents(__DIR__ . '/../../api/Rest.php');

        // Strip comments: the docblock legitimately NAMES these headers to warn
        // about the trusted-proxy prerequisite. What must not exist is code
        // that reads them.
        $code = implode('', array_map(
            static fn($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
            token_get_all($source),
        ));

        $this->assertStringContainsString('NTDST_RateLimiter::attempt', $code);

        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'X-Forwarded-For'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, 'support/ClientIp.php is the one resolver.');
        }
    }
}

final class NtdstMethodRequest
{
    public function __construct(private string $method) {}

    public function get_method(): string
    {
        return $this->method;
    }
}
