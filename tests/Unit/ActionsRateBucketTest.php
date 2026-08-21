<?php // tests/Unit/ActionsRateBucketTest.php
// F1 — the rate-limit bucket must not be keyed on attacker-chosen input.
//
// `checkRateLimit()` runs from permission_callback, i.e. BEFORE anything has
// asked whether the action exists. With the raw `action` parameter folded into
// the transient key, one varied character per request bought a fresh bucket:
// the site's only API throttle was defeated by a for-loop, and every request
// wrote 2 wp_options rows that only a daily cron reaps.
//
// The contract these tests pin: REGISTRATION IS ESTABLISHED BEFORE THE KEY IS
// BUILT. An unregistered action gets no bucket at all — not a harder hash.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Actions.php';

// The WP doubles are declared once per suite, whichever file loads first —
// same guard idiom as DownloadDispatchTest.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->msg; }
        public function get_error_data(): mixed { return $this->data; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $queryParams = [];

        public function __construct(private array $params = []) {}
        public function get_json_params(): array { return $this->params; }
        public function get_body_params(): array { return []; }
        public function get_param(string $k): mixed { return $this->params[$k] ?? $this->queryParams[$k] ?? null; }
        public function get_file_params(): array { return []; }
        public function set_query_params(array $params): void { $this->queryParams = $params; }
        public function get_query_params(): array { return $this->queryParams; }
    }
}

final class ActionsRateBucketTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> The transient store — every bucket ever written. */
    private array $transients = [];

    /** @var list<string> Hook names has_filter() reports as mounted. */
    private array $mounted = [];

    /** @var list<string> What the ntdst/api/public_actions filter returns. */
    private array $publicActions = [];

    private int $limit = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->transients = [];
        $this->mounted = [];
        $this->publicActions = [];
        $this->limit = 30;

        // A clean anonymous caller: no origin, no referer, no auth cookie —
        // verifyOrigin() allows that shape, so origin never masks a rate-limit
        // result in these tests.
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];
        $_COOKIE = [];

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('__')->returnArg(1);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);

        Functions\when('apply_filters')->alias(function ($hook, $value = null) {
            if ($hook === 'ntdst/api/public_actions') {
                return $this->publicActions;
            }
            if (str_starts_with((string) $hook, 'ntdst/api/rate_limit/')) {
                return $this->limit;
            }
            return $value;
        });

        Functions\when('has_filter')->alias(fn($hook) => in_array($hook, $this->mounted, true));

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

    /** A fresh request object per call: the limiter's memo scope is per request. */
    private function request(array $params): WP_REST_Request
    {
        return new WP_REST_Request($params);
    }

    private function actions(): NTDST_Actions
    {
        return ntdst_actions();
    }

    // =====================================================================
    // The defect itself
    // =====================================================================

    public function testVaryingTheActionParameterMintsNoBuckets(): void
    {
        $actions = $this->actions();

        for ($i = 0; $i < 50; $i++) {
            $actions->check_action_permission($this->request(['action' => "ghost_{$i}"]));
        }

        $this->assertSame(
            [],
            $this->transients,
            'An unregistered action must get no bucket at all — 50 varied parameters wrote '
            . count($this->transients) . ' buckets, so the throttle is a for-loop away from useless.',
        );
    }

    public function testAnUnregisteredActionIsRefused(): void
    {
        $result = $this->actions()->check_action_permission($this->request(['action' => 'ghost']));

        $this->assertFalse(
            $result,
            'A bare false — the same 401 rest_forbidden as an auth denial, so the refusal is no oracle.',
        );
    }

    public function testAnEmptyActionIsRefusedEvenWhenTheEmptyHookIsMounted(): void
    {
        // `register('')` — or any name that sanitizes to nothing — mounts the
        // bare prefix `ntdst/api_data/` as a real hook, so has_filter() would
        // report an EMPTY action as registered and hand it a bucket keyed on
        // nothing. An action that names nothing is not an action.
        $this->mounted = ['ntdst/api_data/'];

        $result = $this->actions()->check_action_permission($this->request(['action' => '']));

        $this->assertFalse($result);
        $this->assertSame([], $this->transients, 'An empty action named nothing; it may not own a bucket.');
    }

    // =====================================================================
    // What must NOT change: a registered action is still counted, and still
    // throttled. A guard that refused everything would pass the tests above.
    // =====================================================================

    public function testARegisteredActionSharesOneBucketAcrossRequests(): void
    {
        $this->mounted = ['ntdst/api_data/real_thing'];
        $this->publicActions = ['real_thing'];

        for ($i = 0; $i < 5; $i++) {
            $allowed = $this->actions()->check_action_permission($this->request(['action' => 'real_thing']));
        }

        $this->assertTrue($allowed, 'Five requests are under the 30 default.');
        $this->assertCount(1, $this->transients, 'One caller, one action, one bucket.');
        $this->assertSame(5, (int) reset($this->transients), 'Every request must spend a unit.');
    }

    public function testTheThrottleStillBitesOnARegisteredAction(): void
    {
        $this->mounted = ['ntdst/api_data/real_thing'];
        $this->publicActions = ['real_thing'];
        $this->limit = 3;

        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = $this->actions()->check_action_permission($this->request(['action' => 'real_thing']));
        }

        $this->assertTrue($results[0]);
        $this->assertInstanceOf(WP_Error::class, $results[3], 'The fourth request is over a limit of 3.');
        $this->assertSame(429, $results[3]->get_error_data()['status'] ?? null);
    }

    public function testAnActionRegisteredONLYByItsMountedFilterIsAccepted(): void
    {
        // Every other /action test here lists the action public AS WELL AS
        // mounting it, so `$isPublic` short-circuits isRegisteredAction()
        // before the filter prefix is ever read — which left the /action door's
        // prefix argument completely unexercised. Two mutants lived there:
        // check_action_permission() passing DOWNLOAD_FILTER, and
        // isRegisteredAction() ignoring $dispatchFilters altogether. This
        // action is registered by NOTHING BUT the mounted data filter.
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        $this->mounted = ['ntdst/api_data/private_thing'];

        $result = $this->actions()->check_action_permission($this->request(['action' => 'private_thing']));

        $this->assertTrue($result, 'A mounted ntdst/api_data/ handler IS registration, public list or not.');
        $this->assertCount(1, $this->transients);
    }

    public function testTheActionDoorRefusesAnActionMountedOnAForeignFilter(): void
    {
        // A handler mounted on some OTHER filter is not an action. This was
        // written when /download dispatched `ntdst/api_download/{action}` and
        // the two doors had to refuse each other's registrations; that surface
        // is gone, but the property survives it — registration means a mounted
        // `ntdst/api_data/` handler and nothing else, and an action that lacks
        // one is refused before any bucket key exists (F1).
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        $this->mounted = ['some/other/filter/export_csv'];

        $result = $this->actions()->check_action_permission($this->request(['action' => 'export_csv']));

        $this->assertFalse($result, 'A handler on a foreign filter is not a registered action.');
        $this->assertSame([], $this->transients, 'And it earns no bucket on this door.');
    }

    public function testAPublicActionIsRegisteredEvenWithNoHandlerMountedYet(): void
    {
        // The `ntdst/api/public_actions` filter is the site's OWN declaration —
        // one of the two registration forms this file already owns, and just as
        // bounded as the mounted-filter list.
        $this->publicActions = ['welcome'];

        $result = $this->actions()->check_action_permission($this->request(['action' => 'welcome']));

        $this->assertTrue($result);
        $this->assertCount(1, $this->transients);
    }

    // =====================================================================
    // M2 — a caller who cannot dispatch may not spend the site's storage
    // =====================================================================

    public function testAnAnonymousCallerRefusedByTheAuthGateWritesNoBucket(): void
    {
        // `private_thing` is registered, so F1's gate lets it through — but an
        // anonymous caller can never dispatch it. Charging first meant every
        // one of those doomed requests wrote 2 wp_options rows on demand, with
        // only a daily cron to reap them. The auth answer is free; the bucket
        // is not, so the free question is asked first.
        $this->mounted = ['ntdst/api_data/private_thing'];

        for ($i = 0; $i < 3; $i++) {
            $result = $this->actions()->check_action_permission($this->request(['action' => 'private_thing']));
        }

        $this->assertFalse($result, 'control: anonymous callers cannot reach a non-public action.');
        $this->assertSame([], $this->transients, 'Three refusals must not write three buckets.');
    }

    public function testTheNonceDoorAlsoRefusesBeforeCharging(): void
    {
        $this->mounted = ['ntdst/api_data/private_thing'];

        $result = $this->actions()->check_nonce_permission($this->request(['action' => 'private_thing']));

        $this->assertFalse($result);
        $this->assertSame([], $this->transients, 'Same rule on the nonce door.');
    }

    public function testAPublicActionIsStillChargedForAnonymousCallers(): void
    {
        // The other half: throttling anonymous traffic on a PUBLIC action is
        // the entire point of the limiter. Moving the auth gate up must not
        // stop charging the callers who can actually reach something.
        $this->publicActions = ['welcome'];
        $this->mounted = ['ntdst/api_data/welcome'];

        $this->actions()->check_action_permission($this->request(['action' => 'welcome']));

        $this->assertCount(1, $this->transients, 'A reachable anonymous caller is still counted.');
    }

    // =====================================================================
    // The other two doors into checkRateLimit()
    // =====================================================================

    public function testTheNonceEndpointMintsNoBucketForAnUnregisteredAction(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);

        $actions = $this->actions();

        for ($i = 0; $i < 20; $i++) {
            $result = $actions->check_nonce_permission($this->request(['action' => "ghost_{$i}"]));
        }

        $this->assertSame([], $this->transients, 'The nonce door keys on the same attacker-chosen string.');
        $this->assertFalse($result, 'The router mints nonces for actions it knows, not for any string.');
    }

}
