<?php // tests/Unit/DownloadDenialStatusTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

if (!function_exists('add_filter')) {
    function add_filter(...$args) { return true; }
}
require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Actions.php';

// The WP_REST_Request/WP_REST_Response/WP_Error doubles are declared once, in
// DownloadDispatchTest.php, which the Unit suite loads first alphabetically
// (same convention DownloadQueryParamsTest.php follows).
require_once __DIR__ . '/DownloadDispatchTest.php';

/**
 * Stride Phase-2 B3 review, finding C1 (Critical):
 *
 * handle_download() DISCARDS the return value of
 * apply_filters("ntdst/api_download/{$action}", null, $params) — a handler
 * that refuses with `new WP_Error('forbidden', 'msg', ['status' => 403])`
 * never reaches the wire. Every denial ships as a 500 `download_not_emitted`
 * instead of the status/code/message the handler declared.
 *
 * Compare handle_action() (Endpoints.php ~589-597), which reads the
 * WP_Error's `data['status']` and forwards code + message. handle_download()
 * must honour the same convention for the exact reason handle_action() does:
 * a denial is not a server fault, and collapsing it to 500 poisons
 * monitoring with false application-error signal.
 *
 * These four tests assert the denial contract handle_download() currently
 * breaks. (a)-(c) are RED against main — the handler's WP_Error is silently
 * discarded and every one of them observes 500/download_not_emitted instead.
 * (d) is the non-regression guard: a handler that returns a non-WP_Error
 * value without emitting must still 500 with download_not_emitted — that
 * behaviour must NOT change.
 */
final class DownloadDenialStatusTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** A minimal WP_REST_Request double carrying params. */
    private function request(array $params): WP_REST_Request
    {
        return new WP_REST_Request($params);
    }

    /** Read the error code the dispatcher put in the api envelope. */
    private function errorCode(WP_REST_Response $r): ?string
    {
        return $r->get_data()['data']['code'] ?? null;
    }

    /** Read the error message the dispatcher put in the api envelope. */
    private function errorMessage(WP_REST_Response $r): ?string
    {
        return $r->get_data()['data']['message'] ?? $r->get_data()['message'] ?? null;
    }

    private function stubDenyingHandler(WP_Error $error): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(true);
        Functions\when('apply_filters')->alias(
            fn($hook, $v, $p = null) => str_starts_with((string) $hook, 'ntdst/api_download/') ? $error : $v,
        );
    }

    // (a) Handler-declared 403 must reach the wire, not collapse to 500.
    public function test_handler_forbidden_status_reaches_the_wire(): void
    {
        $this->stubDenyingHandler(new WP_Error('forbidden', 'Handler says no', ['status' => 403]));

        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($this->request(['action' => 'test_action', 'nonce' => 'good']));

        $this->assertSame(403, $res->get_status(), 'a handler WP_Error with status=403 must ship as 403, not 500');
        $this->assertSame('forbidden', $this->errorCode($res));
        $this->assertSame('Handler says no', $this->errorMessage($res));
    }

    // (b) Handler-declared 404 must also reach the wire — proves the status
    // is READ from the WP_Error, not hardcoded to one denial value.
    public function test_handler_not_found_status_reaches_the_wire(): void
    {
        $this->stubDenyingHandler(new WP_Error('quote_not_found', 'No such quote', ['status' => 404]));

        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($this->request(['action' => 'test_action', 'nonce' => 'good']));

        $this->assertSame(404, $res->get_status(), 'a handler WP_Error with status=404 must ship as 404, not 500');
        $this->assertSame('quote_not_found', $this->errorCode($res));
    }

    // (c) WP_Error with no declared status must fall back to a sensible
    // default — pinned at 403 (a denial, not a server fault), matching the
    // remediation's documented fallback.
    public function test_handler_error_without_status_defaults_to_403(): void
    {
        $this->stubDenyingHandler(new WP_Error('denied', 'No status supplied'));

        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($this->request(['action' => 'test_action', 'nonce' => 'good']));

        $this->assertSame(403, $res->get_status(), 'a WP_Error with no declared status must default to 403, not 500');
        $this->assertSame('denied', $this->errorCode($res));
    }

    // (d) NON-REGRESSION GUARD: a handler that returns a non-WP_Error value
    // without emitting (the misconfigured-handler case) must still 500 with
    // download_not_emitted. This must stay green through the fix — it is the
    // "fail loud on a broken handler" contract, distinct from "a handler
    // deliberately denied."
    public function test_non_wp_error_return_without_emission_still_500s(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(true);
        Functions\when('apply_filters')->alias(fn($hook, $v, $p = null) => $v);

        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($this->request(['action' => 'test_action', 'nonce' => 'good']));

        $this->assertSame('download_not_emitted', $this->errorCode($res));
        $this->assertSame(500, $res->get_status());
    }
}
