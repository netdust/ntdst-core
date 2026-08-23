<?php // tests/Unit/NtdstPagesTest.php
// T01 — NTDST_Router becomes NTDST_Pages and its HTTP-verb methods become path().
//
// WHY the rename: get()/post() on this class register a FRONT-END PAGE pattern
// matched on a request method. The package is gaining ntdst_rest(), where get()
// must mean an HTTP GET resource route. Two meanings of get() one method apart
// is the collision this task removes at its source, so after T01 an HTTP verb
// in this codebase means a REST route and nothing else.
//
// T10 (core-shape FR-9 / INV-6) — a page route is a WordPress REWRITE RULE.
// path() no longer compiles a private regex and re-matches REQUEST_URI inside
// template_include after WordPress already gave up on the URL: it hands the
// pattern to add_rewrite_rule(), names its placeholders on the query_vars
// filter, and dispatches on template_redirect from get_query_var(). WordPress
// parses the URL, so nothing un-404s a request and nothing suppresses
// redirect_canonical. A callback returns a template PATH and never exits.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Pages.php';

final class NtdstPagesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<array{0:string,1:string,2:string}> every add_rewrite_rule() call, in order */
    private array $rules = [];
    /** @var array<string, mixed> the option store */
    private array $options = [];
    /** @var array<string, mixed> what WordPress parsed out of the URL */
    private array $queryVars = [];
    /** @var list<bool> the $hard argument of every flush_rewrite_rules() call */
    private array $flushes = [];
    /** @var list<int> every status_header() call */
    private array $statuses = [];
    /** @var list<array{0:string,1:string}> every _doing_it_wrong() call */
    private array $wrong = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->rules = [];
        $this->options = [];
        $this->queryVars = [];
        $this->flushes = [];
        $this->statuses = [];
        $this->wrong = [];

        // The filter recorder is a GLOBAL bag the bootstrap writes for the
        // whole process; a case that reads back what a mount did has to start
        // from an empty one.
        unset(
            $GLOBALS['_ntdst_test_filters'],
            $GLOBALS['_ntdst_test_filters_at'],
            $GLOBALS['_ntdst_test_filter_args'],
            $GLOBALS['wp_query'],
            $GLOBALS['wp_rewrite'],
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
        Functions\when('flush_rewrite_rules')->alias(function (bool $hard = true): void {
            $this->flushes[] = $hard;
        });
        Functions\when('status_header')->alias(function (int $status): void {
            $this->statuses[] = $status;
        });
        Functions\when('nocache_headers')->justReturn(null);
        // Not returnArg(): the escape is the assertion in
        // testABadTemplatePathIsEscapedIntoTheWarning, so the stub has to be
        // WordPress's own algorithm rather than a pass-through.
        Functions\when('esc_html')->alias(
            static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'),
        );
        Functions\when('_doing_it_wrong')->alias(function (string $fn, string $message, $version = ''): void {
            $this->wrong[] = [$fn, $message];
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $GLOBALS['wp_query'], $GLOBALS['wp_rewrite']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Drive one request WordPress already parsed.
     *
     * T10: the URL is no longer the router's input — WordPress matched the
     * rewrite rule and put the route's index in `ntdst_page`, so a dispatch is
     * that query var plus the request method.
     */
    private function dispatch(NTDST_Pages $pages, int $index, string $method, array $params = []): void
    {
        $this->queryVars['ntdst_page'] = (string) $index;

        // The placeholders WordPress parsed. They arrive as query vars, which
        // is also how a hand-written `?ntdst_p_slug[]=x` arrives — so this is
        // the entry the param check has to be sound over, not the rule's regex.
        foreach ($params as $name => $value) {
            $this->queryVars['ntdst_p_' . $name] = $value;
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $pages->dispatch();
    }

    /** A router whose terminator is observable instead of ending the process. */
    private function terminatingPages(): NTDST_Pages
    {
        return new class extends NTDST_Pages {
            protected function terminate(): never
            {
                throw new NtdstPagesTerminated();
            }
        };
    }

    /** The callback the template_include filter mounted, if any. */
    private function templateIncludeFilter(): ?callable
    {
        $mounts = $GLOBALS['_ntdst_test_filters_at']['template_include'] ?? [];

        return $mounts === [] ? null : reset($mounts);
    }

    public function testAPathIsARewriteRuleAndDispatchesOnTemplateRedirect(): void
    {
        $seen = null;
        $pages = new NTDST_Pages();
        $pages->path('/card/:slug', function (array $params) use (&$seen): string {
            $seen = $params;

            return __FILE__;
        });

        // 1. The URL is WordPress's to parse: ONE rule, at the top of the list.
        $this->assertSame(
            [['^card/([^/]+)/?$', 'index.php?ntdst_page=0&ntdst_p_slug=$matches[1]', 'top']],
            $this->rules,
            'path() must register the pattern as a rewrite rule that names the route and its placeholders.',
        );

        // 2. A query var WordPress does not know is a var it drops.
        $vars = $pages->queryVars(['p']);
        $this->assertContains('p', $vars, 'the filter must keep the vars WordPress already had.');
        $this->assertContains('ntdst_page', $vars);
        $this->assertContains('ntdst_p_slug', $vars);

        // 3. Dispatch is template_redirect over get_query_var().
        $this->queryVars['ntdst_p_slug'] = 'ace-of-cups';
        $this->dispatch($pages, 0, 'GET');

        $this->assertSame(['slug' => 'ace-of-cups'], $seen, 'the callback receives its named placeholders.');

        // 4. The string return becomes the template, through ONE filter — no
        //    render, no exit, no is_404 flipping.
        $filter = $this->templateIncludeFilter();
        $this->assertNotNull($filter, 'a resolved route must answer template_include.');
        $this->assertCount(1, $GLOBALS['_ntdst_test_filters_at']['template_include']);
        $this->assertSame(__FILE__, $filter('/tmp/theme/index.php'));
        $this->assertSame([], $this->statuses, 'WordPress owns the status of a URL it parsed itself.');
    }

    public function testAPlaceholderFirstSegmentIsRefused(): void
    {
        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/:anything', function () use (&$ran): string {
            $ran++;

            return __FILE__;
        });

        $this->assertSame([], $this->rules, '`^([^/]+)/?$` at the top of the rule list swallows every URL on the site.');
        $this->assertNotSame([], $this->wrong, 'a refused pattern says so through _doing_it_wrong().');

        $this->dispatch($pages, 0, 'GET');
        $this->assertSame(0, $ran, 'a refused pattern registers no route to dispatch.');
    }

    public function testAFalseReturnHandsTheRequestBackToWordPressAsNotFound(): void
    {
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $wp_query;

        $pages = new NTDST_Pages();
        $pages->path('/card/:slug', fn (): bool => false);

        $this->dispatch($pages, 0, 'GET');

        $this->assertTrue($wp_query->notFound, 'a route that refuses asks WordPress for its own 404.');
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter(), 'a refusal mounts no template.');
    }

    public function testAHandledReturnStopsTheWordPressRender(): void
    {
        // C-1. `null`/`true` means "the callback wrote the response itself".
        // Returning out of template_redirect leaves WordPress to render the
        // query it already resolved, so the theme's blog index is appended to
        // bytes that were already sent — after a Content-Length, after a
        // vCard. The ONE dispatcher ends the request, the way WordPress's own
        // template_redirect consumers do.
        foreach ([null, true] as $handled) {
            $pages = $this->terminatingPages();
            $pages->path('/card/:slug', fn () => $handled);

            $terminated = false;

            try {
                $this->dispatch($pages, 0, 'GET', ['slug' => 'ace-of-cups']);
            } catch (NtdstPagesTerminated) {
                $terminated = true;
            }

            $this->assertTrue(
                $terminated,
                'a handled return must end the request; anything else renders the resolved query after it.',
            );
            $this->assertNull($this->templateIncludeFilter(), 'a handled request mounts no template.');
        }
    }

    public function testANullReturnIsNotARefusal(): void
    {
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $wp_query;

        $pages = $this->terminatingPages();
        $pages->path('/card/:slug', fn (): ?string => null);

        $this->expectException(NtdstPagesTerminated::class);

        try {
            $this->dispatch($pages, 0, 'GET', ['slug' => 'ace-of-cups']);
        } finally {
            $this->assertFalse($wp_query->notFound, 'null means the callback answered; it is not a refusal.');
            $this->assertSame([], $this->statuses);
            $this->assertNull($this->templateIncludeFilter());
        }
    }

    /**
     * @dataProvider malformedParamProvider
     */
    public function testAQueryVarSuppliedParamMustLookLikeTheRuleWouldHaveProducedIt(
        array $params,
        string $why,
    ): void {
        // C-2. `ntdst_page` and `ntdst_p_*` are PUBLIC query vars — the
        // query_vars filter has to name them for the rewrite rule to survive,
        // and that also lets anyone hand-write them onto any URL on the site.
        // `/?ntdst_page=0&ntdst_p_slug[]=x` never went through the rule, so the
        // value never went through `([^/]+)`. The dispatcher checks the shape
        // the rule would have produced before it calls anybody.
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $wp_query;

        $ran = 0;
        $pages = $this->terminatingPages();
        $pages->path('/card/:slug', function () use (&$ran): string {
            $ran++;

            return __FILE__;
        });

        $this->dispatch($pages, 0, 'GET', $params);

        $this->assertSame(0, $ran, "the callback must not run: {$why}.");
        $this->assertTrue($wp_query->notFound, "a param the rule could not have produced is a 404: {$why}.");
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function malformedParamProvider(): array
    {
        return [
            'an array' => [['slug' => ['x']], '`?ntdst_p_slug[]=x` is not a string the regex could match'],
            'a slash' => [['slug' => 'a/b'], '`([^/]+)` cannot produce a value containing a slash'],
            'empty' => [['slug' => ''], '`([^/]+)` matches one character or more'],
            'absent' => [[], 'every declared placeholder is present in a URL the rule matched'],
        ];
    }

    public function testAResponseReturnFromAPathCallbackWarnsAndRefuses(): void
    {
        // I-1. README promises a _doing_it_wrong() for the retired contract,
        // and a page callback returning an NTDST_Response (daan CardService,
        // stride x5) fell to a SILENT 404 instead — the one signal that tells
        // an adopter what broke, missing at exactly the call site that broke.
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $wp_query;

        $pages = $this->terminatingPages();
        $pages->path('/card/:slug', fn (): object => new stdClass());

        $this->dispatch($pages, 0, 'GET', ['slug' => 'ace-of-cups']);

        $this->assertNotSame([], $this->wrong, 'an object return is the retired contract; say so out loud.');
        $this->assertStringContainsString(
            'stdClass',
            $this->wrong[0][1],
            'the warning must name the type it got — that is the actionable half.',
        );
        $this->assertSame(
            $this->templateFromWarning(),
            $this->wrong[0][1],
            'ONE message for "a callback returned something that is not a path" — two copies are two contracts.',
        );
        $this->assertTrue($wp_query->notFound, 'the request still refuses; the warning is added, not swapped in.');
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter());
    }

    public function testABadTemplatePathIsEscapedIntoTheWarning(): void
    {
        // S-4. The path in the message is a value the CALLBACK produced, and
        // _doing_it_wrong() output is HTML.
        $GLOBALS['wp_query'] = new class {
            public function set_404(): void {}
        };

        $pages = $this->terminatingPages();
        $pages->path('/card/:slug', fn (): string => '/no/such/<script>x</script>.php');

        $this->dispatch($pages, 0, 'GET', ['slug' => 'ace-of-cups']);

        $this->assertNotSame([], $this->wrong);
        $this->assertStringContainsString('&lt;script&gt;', $this->wrong[0][1]);
        $this->assertStringNotContainsString('<script>', $this->wrong[0][1]);
    }

    /** What templateFrom() says for the same fault, read back from the template filter. */
    private function templateFromWarning(): string
    {
        // The probe mounts a template_include filter of its own, and the
        // caller is about to assert that the DISPATCH mounted none — so the
        // recorder is put back exactly as it was found.
        $wrong = $this->wrong;
        $mounts = $GLOBALS['_ntdst_test_filters_at']['template_include'] ?? null;
        $this->wrong = [];
        unset($GLOBALS['_ntdst_test_filters_at']['template_include']);

        $probe = new NTDST_Pages();
        $probe->when(fn (): bool => true, fn () => new stdClass());
        $GLOBALS['_ntdst_test_filters_at']['template_include'][10]('/theme/index.php');

        $message = $this->wrong[0][1];
        $this->wrong = $wrong;
        unset($GLOBALS['_ntdst_test_filters_at']['template_include']);

        if ($mounts !== null) {
            $GLOBALS['_ntdst_test_filters_at']['template_include'] = $mounts;
        }

        return $message;
    }

    public function testTheRuleSetFlushesOnceAndNotAgain(): void
    {
        $pages = new NTDST_Pages();
        $pages->path('/card/:slug', fn (): string => __FILE__);

        $pages->flushWhenRulesChanged();

        $this->assertSame([false], $this->flushes, 'a changed rule set flushes soft, exactly once.');
        $this->assertArrayHasKey('ntdst_pages_rules_hash', $this->options);

        $pages->flushWhenRulesChanged();

        $this->assertSame([false], $this->flushes, 'the same rule set on the next request must flush nothing.');
    }

    public function testAFlushHappensWhenTheLiveRulesLostOurRoutes(): void
    {
        // I-3. The hash answers "did OUR rule set change?" and nothing else.
        // A Permalinks save, or another plugin calling flush_rewrite_rules()
        // on a request where this consumer never registered, rewrites the
        // option WITHOUT our rules — and the hash still matches, so nothing
        // ever flushes again and every page route 404s, silently, forever.
        $pages = new NTDST_Pages();
        $pages->path('/card/:slug', fn (): string => __FILE__);

        // The hash of exactly these rules, already stored: a same-rules run.
        $pages->flushWhenRulesChanged();
        $this->assertSame([false], $this->flushes);

        // What WordPress actually has now — everything except ours.
        $GLOBALS['wp_rewrite'] = new class {
            /** @return array<string, string> */
            public function wp_rewrite_rules(): array
            {
                return ['^feed/?$' => 'index.php?feed=1'];
            }
        };

        $pages->flushWhenRulesChanged();

        $this->assertSame(
            [false, false],
            $this->flushes,
            'rules that are gone from the live set must be flushed back, exactly once, hash or no hash.',
        );
    }

    public function testNoFlushWhenTheLiveRulesStillCarryOurRoutes(): void
    {
        $pages = new NTDST_Pages();
        $pages->path('/card/:slug', fn (): string => __FILE__);

        $pages->flushWhenRulesChanged();

        $GLOBALS['wp_rewrite'] = new class {
            /** @return array<string, string> */
            public function wp_rewrite_rules(): array
            {
                return ['^card/([^/]+)/?$' => 'index.php?ntdst_page=0&ntdst_p_slug=$matches[1]'];
            }
        };

        $pages->flushWhenRulesChanged();

        $this->assertSame(
            [false],
            $this->flushes,
            'the presence check must not turn the hash into a flush on every request.',
        );
    }

    public function testTheRouterNoLongerFightsTheWordPressLoader(): void
    {
        // FR-9 / INV-6: WordPress parses the URL now. Nothing re-matches
        // REQUEST_URI inside template_include, nothing clears is_404, nothing
        // answers redirect_canonical, and the router does not redirect —
        // NTDST_Response owns that word.
        foreach ([
            'handleTemplateInclude',
            'resolveRouteResult',
            'commitOk',
            'renderResponse',
            'preventRedirectForRoutes',
            'redirect',
        ] as $method) {
            $this->assertFalse(
                method_exists(NTDST_Pages::class, $method),
                "NTDST_Pages::{$method}() must not exist — a page URL is a rewrite rule WordPress parses.",
            );
        }
    }

    public function testATemplateCallbackReturnsAPathAndNeverRenders(): void
    {
        $pages = new NTDST_Pages();
        $pages->template('single', fn ($post, $template): string => '/tmp/x/single-gig.php');

        $mount = $GLOBALS['_ntdst_test_filters_at']['single_template'][10] ?? null;
        $this->assertNotNull($mount, 'template() must keep its consumer handler at priority 10.');
        $this->assertSame('/tmp/x/single-gig.php', $mount('/theme/single.php'));

        // A non-string return leaves WordPress's own candidate alone — the
        // callback does not render and does not exit.
        $pages->when(fn (): bool => true, fn () => new stdClass());

        $when = $GLOBALS['_ntdst_test_filters_at']['template_include'][10] ?? null;
        $this->assertNotNull($when);
        $this->assertSame('/theme/index.php', $when('/theme/index.php'));
        $this->assertNotSame([], $this->wrong, 'an object return is the retired Response contract; say so out loud.');
    }

    public function testPathRegistersARouteMatchedOnItsMethod(): void
    {
        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/x', function () use (&$ran) {
            $ran++;
            // Return an existing file path: dispatch() mounts it on
            // template_include. Returning false instead asks WordPress for a
            // 404, which is a different case.
            return __FILE__;
        }, 'POST');

        $this->dispatch($pages, 0, 'POST');

        $this->assertSame(1, $ran, 'path() must dispatch its callback for a matching method.');
    }

    public function testPathDoesNotMatchADifferentMethod(): void
    {
        // I-2. The rule MATCHED — WordPress resolved this URL to our route and
        // nothing else. Returning would leave WordPress rendering whatever the
        // query fell back to (the blog index) with a 200 on a URL that has no
        // GET representation. A matched rule whose verb is wrong is a 404.
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $wp_query;

        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/x', function () use (&$ran) {
            $ran++;

            return __FILE__;
        }, 'POST');

        $this->dispatch($pages, 0, 'GET');

        $this->assertSame(0, $ran, 'A POST-registered page route must not answer a GET.');
        $this->assertTrue($wp_query->notFound, 'a matched rule with the wrong verb is WordPress\'s own 404.');
        $this->assertSame([404], $this->statuses);
        $this->assertNull($this->templateIncludeFilter());
    }

    public function testAnUnknownRouteIndexIsNotOurRequestAtAll(): void
    {
        // The other half of I-2: `ntdst_page=7` with no route 7 is not a
        // matched rule — nothing of ours claimed this URL, so WordPress keeps
        // whatever it resolved. Only a MATCHED route refuses.
        $wp_query = new class {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $wp_query;

        $pages = new NTDST_Pages();
        $pages->path('/x', fn (): string => __FILE__);

        $this->dispatch($pages, 7, 'GET');

        $this->assertFalse($wp_query->notFound, 'a query var naming no route of ours is not ours to refuse.');
        $this->assertSame([], $this->statuses);
    }

    public function testPathDefaultsToGet(): void
    {
        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/y', function () use (&$ran) {
            $ran++;

            return __FILE__;
        });

        $this->dispatch($pages, 0, 'GET');

        $this->assertSame(1, $ran, 'path() without an explicit method must register GET.');
    }

    public function testTheHttpVerbMethodsAreGoneFromThePageRouter(): void
    {
        // The whole point of the rename: get()/post() no longer exist here, so
        // an HTTP verb in this codebase can only mean a REST resource route.
        $this->assertFalse(
            method_exists(NTDST_Pages::class, 'get'),
            'NTDST_Pages::get() must not exist — HTTP verbs belong to ntdst_rest() alone.',
        );
        $this->assertFalse(
            method_exists(NTDST_Pages::class, 'post'),
            'NTDST_Pages::post() must not exist — HTTP verbs belong to ntdst_rest() alone.',
        );
    }

    public function testTemplateHelpersSurviveTheRename(): void
    {
        // The rename must not drop the class's actual job.
        //
        // T10 dropped `redirect` from this list, and the drop is the point
        // rather than an erosion of the pin: it was never a template helper.
        // wp_safe_redirect()-and-exit is a RESPONSE, NTDST_Response::redirect()
        // is the one place that word lives (INV-6 takes `function redirect` to
        // one), and a page router that exits is the contract FR-9 removed.
        // testTheRouterNoLongerFightsTheWordPressLoader() pins its absence.
        foreach (['template', 'single', 'page', 'archive', 'when', 'url'] as $method) {
            $this->assertTrue(
                method_exists(NTDST_Pages::class, $method),
                "NTDST_Pages::{$method}() must survive the rename — this class still routes templates.",
            );
        }
    }
}

/** The observable end of a request — what the test double's terminate() raises. */
final class NtdstPagesTerminated extends RuntimeException
{
}
