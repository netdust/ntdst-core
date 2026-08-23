<?php // tests/Unit/DownloadHeadersTest.php
// `NTDST_Response::downloadHeaders()` — the header policy, without the body.
//
// `download()` / `inline()` take `string $content` and `echo` it. That is right
// for a vCard or an invoice and impossible for a large archive: daan's press
// kit streams a ZIP of a few hundred megabytes chunked from a handle, and can
// never hold it whole. Until now such a caller had to re-derive core's header
// policy by hand — and PressKitService did, arriving independently at
// Content-Type, Content-Length and BOTH filename forms, and missing
// `X-Content-Type-Options: nosniff`. Correct in three headers, wrong in the
// fourth, which is what re-derivation looks like every time.
//
// So the seam is the POLICY, not the emission. Core does not learn to stream;
// a streaming caller borrows the header vocabulary and emits its own bytes.
// `fileHeaders()` only ever needed the content to call `strlen()` on it, so
// this takes the length instead and `fileHeaders()` now delegates.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';

final class DownloadHeadersTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // T11: the type comes from WordPress's table and nosniff goes out
        // through WordPress's emitter, so both are stubbed here — the table
        // with WordPress's own rows (alternation keys and all), and the check
        // with WordPress's own algorithm, so this file asserts on the policy
        // and not on an approximation of WordPress.
        $GLOBALS['_ntdst_test_nosniff'] = 0;
        Functions\when('send_nosniff_header')->alias(static function (): void {
            $GLOBALS['_ntdst_test_nosniff']++;
        });
        Functions\when('wp_get_mime_types')->alias(static fn (): array => NTDST_Response::mimeTypes([
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'csv' => 'text/csv',
            'txt|asc|c|cc|h|srt' => 'text/plain',
            'ics' => 'text/calendar',
        ]));
        Functions\when('wp_check_filetype')->alias(static function (string $filename, $mimes = null): array {
            foreach (($mimes ?: wp_get_mime_types()) as $exts => $type) {
                if (preg_match('!\.(' . $exts . ')$!i', $filename, $m) === 1) {
                    return ['ext' => strtolower($m[1]), 'type' => $type];
                }
            }

            return ['ext' => false, 'type' => false];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, string> header name (lowercased) => value */
    private function headers(int $length, string $filename, ?string $type = null, string $disposition = 'attachment'): array
    {
        $out = [];
        foreach (NTDST_Response::downloadHeaders($length, $filename, $type, $disposition) as $h) {
            [$name, $value] = explode(':', $h, 2);
            $out[strtolower(trim($name))] = trim($value);
        }

        return $out;
    }

    public function testTheLengthComesFromTheArgumentNotFromABody(): void
    {
        // The whole point: a caller that never materialises the body can still
        // send a truthful Content-Length.
        $h = $this->headers(734003200, 'press-kit.zip', 'application/zip');

        $this->assertSame('734003200', $h['content-length'], 'A 700 MB archive, and not one byte held.');
    }

    public function testNosniffIsAlwaysSent(): void
    {
        // The header a hand-rolled block forgets. A body whose bytes look like
        // HTML or SVG must never be sniffed into executing markup in the site
        // origin — and an archive of user-supplied assets is exactly that risk.
        //
        // Since T11 it is SENT rather than returned: WordPress's own
        // send_nosniff_header() fires inside downloadHeaders(), so a streaming
        // caller that emits only some of the returned lines still cannot drop
        // it. The wire is unchanged; the way it cannot be lost is stronger.
        $this->assertArrayNotHasKey('x-content-type-options', $this->headers(10, 'kit.zip'));
        $this->assertSame(1, $GLOBALS['_ntdst_test_nosniff'], 'send_nosniff_header() must fire exactly once.');
    }

    public function testBothFilenameFormsAreSent(): void
    {
        // `filename=` is the ASCII fallback; `filename*=UTF-8''` is what keeps
        // a name like `Daniël` intact, because a header value is read as
        // latin-1 otherwise.
        $d = $this->headers(10, 'Daniël-perskit.zip')['content-disposition'];

        $this->assertStringContainsString('attachment;', $d);
        // TWO underscores: the ASCII fallback substitutes per BYTE, and `ë` is
        // two bytes in UTF-8. Not a defect — the fallback only has to be a
        // legal ASCII name, and `filename*` is what carries the real one.
        $this->assertStringContainsString('filename="Dani__l-perskit.zip"', $d);
        $this->assertStringContainsString("filename*=UTF-8''Dani%C3%ABl-perskit.zip", $d);
    }

    public function testTheFilenameCannotOpenASecondHeaderOrCloseTheValueEarly(): void
    {
        $d = $this->headers(10, "kit\r\nX-Evil: 1\".zip")['content-disposition'];

        $this->assertStringNotContainsString("\r", $d);
        $this->assertStringNotContainsString("\n", $d);
        $this->assertSame(2, substr_count($d, '"'), 'Exactly the pair that quotes the ASCII filename.');
    }

    public function testAPathIsStrippedToItsBasename(): void
    {
        $d = $this->headers(10, '../../wp-config.php')['content-disposition'];

        $this->assertStringContainsString('filename="wp-config.php"', $d);
        $this->assertStringNotContainsString('..', $d);
    }

    public function testTheDispositionIsTheCallersChoice(): void
    {
        $this->assertStringStartsWith('inline;', $this->headers(10, 'a.pdf', null, 'inline')['content-disposition']);
        $this->assertStringStartsWith('attachment;', $this->headers(10, 'a.pdf')['content-disposition']);
    }

    public function testTheContentTypeIsDetectedFromTheNameWhenNotGiven(): void
    {
        $this->assertSame('application/zip', $this->headers(10, 'kit.zip')['content-type']);
        $this->assertSame('text/vcard', $this->headers(10, 'x.vcf', 'text/vcard')['content-type']);
    }

    public function testFileHeadersStillAgreesWithItExactly(): void
    {
        // fileHeaders() now delegates. If the two ever diverge, one of the two
        // download paths silently stops matching the other — which is the
        // duplication this seam exists to remove, reintroduced inside core.
        $content = 'PK' . str_repeat("\0", 4094);

        $seam = new class extends NTDST_Response {
            /** @return list<string> */
            public function viaFileHeaders(string $c, string $f, ?string $t, string $d): array
            {
                return $this->fileHeaders($c, $f, $t, $d);
            }
        };

        foreach ([['kit.zip', null, 'attachment'], ['Daniël.pdf', 'application/pdf', 'inline']] as [$name, $type, $disp]) {
            $this->assertSame(
                NTDST_Response::downloadHeaders(strlen($content), $name, $type, $disp),
                $seam->viaFileHeaders($content, $name, $type, $disp),
                "fileHeaders() must be downloadHeaders(strlen(\$content)) for {$name}",
            );
        }
    }
}
