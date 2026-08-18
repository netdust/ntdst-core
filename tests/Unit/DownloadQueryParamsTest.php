<?php // tests/Unit/DownloadQueryParamsTest.php
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

// The WP_REST_Request/WP_REST_Response/WP_Error doubles (incl. the
// query-param support get_request_params() needs) are declared once, in
// DownloadDispatchTest.php, which the Unit suite loads first alphabetically.
require_once __DIR__ . '/DownloadDispatchTest.php';

/**
 * REGRESSION: real GET /download requests carry their params as a QUERY
 * STRING (`<a href="...download?action=X&nonce=Y">`), never as a JSON body
 * or form-encoded body — a GET navigation sends no request body at all.
 *
 * get_request_params() (api/Actions.php) reads ONLY get_json_params() and
 * get_body_params(). It never reads get_query_params(). So a real anchor
 * click reaches handle_download() with $params === [] (plus the reserved
 * `_files` key), `action` and `nonce` both resolve to '', and every
 * legitimate download 400s with `missing_params` — found via a real-browser
 * drive in Stride (Task 10): the URL visibly carried `?action=...&nonce=...`
 * and the endpoint still refused it.
 *
 * This test builds the request the way a real anchor click does — query
 * params ONLY, no body/JSON params — and asserts the dispatcher must reach
 * the registered ntdst/api_download/{action} filter. It must NOT also set
 * body params: that would silently paper over exactly the gap being proven.
 */
final class DownloadQueryParamsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Read the error code the dispatcher put in the api envelope. */
    private function errorCode(WP_REST_Response $r): ?string
    {
        return $r->get_data()['data']['code'] ?? null;
    }

    public function test_get_download_with_only_query_params_reaches_the_handler(): void
    {
        // Arrange: the exact shape a real <a href="...?action=X&nonce=Y">
        // click produces on the wire — query params ONLY. No JSON body, no
        // form-encoded body params (a GET navigation sends no body).
        $request = new WP_REST_Request(); // no constructor (JSON) params
        $request->set_query_params([
            'action' => 'test_action',
            'nonce'  => 'good-nonce',
            'edition_id' => 42,
        ]);

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(true);

        $reached = new stdClass();
        $reached->action = null;
        $reached->params = null;
        Functions\when('apply_filters')->alias(function ($hook, $unused, $params = null) use ($reached) {
            if ($hook === 'ntdst/api_download/test_action') {
                $reached->action = 'test_action';
                $reached->params = $params;
            }
            return $unused;
        });

        // Act
        $endpoints = new NTDST_Actions();
        $res = $endpoints->handle_download($request);

        // Assert: the registered download filter for a query-string-only GET
        // must be dispatched. On unfixed code, get_request_params() sees an
        // empty $params (no get_query_params() read), so action/nonce
        // resolve to '' and the dispatcher 400s with 'missing_params' before
        // ever reaching apply_filters('ntdst/api_download/...').
        $this->assertSame(
            'test_action',
            $reached->action,
            'a GET download request carrying params ONLY in the query string must still reach its '
            . 'ntdst/api_download/{action} filter — real <a href> download links never send a body',
        );
        $this->assertSame(42, $reached->params['edition_id'] ?? null, 'query params must reach the download handler');
        // No response-status assertion here: a real handler emits via
        // Response::download()/inline() and exits, which no PHPUnit-safe
        // mock can do — the mocked filter here always falls through to
        // 'download_not_emitted'/500, same as DownloadDispatchTest's
        // test_registered_download_action_dispatches_its_filter (which
        // avoids it for the same reason) and pinned as INTENDED behavior by
        // test_download_handler_that_returns_instead_of_emitting_is_500.
        // Dispatch-reached (asserted above) is the contract this seam can prove.
    }

    public function test_get_request_params_reads_the_query_string_for_a_get_request(): void
    {
        // Narrower unit-level assertion on the same contract, via reflection
        // on the private extractor directly — pins the exact promise:
        // get_request_params() must surface query params, not silently drop
        // them when there is no JSON/body payload (the GET case, always).
        $request = new WP_REST_Request();
        $request->set_query_params(['action' => 'test_action', 'nonce' => 'good-nonce']);

        $endpoints = new NTDST_Actions();
        $method = new \ReflectionMethod(NTDST_Actions::class, 'get_request_params');
        $method->setAccessible(true);
        $params = $method->invoke($endpoints, $request);

        $this->assertSame('test_action', $params['action'] ?? null, 'query-string action must be extracted');
        $this->assertSame('good-nonce', $params['nonce'] ?? null, 'query-string nonce must be extracted');
    }

    // =====================================================================
    // Non-regression guard: POST /action's existing JSON-body-param
    // behavior must be unchanged by the fix. The fix must ADD a query-param
    // read, not blunt-replace the JSON/body read — a caller posting a JSON
    // body to /action must still have those params win.
    // =====================================================================

    public function test_post_action_json_body_params_still_dispatch_unchanged(): void
    {
        // Arrange: a POST /action request carrying its params as JSON body
        // params (the existing, already-working path) — constructor args on
        // the double model get_json_params(), exactly as DownloadDispatchTest
        // already does for handle_download(). No query params set at all.
        $request = new WP_REST_Request(['action' => 'test_action', 'nonce' => 'good-nonce', 'foo' => 'bar']);

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('has_filter')->justReturn(true);

        $reached = new stdClass();
        $reached->action = null;
        $reached->params = null;
        Functions\when('apply_filters')->alias(function ($hook, $unused, $params = null) use ($reached) {
            if ($hook === 'ntdst/api_data/test_action') {
                $reached->action = 'test_action';
                $reached->params = $params;
            }
            return $unused;
        });

        // Act
        $endpoints = new NTDST_Actions();
        $endpoints->handle_action($request);

        // Assert: JSON body params still dispatch exactly as before the fix.
        $this->assertSame('test_action', $reached->action, 'POST /action JSON body params must still dispatch');
        $this->assertSame('bar', $reached->params['foo'] ?? null, 'JSON body params must still reach the handler');
    }
}
