<?php // tests/Unit/TemplateLoaderTest.php
declare(strict_types=1);
// core-shape T09 — NTDST_Template_Loader owns template resolution, in its own
// file, and picks from WordPress's candidate list instead of writing its own.
//
// This is the RED contract for spec FR-10 and for INV-5 / INV-6.
//
//   templateInclude()  — hand-listed `single-{type}-{slug}`, `single-{type}`,
//               `single`, `archive-{type}`, `archive` on `template_include` and
//               searched the registry for each. WordPress already computes the
//               full hierarchy ({$type}_template_hierarchy) and hands the
//               finished candidate list to the `{$type}_template` filter as its
//               third argument. The hand-list was a PARTIAL copy of it — no
//               `singular`, no `page`, no taxonomy, no decoded slug — so a
//               registered `page-about.php` was never picked up and a decoded
//               post name never matched. It goes; pickFromCandidates() takes
//               WordPress's list and asks the registry for each name in turn.
//   locateInCustomPaths() — looped the registry itself, with no traversal
//               guard and no cache. It calls locate() now: one search, one
//               guard, one cache (INV-6).
//   NTDST_Response::addPath()/$extra_paths — the SECOND template path
//               registry. The Mailer was its only reader and core-trim moved
//               the Mailer out of the package (FR-9), so the freshness review
//               (2026-08-23) ruled html() keeps its two-parameter signature and
//               Response keeps no registry at all. locate() keeps its own
//               $extraPaths for internal use.
//
// TWO HARNESS FACTS THIS FILE IS BUILT ON, verified against tests/bootstrap.php
// rather than assumed:
//
//   1. `add_filter` is REAL here (bootstrap defines it before Patchwork,
//      because class files mount filters at LOAD time and Brain Monkey cannot
//      patch a function defined that early). It records the callback by hook
//      and priority into $GLOBALS['_ntdst_test_filters_at'] and the accepted-
//      args count into $GLOBALS['_ntdst_test_filter_args']. The load-time
//      record cannot be read back here — four other files legitimately WIPE
//      those bags in their own setUp — so this file clears them and drives
//      init() itself, which is the same call core/TemplateLoader.php makes on
//      its last line.
//   2. `file_exists`/`realpath` are PHP internals and are not redefinable, so
//      every resolution case below runs against REAL files in a real temp
//      directory. That is deliberate for the traversal case: isInside() is a
//      realpath() comparison, and a mocked filesystem would prove nothing
//      about it.
//
// api/Response.php is NOT loadable at unit tier (it is not on the bootstrap's
// require list and declares a dozen global helpers), so the two Response
// clauses are asserted over its SOURCE. bin/guard.sh's METHOD_PINS and
// PackageBootIntegrityTest's removed-symbol sweep are the mechanical halves;
// this is the one that reads in the same file as the rest of the contract.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TemplateLoaderTest extends TestCase
{
    private string $root = '';

    /** A registered plugin template directory. */
    private string $registered = '';

    /**
     * What get_stylesheet_directory()/get_template_directory() answer RIGHT NOW.
     *
     * Live, not frozen: switch_to_blog() and the `stylesheet` filter both move
     * the theme inside a single request, so a case that switches themes just
     * writes this property. A justReturn() would freeze the answer and no test
     * could tell a per-theme cache from a global one.
     */
    private string $themeDir = '';

    /** WordPress's third locate_template() branch, ABSPATH . WPINC . '/theme-compat'. */
    private string $themeCompat = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->root = sys_get_temp_dir() . '/ntdst-tpl-' . bin2hex(random_bytes(6));
        $this->registered = $this->root . '/registered';
        mkdir($this->registered, 0o777, true);

        // The theme half of searchPaths() points at nothing that exists, so a
        // resolution in these tests can only come from the registry — unless a
        // case moves $themeDir (a theme switch) or fills $themeCompat.
        $this->themeDir = $this->root . '/no-such-theme';
        $this->themeCompat = $this->root . '/wp-includes/theme-compat';

        Functions\when('get_stylesheet_directory')->alias(fn (): string => $this->themeDir);
        Functions\when('get_template_directory')->alias(fn (): string => $this->themeDir);
        Functions\when('locate_template')->alias(
            fn ($names, $load = false, $require_once = true): string => $this->locateTemplateStub((array) $names),
        );

        $this->resetLoader();

        $GLOBALS['_ntdst_test_filters'] = [];
        $GLOBALS['_ntdst_test_filters_at'] = [];
        $GLOBALS['_ntdst_test_filter_args'] = [];
        NTDST_Template_Loader::init();
    }

    protected function tearDown(): void
    {
        $this->resetLoader();
        $this->rmrf($this->root);

        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // The move
    // -----------------------------------------------------------------------

    public function testTheLoaderIsDeclaredInItsOwnFileAndNotInResponse(): void
    {
        $declared = (new ReflectionClass(NTDST_Template_Loader::class))->getFileName();

        $this->assertSame(
            realpath(dirname(__DIR__, 2) . '/core/TemplateLoader.php'),
            realpath((string) $declared),
            'NTDST_Template_Loader must be declared in core/TemplateLoader.php.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(final\s+)?class\s+NTDST_Template_Loader/m',
            $this->responseSource(),
            'api/Response.php must not declare the loader any more — Response is the wire shape.',
        );
    }

    // -----------------------------------------------------------------------
    // WordPress computes the hierarchy; core picks from it
    // -----------------------------------------------------------------------

    public function testTheHandListedHierarchyIsGone(): void
    {
        $this->assertFalse(
            method_exists(NTDST_Template_Loader::class, 'templateInclude'),
            'templateInclude() hand-listed a partial copy of WordPress\'s template hierarchy (INV-5).',
        );
    }

    /**
     * @dataProvider hierarchyFilterProvider
     */
    public function testInitMountsWordPressesOwnCandidateFilterWithThreeArgs(string $hook): void
    {
        $mounted = $GLOBALS['_ntdst_test_filters_at'][$hook] ?? [];

        $this->assertNotSame([], $mounted, "init() must mount {$hook} — WordPress hands the candidate list there.");
        $this->assertContains(
            [NTDST_Template_Loader::class, 'pickFromCandidates'],
            array_values($mounted),
            "{$hook} must resolve through pickFromCandidates().",
        );

        $args = $GLOBALS['_ntdst_test_filter_args'][$hook] ?? [];
        $this->assertContains(
            3,
            array_values($args),
            "{$hook} must accept 3 args — with fewer, the callback never sees WordPress's candidate list.",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hierarchyFilterProvider(): array
    {
        return [
            'single_template' => ['single_template'],
            'page_template' => ['page_template'],
            'archive_template' => ['archive_template'],
            'singular_template' => ['singular_template'],
            'index_template' => ['index_template'],
        ];
    }

    public function testInitStillMountsTheThemeFilePathFilter(): void
    {
        $mounted = $GLOBALS['_ntdst_test_filters_at']['theme_file_path'] ?? [];

        $this->assertContains(
            [NTDST_Template_Loader::class, 'locateInCustomPaths'],
            array_values($mounted),
            'theme_file_path must still resolve through the registry.',
        );
    }

    /**
     * The mount sits BEFORE a consumer's own `{$type}_template` handler.
     *
     * NTDST_Pages::template() mounts a consumer callback at WordPress's default
     * 10 (core/Pages.php:153, no priority argument), and a handler a theme
     * wrote by hand must be able to override what the registry picked. So core
     * has to run FIRST and fill the gap; the consumer decides LAST. Nothing
     * pinned the 5 before this case, and the old callback got this backwards —
     * it sat on `template_include` at 99, after every `{$type}_template`
     * filter, and overrode those handlers instead.
     *
     * All five hooks, in one case: the ordering is one decision, not five.
     */
    public function testTheLoaderMountsBeforeAConsumerHandler(): void
    {
        foreach (array_keys(self::hierarchyFilterProvider()) as $hook) {
            $mounted = $GLOBALS['_ntdst_test_filters_at'][$hook] ?? [];
            $priorities = array_keys(array_filter(
                $mounted,
                static fn ($cb): bool => $cb === [NTDST_Template_Loader::class, 'pickFromCandidates'],
            ));

            $this->assertNotSame([], $priorities, "init() must mount pickFromCandidates() on {$hook}.");

            foreach ($priorities as $priority) {
                $this->assertLessThan(
                    10,
                    $priority,
                    "{$hook}: pickFromCandidates() must run before a consumer's own handler, which "
                    . 'NTDST_Pages::template() mounts at WordPress\'s default 10 (core/Pages.php:153, '
                    . 'no priority argument) — core fills the gap first so a hand-written handler can '
                    . 'still override the registry\'s pick.',
                );
            }
        }
    }

    public function testThePickerReturnsTheRegisteredFileForWordPressesOwnCandidate(): void
    {
        $this->writeTemplate($this->registered, 'single-gig.php');
        NTDST_Template_Loader::addPath($this->registered);

        $picked = NTDST_Template_Loader::pickFromCandidates(
            '/theme/single.php',
            'single',
            ['single-gig-x.php', 'single-gig.php', 'single.php'],
        );

        $this->assertSame(
            $this->registered . '/single-gig.php',
            $picked,
            "The registry answers for the most specific candidate WordPress offered.",
        );
    }

    public function testThePickerHandsWordPressesOwnAnswerBackWhenTheRegistryHasNothing(): void
    {
        NTDST_Template_Loader::addPath($this->registered);

        $picked = NTDST_Template_Loader::pickFromCandidates(
            '/theme/single.php',
            'single',
            ['single-gig-x.php', 'single-gig.php', 'single.php'],
        );

        $this->assertSame(
            '/theme/single.php',
            $picked,
            'With no registered candidate the filter returns WordPress\'s own choice untouched.',
        );
    }

    public function testThePickerHonoursWordPressesOrderAndNotItsOwn(): void
    {
        // Both the specific and the generic name are registered. WordPress's
        // list is ordered most-specific-first, and the picker must follow THAT
        // order rather than any order of its own.
        $this->writeTemplate($this->registered, 'single-gig.php');
        $this->writeTemplate($this->registered, 'single.php');
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame(
            $this->registered . '/single-gig.php',
            NTDST_Template_Loader::pickFromCandidates('/theme/single.php', 'single', ['single-gig.php', 'single.php']),
        );

        $this->resetLoader();
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame(
            $this->registered . '/single.php',
            NTDST_Template_Loader::pickFromCandidates('/theme/single.php', 'single', ['single.php', 'single-gig.php']),
            'Reversing WordPress\'s list must reverse the answer — the order is WordPress\'s, not core\'s.',
        );
    }

    public function testThePickerSpellsNoTemplateNameOfItsOwn(): void
    {
        $body = $this->methodSource('pickFromCandidates');

        $this->assertNotSame('', $body, 'pickFromCandidates() must exist in core/TemplateLoader.php.');

        // A hard-coded template name is the defect this task removed. Any
        // `.php` string literal inside the method body is one: the candidate
        // list arrives as an argument, so the method has no reason to spell a
        // filename at all.
        $this->assertSame(
            0,
            preg_match_all('/[\'"][^\'"]*\.php[\'"]/', $this->stripComments($body)),
            "pickFromCandidates() must spell no template name — the list is WordPress's argument:\n" . $body,
        );
    }

    // -----------------------------------------------------------------------
    // One search, one guard
    // -----------------------------------------------------------------------

    public function testLocateFindsARegisteredTemplateWithOrWithoutTheExtension(): void
    {
        $this->writeTemplate($this->registered, 'gig-card.php');
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame($this->registered . '/gig-card.php', NTDST_Template_Loader::locate('gig-card'));
        $this->assertSame($this->registered . '/gig-card.php', NTDST_Template_Loader::locate('gig-card.php'));
    }

    public function testLocateRefusesANameThatTraversesOutOfTheRegisteredDirectory(): void
    {
        // The file REALLY exists one level above the registered directory, so
        // `file_exists()` on the joined path is TRUE and only isInside()'s
        // realpath comparison can refuse it. The positive control below is what
        // makes this test non-vacuous: the same file, reached legally, resolves.
        $this->writeTemplate($this->root, 'passwd.php');
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertFileExists($this->registered . '/../passwd.php');

        $this->assertNull(
            NTDST_Template_Loader::locate('../passwd'),
            'A name that climbs out of a registered directory must not resolve (traversal guard).',
        );
        $this->assertNull(
            NTDST_Template_Loader::locate('../../etc/passwd'),
            'locate("../../etc/passwd") must return null.',
        );

        // Positive control — the guard refuses the traversal, not everything.
        $this->resetLoader();
        NTDST_Template_Loader::addPath($this->root);
        $this->assertSame($this->root . '/passwd.php', NTDST_Template_Loader::locate('passwd'));
    }

    public function testThePickerRefusesATraversingCandidateToo(): void
    {
        $this->writeTemplate($this->root, 'escaped.php');
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame(
            '/theme/single.php',
            NTDST_Template_Loader::pickFromCandidates('/theme/single.php', 'single', ['../escaped.php']),
            'The picker resolves through locate(), so it inherits the traversal guard.',
        );
    }

    public function testLocateInCustomPathsReturnsWhateverLocateReturns(): void
    {
        $this->writeTemplate($this->registered, 'parts/hero.php');
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame(
            NTDST_Template_Loader::locate('parts/hero.php'),
            NTDST_Template_Loader::locateInCustomPaths('/theme/parts/hero.php', 'parts/hero.php'),
            'locateInCustomPaths() must be locate(), not a second loop over the registry.',
        );

        $this->assertSame(
            $this->registered . '/parts/hero.php',
            NTDST_Template_Loader::locateInCustomPaths('/theme/parts/hero.php', 'parts/hero.php'),
        );
    }

    public function testLocateInCustomPathsHandsWordPressesPathBackWhenNothingResolves(): void
    {
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame(
            '/theme/parts/hero.php',
            NTDST_Template_Loader::locateInCustomPaths('/theme/parts/hero.php', 'parts/hero.php'),
        );
    }

    public function testLocateInCustomPathsRefusesATraversingName(): void
    {
        $this->writeTemplate($this->root, 'escaped.php');
        NTDST_Template_Loader::addPath($this->registered);

        $this->assertSame(
            '/theme/escaped.php',
            NTDST_Template_Loader::locateInCustomPaths('/theme/escaped.php', '../escaped.php'),
            'theme_file_path went through an unguarded loop before this task; it goes through locate() now.',
        );
    }

    // -----------------------------------------------------------------------
    // One registry — Response keeps none
    // -----------------------------------------------------------------------

    public function testResponseCarriesNoSecondTemplatePathRegistry(): void
    {
        $source = $this->responseSource();

        $this->assertDoesNotMatchRegularExpression(
            '/function\s+addPath\s*\(/',
            $source,
            'NTDST_Response::addPath() was the second registry; NTDST_Template_Loader::addPath() is the one.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$extra_paths/',
            $source,
            'NTDST_Response::$extra_paths was that registry\'s storage and goes with it.',
        );
    }

    public function testResponseHtmlKeepsItsTwoParameterSignature(): void
    {
        // Freshness-review ruling 2026-08-23: services/Mailer.php left core, so
        // a third $extraPaths parameter would have zero readers (INV-9).
        $this->assertSame(
            1,
            preg_match(
                '/public function html\(string \$template, array \$data = \[\]\): string/',
                $this->responseSource(),
            ),
            'html() takes ($template, $data) and nothing else.',
        );
    }

    public function testLocateKeepsItsOwnExtraPathsForInternalUse(): void
    {
        $extra = $this->root . '/per-call';
        mkdir($extra);
        $this->writeTemplate($extra, 'invoice.php');

        $this->assertSame(
            $extra . '/invoice.php',
            NTDST_Template_Loader::locate('invoice', [$extra]),
        );

        // Per-call directories never populate the shared cache.
        $this->assertNull(NTDST_Template_Loader::locate('invoice'));
    }

    // -----------------------------------------------------------------------
    // One cache, keyed per theme
    // -----------------------------------------------------------------------

    /**
     * A theme switch must MISS the cache (R2-1).
     *
     * $template_cache was keyed by name alone and a hit returned before any
     * bound was re-checked, while themeDirs() is read live. So after
     * switch_to_blog() — or any `stylesheet`/`template` filter change — a name
     * already resolved under theme A kept answering with theme A's file while
     * every other part of the class had moved to theme B. The multisite shape
     * of that bug is one blog rendering another blog's header.
     */
    public function testACacheHitDoesNotSurviveAThemeSwitch(): void
    {
        $themeA = $this->root . '/theme-a';
        $themeB = $this->root . '/theme-b';
        $this->writeTemplate($themeA, 'header.php');
        $this->writeTemplate($themeB, 'header.php');

        $this->themeDir = $themeA;
        $this->assertSame(
            $themeA . '/header.php',
            NTDST_Template_Loader::locate('header'),
            'theme A resolves and populates the cache.',
        );

        $this->themeDir = $themeB;
        $this->assertSame(
            $themeB . '/header.php',
            NTDST_Template_Loader::locate('header'),
            'After a theme switch the cached name must MISS: the cache is keyed per theme, '
            . 'and a hit that predates the switch answers for a theme that is no longer active.',
        );
    }

    /**
     * The switch does not throw the cache away either — theme A's entry is
     * still there, so switching back is a hit and not a re-scan. Keying is the
     * fix; flushing on every locate() would be a different (slower) one.
     */
    public function testSwitchingBackIsStillServedFromTheCache(): void
    {
        $themeA = $this->root . '/theme-a';
        $themeB = $this->root . '/theme-b';
        $this->writeTemplate($themeA, 'header.php');
        $this->writeTemplate($themeB, 'header.php');

        $this->themeDir = $themeA;
        NTDST_Template_Loader::locate('header');
        $this->themeDir = $themeB;
        NTDST_Template_Loader::locate('header');
        $this->themeDir = $themeA;

        $this->assertSame($themeA . '/header.php', NTDST_Template_Loader::locate('header'));
        $this->assertCount(
            2,
            $this->cachedEntries(),
            'One entry per (theme, name): both themes stay cached, neither shadows the other.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * The shared resolved-template cache, read back through reflection.
     *
     * @return array<string, string>
     */
    private function cachedEntries(): array
    {
        $property = (new ReflectionClass(NTDST_Template_Loader::class))->getProperty('template_cache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function resetLoader(): void
    {
        $class = new ReflectionClass(NTDST_Template_Loader::class);
        foreach (['custom_paths' => [], 'template_cache' => [], 'page_data' => []] as $name => $empty) {
            $property = $class->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, $empty);
        }
    }

    /**
     * Real-equivalent of WordPress's locate_template(): the stylesheet dir, the
     * template dir, then ABSPATH . WPINC . '/theme-compat/' — WordPress's own
     * three branches, in its order. The first two are the same directory here;
     * the third is the branch R2-3 pins as REFUSED. None of these directories
     * exists unless a case creates it, so every other case still resolves from
     * the registry and sees '' from the fallthrough, as before.
     *
     * @param list<mixed> $names
     */
    private function locateTemplateStub(array $names): string
    {
        foreach ($names as $name) {
            foreach ([$this->themeDir, $this->themeDir, $this->themeCompat] as $dir) {
                $file = $dir . '/' . ltrim((string) $name, '/');
                if (is_file($file)) {
                    return $file;
                }
            }
        }

        return '';
    }

    private function writeTemplate(string $dir, string $relative): void
    {
        $target = $dir . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o777, true);
        }
        file_put_contents($target, "<?php // fixture\n");
    }

    private function responseSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/api/Response.php');
    }

    /** The source of one method of the loader, brace-matched from its signature. */
    private function methodSource(string $method): string
    {
        if (!method_exists(NTDST_Template_Loader::class, $method)) {
            return '';
        }

        $reflection = new ReflectionMethod(NTDST_Template_Loader::class, $method);
        $lines = file((string) $reflection->getFileName());

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    private function stripComments(string $source): string
    {
        return (string) preg_replace('#//.*$|/\*.*?\*/#ms', '', $source);
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
