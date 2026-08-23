<?php // tests/Unit/CoreShapeCluster4aFeatureTest.php
// FEATURE tests for core-shape Cluster 4a (FR-10), written from the spec and the
// task brief by the independent test-author — not from the implementation.
//
// The promise under test, stated as behaviour a caller can observe:
//   1. ONE registry, ONE resolver. A file lives in a registered directory, or it
//      does not resolve. Nothing in the class knows the name "single".
//   2. WordPress computes the candidate list; core only PICKS from it, IN
//      WORDPRESS'S ORDER. The first candidate that exists in ANY searched path
//      wins — a more specific candidate found in the theme beats a less specific
//      one found in a registered custom path. Order is the contract; "custom
//      paths always win" is NOT.
//   3. Nothing outside a searched directory is ever returned: `../` in a
//      candidate name and a symlink that escapes a registered path are both
//      refused (isInside()).
//   4. The mount loses to a consumer at priority 10 and wins over the theme's
//      template when no consumer is mounted.
//   5. ONE hand-off: api/Response.php carries no resolver of its own.
//
// Harness notes. PHP internals (file_exists/realpath/glob/is_file) cannot be
// Monkey-patched, so this file builds a REAL theme, a REAL registered directory
// and a REAL escape target under sys_get_temp_dir() and lets the shipped code
// hit the filesystem. Only the three WordPress theme functions are stubbed, and
// locate_template is stubbed as a real-equivalent (first name that exists in the
// stylesheet dir, '' otherwise) so the theme half of the search is honest.
// add_filter is the bootstrap's recorder; applyFilters() below replays the
// recorded bag in WordPress's order (ascending priority, accepted-args
// respected) because Brain Monkey's apply_filters never calls a callback.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class CoreShapeCluster4aFeatureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** Sandbox root. Everything the loader may legitimately reach is under it. */
    private string $root = '';

    /** The theme (stylesheet AND template dir). */
    private string $theme = '';

    /** A directory a plugin registers with addPath(). */
    private string $custom = '';

    /** Deliberately OUTSIDE $root — the traversal/symlink target. */
    private string $outside = '';

    /**
     * The recorder's three bags, as they were when this file started.
     *
     * Bite: every class file mounts its filters ONCE, at bootstrap require
     * time. A test that clears the bags to watch a fresh init() would delete
     * the load-time mounts of every OTHER file too, and the next test file
     * would fail having changed nothing. Snapshot in, restore out.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $filterBagSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $base = sys_get_temp_dir() . '/ntdst-4a-' . bin2hex(random_bytes(6));
        $this->root = $base . '/root';
        $this->theme = $this->root . '/theme';
        $this->custom = $this->root . '/custom';
        $this->outside = $base . '/outside';

        foreach ([$this->theme, $this->custom, $this->outside] as $dir) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($this->outside . '/secret.php', '<?php // must never resolve');
        file_put_contents($this->outside . '/single.php', '<?php // must never resolve');

        $this->resetLoader();

        $this->filterBagSnapshot = [
            'filters' => $GLOBALS['_ntdst_test_filters'] ?? [],
            'at' => $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'args' => $GLOBALS['_ntdst_test_filter_args'] ?? [],
        ];

        $theme = $this->theme;
        Functions\when('get_stylesheet_directory')->justReturn($theme);
        Functions\when('get_template_directory')->justReturn($theme);
        Functions\when('locate_template')->alias(static function ($names, $load = false, $require_once = true) use ($theme) {
            foreach ((array) $names as $name) {
                $file = $theme . '/' . ltrim((string) $name, '/');
                if (is_file($file)) {
                    return $file;
                }
            }

            return '';
        });
    }

    protected function tearDown(): void
    {
        $GLOBALS['_ntdst_test_filters'] = $this->filterBagSnapshot['filters'];
        $GLOBALS['_ntdst_test_filters_at'] = $this->filterBagSnapshot['at'];
        $GLOBALS['_ntdst_test_filter_args'] = $this->filterBagSnapshot['args'];

        $this->resetLoader();
        $this->removeTree(dirname($this->root));
        Monkey\tearDown();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1. WordPress's candidate list, WordPress's order
    // -----------------------------------------------------------------------

    /**
     * A registered directory answers the candidate WordPress asked for, for
     * every one of the five template types the mount covers.
     *
     * The more specific candidate (single-gig-x.php) is absent everywhere; the
     * less specific one (single-gig.php) is in the registered directory. The
     * registered file is what the filter must hand back.
     *
     * @dataProvider templateTypeProvider
     *
     * @param list<string> $candidates
     */
    public function testRegisteredFileAnswersTheCandidateForEveryTemplateType(
        string $type,
        array $candidates,
        string $present
    ): void {
        $this->write($this->custom, $present);
        NTDST_Template_Loader::addPath($this->custom);

        $picked = NTDST_Template_Loader::pickFromCandidates($this->theme . '/index.php', $type, $candidates);

        $this->assertSame(
            $this->custom . '/' . $present,
            $picked,
            "{$type}_template must return the registered file for the candidate WordPress listed"
        );
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: string}> */
    public static function templateTypeProvider(): array
    {
        return [
            'single'   => ['single', ['single-gig-x.php', 'single-gig.php', 'single.php'], 'single-gig.php'],
            'page'     => ['page', ['page-about-x.php', 'page-about.php', 'page.php'], 'page-about.php'],
            'archive'  => ['archive', ['archive-gig-x.php', 'archive-gig.php', 'archive.php'], 'archive-gig.php'],
            'singular' => ['singular', ['singular-gig.php', 'singular.php'], 'singular.php'],
            'index'    => ['index', ['index-x.php', 'index.php'], 'index.php'],
        ];
    }

    // DROPPED as already pinned: "registry empty -> WordPress's own answer comes
    // back untouched" is TemplateLoaderTest::testThePickerHandsWordPressesOwn
    // AnswerBackWhenTheRegistryHasNothing. The EMPTY-REGISTRY / EMPTY-LIST edge
    // below is not pinned there, so it stays.

    /** The empty state: no registered paths at all, and an empty candidate list. */
    public function testEmptyRegistryAndEmptyCandidateListLeaveTheThemeTemplate(): void
    {
        $incoming = $this->theme . '/index.php';

        $this->assertSame([], NTDST_Template_Loader::getCustomPaths(), 'registry starts empty');
        $this->assertSame($incoming, NTDST_Template_Loader::pickFromCandidates($incoming, 'index', []));
        $this->assertSame($incoming, NTDST_Template_Loader::pickFromCandidates($incoming, 'index', ['index.php']));
    }

    /**
     * ORDER IS THE CONTRACT. The theme holds the MORE SPECIFIC candidate
     * (single-gig.php, first in WordPress's list); a registered directory holds
     * only the less specific single.php. WordPress's order says the theme's
     * single-gig.php wins — a loader that scans its own paths first would hand
     * back single.php and silently break every theme override.
     */
    public function testWordPressCandidateOrderBeatsRegistryPrecedence(): void
    {
        $this->write($this->theme, 'single-gig.php');
        $this->write($this->custom, 'single.php');
        NTDST_Template_Loader::addPath($this->custom);

        $picked = NTDST_Template_Loader::pickFromCandidates(
            $this->theme . '/single.php',
            'single',
            ['single-gig.php', 'single.php']
        );

        $this->assertSame(
            $this->theme . '/single-gig.php',
            $picked,
            'the FIRST candidate that exists anywhere wins; registry order must not re-rank WordPress'
        );
    }

    /**
     * No hard-coded template name. An invented type with an invented candidate
     * that nothing answers must NOT fall back to a conventional file that
     * happens to sit in a registered directory.
     */
    public function testAnUnansweredCandidateNeverFallsBackToAConventionalName(): void
    {
        $this->write($this->custom, 'single.php');
        $this->write($this->custom, 'index.php');
        NTDST_Template_Loader::addPath($this->custom);

        $incoming = $this->theme . '/index.php';

        $this->assertSame(
            $incoming,
            NTDST_Template_Loader::pickFromCandidates($incoming, 'ntdst_widget', ['widget-foo.php']),
            'the class must not guess single.php/index.php for a type it was not given'
        );
    }

    /**
     * Re-entry: a MISS must not be cached. A path registered after a failed
     * resolution is seen by the very next call (FR-10 "hit-only cache", the
     * ordering hazard the old resetCachedPaths() papered over).
     */
    public function testAPathRegisteredAfterAMissIsSeenOnTheNextCall(): void
    {
        $incoming = $this->theme . '/single.php';
        $candidates = ['single-gig.php', 'single.php'];

        $this->assertSame($incoming, NTDST_Template_Loader::pickFromCandidates($incoming, 'single', $candidates));

        $this->write($this->custom, 'single-gig.php');
        NTDST_Template_Loader::addPath($this->custom);

        $this->assertSame(
            $this->custom . '/single-gig.php',
            NTDST_Template_Loader::pickFromCandidates($incoming, 'single', $candidates),
            'the registry is live: a miss must not poison the cache'
        );
    }

    // -----------------------------------------------------------------------
    // 2. Refusal: nothing outside a searched directory
    // -----------------------------------------------------------------------

    /**
     * `../` in a candidate name is refused. WordPress never produces such a
     * candidate, so this is the injected one — a plugin filtering the hierarchy,
     * or a slug that survived into a name.
     */
    public function testTraversalInACandidateNameIsRefused(): void
    {
        NTDST_Template_Loader::addPath($this->custom);
        $incoming = $this->theme . '/single.php';

        $picked = NTDST_Template_Loader::pickFromCandidates(
            $incoming,
            'single',
            ['../../outside/secret.php', '../../outside/single.php']
        );

        $this->assertSame($incoming, $picked, 'a ../ candidate must resolve to nothing');
        $this->assertStringNotContainsString('outside', $picked, 'no path outside the sandbox may be returned');
    }

    /**
     * locate() itself refuses the classic traversal, whatever the caller.
     */
    public function testLocateRefusesTraversalOutOfEveryRegisteredPath(): void
    {
        NTDST_Template_Loader::addPath($this->custom);

        $this->assertNull(NTDST_Template_Loader::locate('../../outside/secret'));
        $this->assertNull(NTDST_Template_Loader::locate('../../outside/secret.php'));
    }

    /**
     * A symlink INSIDE a registered directory that points out of the sandbox is
     * refused: the real file is not inside the registered base, so isInside()
     * must reject it even though every path component exists.
     */
    public function testASymlinkEscapingARegisteredPathIsRefused(): void
    {
        if (!@symlink($this->outside, $this->custom . '/escape')) {
            $this->markTestSkipped('symlinks unavailable on this filesystem');
        }

        NTDST_Template_Loader::addPath($this->custom);
        $incoming = $this->theme . '/single.php';

        $this->assertSame(
            $incoming,
            NTDST_Template_Loader::pickFromCandidates($incoming, 'single', ['escape/single.php']),
            'a candidate reached through an escaping symlink must not be served'
        );
        $this->assertNull(
            NTDST_Template_Loader::locate('escape/secret.php'),
            'locate() must refuse the same escape'
        );
    }

    // -----------------------------------------------------------------------
    // 3. The mount: who wins the filter
    // -----------------------------------------------------------------------

    /**
     * With no consumer mounted, the loader's answer is what WordPress ends up
     * including — it beats the theme template the filter arrived with.
     */
    public function testTheMountBeatsTheThemeTemplateWhenNoConsumerIsMounted(): void
    {
        $this->write($this->custom, 'single-gig.php');
        NTDST_Template_Loader::addPath($this->custom);
        $this->clearFilterBag();
        NTDST_Template_Loader::init();

        $result = $this->applyFilters(
            'single_template',
            $this->theme . '/single.php',
            'single',
            ['single-gig.php', 'single.php']
        );

        $this->assertSame($this->custom . '/single-gig.php', $result);
    }

    /**
     * A consumer (NTDST_Pages::template()) mounted at the WordPress default
     * priority 10 has the LAST word. The loader must therefore mount earlier
     * than 10 — a route that owns its page cannot be overruled by the registry.
     */
    public function testAConsumerAtPriorityTenOverrulesTheLoader(): void
    {
        $this->write($this->custom, 'single-gig.php');
        NTDST_Template_Loader::addPath($this->custom);
        $this->clearFilterBag();
        NTDST_Template_Loader::init();

        add_filter('single_template', static fn ($template) => '/routed/by-consumer.php', 10, 3);

        $result = $this->applyFilters(
            'single_template',
            $this->theme . '/single.php',
            'single',
            ['single-gig.php', 'single.php']
        );

        $this->assertSame('/routed/by-consumer.php', $result, 'a consumer at 10 must win the hand-off');
    }

    // DROPPED as already pinned: the mount SHAPE (five {$type}_template hooks +
    // theme_file_path, 3 accepted args) is TemplateLoaderTest::testInitMounts
    // WordPressesOwnCandidateFilterWithThreeArgs and ...testInitStillMountsThe
    // ThemeFilePathFilter — and a mount shape is a config assertion, not a
    // feature. The two dispatch tests above keep the part with an observable
    // consequence: who wins the filter.
    //
    // DROPPED as already pinned: locateInCustomPaths() answering from the
    // registry, and passing the incoming path through when nothing resolves,
    // are TemplateLoaderTest::testLocateInCustomPathsReturnsWhateverLocate
    // Returns and ...testLocateInCustomPathsHandsWordPressesPathBackWhen
    // NothingResolves.

    // -----------------------------------------------------------------------
    // 4. One hand-off — Response owns no resolution
    // -----------------------------------------------------------------------

    /**
     * api/Response.php resolves ONLY through the loader: no second search, no
     * theme constants, no filesystem probing of its own.
     */
    public function testResponseCarriesNoSecondResolver(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../api/Response.php');

        foreach (['file_exists(', 'TEMPLATEPATH', 'STYLESHEETPATH', 'locate_template(', 'get_stylesheet_directory(', 'get_template_directory(', 'glob(', 'is_file('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "api/Response.php must not resolve templates itself ({$forbidden})"
            );
        }

        $this->assertStringNotContainsString(
            'function locate',
            $source,
            'there is exactly one locate(), and it lives in core/TemplateLoader.php'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'NTDST_Template_Loader::locate('),
            'html() and render() each resolve through the one loader'
        );
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /** Sandboxes are per-test; leaving 15 of them per run behind is litter. */
    private function removeTree(string $dir): void
    {
        if (!str_starts_with($dir, sys_get_temp_dir() . '/ntdst-4a-') || !is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }

            $this->removeTree($path);
        }

        rmdir($dir);
    }

    private function write(string $dir, string $relative): void
    {
        $file = $dir . '/' . $relative;
        $parent = dirname($file);

        if (!is_dir($parent)) {
            mkdir($parent, 0o777, true);
        }

        file_put_contents($file, '<?php // fixture ' . $relative);
    }

    /** Replay the recorded filter bag the way WordPress would. */
    private function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $bag = $GLOBALS['_ntdst_test_filters_at'][$hook] ?? [];
        ksort($bag);

        foreach ($bag as $priority => $callback) {
            $accepted = (int) ($GLOBALS['_ntdst_test_filter_args'][$hook][$priority] ?? 1);
            $call = array_slice(array_merge([$value], $args), 0, max(1, $accepted));
            $value = $callback(...$call);
        }

        return $value;
    }

    private function clearFilterBag(): void
    {
        unset(
            $GLOBALS['_ntdst_test_filters'],
            $GLOBALS['_ntdst_test_filters_at'],
            $GLOBALS['_ntdst_test_filter_args']
        );
    }

    /** The class is static state; every test starts from an empty registry. */
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
