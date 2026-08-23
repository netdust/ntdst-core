<?php // tests/Unit/ThemeTrimTest.php
declare(strict_types=1);
// core-trim T09 — NTDST_Theme wires the theme's hooks. It is not a door onto
// the rest of the framework.
//
// This is the RED contract for spec FR-8 and SC-3. Until this task the class
// carried a mixin mechanism and two helpers that each answered a question
// somebody else owns:
//
//   mixin()/__call()/$mixins — wireMixins() proxied `data`, `pages`,
//               `response`, `log` and `mail`, so `$theme->data()` reached the
//               Data layer through a magic method. A __call surface cannot be
//               READ: nothing in the class says which names resolve, so the
//               only way to learn the theme's API is to run it. A theme that
//               wrote `$theme->data()` writes `ntdst_data()` — same object,
//               one hop, named out loud at the call site.
//   when()    — `if ($condition()) { $callback($this); }`. An `if` statement
//               with a fluent return, and PHP already has an `if`.
//   templatePath() — one forwarder onto NTDST_Template_Loader::addPath(), a
//               second public surface that has to track its owner's signature.
//
// `on()` and `filter()` STAY, and that is the deliberate half of this task:
// 21 consumer readers, and they are the chainable-configuration case
// philosophy §5 names (ruled 2026-08-23). This file pins them THROUGH THE
// HOOKS THEY REGISTER, not by reflection — a wrapper that stops calling
// add_action() still passes a method-exists check.
//
// TWO HARNESS FACTS THIS FILE IS BUILT ON, both verified against
// tests/bootstrap.php rather than assumed:
//
//   1. `add_action` is Brain Monkey's, so `has_action()` reports the real
//      priority and the `on()` assertion reads it directly.
//   2. `add_filter` is NOT. tests/bootstrap.php defines a REAL add_filter
//      before Patchwork (its rule 1: class files mount filters at load time),
//      so Brain Monkey never sees a filter and `has_filter()` returns false
//      for one that IS mounted. The bootstrap's own rule says what to do
//      instead — "a test that needs to know what a service DID with one of
//      them reads the global the recorder writes; it does not patch the
//      function" — so the filter() assertion reads
//      $GLOBALS['_ntdst_test_filters_at'][$hook][$priority]. Same contract
//      (the callback is mounted on `the_title` at priority 10), the
//      observation mechanism this harness actually supports.
//
//   3. Functions\expect('ntdst_data')->never() and the same on ntdst_pages
//      are IMPOSSIBLE here, and that shaped the constructor assertion. Both
//      functions are defined by files tests/bootstrap.php requires before
//      Patchwork loads (api/Data.php and core/Pages.php), so an expectation
//      on either raises Patchwork's DefinedTooEarly. ntdst_log is real for
//      the same class of reason, by the bootstrap's own rule. So "the
//      constructor reaches no layer" is read three ways instead — the
//      construction runs FOR REAL (ntdst_response/ntdst_mail are undefined,
//      so reaching either still fatals), the constructor's own source names
//      no helper, and the Logger recorder stays empty.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Theme.php';

final class ThemeTrimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The bootstrap's two recorders are process-wide; a stale entry from an
        // earlier file would make the never-called assertions lie.
        $GLOBALS['_ntdst_test_log'] = [];
        $GLOBALS['_ntdst_test_filters'] = [];
        $GLOBALS['_ntdst_test_filters_at'] = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * SC-3: the class has no __call. Asserted as an EXACT absence list rather
     * than a public-method count, because the count is core-shape FR-12's to
     * change (it deletes style/script/single/page/archive next) and a count
     * here would fail on that task's diff for no reason.
     */
    public function testThemeDeclaresNoMixinMechanismAndNoRemovedHelper(): void
    {
        $class = new ReflectionClass(NTDST_Theme::class);

        foreach (['__call', 'mixin', 'when', 'templatePath', 'wireMixins'] as $method) {
            $this->assertFalse(
                $class->hasMethod($method),
                "NTDST_Theme still declares {$method}() — FR-8 removed it in 5.0.0",
            );
        }

        $this->assertFalse(
            $class->hasProperty('mixins'),
            'NTDST_Theme still carries the $mixins property — FR-8 removed the mechanism it fed',
        );
    }

    /** on() forwards to add_action verbatim, at the priority the caller gave. */
    public function testOnRegistersTheActionAtTheGivenPriority(): void
    {
        $theme = new NTDST_Theme([]);
        $cb = static fn() => null;

        $this->assertSame($theme, $theme->on('init', $cb, 5, 2), 'on() stays chainable');
        $this->assertSame(5, has_action('init', $cb), 'on() must mount the callback at priority 5');
    }

    /** filter() forwards to add_filter verbatim, defaulting to priority 10. */
    public function testFilterRegistersTheCallbackAtItsDefaultPriority(): void
    {
        $theme = new NTDST_Theme([]);
        $cb2 = static fn($title) => $title;

        $this->assertSame($theme, $theme->filter('the_title', $cb2), 'filter() stays chainable');
        $this->assertSame(
            $cb2,
            $GLOBALS['_ntdst_test_filters_at']['the_title'][10] ?? null,
            'filter() must mount the callback on the_title at priority 10',
        );
    }

    /**
     * The behavioural half of FR-8: constructing a theme reaches NO other
     * layer. Before this task the constructor called all five helpers through
     * wireMixins(), so a theme could not exist without the Data, Pages,
     * Response, Logger and Mailer layers being live.
     */
    public function testConstructingAThemeCallsNoFrameworkHelper(): void
    {
        // NOT Functions\expect(...)->never(): ntdst_data() and ntdst_pages()
        // are defined by api/Data.php and core/Pages.php, both loaded before
        // Patchwork, so an expectation on either raises DefinedTooEarly —
        // verified, not assumed. The contract is read three ways instead, and
        // together they are stronger than the expectation would have been.

        // 1. FOR REAL. ntdst_response() and ntdst_mail() are undefined in this
        //    harness, so a constructor that still reached either one fatals
        //    here with "Call to undefined function" — which is exactly how
        //    this test went red before the trim.
        $theme = new NTDST_Theme([]);
        $this->assertInstanceOf(NTDST_Theme::class, $theme);

        // 2. THE CONSTRUCTOR'S OWN BODY names no layer. This is what covers the
        //    two helpers that DO exist here: a constructor calling ntdst_data()
        //    would succeed silently, and only the source says it happened.
        foreach (['ntdst_data(', 'ntdst_pages(', 'ntdst_response(', 'ntdst_log(', 'ntdst_mail('] as $helper) {
            $this->assertStringNotContainsString(
                $helper,
                $this->constructorBody(),
                "NTDST_Theme::__construct() still calls {$helper}) — FR-8 removed wireMixins()",
            );
        }

        // 3. The Logger recorder stayed empty across the construction.
        $this->assertSame(
            [],
            $GLOBALS['_ntdst_test_log'],
            'constructing a theme wrote a log line, so it reached the Logger',
        );
    }

    /** The source of NTDST_Theme::__construct(), comment lines stripped. */
    private function constructorBody(): string
    {
        $ctor = new ReflectionMethod(NTDST_Theme::class, '__construct');
        $file = file($ctor->getFileName() ?: '');
        $this->assertIsArray($file);

        $body = array_slice(
            $file,
            $ctor->getStartLine() - 1,
            $ctor->getEndLine() - $ctor->getStartLine() + 1,
        );

        return implode('', array_filter(
            $body,
            static fn(string $line) => preg_match('#^\s*(\*|//|\#|/\*)#', $line) !== 1,
        ));
    }

    /**
     * The proxy is GONE, not merely unused: `$theme->pages()` used to resolve
     * through __call and hand back NTDST_Pages. With no __call, PHP raises
     * Error for an undefined method — which is the loud failure a consumer
     * needs in order to learn it should write ntdst_pages().
     *
     * (This replaces core-shape T12's mixin-proxy assertion, which asserted
     * the opposite — plan `## Sequencing note`.)
     */
    public function testTheRemovedProxyIsAnUndefinedMethod(): void
    {
        $theme = new NTDST_Theme([]);

        $this->expectException(Error::class);
        $theme->pages();
    }

    /**
     * The mixin helpers leave the FILE, not just the constructor. This is the
     * assertion with real bite for the two helpers no expectation can reach:
     * ntdst_data (DefinedTooEarly) and ntdst_log (real, and no test may patch
     * it — the bootstrap's recorder sees a line being WRITTEN through it, not
     * the function being CALLED).
     *
     * COMMENT LINES ARE EXCLUDED, by the same shape bin/guard.sh's REMOVED
     * sweep uses, and the exclusion is the point rather than a convenience:
     * the class docblock has to write `ntdst_data()->register(...)` in order
     * to answer "what do I write instead", which is the exemption README's
     * migration rows already get. What no shipped line may do is CALL one.
     *
     * ntdst_pages is deliberately NOT swept: single()/page()/archive() forward
     * onto it BY NAME — that is this task's re-route, now that the __call
     * proxy they used to reach is gone — until core-shape FR-12 deletes those
     * three forwarders. Sweeping it would pin a property this task does not
     * promise, and would fail on the very lines T09 wrote.
     */
    public function testTheClassFileCallsNoneOfTheMixinHelpers(): void
    {
        $source = file_get_contents(__DIR__ . '/../../core/Theme.php');
        $this->assertIsString($source);

        $code = array_filter(
            explode("\n", $source),
            static fn(string $line) => preg_match('#^\s*(\*|//|\#|/\*)#', $line) !== 1,
        );
        $code = implode("\n", $code);

        foreach (['ntdst_data(', 'ntdst_response(', 'ntdst_log(', 'ntdst_mail('] as $helper) {
            $this->assertStringNotContainsString(
                $helper,
                $code,
                "core/Theme.php still calls {$helper}) — FR-8 removed the mixin that reached it",
            );
        }
    }
}
