<?php // tests/Unit/BootstrapWalksItsServiceListOnceTest.php
// core-trim Cluster A gate — what the boot does with the list it accepted.
//
// THE CONTRACT (cluster-a-fix-brief.md A2, A6, A7, A8, A10; sentinels I-4, S-4,
// S-5, simplicity 1, reviewer S2). Registration reads the consumer's `services`
// array ONCE. Every class in it is asked for its metadata once, the latch that
// makes `register()` idempotent is set before the walk rather than after it, a
// missing service is reported where production can see it, and a slug is
// resolved only for a class that is going to need one.
//
// THE FAILURES THIS FILE EXISTS TO CATCH, in the order they cost.
//
// 1. A MISSING SERVICE IS SILENT IN PRODUCTION (I-4). `_doing_it_wrong()` is
//    WP_DEBUG-gated: on a live site it prints nothing, logs nothing, and the
//    service the config lists simply is not there. That is the exact failure
//    5.0.0 set out to end — a service missing without a word — moved from
//    development to production. So the notice has a companion that is NOT
//    gated: an error-level line through the package's own logger.
//
// 2. A RETRY REPEATS THE FIRST TRY'S NOISE (S-4). `servicesRegistered` is set at
//    the END of `register()` today. Anything that throws mid-walk — a service
//    whose `metadata()` blows up — leaves the latch down, so the next call walks
//    the whole list again. A consumer that wires `register()` to two hooks then
//    gets the list registered twice and the refusals printed twice.
//
// 3. `metadata()` IS A CONSUMER'S CODE (S-5). Core calls it statically, and
//    twice: once for the metadata, once through the slug. An instance method
//    named `metadata()` is therefore a fatal at boot, and any side effect in a
//    static one happens twice. It is a declaration; it is read once, and a class
//    that declares it wrong gets a notice, not a white screen.
//
// 4. AN OVERRIDE KEY AUTOLOADS THE ADMIN TREE (reviewer S2). The dead-key check
//    resolves the slug of every LISTED class, and resolving a slug calls
//    `method_exists()`, which autoloads. On a front-end request that pulls every
//    admin service's class file into memory on every page view — for a config
//    that is perfectly correct.
//
// The `listedServiceClasses()` walk is the other half of #4 (simplicity 1): one
// pass over the list, its names carried to the override check, rather than a
// second traversal that rebuilds them.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapWalksItsServiceListOnceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use NtdstRecordsRefusals;
    use NtdstPlantsServiceFiles;

    /** The autoloader this test installs, so tearDown can take it back off. */
    private $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->recordRefusals();
        $this->plantingRoot('walk', '_ntdst_walk_included', '_ntdst_walk_constructed');

        $GLOBALS['_ntdst_walk_constructed'] = [];       // services core booted, in order
        $GLOBALS['_ntdst_walk_config'] = [];            // slug => the config the service read back
        $GLOBALS['_ntdst_walk_metadata_calls'] = [];    // class => how often metadata() ran
        $GLOBALS['_ntdst_walk_autoloaded'] = [];        // every name handed to an autoloader
        $GLOBALS['_ntdst_walk_is_admin'] = false;
        $GLOBALS['_ntdst_test_log'] = [];               // the suite's real ntdst_log() recorder

        foreach (['_ntdst_test_filters', '_ntdst_test_filters_at'] as $bag) {
            foreach (array_keys($GLOBALS[$bag] ?? []) as $hook) {
                if (str_starts_with((string) $hook, 'ntdst/service/')) {
                    unset($GLOBALS[$bag][$hook]);
                }
            }
        }

        Functions\when('is_admin')->alias(static fn() => (bool) $GLOBALS['_ntdst_walk_is_admin']);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_option')->justReturn('1');
        Functions\when('apply_filters')->alias(static function ($hook, $value = null, ...$rest) {
            $mounted = $GLOBALS['_ntdst_test_filters_at'][(string) $hook] ?? [];
            ksort($mounted);

            foreach ($mounted as $callback) {
                $value = $callback($value, ...$rest);
            }

            return $value;
        });
    }

    protected function tearDown(): void
    {
        if ($this->recorder !== null) {
            spl_autoload_unregister($this->recorder);
            $this->recorder = null;
        }

        $this->sweepLitter();

        Monkey\tearDown();
        parent::tearDown();
    }

    // ========================================================================
    // A2 / I-4 — a missing listed service is reported where production reads
    // ========================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function missingSectorProvider(): array
    {
        return [
            'services.core' => ['core', false],
            'services.admin' => ['admin', true],
        ];
    }

    /**
     * A2 / I-4 — a listed class nothing loaded is logged at ERROR level, with
     * WP_DEBUG off.
     *
     * `_doing_it_wrong()` is the right channel for a developer at their desk and
     * the wrong one for a live site: WordPress decides whether it prints, logs
     * or does nothing at all, and with `WP_DEBUG` off it does nothing. The
     * failure it is hiding is the expensive one — a service the config lists,
     * missing, on production, with no trace anywhere. bootService() already logs
     * a failed CONSTRUCTION at error level for exactly this reason; a service
     * that never got as far as construction deserves the same.
     *
     * WP_DEBUG being off is asserted first, so "the log line fired anyway" is a
     * statement about the log line and not about the environment.
     *
     * @dataProvider missingSectorProvider
     */
    public function testAListedClassThatIsNotLoadedIsLoggedAtErrorLevelWithDebugOff(
        string $sector,
        bool $isAdmin,
    ): void {
        $this->assertFalse(
            defined('WP_DEBUG') && WP_DEBUG,
            'This case is only meaningful with WP_DEBUG off; the suite runs with it undefined. If a later test '
                . 'file defines it, this case has to be moved to its own process.',
        );

        $GLOBALS['_ntdst_walk_is_admin'] = $isAdmin;
        $missing = 'Ntdst\\Walk\\AbsentService';

        $boot = new NTDST_Bootstrap(['services' => [$sector => [$missing]]]);
        $boot->register();

        $errors = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'],
            static fn(array $entry) => $entry[1] === 'error',
        ));

        $this->assertCount(
            1,
            $errors,
            'A listed service that is not loaded must leave ONE error-level record — the only trace a '
                . 'production site gets, because _doing_it_wrong() is WP_DEBUG-gated. Log: '
                . var_export($GLOBALS['_ntdst_test_log'], true),
        );
        $this->assertStringContainsString(
            $missing,
            (string) $errors[0][2],
            'The error names the class the consumer listed.',
        );
        $this->assertStringContainsString(
            "services.{$sector}",
            (string) $errors[0][2],
            'and the config key it came from — a fleet config lists services in three places.',
        );

        $this->assertCount(
            1,
            $this->wrongs,
            'The developer-facing notice stays beside it: two channels, one event. ' . $this->wrongsText(),
        );
    }

    /**
     * A2, the empty state — a boot with nothing missing writes no error at all.
     *
     * The half that makes the case above mean something. An implementation that
     * logs an error for every registration would satisfy the assertion up there
     * and turn every healthy site's log into noise, which is how a real error
     * gets scrolled past.
     */
    public function testAHealthyBootWritesNoErrorRecord(): void
    {
        $boot = new NTDST_Bootstrap(['services' => ['core' => [WalkCountingService::class]]]);
        $boot->register()->bootFeatures();

        $errors = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'],
            static fn(array $entry) => $entry[1] === 'error',
        ));

        $this->assertSame(
            [],
            $errors,
            'Nothing went wrong, so nothing is logged at error level: ' . var_export($errors, true),
        );
    }

    // ========================================================================
    // A11 — one unresolvable class is ONE refusal, however often it is listed
    // ========================================================================

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function repeatedMissingClassProvider(): array
    {
        return [
            'the same name twice in one sector' => [
                ['core' => ['Ntdst\\Walk\\AbsentService', 'Ntdst\\Walk\\AbsentService']],
                false,
            ],
            'the same name in core and in admin' => [
                ['core' => ['Ntdst\\Walk\\AbsentService'], 'admin' => ['Ntdst\\Walk\\AbsentService']],
                true,
            ],
        ];
    }

    /**
     * A11 — a class the consumer listed twice is refused ONCE.
     *
     * `registerService()` dedupes on `$this->services`, and an unresolvable class
     * never gets INTO `$this->services` — so the second listing is refused all
     * over again. Both rows are ordinary fleet shapes: a service moved between
     * sectors and left behind in the old one, or a copy-paste inside one list.
     * The consequence is not cosmetic now that a missing service also writes an
     * error record (A2): a duplicated entry doubles every line in a production
     * log, and a reader who sees the same class twice starts looking for two
     * different problems.
     *
     * Both channels are counted, because they are one event reported twice over
     * and a fix that dedupes only the notice leaves the log doubled.
     *
     * @dataProvider repeatedMissingClassProvider
     * @param array<string, mixed> $services
     */
    public function testAClassListedTwiceAndMissingIsRefusedOnlyOnce(array $services, bool $isAdmin): void
    {
        $GLOBALS['_ntdst_walk_is_admin'] = $isAdmin;

        $boot = new NTDST_Bootstrap(['services' => $services]);
        $boot->register();

        $this->assertCount(
            1,
            $this->wrongs,
            'One missing class is one refusal, however many times the config names it: ' . $this->wrongsText(),
        );

        $errors = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'],
            static fn(array $entry) => $entry[1] === 'error',
        ));

        $this->assertCount(
            1,
            $errors,
            'and one line in the production log, not one per listing: ' . var_export($errors, true),
        );
    }

    // ========================================================================
    // A6 / S-4 — the latch is set before the walk, not after it
    // ========================================================================

    /**
     * A6 / S-4, the behaviour that must not change — a throw out of a service's
     * `metadata()` still surfaces to the caller.
     *
     * Registration has no try/catch: `getServiceMetadata()` calls the consumer's
     * static method directly, so an exception in it propagates out of
     * `register()` and the request dies there. That is today's contract and the
     * fix must not quietly swallow it — a service whose declaration throws is a
     * broken deployment, not a warning. This case is here so the latch fix
     * cannot be implemented by wrapping the walk in a catch.
     */
    public function testAThrowFromAServicesMetadataStillSurfacesFromRegister(): void
    {
        $boot = new NTDST_Bootstrap(['services' => ['core' => [WalkThrowsOnceService::class]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('metadata() blew up');

        $boot->register();
    }

    /**
     * A6 / S-4 — a `register()` that threw mid-walk is still spent: the second
     * call walks nothing.
     *
     * `servicesRegistered` is set on the LAST line of `register()` today, so a
     * throw anywhere in the walk leaves the latch down. A consumer that wires
     * `register()` to two hooks — or a plugin that boots twice on one request —
     * then re-runs the whole list: every class re-registered, every refusal
     * printed a second time. The service below throws only the FIRST time its
     * `metadata()` is asked, which is exactly the shape that makes the retry
     * look like it worked while quietly duplicating everything the first pass
     * did.
     *
     * Both halves are asserted: the list is not walked again (the counter does
     * not move) and the retry adds no notice to whatever the first call already
     * said. `register()` is once per instance, however it ended.
     */
    public function testASecondRegisterAfterAFailedFirstOneWalksNothingAgain(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [WalkThrowsOnceService::class],
                'overrides' => ['typo' => []],
            ],
        ]);

        try {
            $boot->register();
            $this->fail('The first register() was supposed to throw out of metadata().');
        } catch (RuntimeException) {
            // Expected: pinned by the case above.
        }

        $afterFirst = count($this->wrongs);

        $boot->register();

        $this->assertSame(
            1,
            $GLOBALS['_ntdst_walk_metadata_calls'][WalkThrowsOnceService::class] ?? 0,
            'The second register() walked the list again. The latch has to be set BEFORE the walk, or a boot '
                . 'that failed half way through re-runs every registration it already did.',
        );
        $this->assertCount(
            $afterFirst,
            $this->wrongs,
            'and a retry must add no notice of its own — one misconfiguration is one notice, however many '
                . 'times register() is called: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // A7 / S-5 — `metadata()` is static, and it is read once
    // ========================================================================

    /**
     * A7 / S-5 — an INSTANCE `metadata()` draws a notice and the class is
     * treated as declaring nothing.
     *
     * `method_exists()` answers true for an instance method, and core then calls
     * `$class::metadata()` — a fatal `Error` on every request, out of
     * `register()`, before the page renders. The consumer's mistake is one
     * missing `static` keyword in their own service. The framework's answer to a
     * malformed declaration is the same as everywhere else in this cluster: name
     * it, skip it, keep booting. Skipping it means the class has NO declared
     * metadata — so its slug is the one derived from the class name, and the
     * `name` in the unreadable declaration pins nothing.
     */
    public function testAnInstanceMetadataMethodIsRefusedAndTheClassBootsWithoutIt(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [WalkInstanceMetadataService::class],
                'overrides' => ['walk_instance_metadata' => ['reached' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [WalkInstanceMetadataService::class],
            $GLOBALS['_ntdst_walk_constructed'],
            'A missing `static` keyword in a consumer\'s service must not be a fatal at boot.',
        );

        $this->assertCount(
            1,
            $this->wrongs,
            'It is a misconfiguration and says so, once: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            WalkInstanceMetadataService::class,
            $this->wrongs[0][1],
            'The notice names the class whose declaration is wrong.',
        );
        $this->assertStringContainsString(
            'static',
            $this->wrongs[0][1],
            'and says what is wrong with it: metadata() is a declaration, read off the class.',
        );

        $this->assertTrue(
            ($GLOBALS['_ntdst_walk_config']['walk_instance_metadata'] ?? [])['reached'] ?? null,
            'An unreadable declaration declares NOTHING: the slug is the one derived from the class name, and '
                . 'an override keyed on it must still reach the service.',
        );
    }

    /**
     * A7 / S-5 — `metadata()` runs exactly once per class per boot.
     *
     * It is a consumer's method and core calls it twice today: once for the
     * metadata array, once more through `getServiceSlug()` to find out whether a
     * name was declared. Anything a service does in there — a translation call,
     * a `get_option()`, a lazy build — happens twice on every request, and a
     * declaration that is read twice is a declaration that can answer
     * differently the second time. Two services are registered together so the
     * count is proved per class rather than in aggregate.
     */
    public function testEachServicesMetadataIsReadExactlyOncePerBoot(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [WalkCountingService::class, WalkSecondCountingService::class],
                'overrides' => ['walk_counting' => ['seen' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [
                WalkCountingService::class => 1,
                WalkSecondCountingService::class => 1,
            ],
            $GLOBALS['_ntdst_walk_metadata_calls'],
            'metadata() is a declaration, not a query: core reads each class\'s once per boot — including the '
                . 'read that resolves the slug.',
        );
        $this->assertTrue(
            ($GLOBALS['_ntdst_walk_config']['walk_counting'] ?? [])['seen'] ?? null,
            'and reading it once still leaves the slug right: the override reaches its service.',
        );
    }

    // ========================================================================
    // A8 / simplicity 1 — one walk over the list
    // ========================================================================

    /**
     * A8 — the list is traversed by `register()` alone.
     *
     * `listedServiceClasses()` was a second traversal of the same three keys,
     * with its own rules about what counts as an entry — which is how the two
     * passes came to disagree (it silently DROPS a non-string entry that the
     * registration walk turns into a TypeError). `register()` already visits
     * every entry; it collects the names it saw and hands them on. The absence
     * of the method is the assertion, because a private helper nobody calls is
     * the second copy growing back.
     *
     * The behaviour the fold must preserve is pinned elsewhere and stays green:
     * BootstrapOverridesTest's listed-versus-registered cases — an override for
     * a disabled service, a conditional that does not hold, an admin service on
     * a front-end request — are all silent, and a key no listed class answers to
     * is refused.
     */
    public function testTheListedClassesHelperIsNoLongerAMethodOfBootstrap(): void
    {
        $this->assertFalse(
            method_exists(NTDST_Bootstrap::class, 'listedServiceClasses'),
            'register() walks the services list once and carries the names it collected to the override check. '
                . 'A second traversal is a second set of rules about what an entry is, and the two drifted.',
        );
    }

    // ========================================================================
    // A10 / reviewer S2 — a slug is resolved only when it is needed
    // ========================================================================

    /**
     * A10 / reviewer S2 — a correct config resolves no slug for a class this
     * request never registered.
     *
     * Resolving a slug calls `method_exists()` on the class, and `method_exists()
     * autoloads. The dead-key check resolves the slug of every LISTED class,
     * so on a site with any override at all every admin service's class file is
     * pulled into memory on every anonymous page view — and its `metadata()`
     * runs there too. The check has to ask the REGISTERED slugs first, which it
     * already has, and only fall back to resolving listed classes when a key
     * matches none of them.
     *
     * The admin service here exists only as a file that no autoloader but this
     * test's recorder can find, so "was it resolved" is answered by the loader
     * itself rather than by a mock of the function core happens to call today.
     * The override key is the core service's DERIVED slug — the ordinary case on
     * the fleet, and it must draw no notice.
     */
    public function testAValidOverrideKeyDoesNotAutoloadAListedAdminServiceOnAFrontEndRequest(): void
    {
        $this->plantTheLazyAdminService();
        $this->recordEveryAutoloadRequest();

        $GLOBALS['_ntdst_walk_is_admin'] = false;

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [WalkLazyCoreService::class],
                'admin' => ['NtdstWalkLazy\\AdminService'],
                'overrides' => ['walk_lazy_core' => ['eager' => false]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertNotContains(
            'NtdstWalkLazy\\AdminService',
            $GLOBALS['_ntdst_walk_autoloaded'],
            'A front-end request autoloaded an admin service, to answer a question about an override key that '
                . 'already matched a registered slug. Names asked for: '
                . implode(', ', $GLOBALS['_ntdst_walk_autoloaded']),
        );
        $this->assertSame(
            0,
            $GLOBALS['_ntdst_walk_metadata_calls']['NtdstWalkLazy\\AdminService'] ?? 0,
            'and it must not run the class\'s metadata() either — that is consumer code, on a request the '
                . 'service is not part of.',
        );

        $this->assertSame(
            [],
            $this->wrongs,
            'The key names a registered service by its derived slug: refusing it would be a false alarm on the '
                . 'ordinary fleet config. ' . $this->wrongsText(),
        );
        $this->assertSame(
            ['eager' => false, 'ttl' => 60],
            $GLOBALS['_ntdst_walk_config']['walk_lazy_core'] ?? null,
            'and the lazy path must not cost the override its delivery: it still reaches the core service, '
                . 'merged over its defaults.',
        );
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * Write the admin service to a file no autoloader but this test's recorder
     * can find, the way daan-core's services really sit on disk.
     */
    private function plantTheLazyAdminService(): void
    {
        $this->plantFile(
            '/lazy',
            'AdminService.php',
            "<?php namespace NtdstWalkLazy;\n"
                . "class AdminService {\n"
                . "    public static function metadata(): array {\n"
                . "        \$key = 'NtdstWalkLazy\\\\AdminService';\n"
                . "        \$GLOBALS['_ntdst_walk_metadata_calls'][\$key] "
                . "= (\$GLOBALS['_ntdst_walk_metadata_calls'][\$key] ?? 0) + 1;\n"
                . "        return ['name' => 'walk lazy admin', 'admin_only' => true];\n"
                . "    }\n"
                . "}\n",
        );
    }

    /**
     * Install a real autoloader that records every name it is asked for, and
     * resolves the planted admin service.
     */
    private function recordEveryAutoloadRequest(): void
    {
        $root = $this->root;

        $this->recorder = static function (string $name) use ($root): void {
            $GLOBALS['_ntdst_walk_autoloaded'][] = $name;

            if ($name === 'NtdstWalkLazy\\AdminService' && is_file($root . '/lazy/AdminService.php')) {
                require_once $root . '/lazy/AdminService.php';
            }
        };

        spl_autoload_register($this->recorder);
    }
}

/** Its `metadata()` throws the first time it is asked, and only the first. */
final class WalkThrowsOnceService
{
    public static function metadata(): array
    {
        $key = self::class;
        $GLOBALS['_ntdst_walk_metadata_calls'][$key] = ($GLOBALS['_ntdst_walk_metadata_calls'][$key] ?? 0) + 1;

        if ($GLOBALS['_ntdst_walk_metadata_calls'][$key] === 1) {
            throw new RuntimeException('metadata() blew up');
        }

        return [];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_walk_constructed'][] = static::class;
    }
}

/** A consumer who forgot `static`. The `name` here must pin nothing. */
final class WalkInstanceMetadataService
{
    public function metadata(): array
    {
        return ['name' => 'pinned by an unreadable declaration'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_walk_constructed'][] = static::class;
        $GLOBALS['_ntdst_walk_config']['walk_instance_metadata'] = apply_filters(
            'ntdst/service/walk_instance_metadata/config',
            [],
        );
    }
}

/** Counts every read of its declaration. */
final class WalkCountingService
{
    public static function metadata(): array
    {
        $key = self::class;
        $GLOBALS['_ntdst_walk_metadata_calls'][$key] = ($GLOBALS['_ntdst_walk_metadata_calls'][$key] ?? 0) + 1;

        return ['description' => 'declares no name, so the slug is derived'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_walk_constructed'][] = static::class;
        $GLOBALS['_ntdst_walk_config']['walk_counting'] = apply_filters('ntdst/service/walk_counting/config', []);
    }
}

/** The second counter: the promise is per class, not per boot. */
final class WalkSecondCountingService
{
    public static function metadata(): array
    {
        $key = self::class;
        $GLOBALS['_ntdst_walk_metadata_calls'][$key] = ($GLOBALS['_ntdst_walk_metadata_calls'][$key] ?? 0) + 1;

        return ['name' => 'walk second'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_walk_constructed'][] = static::class;
    }
}

/** The core service whose DERIVED slug carries the one valid override key. */
final class WalkLazyCoreService
{
    public const DEFAULTS = ['eager' => true, 'ttl' => 60];

    public function __construct()
    {
        $GLOBALS['_ntdst_walk_constructed'][] = static::class;
        $GLOBALS['_ntdst_walk_config']['walk_lazy_core'] = apply_filters(
            'ntdst/service/walk_lazy_core/config',
            self::DEFAULTS,
        );
    }
}
