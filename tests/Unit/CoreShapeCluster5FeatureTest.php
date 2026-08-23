<?php // tests/Unit/CoreShapeCluster5FeatureTest.php
declare(strict_types=1);
// core-shape Cluster 5 — INDEPENDENT feature tests, written from the cluster's
// Behaviour/Observable block and T12's own criteria (tasks.md ~:153-160,
// FR-12) and T13's README criteria (FR-13/SC-8) — never from core/Theme.php's
// implementation beyond reading setup_theme()'s SIGNATURE (which WordPress
// calls it makes) to compile the assertions against reality.
//
// This file is deliberately NARROW: ThemeTrimTest.php (T09/T12) and
// CoreTrimClusterCFeatureTest.php (Cluster C) already pin most of the
// cluster's promise — see the "ALREADY PINNED, NOT REPEATED" note at the
// bottom of this header for exactly what was dropped and where it lives.
// What follows is what neither file asserts:
//
//   1. setup_theme() actually RECORDS add_theme_support / add_image_size /
//      register_nav_menus / register_sidebar as the config declares them —
//      the cluster's own Observable line, and no existing file calls any of
//      these four WordPress functions even once.
//   2. the_generator stays unmounted even when EVERY OTHER key is populated,
//      not only on an empty config (ThemeTrimTest's case).
//   3. excerpt_more mounts (default priority, not 999) only when
//      excerpt.more is configured — ThemeTrimTest only exercises the
//      excerpt.length half.
//   4. on()/filter() pass the hook name through VERBATIM — a slash-bearing,
//      mixed-case `ntdst/service/x/config`-shaped name is not lowercased,
//      not stripped, not sanitize_key()'d.
//   5. The five methods T12 removed (style/script/single/page/archive) are
//      undefined when CALLED, not merely absent by reflection — and the
//      class docblock names each replacement.
//   6. Malformed config for a key the class iterates (theme_support,
//      image_sizes, menus, sidebars) throws InvalidArgumentException before
//      setup_theme() ever runs.
//   7. THE RED FINDING — T12's own review note: `excerpt` is not on the
//      validated-shape list, so a scalar `'excerpt' => 55` does not throw;
//      it silently mounts nothing. Written as the criterion (`throws
//      InvalidArgumentException`) and marked @group red-finding because it
//      fails against the code as shipped.
//   8. README's `Theme::style` / `Theme::script` / `Theme::single/page/
//      archive` migration rows name the SPECIFIC replacement calls the
//      cluster promises (`ntdst_pages()->single(`/`page(`/`archive(` and
//      `wp_enqueue_style`/`wp_enqueue_script`) — a source assertion over
//      README, not the generic "a row exists" sweep
//      PackageBootIntegrityTest::testEveryRemovedFiveOhSymbolHasAMigrationRow
//      already runs.
//
// ALREADY PINNED, NOT REPEATED (verified by reading, not assumed):
//   - style/script/single/page/archive absent by REFLECTION and from the
//     FILE text  → ThemeTrimTest::testThemeWiresOnlyWhatWordPressThemeSetupDoes
//   - the_generator/excerpt_length/excerpt_more all unmounted on an EMPTY
//     config, and excerpt.length mounts exactly one excerpt_length filter at
//     999 answering the configured value, with excerpt_more/the_generator
//     absent beside it → same test
//   - the mixin proxies (data/pages/response/log/mail) are undefined methods
//     → CoreTrimClusterCFeatureTest::testEachRetiredThemeProxyIsAnUndefinedMethod
//     (a DIFFERENT five names than this file's #5 — T09's proxies, not
//     T12's forwarders)
//   - on()/filter() mount at the caller's priority and stay chainable
//     → ThemeTrimTest::testOnRegistersTheActionAtTheGivenPriority /
//     testFilterRegistersTheCallbackAtItsDefaultPriority, and
//     CoreTrimClusterCFeatureTest::testAConsumerThemeMountsBothItsHooksInOneChainAtItsOwnPriorities
//   - single()/page()/archive() resolve end to end through ntdst_pages()
//     (the class T12 pushes the caller toward) → three tests in
//     CoreTrimClusterCFeatureTest
//   - the removed-symbol MECHANICAL sweep (README has SOME row per removed
//     name) → PackageBootIntegrityTest::testEveryRemovedFiveOhSymbolHasAMigrationRow
//     (this file's #8 checks the row's CONTENT, which that test does not)
//
// HARNESS FACTS (verified against tests/bootstrap.php, same three ThemeTrimTest
// and CoreTrimClusterCFeatureTest name):
//   1. add_action is Brain Monkey's — has_action() reports the real priority.
//   2. add_filter is bootstrap's OWN recorder, defined before Patchwork, so a
//      mounted filter is read off $GLOBALS['_ntdst_test_filters_at'][$hook][$priority].
//   3. sanitize_key/wp_unslash/ntdst_log are real; nothing here mocks them.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Theme.php';

final class CoreShapeCluster5FeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['_ntdst_test_log'] = [];
        $GLOBALS['_ntdst_test_filters'] = [];
        $GLOBALS['_ntdst_test_filters_at'] = [];
        $GLOBALS['_ntdst_test_filter_args'] = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // 1. setup_theme() RECORDS what the config declares (the cluster's
    //    Observable line, in full — not just "a filter got mounted").
    // =====================================================================

    /**
     * A theme with every declarable key populated: add_theme_support fires
     * once per feature (bool arg vs array arg, both shapes), add_image_size
     * fires per named size with the size's own dimensions, register_nav_menus
     * receives the whole map translated, and register_sidebar fires per
     * sidebar carrying the declared id/name through sanitize_key/as-given.
     */
    public function testSetupThemeRecordsEveryDeclaredThemeSupportImageSizeMenuAndSidebar(): void
    {
        Monkey\Functions\when('load_theme_textdomain')->justReturn(true);
        Monkey\Functions\when('get_template_directory')->justReturn('/srv/theme');
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('sanitize_text_field')->returnArg(1);

        $themeSupportCalls = [];
        Functions\when('add_theme_support')->alias(function (...$args) use (&$themeSupportCalls) {
            $themeSupportCalls[] = $args;
        });

        $imageSizeCalls = [];
        Functions\when('add_image_size')->alias(function (...$args) use (&$imageSizeCalls) {
            $imageSizeCalls[] = $args;
        });

        $menuCalls = [];
        Functions\when('register_nav_menus')->alias(function ($menus) use (&$menuCalls) {
            $menuCalls[] = $menus;
        });

        $sidebarCalls = [];
        Functions\when('register_sidebar')->alias(function ($args) use (&$sidebarCalls) {
            $sidebarCalls[] = $args;
        });

        $theme = new NTDST_Theme([
            'theme_support' => [
                'post-thumbnails' => true,
                'html5' => ['search-form', 'comment-form'],
            ],
            'image_sizes' => [
                'card' => [400, 300, true, 'Card'],
            ],
            'menus' => [
                'primary' => 'Primary Menu',
            ],
            'sidebars' => [
                ['id' => 'Main Sidebar', 'name' => 'Main Sidebar'],
            ],
        ]);
        $theme->setup_theme();

        $this->assertSame(
            [['post-thumbnails'], ['html5', ['search-form', 'comment-form']]],
            $themeSupportCalls,
            'a bool feature calls add_theme_support with one arg; an array feature passes its args through',
        );

        $this->assertCount(1, $imageSizeCalls, 'exactly one declared image size must register exactly once');
        $this->assertSame(['card', 400, 300, true], $imageSizeCalls[0], 'add_image_size must receive the declared name and dimensions');

        $this->assertSame([['primary' => 'Primary Menu']], $menuCalls, 'register_nav_menus must receive the declared menu map');

        $this->assertCount(1, $sidebarCalls, 'exactly one declared sidebar must register exactly once');
        $this->assertSame('mainsidebar', $sidebarCalls[0]['id'], 'a declared sidebar id is run through sanitize_key');
        $this->assertSame('Main Sidebar', $sidebarCalls[0]['name']);
    }

    /**
     * the_generator NEVER mounts — not only on an empty config (ThemeTrimTest's
     * case) but with every other declarable key populated too. A regression
     * that started mounting it conditionally on, say, theme_support being
     * non-empty passes the empty-config case and fails this one.
     */
    public function testTheGeneratorStaysUnmountedEvenWithAFullyPopulatedConfig(): void
    {
        Monkey\Functions\when('load_theme_textdomain')->justReturn(true);
        Monkey\Functions\when('get_template_directory')->justReturn('/srv/theme');
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('add_theme_support')->justReturn(null);
        Functions\when('add_image_size')->justReturn(null);
        Functions\when('register_nav_menus')->justReturn(null);
        Functions\when('register_sidebar')->justReturn(null);

        $theme = new NTDST_Theme([
            'theme_support' => ['post-thumbnails' => true],
            'image_sizes' => ['card' => [400, 300, true]],
            'menus' => ['primary' => 'Primary'],
            'sidebars' => [['id' => 'main', 'name' => 'Main']],
            'excerpt' => ['length' => 30, 'more' => '… read on at %s'],
        ]);
        $theme->setup_theme();

        $this->assertArrayNotHasKey(
            'the_generator',
            $GLOBALS['_ntdst_test_filters_at'],
            'the_generator must never mount, no matter what the rest of the config declares',
        );
    }

    /**
     * excerpt_more mounts, at the DEFAULT priority (10 — nothing pins it late
     * the way excerpt_length is), only when the config sets excerpt.more, and
     * its callback formats the configured string with the permalink.
     */
    public function testExcerptMoreMountsOnlyWhenConfiguredAndFormatsWithThePermalink(): void
    {
        Monkey\Functions\when('load_theme_textdomain')->justReturn(true);
        Monkey\Functions\when('get_template_directory')->justReturn('/srv/theme');
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('register_nav_menus')->justReturn(null);
        Monkey\Functions\when('get_permalink')->justReturn('https://example.test/post/');
        Monkey\Functions\when('esc_url')->returnArg(1);

        $theme = new NTDST_Theme(['excerpt' => ['more' => '… continued at %s']]);
        $theme->setup_theme();

        $this->assertArrayNotHasKey(
            'excerpt_length',
            $GLOBALS['_ntdst_test_filters_at'],
            'configuring excerpt.more alone must not also mount excerpt_length',
        );

        $mounted = $GLOBALS['_ntdst_test_filters_at']['excerpt_more'][10] ?? null;
        $this->assertIsCallable($mounted, 'excerpt_more must mount at the default priority (10)');

        $this->assertSame(
            '… continued at https://example.test/post/',
            $mounted(),
            'the filter must format the configured more-string with the current permalink',
        );
    }

    // =====================================================================
    // 2. on()/filter() pass hook names VERBATIM — a slash-and-case name is
    //    not normalised, the framework's own `ntdst/service/{slug}/config`
    //    vocabulary depends on it.
    // =====================================================================

    public function testOnAndFilterMountTheHookNameByteForByteWithNoNormalisation(): void
    {
        $theme = new NTDST_Theme([]);
        $onCb = static fn() => null;
        $filterCb = static fn($v) => $v;

        $hookName = 'Ntdst/Service/Mailer/Config';

        $theme->on($hookName, $onCb);
        $this->assertSame(10, has_action($hookName, $onCb), 'on() must mount under the exact string given');
        $this->assertFalse(
            (bool) has_action(strtolower($hookName), $onCb),
            'on() must not have also mounted a lower-cased variant',
        );

        $theme->filter($hookName, $filterCb);
        $this->assertSame(
            $filterCb,
            $GLOBALS['_ntdst_test_filters_at'][$hookName][10] ?? null,
            'filter() must mount the callback under the exact string given, slashes and case intact',
        );
        $this->assertArrayNotHasKey(
            sanitize_key($hookName),
            $GLOBALS['_ntdst_test_filters_at'],
            'filter() must not have ALSO mounted a sanitize_key()-normalised variant',
        );
    }

    // =====================================================================
    // 3. The five removed methods are undefined when CALLED, and the
    //    docblock names each replacement.
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: array<int, mixed>}>
     */
    public static function removedThemeMethodProvider(): array
    {
        return [
            'style' => ['style', ['handle', 'src.css']],
            'script' => ['script', ['handle', 'src.js']],
            'single' => ['single', ['gig', static fn() => null]],
            'page' => ['page', ['about', static fn() => null]],
            'archive' => ['archive', ['gig', static fn() => null]],
        ];
    }

    /**
     * @dataProvider removedThemeMethodProvider
     */
    public function testEachRemovedThemeMethodIsAnUndefinedMethodWhenCalled(string $method, array $args): void
    {
        $theme = new NTDST_Theme([]);

        $this->expectException(Error::class);
        $theme->$method(...$args);
    }

    public function testTheClassDocblockNamesEachRemovedMethodsReplacement(): void
    {
        $class = new ReflectionClass(NTDST_Theme::class);
        $doc = $class->getDocComment();
        $this->assertIsString($doc, 'NTDST_Theme must carry a class docblock');

        $this->assertStringContainsString(
            'wp_enqueue_style',
            $doc,
            'the docblock must name wp_enqueue_style as style()\'s replacement',
        );
        $this->assertStringContainsString(
            'ntdst_pages()',
            $doc,
            'the docblock must name ntdst_pages() as single()/page()/archive()\'s replacement',
        );
    }

    // =====================================================================
    // 4. Malformed config for the keys the class iterates throws
    //    InvalidArgumentException up front, before setup_theme() runs.
    // =====================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function iteratedConfigKeyProvider(): array
    {
        return [
            'theme_support' => ['theme_support'],
            'image_sizes' => ['image_sizes'],
            'menus' => ['menus'],
            'sidebars' => ['sidebars'],
        ];
    }

    /**
     * @dataProvider iteratedConfigKeyProvider
     */
    public function testAScalarValueForAnIteratedConfigKeyThrowsUpFront(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($key);

        new NTDST_Theme([$key => 'not-an-array']);
    }

    /**
     * RED FINDING (T12 review note): `excerpt` is NOT on validate_config()'s
     * shape list (only theme_support/image_sizes/menus/sidebars are), so a
     * scalar excerpt value throws NOTHING — isset() on an array offset of an
     * int silently returns false, and setup_theme() mounts neither excerpt
     * filter with no signal to the caller that their config was malformed.
     * This asserts the criterion the fix owes, not the code as shipped, and
     * is expected to be RED until that fix lands.
     *
     * @group red-finding
     */
    public function testAScalarExcerptValueThrowsUpFront(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NTDST_Theme(['excerpt' => 55]);
    }

    // =====================================================================
    // 5. README's Theme migration rows name the SPECIFIC replacement calls —
    //    a source assertion over README.md, never over core/Theme.php.
    // =====================================================================

    public function testReadmeStyleAndScriptRowsNameTheWpEnqueueReplacement(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertIsString($readme);

        $this->assertMatchesRegularExpression(
            '/`Theme::style\([^`]*`\s*\|[^\n]*wp_enqueue_style/',
            $readme,
            'the Theme::style migration row must name wp_enqueue_style as the replacement call',
        );
        $this->assertMatchesRegularExpression(
            '/`Theme::script\([^`]*`\s*\|[^\n]*wp_enqueue_script/',
            $readme,
            'the Theme::script migration row must name wp_enqueue_script as the replacement call',
        );
    }

    public function testReadmeSinglePageArchiveRowNamesTheNtdstPagesReplacement(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertIsString($readme);

        // README writes the replacement as the shorthand `ntdst_pages()->single()`
        // / `->page()` / `->archive()` rather than spelling ntdst_pages() three
        // times — asserted as three separate substrings inside the ONE row, not
        // as one literal string, so the test survives a harmless rewording of
        // the shorthand without losing the "all three named" requirement.
        $this->assertMatchesRegularExpression(
            '/`Theme::single\(\)`.*`Theme::page\(\)`.*`Theme::archive\(\)`\s*\|[^\n]*ntdst_pages\(\)->single\(\)[^\n]*->page\(\)[^\n]*->archive\(\)/',
            $readme,
            'the single/page/archive migration row must name ntdst_pages()->single() and the ->page()/->archive() replacements',
        );
    }
}
