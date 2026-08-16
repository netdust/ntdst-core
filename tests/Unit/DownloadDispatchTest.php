<?php // tests/Unit/DownloadDispatchTest.php
// SEAM PRESENT: NTDST_Response::fileHeaders() (protected) already returns the
// download header list as a testable array without exit — Task 2's sendFileHeaders
// extraction is NOT needed; Task 3/4 tests assert emission via fileHeaders().
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

// Response is not in the bootstrap require list; it runs NTDST_Template_Loader::init()
// (add_filter) at load time, so stub add_filter before requiring it (same as
// ResponseRenderStatusTest).
if (!function_exists('add_filter')) {
    function add_filter(...$args) { return true; }
}
require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Endpoints.php';

// NTDST_Endpoints error()/success() wrap a WP_REST_Response; the harness has no
// live WP, so provide the minimal shape the dispatcher touches.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(private array $params = []) {}
        public function get_json_params(): array { return $this->params; }
        public function get_body_params(): array { return []; }
        public function get_param(string $k): mixed { return $this->params[$k] ?? null; }
        public function get_file_params(): array { return []; }
    }
}

/**
 * Characterization contract for the v2.3 GET download dispatch.
 *
 * The gap this plan closes: NTDST_Endpoints registers only POST /action and
 * POST /get_nonce — there is no GET dispatch entry, so a handler that wants to
 * stream a file via Response::download()/inline() has no framework route to
 * reach. Download actions are therefore forced onto raw wp_ajax today.
 *
 * The minimal bootstrap runs Brain Monkey without a live WP REST server, so we
 * characterize the missing route by inspecting NTDST_Endpoints' registration
 * surface directly (its public register_* methods), not via rest_get_server().
 * Task 3 flips this into a real registration assertion.
 */
final class DownloadDispatchTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_download_dispatch_entry_is_registered(): void
    {
        // v2.3 adds the download registrar + handler + permission callback,
        // wired into register_routes() alongside the nonce + action endpoints.
        $this->assertTrue(method_exists(NTDST_Endpoints::class, 'register_download_endpoint'));
        $this->assertTrue(method_exists(NTDST_Endpoints::class, 'handle_download'));
        $this->assertTrue(method_exists(NTDST_Endpoints::class, 'check_download_permission'));
    }

    public function test_response_already_has_a_testable_header_seam(): void
    {
        // Task 2 gate: download()/inline() exit, but fileHeaders() is a protected
        // pure function returning the header list — assertable without exit. This
        // is the seam Task 3/4 use to prove a download emits the right headers.
        $seam = new class extends NTDST_Response {
            /** @return list<string> */
            public function headersFor(string $content, string $filename, ?string $ct, string $disp): array
            {
                return $this->fileHeaders($content, $filename, $ct, $disp);
            }
        };

        $headers = $seam->headersFor('PDFBYTES', 'report.pdf', 'application/pdf', 'attachment');

        $this->assertContains('Content-Type: application/pdf', $headers);
        $this->assertContains('X-Content-Type-Options: nosniff', $headers);
        $this->assertTrue(
            (bool) preg_grep('/^Content-Disposition: attachment; filename="report\.pdf"/', $headers),
            'a download commits an attachment Content-Disposition with the given filename',
        );
    }

    // =====================================================================
    // Task 3 — GET /download dispatch (handle_download)
    //
    // No live WP REST server in this harness (Task 1), so we drive the real
    // callback handle_download() directly with a WP_REST_Request double and
    // Brain Monkey stubs. The dispatch logic — nonce gate, has_filter gate,
    // filter dispatch, fail-loud-on-return — IS the unit under test; error
    // paths return a WP_REST_Response we read directly.
    // =====================================================================

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

    public function test_registered_download_action_dispatches_its_filter(): void
    {
        // Happy path: valid nonce + a registered ntdst/api_download/{action}
        // filter dispatches to the handler. In production the handler emits via
        // Response::download() and exits; the test records that it was reached.
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(true);

        $reached = new stdClass();
        $reached->action = null;
        $reached->params = null;
        Functions\when('apply_filters')->alias(function ($hook, $unused, $params = null) use ($reached) {
            if ($hook === 'ntdst/api_download/test_report') {
                $reached->action = 'test_report';
                $reached->params = $params;
            }
            return $unused; // handler "returns" (can't exit in a test)
        });

        $endpoints = new NTDST_Endpoints();
        $endpoints->handle_download(
            $this->request(['action' => 'test_report', 'nonce' => 'good', 'edition_id' => 42]),
        );

        $this->assertSame('test_report', $reached->action, 'the registered download filter must be dispatched');
        $this->assertSame(42, $reached->params['edition_id'] ?? null, 'request params reach the download handler');
    }

    public function test_download_missing_action_or_nonce_is_rejected(): void
    {
        Functions\when('sanitize_text_field')->returnArg();

        $endpoints = new NTDST_Endpoints();
        $res = $endpoints->handle_download($this->request(['action' => '', 'nonce' => '']));

        $this->assertSame('missing_params', $this->errorCode($res));
    }

    public function test_download_bad_nonce_never_dispatches(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(false);
        $dispatched = false;
        Functions\when('apply_filters')->alias(function ($hook, $v) use (&$dispatched) {
            if (str_starts_with((string) $hook, 'ntdst/api_download/')) { $dispatched = true; }
            return $v;
        });

        $endpoints = new NTDST_Endpoints();
        $res = $endpoints->handle_download($this->request(['action' => 'test_report', 'nonce' => 'forged']));

        $this->assertSame('invalid_nonce', $this->errorCode($res));
        $this->assertFalse($dispatched, 'a forged nonce must be rejected before the download filter is dispatched');
    }

    public function test_download_unknown_action_is_404(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(false);

        $endpoints = new NTDST_Endpoints();
        $res = $endpoints->handle_download($this->request(['action' => 'no_such', 'nonce' => 'good']));

        $this->assertSame('unknown_action', $this->errorCode($res));
        $this->assertSame(404, $res->get_status());
    }

    public function test_download_handler_that_returns_instead_of_emitting_is_500(): void
    {
        // Fail-loud: a download handler MUST emit via Response and exit. If it
        // returns (misconfigured), the dispatcher sends 500, never a blank 200.
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(true);
        Functions\when('apply_filters')->alias(fn($hook, $v, $p = null) => $v);

        $endpoints = new NTDST_Endpoints();
        $res = $endpoints->handle_download($this->request(['action' => 'test_report', 'nonce' => 'good']));

        $this->assertSame('download_not_emitted', $this->errorCode($res));
        $this->assertSame(500, $res->get_status());
    }

}
