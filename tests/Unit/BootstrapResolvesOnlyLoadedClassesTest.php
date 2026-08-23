<?php // tests/Unit/BootstrapResolvesOnlyLoadedClassesTest.php
// core-trim T01 / FR-1 / INV-10 — Bootstrap registers exactly the classes the
// consumer listed and PHP can already resolve, and refuses an unresolvable one
// loudly. It reads no file and derives no path.
//
// THE TWO FAILURES THIS FILE EXISTS TO CATCH, in the order they cost.
//
// 1. LOADING CODE NOBODY LISTED (threat row #1). Until 5.0.0 `register()`
//    globbed `*Service.php` under `services.discovery_paths`, `require_once`d
//    every hit, and then regex-parsed the source for its `namespace`/`class`.
//    `registerService()` did the mirror trick for a name it could not resolve:
//    it turned the class name into a relative path, tried it twice (as spelled,
//    then with the leading namespace segment dropped), and `require_once`d
//    whatever it found. A writable directory anywhere on that list is code
//    execution, and a config value that resolves to an unexpected file is the
//    same bug from the other end. Both are deleted. The assertions below are
//    NOT "the scanner is configured off" — they are "the file on disk never
//    ran", which is the only form of the promise an attacker cares about.
//
// 2. A WRONG REFUSAL IS A SITE DOWN AT BOOT. `class_exists()` is now the whole
//    admission test, and three of five consumer sites load their services by
//    plain `require_once` with no Composer map at all (daan, josworld, netdust).
//    So the positive case here declares its service INLINE, in this file, with
//    no autoloader that could resolve it — the way daan-core does — and asserts
//    it registers, boots and comes back out of the container. A refusal there
//    would be a fatal-shaped outage on a site that did nothing wrong.
//
// WHERE "READ NO FILE" IS OBSERVED. Not here any more: the planted-file cases
// moved to the composite feature test (BootstrapLoadsNothingByGuessingTest),
// which plants the same traps inside a realistic consumer config. What stays in
// this file is the admission test itself — refused, resolved, refused once.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapResolvesOnlyLoadedClassesTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use NtdstRecordsRefusals;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->recordRefusals();

        // The globals the inline services write to.
        $GLOBALS['_ntdst_t01_constructed'] = [];   // services core booted
        $GLOBALS['_ntdst_t01_instances'] = [];     // the objects it booted
        $GLOBALS['_ntdst_t01_is_admin'] = false;

        // ntdst_log(), ntdst_set() and ntdst_get() are the suite's REAL
        // implementations (tests/bootstrap.php loads core/Container.php before
        // Patchwork, so they cannot be stubbed). See the note there.
        Functions\when('is_admin')->alias(static fn() => (bool) $GLOBALS['_ntdst_t01_is_admin']);
        Functions\when('do_action')->justReturn(null);
        Functions\when('apply_filters')->alias(static fn($hook, $value = null) => $value);
        Functions\when('get_option')->justReturn('1');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ========================================================================
    // DENIAL — a listed class PHP cannot resolve
    // ========================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function unresolvableSectorProvider(): array
    {
        return [
            'services.core' => ['core', false],
            'services.admin' => ['admin', true],
        ];
    }

    /**
     * FR-1 — an unresolvable listed class is refused ONCE, and the notice names
     * the class AND the config key it came from.
     *
     * Both halves are the deliverable. The old code wrote the same event to
     * `ntdst_log()->debug()`, which on a production site is a file nobody
     * opens: a service silently missing from every request, with the site
     * behaving as though it had never been configured. `_doing_it_wrong()` is
     * the WordPress-native channel for "the code calling me is wrong", and the
     * key is in the message because a fleet config lists services in three
     * places — a notice naming only the class sends the reader hunting.
     *
     * @dataProvider unresolvableSectorProvider
     */
    public function testAListedClassPhpCannotResolveIsRefusedOnceAndNamesItsConfigKey(string $sector, bool $admin): void
    {
        $GLOBALS['_ntdst_t01_is_admin'] = $admin;

        $boot = new NTDST_Bootstrap(['services' => [$sector => ['Nope\\Missing']]]);
        $boot->register()->bootFeatures();

        $this->assertCount(
            1,
            $this->wrongs,
            "A class listed under services.{$sector} that PHP cannot resolve must produce exactly one "
                . '_doing_it_wrong(); got: ' . $this->wrongsText(),
        );

        [$function, $message, $version] = $this->wrongs[0];

        $this->assertStringContainsString(
            'Nope\\Missing',
            $message,
            'The notice must name the class the consumer listed — that is the string they grep their config for.',
        );
        $this->assertStringContainsString(
            'services.' . $sector,
            $message,
            "The notice must name the config key the class came from; services.{$sector} is where the reader fixes it.",
        );
        $this->assertStringContainsString(
            'NTDST_Bootstrap',
            $function,
            '_doing_it_wrong()\'s first argument is the function at fault — WordPress prints it, so it must say core.',
        );
        $this->assertSame(
            '5.0.0',
            $version,
            'The version argument is the @since marker for the notice: this refusal arrived in 5.0.0.',
        );

        $this->assertSame(
            [],
            $GLOBALS['_ntdst_t01_constructed'],
            'A refused class must register nothing — refusing loudly and booting anyway is the worst of both.',
        );
    }

    // The two planted-file cases that stood here — a class name turned into a
    // file path, and a directory full of *Service.php — are the composite
    // feature test's now: BootstrapLoadsNothingByGuessingTest plants BOTH traps
    // under one realistic consumer config and asserts the same promise
    // (`_ntdst_ca_included === []`, both classes still unresolvable) on a boot
    // that also has live services in it. Two suites planting the same files
    // proved it twice; the stronger of the two is the one that kept it.

    /**
     * FR-1 / AF-14 — re-entry: a second `register()` does not repeat the refusal.
     *
     * `register()` is idempotent by the `servicesRegistered` latch. A notice
     * that fires again on every call turns one misconfiguration into a log
     * flood, and on a site with `WP_DEBUG_DISPLAY` on, into repeated output.
     */
    public function testASecondRegisterCallDoesNotRepeatTheRefusal(): void
    {
        $boot = new NTDST_Bootstrap(['services' => ['core' => ['Nope\\Missing']]]);

        $boot->register();
        $boot->register();

        $this->assertCount(
            1,
            $this->wrongs,
            'One misconfigured class is one notice, however many times register() is called: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // THE HAPPY PATH THAT MUST NOT BREAK — no autoloader anywhere
    // ========================================================================

    /**
     * FR-1 / SC-1 — a class PHP can already resolve registers, boots, and is the
     * container's singleton.
     *
     * `T01RequiredOnceService` is declared at the bottom of THIS FILE. No
     * Composer map names it and no autoloader can find it; PHP knows it only
     * because the file that declares it was executed — which is exactly how
     * daan, josworld and netdust load every service they own.
     *
     * The `assertSame` is the load-bearing one. `ntdst_get()` autowires an
     * unregistered class perfectly happily, so "an instance came back" proves
     * nothing on its own. What proves Bootstrap registered and booted it is
     * that the object the CONSTRUCTOR recorded during bootFeatures() is the
     * same object the container hands back afterwards.
     */
    public function testAClassLoadedWithoutAnyAutoloaderRegistersAndBoots(): void
    {
        $boot = new NTDST_Bootstrap(['services' => ['core' => [T01RequiredOnceService::class]]]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [T01RequiredOnceService::class],
            $GLOBALS['_ntdst_t01_constructed'],
            'A service PHP can resolve must boot exactly once — a refusal here is a site down at boot.',
        );

        $instance = ntdst_get(T01RequiredOnceService::class);

        $this->assertInstanceOf(T01RequiredOnceService::class, $instance);
        $this->assertSame(
            $GLOBALS['_ntdst_t01_instances'][0],
            $instance,
            'The booted instance IS the container singleton; anything else means this test autowired it, not Bootstrap.',
        );
        $this->assertSame(
            [],
            $this->wrongs,
            'A resolvable class must draw no notice at all: ' . $this->wrongsText(),
        );
    }

    /**
     * FR-1 — the refusal is per class, and one bad name does not take the good
     * ones down with it.
     *
     * The boundary between "loud" and "fatal". A consumer with one stale entry
     * in a list of twenty must lose that one service, not the site.
     */
    public function testOneUnresolvableEntryDoesNotStopTheResolvableOnes(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => ['core' => ['Nope\\Missing', T01SurvivorService::class]],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [T01SurvivorService::class],
            $GLOBALS['_ntdst_t01_constructed'],
            'The services after a refused one still boot.',
        );
        $this->assertCount(1, $this->wrongs, 'Exactly one entry was bad: ' . $this->wrongsText());
    }

    // ========================================================================
    // STRUCTURE — the machinery is gone, not switched off
    // ========================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function deletedMethodProvider(): array
    {
        return [
            'discoverServices' => ['discoverServices'],
            'discoverServicesInPath' => ['discoverServicesInPath'],
            'getClassNameFromFile' => ['getClassNameFromFile'],
            'isInConditionalConfig' => ['isInConditionalConfig'],
        ];
    }

    /**
     * FR-1 / INV-10 — the scanner is DELETED, not made unreachable.
     *
     * The behavioural assertions above pass just as well against a scanner
     * sitting behind a config flag that nothing sets today. A method that still
     * exists is a method a later task can call again by accident, and INV-10
     * says core loads nothing by guessing — not "core does not currently guess".
     *
     * @dataProvider deletedMethodProvider
     */
    public function testTheDiscoveryMachineryIsNoLongerAMethodOfBootstrap(string $method): void
    {
        $this->assertFalse(
            (new ReflectionClass(NTDST_Bootstrap::class))->hasMethod($method),
            "NTDST_Bootstrap::{$method}() must be deleted, not merely unreachable — INV-10 is a property of the class.",
        );
    }

    // The refusal recorder and the planted-file harness live in
    // tests/Support/BootstrapHarness.php — four suites carried the same copy.
}

/**
 * A service the way daan-core ships one: declared by an executed file, findable
 * by no autoloader.
 */
final class T01RequiredOnceService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_t01_constructed'][] = static::class;
        $GLOBALS['_ntdst_t01_instances'][] = $this;
    }
}

/** The good entry sitting after a bad one in the same list. */
final class T01SurvivorService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_t01_constructed'][] = static::class;
        $GLOBALS['_ntdst_t01_instances'][] = $this;
    }
}
