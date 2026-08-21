<?php // tests/Unit/NtdstRestBeforeDispatchTest.php
// `before_dispatch` — a consumer's own pre-dispatch guard, charged like one.
//
// THE GAP THIS CLOSES (F11, raised by todai-client's intake at its T13
// shake-out). A consumer that must inspect a request BEFORE WordPress decodes
// it — a JSON depth bound, a content-type gate — has to filter
// `rest_pre_dispatch` itself, because `has_valid_params()` runs WP's own
// default-depth `json_decode()` before any permission callback. Two things
// then go wrong, and neither is the consumer's fault:
//
//   1. Budget. This package spends a route's rate budget inside `guard()`, the
//      permission wrapper. A filter that short-circuits `dispatch()` means
//      `permission_callback` NEVER RUNS, so the refusal is free. Measured on
//      the consumer's public write route: 100 rejected requests carrying
//      ~100 MB of body moved the bucket by zero, and a legitimate POST straight
//      afterwards still returned 201. The same hole was closed for preflights
//      and left open for every other verb.
//
//   2. Route scope. `bucket()` is private and the budget key is built inline in
//      `guard()`, so a consumer cannot charge the right bucket even if it wants
//      to — it must hand-copy the key formula, or open a second bucket. The
//      consumer also has to re-implement route matching, and that copy has now
//      been wrong TWICE in one module: case-sensitively (so `/NS/V1/THING`
//      silently skipped the guard) and then by prefix (so the guard answered on
//      paths the CORS policy did not cover, handing back WP's
//      reflect-any-origin-with-credentials default).
//
// THE CHARGE IS ON REFUSAL ONLY, and that is the whole design. A request the
// callback ALLOWS goes on to `guard()`, which charges it there — charging here
// too would bill every legitimate request twice. A request the callback REFUSES
// never reaches `guard()`, so this is the only place it can be billed. One
// charge per request either way, into the REQUEST bucket both paths share.
//
// Note the bucket choice. Preflights are deliberately kept in a bucket of their
// own (`ntdst_rest_pf_`) so a preflight cannot spend what the real request needs
// a moment later. A pre-dispatch refusal is not a preflight: it is the request,
// answered early, so it belongs in the request's own bucket.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestBeforeDispatchTest extends TestCase
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
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.44'];

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

    /**
     * The REAL mounted callback, read by PRIORITY. Driving the mounted filter
     * rather than calling the method is the point: deleting the `add_filter`
     * line has to turn this suite red.
     */
    private function beforeDispatch(): callable
    {
        $this->assertArrayHasKey(
            6,
            $GLOBALS['_ntdst_test_filters_at']['rest_pre_dispatch'] ?? [],
            'NTDST_Rest must mount before_dispatch on rest_pre_dispatch at priority 6 — '
            . 'after its own preflight charge at 5, so a preflight is still charged first.',
        );

        return $GLOBALS['_ntdst_test_filters_at']['rest_pre_dispatch'][6];
    }

    private function request(string $method, string $route): NtdstBeforeDispatchRequest
    {
        return new NtdstBeforeDispatchRequest($method, $route);
    }

    /** @return array<string, mixed> */
    private function requestBuckets(): array
    {
        return array_filter(
            $this->transients,
            static fn($k) => str_starts_with($k, 'ntdst_rest_') && !str_starts_with($k, 'ntdst_rest_pf_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function testARefusalIsCharged(): void
    {
        ntdst_rest('bd1/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 5,
            'rate_window' => 60,
            'before_dispatch' => static fn($request) => new WP_Error('nope', 'no', ['status' => 415]),
        ]);

        $result = ($this->beforeDispatch())(null, null, $this->request('POST', '/bd1/v1/thing'));

        $this->assertInstanceOf(WP_Error::class, $result, 'The callback\'s refusal was not returned.');
        $this->assertSame(
            [1],
            array_values($this->requestBuckets()),
            'A refused request spent no budget, so a caller can be refused without limit — '
            . 'measured on a real consumer at 100 refusals and ~100 MB for zero cost.',
        );
    }

    public function testAnAllowedRequestIsNotChargedHere(): void
    {
        ntdst_rest('bd2/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 5,
            'before_dispatch' => static fn($request) => null,
        ]);

        $result = ($this->beforeDispatch())(null, null, $this->request('POST', '/bd2/v1/thing'));

        $this->assertNull($result);
        $this->assertSame(
            [],
            $this->requestBuckets(),
            'An allowed request was charged here AND will be charged again in guard() — double billing.',
        );
    }

    public function testTheRouteIsMatchedCaseInsensitivelyLikeWordPress(): void
    {
        $seen = [];
        ntdst_rest('bd3/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'before_dispatch' => static function ($request) use (&$seen) {
                $seen[] = $request->get_route();
                return null;
            },
        ]);

        ($this->beforeDispatch())(null, null, $this->request('POST', '/BD3/V1/THING'));

        $this->assertSame(
            ['/BD3/V1/THING'],
            $seen,
            'The callback did not run for an upper-case route WordPress dispatches happily — '
            . 'the exact defect a consumer hit twice writing this matcher by hand.',
        );
    }

    public function testAnUnrelatedRouteIsUntouched(): void
    {
        $ran = false;
        ntdst_rest('bd4/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'before_dispatch' => static function ($request) use (&$ran) {
                $ran = true;
                return null;
            },
        ]);

        $result = ($this->beforeDispatch())(null, null, $this->request('GET', '/wp/v2/posts'));

        $this->assertFalse($ran, 'The callback ran for a route it does not own.');
        $this->assertNull($result);
    }

    public function testAnAnswerAlreadyGivenIsNotStomped(): void
    {
        $already = new WP_Error('rate_limited', 'slow down', ['status' => 429, 'retry_after' => 60]);

        ntdst_rest('bd5/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'before_dispatch' => static fn($request) => new WP_Error('nope', 'no', ['status' => 415]),
        ]);

        $result = ($this->beforeDispatch())($already, null, $this->request('POST', '/bd5/v1/thing'));

        $this->assertSame($already, $result, 'An earlier filter\'s answer was overwritten.');
    }

    public function testANonCallableBeforeDispatchRefusesTheRouteLoudly(): void
    {
        ntdst_rest('bd6/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'before_dispatch' => 'not a callable',
        ]);

        $this->assertArrayNotHasKey(
            '/bd6/v1/thing',
            $this->registered,
            'A misconfigured before_dispatch registered the route anyway, so the author believes '
            . 'a guard is on that never runs.',
        );
    }
}

/** Minimal request double — the shape NTDST_Rest actually calls. */
final class NtdstBeforeDispatchRequest
{
    public function __construct(private string $method, private string $route)
    {
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }
}
