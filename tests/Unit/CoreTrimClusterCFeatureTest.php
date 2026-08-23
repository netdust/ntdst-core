<?php // tests/Unit/CoreTrimClusterCFeatureTest.php
declare(strict_types=1);
// core-trim Cluster C — THE SURFACE SWEEP, read as a consumer's boot reads it.
//
// The cluster's tasks each pinned their own half (ContainerSurfaceTest,
// ThemeTrimTest, the removed-symbol provider rows). This file asks the question
// none of them ask: after the trim, does the PACKAGE a consumer installs still
// behave — the loader it requires, the theme it boots, the container it
// resolves through, the hook vocabulary it listens on — and does every removed
// door fail LOUD when the consumer still knocks on it?
//
// Written from spec/core-trim FR-6..FR-10 + SC-3/SC-4 and the cluster's
// Behaviour block. The implementation was read only for SIGNATURES (what
// NTDST_Pages::template() mounts, what NTDST_Container::resolve() throws) so
// the assertions compile against reality; what they assert comes from the
// criteria.
//
// HARNESS FACTS THIS FILE IS BUILT ON (verified against tests/bootstrap.php,
// not assumed — the same three that shaped ThemeTrimTest):
//   1. add_action IS Brain Monkey's, so has_action() reports a real priority.
//   2. add_filter is NOT: tests/bootstrap.php defines a real one before
//      Patchwork. A mounted filter is therefore read back off the recorder
//      $GLOBALS['_ntdst_test_filters_at'][$hook][$priority] — which is also
//      what makes the template-filter assertions below possible, because the
//      recorder hands back the actual closure NTDST_Pages mounted and this
//      file can INVOKE it.
//   3. ntdst_data/ntdst_pages/ntdst_log are defined before Patchwork or by the
//      bootstrap, so Functions\expect() on them raises DefinedTooEarly. Nothing
//      here patches one; the real objects run and the recorders are read.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Pages.php';
require_once __DIR__ . '/../../core/Theme.php';
// The sweep helper this file reuses. Loaded by name rather than by trusting
// PHPUnit's file order — the reuse is the point: a second copy of the sweep
// would be a second answer to "is this symbol still spelled anywhere".
require_once __DIR__ . '/PackageBootIntegrityTest.php';

final class CoreTrimClusterCFeatureTest extends TestCase
{
    /** The package root — what a consumer's composer install drops in. */
    private const ROOT = __DIR__ . '/../..';

    /** The directories a consumer's boot actually executes. */
    private const SHIPPED_DIRS = ['api', 'core', 'admin', 'services', 'support'];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The bootstrap recorders are process-wide; a stale mount from an
        // earlier file would let a never-mounted hook read as mounted.
        $GLOBALS['_ntdst_test_log'] = [];
        $GLOBALS['_ntdst_test_filters'] = [];
        $GLOBALS['_ntdst_test_filters_at'] = [];
        unset($GLOBALS['post']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['post']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // 1. THE PACKAGE A CONSUMER INSTALLS (FR-7, FR-9)
    // =====================================================================

    /**
     * The loader is the package's contents page. After FR-7 and FR-9 it names
     * no Scheduler and no Mailer — and because loading is this explicit list
     * and not a directory scan, a name that is not here does not exist.
     *
     * (That every listed file parses and defines its symbols is
     * PackageBootIntegrityTest's promise, not repeated here. This asserts what
     * the list may NOT say.)
     */
    public function testTheLoaderRequiresNeitherASchedulerNorAMailer(): void
    {
        $paths = $this->loaderPaths();

        $this->assertNotEmpty($paths, 'ntdst-core.php must require its files by an explicit list.');
        $this->assertContains('/services/Logger.php', $paths, 'The Logger is the one built-in service that stays.');

        foreach ($paths as $path) {
            $this->assertStringNotContainsStringIgnoringCase(
                'Scheduler',
                $path,
                "ntdst-core.php still requires {$path} — FR-7 removed the Scheduler from the package.",
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'Mailer',
                $path,
                "ntdst-core.php still requires {$path} — FR-9 moved the Mailer into netdust-mail.",
            );
        }
    }

    /**
     * services/ holds exactly one file. Asserted as the DIRECTORY LISTING
     * rather than as two absences: a Scheduler that comes back under a new
     * name (services/Cron.php) fails this and passes an absence check.
     */
    public function testTheServicesDirectoryHoldsOnlyTheLogger(): void
    {
        $entries = array_values(array_diff(scandir(self::ROOT . '/services') ?: [], ['.', '..']));
        sort($entries);

        $this->assertSame(
            ['Logger.php'],
            $entries,
            'services/ is the built-in service list. After FR-7 and FR-9 the Logger is all of it.',
        );

        // The Mailer's asset directory left with the class (FR-9); a surviving
        // templates/emails/ is a consumer instruction to a class that is gone.
        $this->assertDirectoryDoesNotExist(
            self::ROOT . '/templates/emails',
            'templates/emails/ was the Mailer\'s layout directory — FR-9 removed it with the class.',
        );
    }

    // =====================================================================
    // 2. A CONSUMER THEME BOOTS ON THE TRIMMED NTDST_Theme (FR-8)
    // =====================================================================

    /**
     * The kept half of FR-8, as a theme's functions.php writes it: one fluent
     * chain, both hooks mounted, at the priorities the theme asked for.
     *
     * Priorities are the assertion with bite. A wrapper that drops the
     * argument still mounts the callback, and the theme's late override then
     * runs before the parent it meant to override.
     */
    public function testAConsumerThemeMountsBothItsHooksInOneChainAtItsOwnPriorities(): void
    {
        $theme = new NTDST_Theme(['textdomain' => 'cluster_c_theme']);
        $footer = static fn() => null;
        $bodyClass = static fn(array $classes) => $classes;

        $returned = $theme
            ->on('wp_footer', $footer, 20, 2)
            ->filter('body_class', $bodyClass, 7);

        $this->assertSame($theme, $returned, 'The chain must hand the theme back at every link.');
        $this->assertSame(20, has_action('wp_footer', $footer), 'on() must mount at the priority given, not at 10.');
        $this->assertSame(
            $bodyClass,
            $GLOBALS['_ntdst_test_filters_at']['body_class'][7] ?? null,
            'filter() must mount at the priority given, not at 10.',
        );
    }

    /**
     * single() resolves END TO END through the layer that owns it: a theme
     * registers, NTDST_Pages mounts the `single_template` filter, and
     * WordPress running that filter reaches the theme's own callback.
     *
     * Written as `ntdst_pages()->single(...)` since core-shape FR-12 deleted
     * NTDST_Theme's one-line forwarder — the CHAIN this case pins is unchanged
     * and one hop shorter, and the deleted hop has its own absence test in
     * ThemeTrimTest::testThemeWiresOnlyWhatWordPressThemeSetupDoes().
     */
    public function testASingleGigRendersTheGigTemplateThroughTheRealFilter(): void
    {
        ntdst_pages()->single('gig', static fn($post, $template) => '/theme/single-gig.php');

        $mounted = $GLOBALS['_ntdst_test_filters_at']['single_template'][10] ?? null;
        $this->assertIsCallable($mounted, 'single() must mount the single_template filter.');

        Functions\when('get_post_type')->justReturn('gig');

        $this->assertSame(
            '/theme/single-gig.php',
            $mounted('/wp-content/themes/default/single.php'),
            'On a gig, the theme\'s handler decides the template.',
        );
    }

    /**
     * THE DENIAL PATH of the same registration: a single view of ANOTHER post
     * type must not reach the gig handler, and must keep the template
     * WordPress chose. A registration that dropped the post-type argument
     * passes the test above and fails this one.
     */
    public function testASingleViewOfAnotherPostTypeNeverReachesTheGigHandler(): void
    {
        $ran = false;
        ntdst_pages()->single('gig', static function ($post, $template) use (&$ran) {
            $ran = true;
            return '/theme/single-gig.php';
        });

        $mounted = $GLOBALS['_ntdst_test_filters_at']['single_template'][10] ?? null;
        $this->assertIsCallable($mounted);

        Functions\when('get_post_type')->justReturn('post');

        $this->assertSame(
            '/wp-content/themes/default/single.php',
            $mounted('/wp-content/themes/default/single.php'),
            'A post is not a gig — WordPress\'s own template must survive untouched.',
        );
        $this->assertFalse($ran, 'The gig handler ran on a post: the post-type gate was lost.');
    }

    /**
     * page() carries the SLUG — and the non-matching page is the edge that a
     * slug-blind rewrite would break: it must fall through to the template
     * WordPress picked.
     */
    public function testThePageHandlerRunsOnTheMatchingSlugOnlyAndFallsThroughOtherwise(): void
    {
        ntdst_pages()->page('about', static fn($post) => '/theme/page-about.php');

        $mounted = $GLOBALS['_ntdst_test_filters_at']['page_template'][10] ?? null;
        $this->assertIsCallable($mounted, 'page() must mount the page_template filter.');

        $GLOBALS['post'] = (object) ['post_name' => 'about'];
        $this->assertSame('/theme/page-about.php', $mounted('/wp/page.php'), 'The about page uses the theme\'s handler.');

        $GLOBALS['post'] = (object) ['post_name' => 'contact'];
        $this->assertSame('/wp/page.php', $mounted('/wp/page.php'), 'Another page keeps WordPress\'s template.');
    }

    /** archive() registers the same way — the third of the three template hooks. */
    public function testTheArchiveRegistrationMountsTheGigArchiveHandler(): void
    {
        ntdst_pages()->archive('gig', static fn($post, $template) => '/theme/archive-gig.php');

        $mounted = $GLOBALS['_ntdst_test_filters_at']['archive_template'][10] ?? null;
        $this->assertIsCallable($mounted, 'archive() must mount the archive_template filter.');

        Functions\when('get_post_type')->justReturn('gig');
        $this->assertSame('/theme/archive-gig.php', $mounted('/wp/archive.php'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function retiredThemeProxyProvider(): array
    {
        return [
            'data' => ['data'],
            'pages' => ['pages'],
            'response' => ['response'],
            'log' => ['log'],
            'mail' => ['mail'],
        ];
    }

    /**
     * THE FIVE RETIRED PROXIES, all of them, as the upgrading consumer meets
     * them: `$theme->mail(...)` is an undefined method and PHP says so.
     *
     * Loud is the requirement. Under the old __call() a name that no longer
     * resolved returned silently — the theme booted and the call did nothing.
     *
     * @dataProvider retiredThemeProxyProvider
     */
    public function testEachRetiredThemeProxyIsAnUndefinedMethod(string $proxy): void
    {
        $theme = new NTDST_Theme([]);

        $this->expectException(Error::class);
        $theme->$proxy();
    }

    // =====================================================================
    // 3. THE CONTAINER AS A CONSUMER USES IT (FR-6)
    // =====================================================================

    /**
     * The kept answer, through the two helpers a consumer writes: register a
     * class, resolve it, and get the same object every time — with a NESTED
     * graph, because one level of autowiring can be satisfied by an accident
     * that two cannot.
     */
    public function testAConsumerBootResolvesANestedGraphOnceThroughTheHelpers(): void
    {
        $this->assertFalse(
            ntdst_container()->has('cluster_c.nothing_registered'),
            'has() must answer no for an id nobody registered.',
        );

        ntdst_set(ClusterCFeatureService::class);

        $first = ntdst_get(ClusterCFeatureService::class);
        $second = ntdst_get(ClusterCFeatureService::class);

        $this->assertInstanceOf(ClusterCFeatureService::class, $first);
        $this->assertSame($first, $second, 'ntdst_get() is the singleton path: two calls, one object.');
        $this->assertSame($first, ntdst_container()->get(ClusterCFeatureService::class), 'The helper and the container are one container.');
        $this->assertInstanceOf(ClusterCFeatureRepo::class, $first->repo, 'The direct dependency arrives autowired.');
        $this->assertInstanceOf(ClusterCFeatureClient::class, $first->repo->client, 'So does the dependency of the dependency.');
        $this->assertTrue(ntdst_container()->has(ClusterCFeatureService::class));
    }

    /**
     * RE-REGISTRATION — the re-entry path. flush() and forget() are gone
     * (FR-6), so set() on a live id is the only way a consumer replaces a
     * binding. It must replace it, not hand back the cached first answer.
     */
    public function testRegisteringOverALiveBindingReplacesTheResolvedInstance(): void
    {
        ntdst_set('cluster_c.reentry', new ClusterCFeatureClient());
        $original = ntdst_get('cluster_c.reentry');

        $replacement = new ClusterCFeatureClient();
        ntdst_set('cluster_c.reentry', $replacement);

        $this->assertSame($replacement, ntdst_get('cluster_c.reentry'), 'set() must clear the resolved cache.');
        $this->assertNotSame($original, ntdst_get('cluster_c.reentry'));
    }

    /**
     * The null edge, which is why set() reads func_num_args(): an explicitly
     * registered null is a VALUE, not "unregistered". A container that folded
     * the two would re-resolve the id as a class name on every get().
     */
    public function testAnExplicitlyRegisteredNullStaysNullAndCountsAsRegistered(): void
    {
        ntdst_set('cluster_c.optional_dep', null);

        $this->assertTrue(ntdst_container()->has('cluster_c.optional_dep'));
        $this->assertNull(ntdst_get('cluster_c.optional_dep'));
        $this->assertNull(ntdst_get('cluster_c.optional_dep'), 'The null must come back from the cache, not be re-resolved.');
    }

    /** The empty state: an id nobody registered fails loud instead of resolving to null. */
    public function testResolvingAnUnregisteredIdThrowsWithTheIdInTheMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cluster_c.never_registered');
        ntdst_get('cluster_c.never_registered');
    }

    /** The typo'd binding: a class that does not exist is named in the failure. */
    public function testABindingToAMissingClassFailsLoudAtResolveTime(): void
    {
        ntdst_set('cluster_c.repo', 'ClusterCFeatureMissingRepo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ClusterCFeatureMissingRepo');
        ntdst_get('cluster_c.repo');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function removedContainerMemberProvider(): array
    {
        return [
            'make' => ['make'],
            'call' => ['call'],
            'forget' => ['forget'],
            'flush' => ['flush'],
            'keys' => ['keys'],
        ];
    }

    /**
     * FR-6 from the consumer's side: the container a boot holds answers
     * set/get/has and nothing else. Asserted by CALLING each removed member —
     * a private or protected survivor passes a public-method count and fails
     * here for the same reason it would fail in a consumer's plugin.
     *
     * @dataProvider removedContainerMemberProvider
     */
    public function testARemovedContainerMemberIsUnreachableFromAConsumer(string $member): void
    {
        $container = ntdst_container();

        $this->expectException(Error::class);
        $container->$member(ClusterCFeatureService::class);
    }

    /** ntdst_make() was make()'s global front door; the consumer writes `new`. */
    public function testTheGlobalFreshResolutionHelperIsGone(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('ntdst_make');
        /** @phpstan-ignore-next-line — the point of the test is that it is undefined */
        ntdst_make(ClusterCFeatureService::class);
    }

    // =====================================================================
    // 4. THE HOOK VOCABULARY A CONSUMER LISTENS ON (SC-4, FR-11)
    // =====================================================================

    /**
     * SC-4 as a feature: a consumer adding a listener has ONE naming
     * convention to learn. After FR-5/FR-9 no shipped line fires an
     * underscore-prefixed `ntdst_` hook, in either quote style.
     *
     * A stray one is exactly what this catches — it would be a second
     * vocabulary, live, that no README documents.
     */
    public function testNoShippedLineFiresAnUnderscorePrefixedNtdstHook(): void
    {
        $hits = [];

        foreach ($this->shippedLines() as [$path, $lineNo, $line]) {
            if (preg_match('/(do_action|apply_filters)\s*\(\s*[\'"]ntdst_/', $line) === 1) {
                $hits[] = "{$path}:{$lineNo} → " . trim($line);
            }
        }

        $this->assertSame(
            [],
            $hits,
            "SC-4: core fires `ntdst/...` hooks only. An `ntdst_`-prefixed one is a second vocabulary:\n"
                . implode("\n", $hits),
        );
    }

    /**
     * Every action the Data layer fires is a hook a consumer can name in
     * advance: the six model-lifecycle hooks and the two registration hooks.
     * Anything else in this file is a hook nobody documented — which is the
     * defect this test exists to surface, so it FAILS BY NAMING it.
     */
    public function testTheDataLayerFiresOnlyItsDocumentedNamespacedHooks(): void
    {
        $modelLifecycle = [
            'ntdst/model/creating',
            'ntdst/model/created',
            'ntdst/model/updating',
            'ntdst/model/updated',
            'ntdst/model/deleting',
            'ntdst/model/deleted',
        ];
        // The two non-model actions of the same file: registration is not a
        // model event, it is what makes the model exist (FR-11's `ntdst/*`).
        $registration = ['ntdst/model/registering', 'ntdst/model/registered'];

        $source = file_get_contents(self::ROOT . '/api/Data.php');
        $this->assertIsString($source);

        preg_match_all('/do_action\(\s*[\'"]([^\'"]+)[\'"]/', $source, $actions);
        $fired = array_values(array_unique($actions[1]));
        sort($fired);

        $allowed = array_merge($modelLifecycle, $registration);
        sort($allowed);

        $this->assertSame(
            $allowed,
            $fired,
            "api/Data.php fires an action outside the documented vocabulary:\n" . implode("\n", $fired),
        );

        // The interpolated per-model filters are the file's only apply_filters,
        // and both are namespaced the same way. A consumer filtering a model's
        // fields writes `ntdst/{model}/fields`.
        preg_match_all('/apply_filters\(\s*[\'"]([^\'"]+)[\'"]/', $source, $filters);
        $applied = array_values(array_unique($filters[1]));
        sort($applied);

        $this->assertSame(
            ['ntdst/{$name}/field_groups', 'ntdst/{$name}/fields'],
            $applied,
            "api/Data.php applies a filter outside the documented vocabulary:\n" . implode("\n", $applied),
        );
    }

    // =====================================================================
    // 5. THE REMOVED SYMBOLS, SWEPT (FR-6..FR-9)
    // =====================================================================

    /**
     * The four call shapes Cluster C removed, one per family.
     *
     * CALL SHAPES, not bare names: `mixin` cannot be a provider row in
     * PackageBootIntegrityTest (the word is prose, and core/Pages.php ships a
     * live when()/mixin-adjacent vocabulary), but `->mixin(` is a CALL and no
     * shipped line may make one. That is the gap this pair of tests closes.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function removedClusterCFamilyProvider(): array
    {
        return [
            'the mail helper (FR-9)' => ['ntdst_mail(', 'Mail.php', '$mail = ntdst_mail()->to(\'a@b.test\');'],
            'the scheduler helper (FR-7)' => ['ntdst_scheduler(', 'Cron.php', 'ntdst_scheduler()->every(\'daily\', \'cb\');'],
            'the theme mixin call (FR-8)' => ['->mixin(', 'Theme.php', '$theme->mixin(\'router\', static fn() => null);'],
            'the fresh-resolution helper (FR-6)' => ['ntdst_make(', 'Make.php', '$fresh = ntdst_make(Thing::class);'],
        ];
    }

    /**
     * FIRST: prove the detector detects. A sweep that reports nothing because
     * its pattern is wrong looks exactly like a clean package — so each family
     * is run over a throwaway tree that DOES spell it, and must be reported
     * with its file and line.
     *
     * @dataProvider removedClusterCFamilyProvider
     */
    public function testTheSweepReportsEachRemovedClusterCFamily(string $symbol, string $file, string $line): void
    {
        $root = sys_get_temp_dir() . '/ntdst-cluster-c-' . getmypid() . '-' . uniqid();
        mkdir($root . '/api', 0777, true);
        file_put_contents($root . '/api/' . $file, "<?php\n{$line}\n");

        try {
            $hits = $this->sweep($root, $symbol);

            $this->assertCount(1, $hits, "The sweep must report a live {$symbol} call:\n" . implode("\n", $hits));
            $this->assertStringContainsString('api/' . $file . ':2', $hits[0]);
        } finally {
            unlink($root . '/api/' . $file);
            rmdir($root . '/api');
            rmdir($root);
        }
    }

    /**
     * THEN: the promise. No directory a consumer's boot executes spells any of
     * the four, and neither does the loader.
     *
     * @dataProvider removedClusterCFamilyProvider
     */
    public function testNoShippedDirectorySpellsARemovedClusterCFamily(string $symbol): void
    {
        $hits = [];

        foreach (self::SHIPPED_DIRS as $dir) {
            $hits = array_merge($hits, $this->sweep(self::ROOT . '/' . $dir, $symbol));
        }

        // The loader is a FILE, not a directory, so the sweep cannot reach it.
        foreach ($this->shippedLines() as [$path, $lineNo, $line]) {
            if ($path !== 'ntdst-core.php') {
                continue; // the five directories are the sweep's job, above
            }
            $code = trim($line);
            if ($code === '' || preg_match('#^(\*|//|\#|/\*)#', $code) === 1) {
                continue; // a comment may discuss a removed name; a call may not
            }
            if (str_contains($line, $symbol)) {
                $hits[] = "{$path}:{$lineNo} → {$code}";
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($hits)),
            "{$symbol} was removed in v5.0.0 but shipped code still calls it:\n" . implode("\n", $hits),
        );
    }

    // =====================================================================
    // helpers
    // =====================================================================

    /**
     * The loader's require list, verbatim.
     *
     * @return list<string>
     */
    private function loaderPaths(): array
    {
        $source = file_get_contents(self::ROOT . '/ntdst-core.php');
        $this->assertIsString($source);

        preg_match_all("#^\s*require_once\s+NTDST_PATH\s*\.\s*'([^']+)';#m", $source, $matches);

        return $matches[1];
    }

    /**
     * Every line of every file a consumer's boot executes: the five shipped
     * directories and the loader itself.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function shippedLines(): array
    {
        $lines = [];
        $files = [self::ROOT . '/ntdst-core.php'];

        foreach (self::SHIPPED_DIRS as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if (str_ends_with($file->getPathname(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        foreach ($files as $path) {
            $short = str_replace(realpath(self::ROOT) . '/', '', (string) realpath($path));
            foreach (file($path) as $n => $line) {
                $lines[] = [$short, $n + 1, $line];
            }
        }

        return $lines;
    }

    /**
     * PackageBootIntegrityTest's sweep, REUSED rather than re-written — a
     * second copy would be a second answer to the same question, which is the
     * defect this whole cluster removes from the shipped code.
     *
     * @return list<string>
     */
    private function sweep(string $root, string $symbol): array
    {
        $method = new ReflectionMethod(PackageBootIntegrityTest::class, 'sweep');
        $method->setAccessible(true);

        return $method->invoke(new PackageBootIntegrityTest('testNoShippedFileReferencesARemovedSymbol'), $root, $symbol);
    }
}

/** A consumer's service graph — three levels, so autowiring is proved twice. */
final class ClusterCFeatureService
{
    public function __construct(public ClusterCFeatureRepo $repo) {}
}

final class ClusterCFeatureRepo
{
    public function __construct(public ClusterCFeatureClient $client) {}
}

final class ClusterCFeatureClient
{
}
