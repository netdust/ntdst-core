<?php // tests/Unit/ResponseTrimTest.php
// FR-11 / SC-6 — NTDST_Response keeps only what WordPress has no word for.
//
// What goes, and why WordPress already says it:
//   json() / jsonPayload()      → wp_send_json_success() / wp_send_json_error(),
//                                 or a REST route through ntdst_rest().
//   render() / renderError() /  → a page callback returns a PATH
//   getErrorHtml() /              (NTDST_Template_Loader::page()); nothing
//   commitRenderStatus()          renders-and-exits, and nothing un-404s a
//                                 request WordPress already judged (INV-6).
//   $mimeTypes / getMimeType() / → wp_get_mime_types() + wp_check_filetype().
//   registerMimeType()            Core adds the FOUR types that table lacks
//                                 through the `mime_types` filter (INV-5).
//   ntdst_redirect()            → wp_safe_redirect($url); exit;
//
// What stays is the file-download HEADER POLICY (DownloadHeadersTest owns it,
// byte for byte), redirect(), and html() — rendering a named template into a
// string, which WordPress has no single call for.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';

final class ResponseTrimTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $theme;
    private string $custom;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $base = sys_get_temp_dir() . '/ntdst-t11-' . bin2hex(random_bytes(6));
        $this->theme = $base . '/theme';
        $this->custom = $base . '/custom';

        foreach ([$this->theme, $this->custom] as $dir) {
            mkdir($dir, 0o777, true);
        }

        $this->resetLoader();

        $theme = $this->theme;
        Functions\when('get_stylesheet_directory')->justReturn($theme);
        Functions\when('get_template_directory')->justReturn($theme);
        Functions\when('locate_template')->justReturn('');
    }

    protected function tearDown(): void
    {
        $this->resetLoader();
        unset($GLOBALS['wp_query']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. The surface: what is gone
    // -----------------------------------------------------------------------

    /**
     * @dataProvider removedMethodProvider
     */
    public function testTheRemovedMethodsAreGoneFromResponse(string $method): void
    {
        $this->assertFalse(
            method_exists(NTDST_Response::class, $method),
            "NTDST_Response::{$method}() is a v5.0.0 removal — WordPress answers it.",
        );
    }

    /** @return array<string, list<string>> */
    public static function removedMethodProvider(): array
    {
        return [
            // The JSON envelope: wp_send_json_success() / wp_send_json_error().
            'json' => ['json'],
            'jsonPayload' => ['jsonPayload'],
            // The render-and-exit half: a callback returns a path (INV-6).
            'render' => ['render'],
            'renderError' => ['renderError'],
            'getErrorHtml' => ['getErrorHtml'],
            'commitRenderStatus' => ['commitRenderStatus'],
            // The MIME table: wp_get_mime_types() (INV-5).
            'getMimeType' => ['getMimeType'],
            'registerMimeType' => ['registerMimeType'],
            // The second template registry, gone with T09 and pinned here too.
            'addPath' => ['addPath'],
        ];
    }

    public function testResponseKeepsNoMimeTable(): void
    {
        $this->assertFalse(
            (new ReflectionClass(NTDST_Response::class))->hasProperty('mimeTypes'),
            'NTDST_Response::$mimeTypes duplicated wp_get_mime_types() (INV-5).',
        );
    }

    public function testTheGlobalRedirectHelperIsGone(): void
    {
        // Two ways to redirect were one too many: ntdst_response()->redirect()
        // survives because it carries the error query-arg contract; the bare
        // helper was wp_safe_redirect() with an exit spelled twice.
        $this->assertFalse(function_exists('ntdst_redirect'));
    }

    public function testTheSurvivingSurfaceIsStillThere(): void
    {
        // The other half of a trim test: what must NOT go with it.
        foreach (['reset', 'with', 'withData', 'error', 'notFound', 'template',
            'getTemplate', 'getStatus', 'redirect', 'html', 'download', 'inline',
            'downloadHeaders', 'mimeTypes', 'uploadMimes', 'init'] as $method) {
            $this->assertTrue(
                method_exists(NTDST_Response::class, $method),
                "NTDST_Response::{$method}() must survive the trim.",
            );
        }

        foreach (['ntdst_response', 'ntdst_page_data', 'ntdst_download', 'ntdst_inline'] as $fn) {
            $this->assertTrue(function_exists($fn), "{$fn}() must survive the trim.");
        }
    }

    public function testTheLoaderKeepsNoReadOnlyCopyOfItsRegistry(): void
    {
        // T11 carry (Cluster 4a simplicity review): getCustomPaths() handed back
        // a copy of $custom_paths with zero readers on the fleet — a second,
        // read-only answer to a question addPath() already owns.
        $this->assertFalse(method_exists(NTDST_Template_Loader::class, 'getCustomPaths'));
    }

    // -----------------------------------------------------------------------
    // 2. Status without rendering
    // -----------------------------------------------------------------------

    public function testErrorIsStatusAndMessageOnly(): void
    {
        $response = (new NTDST_Response())->error('Nope', 403);

        $this->assertSame(403, $response->getStatus());
        // No output, no exit, no error HTML: error() records a decision. The
        // rendering half (renderError/getErrorHtml) is what left.
        $this->assertSame('', $this->capture(static fn () => $response->getStatus()));
    }

    public function testNotFoundHandsTheRefusalToWordPress(): void
    {
        // Before T11 the 404 was a FLAG a route set and something else honoured.
        // WP::handle_404() has already run by then, so the flag alone leaves a
        // 200 on the wire — WordPress's own set_404() is the call that refuses.
        $wpQuery = new class {
            public bool $set = false;

            public function set_404(): void
            {
                $this->set = true;
            }
        };
        $GLOBALS['wp_query'] = $wpQuery;

        $response = (new NTDST_Response())->notFound();

        $this->assertSame(404, $response->getStatus());
        $this->assertTrue($wpQuery->set, 'notFound() must call $wp_query->set_404().');
    }

    public function testNotFoundSurvivesAnAbsentQueryObject(): void
    {
        // The denial path: a route can refuse before WordPress built $wp_query
        // (CLI, an early hook). It must set the status and never fatal.
        unset($GLOBALS['wp_query']);

        $this->assertSame(404, (new NTDST_Response())->notFound()->getStatus());
    }

    // -----------------------------------------------------------------------
    // 3. html(): WordPress includes the template
    // -----------------------------------------------------------------------

    public function testHtmlRendersThroughLoadTemplateInsideABuffer(): void
    {
        $file = $this->custom . '/card.php';
        file_put_contents($file, '<?php echo "CARD";');
        NTDST_Template_Loader::addPath($this->custom);

        // The contract: WordPress's own include (load_template) inside our
        // buffer, with the merged data as its third argument — never an
        // extract() of a caller array in this file (INV-6).
        Functions\expect('load_template')
            ->once()
            ->with($file, false, ['a' => 1, 'b' => 2])
            ->andReturnUsing(static function (): void {
                echo 'CARD';
            });

        $html = (new NTDST_Response())->with('b', 2)->html('card', ['a' => 1]);

        $this->assertSame('CARD', $html);
    }

    public function testHtmlReturnsAnEmptyStringWhenNothingResolves(): void
    {
        // Fail closed and say so. It used to return a red error <div> built by
        // getErrorHtml() — core markup nobody could style, echoed into a page.
        Functions\expect('load_template')->never();

        $html = (new NTDST_Response())->html('nope-not-here', ['a' => 1]);

        $this->assertSame('', $html);
    }

    public function testHtmlLeavesNoBufferBehindWhenTheTemplateThrows(): void
    {
        $file = $this->custom . '/boom.php';
        file_put_contents($file, '<?php // thrown by the stub');
        NTDST_Template_Loader::addPath($this->custom);

        Functions\when('load_template')->alias(static function (): void {
            echo 'PARTIAL';

            throw new RuntimeException('template blew up');
        });

        $depth = ob_get_level();

        try {
            (new NTDST_Response())->html('boom');
            $this->fail('the template exception must not be swallowed');
        } catch (RuntimeException) {
            // An orphaned buffer swallows the REST of the page: everything
            // echoed after the throw disappears into a buffer nobody closes.
            $this->assertSame($depth, ob_get_level(), 'html() must not leak an output buffer.');
        }
    }

    // -----------------------------------------------------------------------
    // 4. The MIME table is WordPress's (INV-5)
    // -----------------------------------------------------------------------

    public function testMimeTypesAddsOnlyTheFourWordPressLacks(): void
    {
        $types = NTDST_Response::mimeTypes(['pdf' => 'application/pdf']);

        $this->assertSame([
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'vcf' => 'text/vcard',
            'svg' => 'image/svg+xml',
        ], $types);
    }

    public function testMimeTypesNeverOverridesWordPressesOwnAnswer(): void
    {
        // The denial path for a filter that ADDS: if WordPress (or a site)
        // already spells one of the four, core's copy must not win — that is
        // the second table coming back through the filter.
        $types = NTDST_Response::mimeTypes(['svg' => 'text/plain', 'json' => 'text/plain']);

        $this->assertSame('text/plain', $types['svg']);
        $this->assertSame('text/plain', $types['json']);
    }

    public function testTheFilterIsMountedAtLoadTime(): void
    {
        // Re-run the load-time mount: the recorder bag is process-wide and
        // other files clear it, so the assertion is on what init() DOES.
        NTDST_Response::init();

        $this->assertSame(
            [NTDST_Response::class, 'mimeTypes'],
            $GLOBALS['_ntdst_test_filters']['mime_types'] ?? null,
            'mimeTypes() must be mounted on the `mime_types` filter — that is the convergence point.',
        );
    }

    public function testCoreDoesNotWidenWhatASiteAcceptsOnUpload(): void
    {
        // `mime_types` is ALSO the base of get_allowed_mime_types(), so adding
        // svg there would make every uploader an SVG uploader — stored XSS in
        // the site origin, from a header-policy change. Core takes its four
        // back off the upload list; a site that wants SVG uploads still says so
        // itself with its own `upload_mimes` filter.
        NTDST_Response::init();

        $allowed = NTDST_Response::uploadMimes(NTDST_Response::mimeTypes(['pdf' => 'application/pdf']));

        $this->assertSame(['pdf' => 'application/pdf'], $allowed);
        $this->assertSame(
            [NTDST_Response::class, 'uploadMimes'],
            $GLOBALS['_ntdst_test_filters']['upload_mimes'] ?? null,
        );
    }

    public function testAnUnknownExtensionIsOctetStream(): void
    {
        $this->stubMimeFunctions();

        $headers = NTDST_Response::downloadHeaders(9, 'blob.unknownext');

        $this->assertContains('Content-Type: application/octet-stream', $headers);
    }

    public function testTheContentTypeComesFromWordPressesTable(): void
    {
        // The value is WordPress's, not a copy of WordPress's: the table says
        // something no hard-coded list would ever say, and it comes out.
        $this->stubMimeFunctions(['pdf' => 'application/x-answered-by-wordpress']);

        $headers = NTDST_Response::downloadHeaders(3, 'Ünïcode name.pdf');

        $this->assertContains('Content-Type: application/x-answered-by-wordpress', $headers);
        $this->assertContains('Content-Length: 3', $headers);
        $this->assertNotContains('X-Content-Type-Options: nosniff', $headers, 'nosniff is SENT, not returned.');
    }

    public function testATextTypeKeepsTheCharsetTheDownloadPolicyOwns(): void
    {
        // WordPress's table has no charset, and a .ics or .csv saved as latin-1
        // mangles every accent in it. The rule is the POLICY's, not a MIME
        // table: it adds no type and overrides none.
        $this->stubMimeFunctions(['ics' => 'text/calendar']);

        $this->assertContains(
            'Content-Type: text/calendar; charset=utf-8',
            NTDST_Response::downloadHeaders(9, 'agenda.ics'),
        );
    }

    public function testACallerSuppliedTypeIsSentExactlyAsGiven(): void
    {
        $this->stubMimeFunctions();

        $this->assertContains(
            'Content-Type: text/vcard; charset=utf-8',
            NTDST_Response::downloadHeaders(12, 'daan.vcf', 'text/vcard; charset=utf-8'),
        );
    }

    public function testNosniffIsSentThroughWordPress(): void
    {
        Functions\expect('send_nosniff_header')->once();
        Functions\when('wp_get_mime_types')->justReturn([]);
        Functions\when('wp_check_filetype')->justReturn(['ext' => false, 'type' => false]);

        NTDST_Response::downloadHeaders(9, 'kit.zip');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** WordPress's own wp_check_filetype algorithm, over a small real table. */
    private function stubMimeFunctions(array $table = []): void
    {
        $table = $table ?: ['pdf' => 'application/pdf', 'zip' => 'application/zip', 'csv' => 'text/csv'];

        Functions\when('send_nosniff_header')->justReturn(null);
        // wp_get_mime_types() applies the `mime_types` filter itself, so the
        // stub models WordPress by running core's callback over the table.
        Functions\when('wp_get_mime_types')->alias(static fn (): array => NTDST_Response::mimeTypes($table));
        Functions\when('wp_check_filetype')->alias(static function (string $filename, $mimes = null): array {
            foreach (($mimes ?: wp_get_mime_types()) as $exts => $type) {
                if (preg_match('!\.(' . $exts . ')$!i', $filename, $m) === 1) {
                    return ['ext' => strtolower($m[1]), 'type' => $type];
                }
            }

            return ['ext' => false, 'type' => false];
        });
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    private function resetLoader(): void
    {
        $class = new ReflectionClass(NTDST_Template_Loader::class);

        foreach (['custom_paths', 'template_cache', 'page_data'] as $name) {
            if ($class->hasProperty($name)) {
                $property = $class->getProperty($name);
                $property->setAccessible(true);
                $property->setValue(null, []);
            }
        }
    }
}
