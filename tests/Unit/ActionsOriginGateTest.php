<?php // tests/Unit/ActionsOriginGateTest.php
// H1 — the Origin/CSRF gate had NO test.
//
// `verifyOrigin()` is the only CSRF control on `/ntdst/v1/action`, the only
// state-changing endpoint the router owns. It had zero coverage: mutating it to
// `if (false && $verifyOrigin && !$this->verifyOrigin())` left the whole suite
// green, and the F1 commit changed that function's signature without adding a
// single assertion about it. A control nothing observes is a control nobody can
// keep.
//
// One test per decision branch, denial FIRST:
//   1. a foreign Origin is refused;
//   2. a referer that merely PREFIXES the site URL is refused — the
//      `example.com.evil.com` shape the trailing slash exists to stop;
//   3. absent Origin AND Referer with a WP auth cookie present is refused;
//   4. absent both with NO cookie is allowed (a server-to-server or CLI call);
//   5. the site's own Origin is allowed, on home_url and on site_url;
//   6. a real same-site referer is allowed;
//   7. `ntdst/api/allowed_origins` widens the gate, and only for an exact
//      string match.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Actions.php';

final class ActionsOriginGateTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const HOME = 'https://example.com';

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<string> Origins the ntdst/api/allowed_origins filter returns. */
    private array $allowedOrigins = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->transients = [];
        $this->allowedOrigins = [];

        $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];
        $_COOKIE = [];

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('__')->returnArg(1);
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('home_url')->alias(static fn($path = '') => self::HOME . $path);
        Functions\when('site_url')->alias(static fn($path = '') => self::HOME . $path);

        // The action under test is REGISTERED and PUBLIC, so registration and
        // the auth gate both pass and the ONLY thing that can deny is the
        // origin check. Anything else and this file would be testing two gates.
        Functions\when('apply_filters')->alias(function ($hook, $value = null) {
            if ($hook === 'ntdst/api/public_actions') {
                return ['pay'];
            }
            if ($hook === 'ntdst/api/allowed_origins') {
                return $this->allowedOrigins;
            }
            return $value;
        });
        Functions\when('has_filter')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(0);
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

    /** POST /action — the door that opts INTO the Origin check. */
    private function dispatch(): WP_Error|bool
    {
        return ntdst_actions()->check_action_permission(new WP_REST_Request(['action' => 'pay']));
    }

    // =====================================================================
    // Denials — these are the assertions that make the gate a gate
    // =====================================================================

    public function testAForeignOriginIsRefused(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.net';

        $this->assertFalse($this->dispatch(), 'A cross-site POST must not reach the dispatcher.');
    }

    public function testARefererThatOnlyPrefixesTheSiteUrlIsRefused(): void
    {
        // The whole reason home_url('/') is compared WITH its trailing slash.
        // `https://example.com.evil.com/pay` starts with `https://example.com`
        // and is a different site entirely.
        $_SERVER['HTTP_REFERER'] = self::HOME . '.evil.com/pay';

        $this->assertFalse($this->dispatch(), 'A registrable-domain suffix attack must not pass on prefix match.');
    }

    public function testAnOriginWhoseHostMerelyENDSWithOursIsRefused(): void
    {
        // The Origin axis has the same suffix hazard as the referer axis:
        // `notexample.com` ends with `example.com`, and `evil-example.com`
        // ends with `example.com` too. The comparison is whole-host equality,
        // never a suffix — an attacker registers the longer name in minutes.
        $_SERVER['HTTP_ORIGIN'] = 'https://notexample.com';

        $this->assertFalse($this->dispatch(), 'Origin is matched host-for-host, not by suffix.');
    }

    public function testAnOriginlessRequestCarryingAnAuthCookieIsRefused(): void
    {
        // A browser always sends Origin on a cross-origin credentialed request.
        // Origin absent + auth cookie present is therefore not a browser doing
        // what it says — it is the shape a stripped-header CSRF attempt takes.
        $_COOKIE = ['wordpress_logged_in_abc123' => 'stefan|1234|hash'];

        $this->assertFalse($this->dispatch(), 'No Origin plus a live session must be refused.');
    }

    public function testAnOriginThatMatchesOnlyAsASubstringOfAnAllowedOneIsRefused(): void
    {
        // The filter is an exact allow-list — in_array(..., true). A caller
        // whose origin merely contains an allowed one gets nothing.
        $this->allowedOrigins = ['https://app.example.com'];
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com.evil.net';

        $this->assertFalse($this->dispatch(), 'ntdst/api/allowed_origins matches whole strings only.');
    }

    // =====================================================================
    // Allows — a gate that refuses everything is not a gate either
    // =====================================================================

    public function testTheSitesOwnOriginIsAllowed(): void
    {
        $_SERVER['HTTP_ORIGIN'] = self::HOME;

        $this->assertTrue($this->dispatch());
    }

    public function testARealSameSiteRefererIsAllowed(): void
    {
        $_SERVER['HTTP_REFERER'] = self::HOME . '/checkout';

        $this->assertTrue($this->dispatch());
    }

    public function testNoOriginNoRefererAndNoCookieIsAllowed(): void
    {
        // A CLI or server-to-server caller carries no browser context and no
        // session to ride — there is no CSRF to commit.
        $this->assertTrue($this->dispatch());
    }

    public function testTheAllowedOriginsFilterWidensTheGate(): void
    {
        $this->allowedOrigins = ['https://app.example.com'];
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com';

        $this->assertTrue($this->dispatch(), 'A site may name additional origins explicitly.');
    }

}
