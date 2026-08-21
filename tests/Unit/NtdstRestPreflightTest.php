<?php // tests/Unit/NtdstRestPreflightTest.php
// F4 — a CORS preflight was never charged.
//
// `guard()` spends budget only when the request method is in the route's
// registered verb list, and an `OPTIONS` preflight never matches `POST`.
// Measured on todai against a clean bucket: 40 consecutive preflights left the
// bucket UNSET, and 5 preflights carrying a 1.1 MB JSON body returned 200 each
// for zero budget — while the identical body as a POST was charged correctly.
// A preflight is not free to serve: WP's rest_handle_options_request() sets a
// matched route, so rest_send_allow_header() invokes the permission callback,
// which for that consumer decoded the body and read an option twice.
//
// THE SHAPE, AND WHY NOT THE OTHER ONE. The tempting fix is to make `$matched`
// cover OPTIONS. It is wrong twice over: WP invokes EVERY sibling handler's
// permission callback to build the Allow header, so a route registered for
// GET+POST+DELETE would charge THREE units for one preflight; and the
// preflight would spend the very POST budget the real request needs a moment
// later, so every CORS write costs two units. Instead the charge happens ONCE
// per request in a pre-dispatch hook, into a bucket of the preflight's own,
// and `$matched` is not touched at all.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestPreflightTest extends TestCase
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
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

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
     * The REAL pre-dispatch filter the class mounted — read from the recording
     * add_filter() in tests/bootstrap.php. Driving the mounted callback is the
     * point: a test that called a method directly would stay green if the
     * `add_filter` line were deleted, which is the exact regression this file
     * has to be able to see.
     */
    private function preDispatch(): callable
    {
        // By PRIORITY, not by hook name. Two callbacks now mount on
        // rest_pre_dispatch — the preflight charge at 5 and before_dispatch at
        // 6 — so reading the hook alone would drive whichever registered first
        // and still pass, which is precisely the regression this file exists
        // to see.
        $this->assertArrayHasKey(
            5,
            $GLOBALS['_ntdst_test_filters_at']['rest_pre_dispatch'] ?? [],
            'NTDST_Rest must mount a rest_pre_dispatch filter at priority 5 to charge preflights.',
        );

        return $GLOBALS['_ntdst_test_filters_at']['rest_pre_dispatch'][5];
    }

    private function request(string $method, string $route): NtdstPreflightRequest
    {
        return new NtdstPreflightRequest($method, $route);
    }

    /** Buckets written for preflights, keyed apart from the verb buckets. */
    private function preflightBuckets(): array
    {
        return array_filter(
            $this->transients,
            static fn($k) => str_starts_with($k, 'ntdst_rest_pf_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function testAPreflightIsCharged(): void
    {
        ntdst_rest('pf1/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 20,
        ]);

        ($this->preDispatch())(null, null, $this->request('OPTIONS', '/pf1/v1/thing'));

        $buckets = $this->preflightBuckets();

        $this->assertCount(1, $buckets, 'A preflight must cost a unit.');
        $this->assertSame(1, (int) reset($buckets));
    }

    public function testAFloodOfPreflightsIsEventuallyRefused(): void
    {
        ntdst_rest('pf2/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 3,
        ]);
        $hook = $this->preDispatch();

        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = $hook(null, null, $this->request('OPTIONS', '/pf2/v1/thing'));
        }

        $this->assertNull($results[0], 'Under the limit the filter passes the request through untouched.');
        $this->assertInstanceOf(WP_Error::class, $results[3]);
        $this->assertSame(429, $results[3]->get_error_data()['status'] ?? null);
        $this->assertSame(60, $results[3]->get_error_data()['retry_after'] ?? null);
    }

    public function testThePreflightDoesNotSpendTheRealRequestsBudget(): void
    {
        // The reason for a separate bucket. A client that preflights and then
        // POSTs must not pay twice, and a preflight flood must not lock the
        // caller out of writing.
        ntdst_rest('pf3/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 1,
        ]);
        $hook = $this->preDispatch();

        $hook(null, null, $this->request('OPTIONS', '/pf3/v1/thing'));

        $permission = $this->registered['/pf3/v1/thing']['permission_callback'];

        $this->assertTrue(
            $permission($this->request('POST', '/pf3/v1/thing')),
            'The single POST unit must still be there after the preflight.',
        );
    }

    public function testARouteThatDeclaredNoRateLimitChargesNoPreflight(): void
    {
        // Rate limiting is opt-in per route and stays opt-in. A consumer
        // declares nothing new to get preflight charging — and nothing new to
        // opt out of it either.
        ntdst_rest('pf4/v1')->post('/free', fn() => [], ['permission' => static fn() => true]);

        $result = ($this->preDispatch())(null, null, $this->request('OPTIONS', '/pf4/v1/free'));

        $this->assertNull($result);
        $this->assertSame([], $this->preflightBuckets());
    }

    public function testANonOptionsRequestIsNotChargedByTheHook(): void
    {
        // The hook sees EVERY REST request on the site. It may only act on a
        // preflight; the verb buckets are guard()'s job and must not be
        // double-charged from here.
        ntdst_rest('pf5/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 20,
        ]);

        $result = ($this->preDispatch())(null, null, $this->request('POST', '/pf5/v1/thing'));

        $this->assertNull($result);
        $this->assertSame([], $this->preflightBuckets());
    }

    public function testAnotherPluginsNamespaceIsUntouched(): void
    {
        ntdst_rest('pf6/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 20,
        ]);

        $result = ($this->preDispatch())(null, null, $this->request('OPTIONS', '/wc/v3/orders'));

        $this->assertNull($result, 'A route this package never registered is none of its business.');
        $this->assertSame([], $this->preflightBuckets());
    }

    public function testTheRouteMatchIsCaseInsensitiveLikeWordPressOwn(): void
    {
        // WP matches routes with preg_match('@^…$@i'). A scope check that is
        // case-SENSITIVE silently stops running for `/PF7/V1/THING` while WP
        // happily dispatches it — the exact bug that took a consumer's CORS
        // correction offline and restored WP core's reflect-any-origin default.
        ntdst_rest('pf7/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 20,
        ]);

        ($this->preDispatch())(null, null, $this->request('OPTIONS', '/PF7/V1/THING'));

        $this->assertCount(1, $this->preflightBuckets(), 'WP would dispatch this route; so must the charge.');
    }

    public function testAResultAnotherFilterAlreadyProducedIsRespected(): void
    {
        ntdst_rest('pf8/v1')->post('/thing', fn() => [], [
            'permission' => static fn() => true,
            'rate_limit' => 20,
        ]);

        $already = new WP_REST_Response(['handled' => true]);
        $result = ($this->preDispatch())($already, null, $this->request('OPTIONS', '/pf8/v1/thing'));

        $this->assertSame($already, $result, 'Short-circuit whatever ran before us, and charge nothing.');
        $this->assertSame([], $this->preflightBuckets());
    }
}

/** A WP_REST_Request double carrying just the method and route the hook reads. */
final class NtdstPreflightRequest
{
    public function __construct(private string $method, private string $route) {}

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }
}
