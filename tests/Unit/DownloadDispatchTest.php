<?php // tests/Unit/DownloadDispatchTest.php
// SEAM PRESENT: NTDST_Response::fileHeaders() (protected) already returns the
// download header list as a testable array without exit — Task 2's sendFileHeaders
// extraction is NOT needed; Task 3/4 tests assert emission via fileHeaders().
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Actions.php';

// NTDST_Actions error()/success() wrap a WP_REST_Response; the harness has no
// live WP, so provide the minimal shape the dispatcher touches.
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

        // Real WP_REST_Request models the query string as its OWN param source,
        // distinct from JSON/body params — a GET request built from a URL
        // querystring (the shape a real <a href> download link produces) has
        // params ONLY here. Added to reproduce the real double: the prior stub
        // conflated constructor params with JSON params only, so no test could
        // exercise a query-string-shaped request — which is why the missing
        // get_query_params() read in get_request_params() went undetected.
        public function set_query_params(array $params): void { $this->queryParams = $params; }
        public function get_query_params(): array { return $this->queryParams; }
    }
}

/**
 * Characterization contract for the v2.3 GET download dispatch.
 *
 * The gap this plan closes: NTDST_Actions registers only POST /action and
 * POST /get_nonce — there is no GET dispatch entry, so a handler that wants to
 * stream a file via Response::download()/inline() has no framework route to
 * reach. Download actions are therefore forced onto raw wp_ajax today.
 *
 * The minimal bootstrap runs Brain Monkey without a live WP REST server, so we
 * characterize the missing route by inspecting NTDST_Actions' registration
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
        $this->assertTrue(method_exists(NTDST_Actions::class, 'register_download_endpoint'));
        $this->assertTrue(method_exists(NTDST_Actions::class, 'handle_download'));
        $this->assertTrue(method_exists(NTDST_Actions::class, 'check_download_permission'));
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

        $endpoints = new NTDST_Actions();
        $endpoints->handle_download(
            $this->request(['action' => 'test_report', 'nonce' => 'good', 'edition_id' => 42]),
        );

        $this->assertSame('test_report', $reached->action, 'the registered download filter must be dispatched');
        $this->assertSame(42, $reached->params['edition_id'] ?? null, 'request params reach the download handler');
    }

    public function test_download_missing_action_or_nonce_is_rejected(): void
    {
        Functions\when('sanitize_text_field')->returnArg();

        $endpoints = new NTDST_Actions();
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

        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($this->request(['action' => 'test_report', 'nonce' => 'forged']));

        $this->assertSame('invalid_nonce', $this->errorCode($res));
        $this->assertFalse($dispatched, 'a forged nonce must be rejected before the download filter is dispatched');
    }

    public function test_download_unknown_action_is_404(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(false);

        $endpoints = new NTDST_Actions();
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

        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($this->request(['action' => 'test_report', 'nonce' => 'good']));

        $this->assertSame('download_not_emitted', $this->errorCode($res));
        $this->assertSame(500, $res->get_status());
    }


    // =====================================================================
    // Task 4 — security parity: check_download_permission() shares the
    // /action policy (rate-limit + public-action + auth gate). It does NOT
    // apply the Origin/CSRF check /action uses, because a <a href> download
    // is a top-level GET navigation with no Origin header — the per-action
    // nonce (verified in handle_download) is this surface's CSRF gate.
    //
    // These four denial paths ARE the threat-model coverage for the new GET
    // surface. Driven through the real callback with Brain Monkey stubs.
    // =====================================================================

    private function endpointsWithPublicActions(array $public): NTDST_Actions
    {
        // public_actions is read via the ntdst/api/public_actions filter.
        Functions\when('apply_filters')->alias(function ($hook, $value, ...$rest) use ($public) {
            if ($hook === 'ntdst/api/public_actions') { return $public; }
            // rate-limit / window filters pass their default through
            return $value;
        });

        // `stride_quote_pdf` is a REGISTERED download action — its handler is
        // mounted on ntdst/api_download/{action}, which is what a real one
        // looks like. Since F1 the permission gate establishes registration
        // before it builds a rate bucket, so a fixture that never mounts the
        // handler is describing an action that does not exist, and is refused
        // before it reaches the limit / origin / auth question under test.
        Functions\when('has_filter')->alias(
            static fn($hook) => $hook === 'ntdst/api_download/stride_quote_pdf',
        );

        return new NTDST_Actions();
    }

    public function test_download_denies_anonymous_non_public_action(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_user_logged_in')->justReturn(false);

        $endpoints = $this->endpointsWithPublicActions([]); // nothing public
        $decision = $endpoints->check_download_permission($this->request(['action' => 'stride_quote_pdf']));

        $this->assertFalse($decision, 'anonymous caller must be denied a non-public download action');
    }

    public function test_download_allows_anonymous_only_when_action_is_public(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('is_user_logged_in')->justReturn(false);

        $endpoints = $this->endpointsWithPublicActions(['public_flyer']); // explicitly public
        $decision = $endpoints->check_download_permission($this->request(['action' => 'public_flyer']));

        $this->assertTrue($decision, 'an action listed in ntdst/api/public_actions is reachable anonymously');
    }

    public function test_download_rate_limited_returns_429(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('__')->returnArg();
        Functions\when('get_current_user_id')->justReturn(7);
        // At the limit already → consumeRateBudget returns false → 429.
        Functions\when('get_transient')->justReturn(999999);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);

        $endpoints = $this->endpointsWithPublicActions([]);
        $decision = $endpoints->check_download_permission($this->request(['action' => 'stride_quote_pdf']));

        $this->assertInstanceOf(WP_Error::class, $decision, 'a rate-limited download is a WP_Error');
        $this->assertSame(429, $decision->get_error_data()['status'] ?? null);
    }

    public function test_download_authenticated_user_is_allowed(): void
    {
        // The common case: a logged-in admin clicking a download link, no
        // Origin header (GET navigation) — must be allowed. This is exactly
        // the case /action's verifyOrigin() would wrongly deny, proving the
        // download gate correctly omits the origin check.
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(7);
        Functions\when('is_user_logged_in')->justReturn(true);

        $endpoints = $this->endpointsWithPublicActions([]);
        $decision = $endpoints->check_download_permission($this->request(['action' => 'stride_quote_pdf']));

        $this->assertTrue($decision, 'a logged-in user may reach a non-public download without an Origin header');
    }

}
