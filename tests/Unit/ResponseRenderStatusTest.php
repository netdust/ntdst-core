<?php // tests/Unit/ResponseRenderStatusTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';

/**
 * Regression contract for the render-and-exit status commit.
 *
 * render() exits, so it cannot be called directly under phpunit; the status
 * commit it performs before exiting lives in the protected commitRenderStatus()
 * seam (mirroring how Router made commitOk()/resolveRouteResult() protected so
 * tests can seam them). We drive that seam plus the error-path accessors.
 *
 * The bug this pins: before the fix, render() emitted a body without committing
 * a status, so a route callback that rendered shipped its page under the 404
 * WordPress pre-set for the unmatched URL. The fix makes render() commit its
 * own status (like json() already does) — 200 for a normal render, the 4xx for
 * an error/notFound render.
 */
final class ResponseRenderStatusTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { unset($GLOBALS['wp_query']); Monkey\tearDown(); parent::tearDown(); }

    /** Expose the protected commit seam. */
    private function response(): NTDST_Response
    {
        return new class extends NTDST_Response {
            public function commit(): void { $this->commitRenderStatus(); }
        };
    }

    public function test_normal_render_commits_200_and_clears_the_preset_404(): void
    {
        $wpQuery = new class {
            public bool $is_404 = true;
            public function is_404(): bool { return $this->is_404; }
        };
        $GLOBALS['wp_query'] = $wpQuery;

        // The contract: a normal (non-error) render sends 200 and clears the
        // 404 WordPress marked for the unmatched enrollment/dashboard URL.
        Functions\expect('status_header')->once()->with(200);

        $this->response()->commit();

        $this->assertFalse($wpQuery->is_404, 'render must clear the pre-set 404 so the page ships as 200');
    }

    public function test_commit_is_a_safe_noop_on_a_non_404_request(): void
    {
        $wpQuery = new class {
            public function is_404(): bool { return false; }
        };
        $GLOBALS['wp_query'] = $wpQuery;

        // Not a 404 request: still asserts its own 200, never touches is_404.
        Functions\expect('status_header')->once()->with(200);

        $this->response()->commit();
    }

    public function test_error_render_keeps_its_4xx_status_not_200(): void
    {
        // error()/notFound() route render() through renderError(), which commits
        // $this->status — so `error(...,403)->render()` must yield 403, not 200.
        $forbidden = (new NTDST_Response())->error('Nope', 403);
        $this->assertSame(403, $forbidden->getStatus(), 'error() must set the caller status a route can return');

        $notFound = (new NTDST_Response())->notFound();
        $this->assertSame(404, $notFound->getStatus(), 'notFound() must yield 404 so a route can genuinely refuse');
    }
}
