<?php // tests/Unit/CoreShapeCluster4bFeatureTest.php
// core-shape Cluster 4b — FEATURE tests, written from the promise.
//
// The promise (tasks.md ~:121-128, spec FR-9 / FR-11 / SC-5 / SC-6): a page
// route IS a WordPress rewrite rule; ONE template_redirect dispatcher hands a
// PATH back to WordPress or refuses with WordPress's own 404; the download
// header policy asks WordPress for the type and sends nosniff itself.
//
// These cases drive the promise from outside — two routes on one router, a
// request WordPress already parsed, and the bytes that leave downloadHeaders().
// They deliberately do NOT restate what NtdstPagesTest / ResponseTrimTest /
// DownloadHeadersTest already pin (see the report for the dropped list); what
// is here is the multi-route behaviour, the arms those files leave open, the
// two homes of a refusal, and the controller's open finding.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Pages.php';
require_once __DIR__ . '/../../api/Response.php';

final class CoreShapeCluster4bFeatureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<array{0:string,1:string,2:string}> every add_rewrite_rule(), in order */
    private array $rules = [];
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<string, mixed> what WordPress parsed out of the URL */
    private array $queryVars = [];
    /** @var list<int> every status_header() */
    private array $statuses = [];
    /** @var list<array{0:string,1:string}> every _doing_it_wrong() */
    private array $wrong = [];
    private int $nocache = 0;
    private int $nosniff = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->rules = [];
        $this->options = [];
        $this->queryVars = [];
        $this->statuses = [];
        $this->wrong = [];
        $this->nocache = 0;
        $this->nosniff = 0;

        // The recorder bags are process-wide; a case that reads back a mount
        // has to start from an empty one.
        unset(
            $GLOBALS['_ntdst_test_filters'],
            $GLOBALS['_ntdst_test_filters_at'],
            $GLOBALS['_ntdst_test_filter_args'],
            $GLOBALS['wp_query'],
            $GLOBALS['wp_rewrite'],
            $GLOBALS['wp'],
        );

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_rewrite_rule')->alias(function (string $regex, string $query, string $after = 'bottom'): void {
            $this->rules[] = [$regex, $query, $after];
        });
        Functions\when('get_query_var')->alias(fn (string $var, $default = '') => $this->queryVars[$var] ?? $default);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('status_header')->alias(function (int $status): void {
            $this->statuses[] = $status;
        });
        Functions\when('nocache_headers')->alias(function (): void {
            $this->nocache++;
        });
        Functions\when('esc_html')->alias(
            static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'),
        );
        Functions\when('_doing_it_wrong')->alias(function (string $fn, string $message, $version = ''): void {
            $this->wrong[] = [$fn, $message];
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $GLOBALS['wp_query'], $GLOBALS['wp_rewrite'], $GLOBALS['wp']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. Two routes, two rewrite rules
    // -----------------------------------------------------------------------

    public function testTwoRoutesBecomeTwoRewriteRulesWordPressCanParse(): void
    {
        // The promise is that the URL is WordPress's to parse. Two routes on
        // one router are two rules, numbered in registration order, and the
        // placeholder of the second never leaks into the first.
        $pages = $this->router();
        $pages->path('/card', fn (): string => __FILE__);
        $pages->path('/projects/:slug', fn (): string => __FILE__);

        $this->assertSame(
            [
                ['^card/?$', 'index.php?ntdst_page=0', 'top'],
                ['^projects/([^/]+)/?$', 'index.php?ntdst_page=1&ntdst_p_slug=$matches[1]', 'top'],
            ],
            $this->rules,
            'each route is one rewrite rule that names its own index and its own placeholders.',
        );

        $vars = $pages->queryVars(['p', 'name']);

        $this->assertSame(['p', 'name'], array_values(array_intersect($vars, ['p', 'name'])), 'WordPress keeps its own vars.');
        $this->assertContains('ntdst_page', $vars);
        $this->assertContains('ntdst_p_slug', $vars);
        $this->assertSame(
            1,
            count(array_keys($vars, 'ntdst_page', true)),
            'two routes declare the route var ONCE — a repeated var is a rule set that grew a copy per route.',
        );
    }

    public function testEachRouteAnswersOnlyItsOwnIndex(): void
    {
        $seenCard = 0;
        $seenProject = null;

        $pages = $this->router();
        $pages->path('/card', function () use (&$seenCard): string {
            $seenCard++;

            return __FILE__;
        });
        $pages->path('/projects/:slug', function (array $params) use (&$seenProject): string {
            $seenProject = $params;

            return __DIR__ . '/../bootstrap.php';
        });

        $this->dispatch($pages, 1, 'GET', ['slug' => 'de-vloer']);

        $this->assertSame(['slug' => 'de-vloer'], $seenProject, 'the matched route gets exactly the params its rule produced.');
        $this->assertSame(0, $seenCard, 'the other route on the same router must not run.');
        $this->assertSame(
            __DIR__ . '/../bootstrap.php',
            $this->templateIncludeFilter()('/theme/index.php'),
            'the path the matched callback returned is what WordPress includes.',
        );
        $this->assertSame([], $this->statuses, 'WordPress owns the status of a URL it parsed itself.');
    }

    public function testAVerbMismatchRefusesWithoutRunningAnyRoute(): void
    {
        // A matched rule whose verb is wrong is a 404 — and the refusal must
        // not fall through to the neighbouring route that DOES answer GET.
        $wp_query = $this->wpQuery();
        $ran = [];

        $pages = $this->router();
        $pages->path('/card', function () use (&$ran): string {
            $ran[] = 'card';

            return __FILE__;
        });
        $pages->path('/projects/:slug', function () use (&$ran): string {
            $ran[] = 'projects';

            return __FILE__;
        }, 'POST');

        $this->dispatch($pages, 1, 'GET', ['slug' => 'de-vloer']);

        $this->assertSame([], $ran, 'no callback answers a verb neither route declared for this URL.');
        $this->assertTrue($wp_query->notFound, 'a matched rule with the wrong verb is WordPress\'s own 404.');
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter(), 'a refusal mounts no template.');
    }

    // -----------------------------------------------------------------------
    // 2. The template arm the dispatcher must refuse
    // -----------------------------------------------------------------------

    public function testATemplatePathThatDoesNotExistIsRefusedAndNeverHandedToWordPress(): void
    {
        // `include`-ing a path that is not there is a warning inside the
        // theme's render, at HTTP 200, with half a page already sent. The
        // promise is the opposite: say so through _doing_it_wrong() and give
        // WordPress its own 404.
        $missing = sys_get_temp_dir() . '/ntdst-no-such-template-' . bin2hex(random_bytes(6)) . '.php';
        $this->assertFileDoesNotExist($missing, 'the fixture only means anything while the file is absent.');

        $wp_query = $this->wpQuery();

        $pages = $this->router();
        $pages->path('/card', fn (): string => $missing);

        $this->dispatch($pages, 0, 'GET');

        $this->assertNotSame([], $this->wrong, 'a template that is not on disk is worth saying out loud.');
        $this->assertStringContainsString(
            basename($missing),
            $this->wrong[0][1],
            'the warning must name the path it could not find — that is the actionable half.',
        );
        $this->assertTrue($wp_query->notFound, 'a template that does not exist cannot be a 200.');
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter(), 'nothing hands a missing file to WordPress.');
    }

    // -----------------------------------------------------------------------
    // 3. One refusal, wherever it comes from
    // -----------------------------------------------------------------------

    public function testAPageRefusalSendsTheSameThreeLinesResponseSends(): void
    {
        // Two homes write a 404 today — the dispatcher's and
        // NTDST_Response::notFound(). WP::handle_404() has already queued a
        // 200 by the time either runs, so a refusal that skips status_header()
        // or nocache_headers() is a soft 404: the 404 template served at 200,
        // and cached that way by every proxy in front of the site.
        $wp_query = $this->wpQuery();

        $pages = $this->router();
        $pages->path('/card', fn (): bool => false);
        $this->dispatch($pages, 0, 'GET');

        $viaPages = ['set_404' => $wp_query->notFound, 'status' => $this->statuses, 'nocache' => $this->nocache];

        $wp_query = $this->wpQuery();
        $this->statuses = [];
        $this->nocache = 0;
        (new NTDST_Response())->notFound();

        $viaResponse = ['set_404' => $wp_query->notFound, 'status' => $this->statuses, 'nocache' => $this->nocache];

        $this->assertSame(
            ['set_404' => true, 'status' => [404], 'nocache' => 1],
            $viaResponse,
            'NTDST_Response::notFound() writes WordPress\'s three lines.',
        );
        $this->assertSame(
            $viaResponse,
            $viaPages,
            'a page route\'s refusal is the same refusal — two homes that disagree are two 404 contracts.',
        );
    }

    // -----------------------------------------------------------------------
    // 4. The controller's open finding: a route with no matched rule
    // -----------------------------------------------------------------------

    /**
     * @group red-finding
     */
    public function testABareQueryVarHitCannotInvokeAParameterlessRoute(): void
    {
        // `ntdst_page` is a PUBLIC query var, so `/?ntdst_page=0` reaches the
        // dispatcher without ever going through a rewrite rule. For a route
        // with no placeholders the param check has nothing to check, so the
        // front page answers 200 with the card page's body (observed on daan:
        // `/?ntdst_page=0&ntdst_p_x[]=1` → 26530B, byte-identical to `/card/`).
        // Any URL on the site can then invoke any parameterless route:
        // duplicate content at `/`, and a route whose relative links and
        // is_front_page() assumptions are all wrong. Only a request WordPress
        // matched to OUR rule is ours to answer.
        $wp_query = $this->wpQuery();

        $ran = 0;
        $pages = $this->router();
        $pages->path('/card', function () use (&$ran): string {
            $ran++;

            return __FILE__;
        });

        $this->dispatch($pages, 0, 'GET', [], matchedRule: '');

        $this->assertSame(0, $ran, 'a URL that never matched our rule must not run our route.');
        $this->assertTrue($wp_query->notFound, 'a hand-written query var is not a page of ours.');
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter());
    }

    public function testTheSameRouteStillAnswersWhenItsRuleDidMatch(): void
    {
        // The other half: refusing the bare query var must not refuse the real
        // URL. `/card/` matched `^card/?$`, so route 0 answers.
        $wp_query = $this->wpQuery();

        $ran = 0;
        $pages = $this->router();
        $pages->path('/card', function () use (&$ran): string {
            $ran++;

            return __FILE__;
        });

        $this->dispatch($pages, 0, 'GET', [], matchedRule: '^card/?$');

        $this->assertSame(1, $ran, 'the route WordPress matched is the route that answers.');
        $this->assertFalse($wp_query->notFound);
        $this->assertSame(__FILE__, $this->templateIncludeFilter()('/theme/index.php'));
    }

    // -----------------------------------------------------------------------
    // 5. The download wire (FR-11 / SC-6)
    // -----------------------------------------------------------------------

    /**
     * @dataProvider addedTypeProvider
     */
    public function testTheFourTypesWordPressLacksReachTheDownloadWire(string $filename, string $expectSubstring): void
    {
        // daan streams a vCard and an SVG through this policy. WordPress's own
        // table has no row for json, xml, vcf or svg, so without core's
        // `mime_types` addition every one of them leaves as
        // application/octet-stream — the browser downloads the vCard instead of
        // opening it, and nothing renders. The wire is the assertion; the table
        // is where the answer comes from.
        $this->stubMimeWire();

        $headers = $this->headersFor(97, $filename);

        $type = $headers['content-type'];
        $declared = $this->declaredTypeFor($filename);

        $this->assertNotSame('application/octet-stream', $type, "{$filename} must resolve to a real type.");
        $this->assertStringContainsString($expectSubstring, $type);
        $this->assertSame(
            str_starts_with($declared, 'text/') ? $declared . '; charset=utf-8' : $declared,
            $type,
            'the wire says what WordPress\'s filtered table says, and text/* carries the charset the policy owns.',
        );
        $this->assertSame('97', $headers['content-length'], 'the length is the caller\'s, byte for byte.');
        $this->assertStringContainsString('filename="' . basename($filename) . '"', $headers['content-disposition']);
        $this->assertStringContainsString("filename*=UTF-8''", $headers['content-disposition'], 'the RFC 5987 form ships too.');
        $this->assertSame(1, $this->nosniff, 'nosniff is sent for every download, once.');
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function addedTypeProvider(): array
    {
        return [
            'json' => ['export.json', 'json'],
            'xml' => ['feed.xml', 'xml'],
            'vcf' => ['daan.vcf', 'vcard'],
            'svg' => ['logo.svg', 'svg'],
        ];
    }

    // -----------------------------------------------------------------------
    // 6. SC-5: nothing in the package overrules WordPress's own verdict
    // -----------------------------------------------------------------------

    public function testNothingInCoreOrApiUnDoesWordPressesOwnVerdictOnAUrl(): void
    {
        // The two moves FR-9 removed, as a source fact rather than a habit:
        // clearing is_404 leaves a page WordPress judged missing rendering at
        // 200, and answering redirect_canonical turns off the canonical
        // redirect for the whole site to keep one hand-matched URL alive.
        $offenders = [];

        foreach ([__DIR__ . '/../../core', __DIR__ . '/../../api'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match('/is_404\s*=\s*false|redirect_canonical/', $line) === 1) {
                        $offenders[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'WordPress parses the URL now — nothing in core/ or api/ overrules its verdict.');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** A router whose terminator is observable instead of ending the process. */
    private function router(): NTDST_Pages
    {
        return new class extends NTDST_Pages {
            protected function terminate(): never
            {
                throw new Cluster4bRequestEnded();
            }
        };
    }

    /** The $wp_query double, installed as the global WordPress refuses through. */
    private function wpQuery(): object
    {
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };

        $GLOBALS['wp_query'] = $wp_query;

        return $wp_query;
    }

    /**
     * Drive one request WordPress already parsed.
     *
     * By default the request carries the matched rule of the route it names —
     * that is what a real URL hit looks like. A case that wants the bare
     * `?ntdst_page=` hit passes matchedRule: ''.
     */
    private function dispatch(
        NTDST_Pages $pages,
        int $index,
        string $method,
        array $params = [],
        ?string $matchedRule = null,
    ): void {
        $this->queryVars['ntdst_page'] = (string) $index;

        foreach ($params as $name => $value) {
            $this->queryVars['ntdst_p_' . $name] = $value;
        }

        $wp = new stdClass();
        $wp->matched_rule = $matchedRule ?? ($this->rules[$index][0] ?? '');
        $GLOBALS['wp'] = $wp;

        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            $pages->dispatch();
        } catch (Cluster4bRequestEnded) {
            // The dispatcher ended the request; the case asserts on what it
            // wrote before it did.
        }
    }

    /** The callback the template_include filter mounted, if any. */
    private function templateIncludeFilter(): ?callable
    {
        $mounts = $GLOBALS['_ntdst_test_filters_at']['template_include'] ?? [];

        return $mounts === [] ? null : reset($mounts);
    }

    /** WordPress's table and its own detection, so the case asserts on the policy. */
    private function stubMimeWire(): void
    {
        Functions\when('send_nosniff_header')->alias(function (): void {
            $this->nosniff++;
        });
        Functions\when('wp_get_mime_types')->alias(static fn (): array => NTDST_Response::mimeTypes([
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'csv' => 'text/csv',
            'txt|asc|c|cc|h|srt' => 'text/plain',
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

    /** What the filtered table declares for this filename, before the policy's charset. */
    private function declaredTypeFor(string $filename): string
    {
        $found = wp_check_filetype($filename)['type'];

        $this->assertIsString($found, "the `mime_types` filter must declare a type for {$filename}.");

        return $found;
    }

    /** @return array<string, string> header name (lowercased) => value */
    private function headersFor(int $length, string $filename): array
    {
        $out = [];

        foreach (NTDST_Response::downloadHeaders($length, $filename) as $header) {
            [$name, $value] = explode(':', $header, 2);
            $out[strtolower(trim($name))] = trim($value);
        }

        return $out;
    }
}

/** The observable end of a request — what this file's terminator raises. */
final class Cluster4bRequestEnded extends RuntimeException
{
}
