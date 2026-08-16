<?php // tests/Unit/DownloadDispatchTest.php
// SEAM PRESENT: NTDST_Response::fileHeaders() (protected) already returns the
// download header list as a testable array without exit — Task 2's sendFileHeaders
// extraction is NOT needed; Task 3/4 tests assert emission via fileHeaders().
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
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

    public function test_no_download_dispatch_entry_exists_before_v23(): void
    {
        // The starting state: Endpoints exposes register_routes() which wires
        // only the nonce + action endpoints. No download registrar/handler yet.
        $this->assertFalse(
            method_exists(NTDST_Endpoints::class, 'register_download_endpoint'),
            'v2.3 will add register_download_endpoint(); it must be absent at the start',
        );
        $this->assertFalse(
            method_exists(NTDST_Endpoints::class, 'handle_download'),
            'v2.3 will add handle_download(); it must be absent at the start',
        );
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
}
