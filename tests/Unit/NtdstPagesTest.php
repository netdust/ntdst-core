<?php // tests/Unit/NtdstPagesTest.php
// T01 — NTDST_Router becomes NTDST_Pages and its HTTP-verb methods become path().
//
// WHY the rename: get()/post() on this class register a FRONT-END PAGE pattern
// matched on a request method. The package is gaining ntdst_rest(), where get()
// must mean an HTTP GET resource route. Two meanings of get() one method apart
// is the collision this task removes at its source, so after T01 an HTTP verb
// in this codebase means a REST route and nothing else.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Pages.php';

final class NtdstPagesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // commitOk() reads the global $wp_query; with none set it is a no-op.
        unset($GLOBALS['wp_query']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function dispatch(NTDST_Pages $pages, string $uri, string $method): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $pages->handleTemplateInclude('/tmp/theme/index.php');
    }

    public function testPathRegistersARouteMatchedOnItsMethod(): void
    {
        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/x', function () use (&$ran) {
            $ran++;
            // Return an existing file path: resolveRouteResult() passes it
            // through as the template. Returning true/null instead makes the
            // router exit(), which would kill the test process.
            return __FILE__;
        }, 'POST');

        $this->dispatch($pages, '/x', 'POST');

        $this->assertSame(1, $ran, 'path() must dispatch its callback for a matching method.');
    }

    public function testPathDoesNotMatchADifferentMethod(): void
    {
        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/x', function () use (&$ran) {
            $ran++;
            return __FILE__;
        }, 'POST');

        $this->dispatch($pages, '/x', 'GET');

        $this->assertSame(0, $ran, 'A POST-registered page route must not answer a GET.');
    }

    public function testPathDefaultsToGet(): void
    {
        $ran = 0;
        $pages = new NTDST_Pages();
        $pages->path('/y', function () use (&$ran) {
            $ran++;
            return __FILE__;
        });

        $this->dispatch($pages, '/y', 'GET');

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
        foreach (['template', 'single', 'page', 'archive', 'when', 'url', 'redirect'] as $method) {
            $this->assertTrue(
                method_exists(NTDST_Pages::class, $method),
                "NTDST_Pages::{$method}() must survive the rename — this class still routes templates.",
            );
        }
    }
}
