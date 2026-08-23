<?php // tests/Unit/BootstrapOverridesTest.php
// core-trim T02 / FR-2 — the enable switch goes; overrides keep ONE filter,
// under its new name, and a key that matches no service is refused.
//
// THE THREE FAILURES THIS FILE EXISTS TO CATCH, in the order they cost.
//
// 1. A SERVICE MEANT TO BE OFF BOOTS (threat row #2). Until 5.0.0 a service
//    could be switched off in three places: `metadata()['enabled']`, a
//    `conditional` entry's condition, and — fail-open — the filter
//    `ntdst_service_{slug}_enabled` plus the option `ntdst_service_{slug}`.
//    The third one is the wart `docs/philosophy.md` §4 names: a DENY filter
//    that answers `true` when nobody is listening, so any typo in the slug is a
//    service the site owner believes is off and is not. It is removed, and the
//    assertions below are not "the filter is unused" — they are "core never
//    ASKS", because a switch nobody reads is still a switch a reader believes
//    in. The two remaining ways are asserted alive in the same file: deleting a
//    third switch is worth nothing if it takes one of the other two with it.
//
// 2. AN OVERRIDE SILENTLY LOST (threat row #3). The config-override filter is
//    renamed `ntdst_service_{slug}_config` -> `ntdst/service/{slug}/config`.
//    An un-renamed consumer keeps calling the old name and gets its bare
//    defaults back — no error, no notice, the site just quietly stops hiding
//    its WordPress version. So this pins BOTH halves: the new name carries the
//    override, and the old name is never mounted and never applied. One filter,
//    one name.
//
// 3. A TYPO'D OVERRIDE KEY IS INERT. `services.overrides.securty => [...]`
//    matched no slug and did nothing at all — the same silence as #2, reached
//    from the consumer's side. `register()` refuses it out loud now, naming the
//    key the reader has to fix.
//
// HOW THE OVERRIDE IS OBSERVED. Not by reading a cache off Bootstrap: the
// accessor that exposed it (`getServiceConfig()`) is removed by this very task,
// and a promise about an internal array is not the promise a consumer relies
// on. The inline services below read their config the way stride's
// SecurityService:60 and PerformanceService:39 really do — `apply_filters()` on
// their own slug, inside the constructor — and record what came back. Core
// mounts its callback through `add_filter()`, which is the suite's REAL
// recorder (tests/bootstrap.php), so `wordPressDispatchesItsFilters()` runs the
// mounted callbacks the way WordPress would. That is the full round trip: the
// consumer asks, and the value it gets back either carries the override or does
// not.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapOverridesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Every `_doing_it_wrong()` this test provoked: [function, message, version].
     *
     * Recorded rather than counted by a Mockery `->times(1)`, the way
     * BootstrapResolvesOnlyLoadedClassesTest does it: a refusal is judged on
     * WHAT IT SAYS, and a count failure has to be able to print the refusals
     * that did fire.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private array $wrongs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->wrongs = [];

        $GLOBALS['_ntdst_t02_constructed'] = [];   // services core booted, in order
        $GLOBALS['_ntdst_t02_config'] = [];        // slug => the config the service read back
        $GLOBALS['_ntdst_t02_applied'] = [];       // every filter name applied, when dispatching

        // `add_filter()` is the suite's real recorder and is never reset by
        // Brain Monkey, so a mount made by an earlier test file is still on the
        // bus. Clear only this task's hooks: "the old name was never mounted"
        // is a claim about THIS run.
        foreach (['_ntdst_test_filters', '_ntdst_test_filters_at'] as $bag) {
            foreach (array_keys($GLOBALS[$bag] ?? []) as $hook) {
                if (str_starts_with((string) $hook, 'ntdst/service/') || str_starts_with((string) $hook, 'ntdst_service_')) {
                    unset($GLOBALS[$bag][$hook]);
                }
            }
        }

        // ntdst_log(), ntdst_set(), ntdst_get() and add_filter() are the suite's
        // REAL implementations (tests/bootstrap.php loads them before
        // Patchwork, so they cannot be stubbed). See the note there.
        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);
        Functions\when('_doing_it_wrong')->alias(function ($function = '', $message = '', $version = '') {
            $this->wrongs[] = [(string) $function, (string) $message, (string) $version];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ========================================================================
    // DENIAL — an overrides key that matches no registered service
    // ========================================================================

    /**
     * FR-2 / AF-5b — a typo'd `services.overrides` key is refused ONCE at
     * `register()`, and the notice names the key.
     *
     * The consumer's half of threat row #3. `overrides.typo` reached no service
     * and said nothing; the site owner edited a config, reloaded, saw no
     * change, and had nothing to grep for. The refusal has to carry the FULL
     * dotted key, because that is the string they will search their config for
     * — a notice saying "unknown service override" sends them hunting.
     *
     * Three further promises ride along, and each is a way this could be
     * implemented wrong: the typo must register NOTHING (no filter under its
     * name, no phantom service), the correctly-keyed override in the same array
     * must still reach its service, and the good key must draw no notice of its
     * own.
     */
    public function testAnOverrideKeyMatchingNoRegisteredServiceIsRefusedAndTheGoodOneStillApplies(): void
    {
        $this->wordPressDispatchesItsFilters();
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [T02SecurityService::class],
                'overrides' => [
                    'security' => ['hide_wp_version' => true],
                    'typo' => [],
                ],
            ],
        ]);

        $boot->register();

        $this->assertCount(
            1,
            $this->wrongs,
            'An overrides key matching no registered service must produce exactly one _doing_it_wrong() at '
                . 'register(); got: ' . $this->wrongsText(),
        );

        [$function, $message, $version] = $this->wrongs[0];

        $this->assertStringContainsString(
            'services.overrides.typo',
            $message,
            'The notice must name the full dotted key — that is the string the site owner greps their config for.',
        );
        $this->assertStringContainsString(
            'matches no registered service',
            $message,
            'And it must say WHY: the key is not a service slug. A bare key with no reason is a riddle.',
        );
        $this->assertStringContainsString(
            'NTDST_Bootstrap',
            $function,
            '_doing_it_wrong()\'s first argument is the function at fault — WordPress prints it, so it must say core.',
        );
        $this->assertSame('5.0.0', $version, 'The @since marker for the refusal: it arrived in 5.0.0.');

        $boot->bootFeatures();

        $this->assertArrayNotHasKey(
            'ntdst/service/typo/config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'A refused key must mount nothing — refusing loudly and wiring it up anyway is the worst of both.',
        );
        $this->assertNotContains(
            'ntdst/service/typo/config',
            $GLOBALS['_ntdst_t02_applied'],
            'and nothing may ask for it either.',
        );
        $this->assertSame(
            [T02SecurityService::class],
            $GLOBALS['_ntdst_t02_constructed'],
            'The typo invents no service; the listed one still boots.',
        );
        $this->assertTrue(
            ($GLOBALS['_ntdst_t02_config']['security'] ?? [])['hide_wp_version'] ?? null,
            'One bad key must not take the good one down with it: overrides.security still reaches its service.',
        );
    }

    /**
     * FR-2 / AF-14 — re-entry: a second `register()` does not repeat the refusal.
     *
     * `register()` is idempotent by the `servicesRegistered` latch, and the
     * override check has to sit inside it. A notice that fires again on every
     * call turns one typo into a log flood, and with `WP_DEBUG_DISPLAY` on,
     * into repeated output on the page.
     */
    public function testTheOverrideRefusalDoesNotRepeatOnASecondRegisterCall(): void
    {
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [T02SecurityService::class],
                'overrides' => ['typo' => []],
            ],
        ]);

        $boot->register();
        $boot->register();

        $this->assertCount(
            1,
            $this->wrongs,
            'One typo is one notice, however many times register() is called: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // DENIAL — the two ways a service is OFF, which must both stay alive
    // ========================================================================

    /**
     * FR-2 — `metadata()['enabled'] => false` keeps a service off.
     *
     * The first of the two surviving switches. Removing the fail-open filter is
     * only safe because this one is exact: it is the service's own declaration,
     * read from the class, with nothing between it and the decision.
     */
    public function testAServiceWhoseMetadataSaysEnabledFalseIsNotRegistered(): void
    {
        $this->wordPressDispatchesItsFilters();
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [T02DisabledService::class, T02SecurityService::class],
                'overrides' => ['security' => ['hide_wp_version' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [T02SecurityService::class],
            $GLOBALS['_ntdst_t02_constructed'],
            'A service that declares enabled => false must not be constructed — that declaration is the switch now.',
        );
        $this->assertArrayNotHasKey(
            'ntdst/service/disabled_one/config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'A service that never registers mounts no config filter either.',
        );
    }

    /**
     * FR-2 — a `conditional` entry whose condition returns false stays off, and
     * its sibling whose condition returns true boots.
     *
     * The second surviving switch, asserted in the same run as a live one. A
     * test that only proves the false case passes just as well against a
     * `conditional` block that stopped working altogether — which would be the
     * same outage as a wrong refusal, arriving quietly.
     */
    public function testAConditionalWhoseConditionIsFalseIsNotRegisteredAndOneThatHoldsIs(): void
    {
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'conditional' => [
                    'never' => ['condition' => static fn(): bool => false, 'service' => T02ConditionalOffService::class],
                    'always' => ['condition' => static fn(): bool => true, 'service' => T02ConditionalOnService::class],
                ],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [T02ConditionalOnService::class],
            $GLOBALS['_ntdst_t02_constructed'],
            'A false condition keeps its service off; a true one must still boot.',
        );
        $this->assertSame(
            [],
            $this->wrongs,
            'A conditional that simply does not apply is not a misconfiguration: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // THERE IS NO THIRD SWITCH
    // ========================================================================

    /**
     * FR-2 / SC-1 — a service with no `enabled` key registers, and core asks
     * neither the option nor the retired filter to find that out.
     *
     * The strong form of the removal. "The filter no longer disables anything"
     * would pass against code that still applies it and ignores the answer —
     * and a filter core still applies is a filter a consumer still finds in a
     * hook dump and still writes an `add_filter` against. `->never()` on both
     * is the promise: the question is not asked at all, so there is nothing
     * left to fail open.
     */
    public function testAServiceWithNoEnabledKeyRegistersWithoutAskingAnOptionOrAFilter(): void
    {
        Functions\expect('get_option')->never();
        Filters\expectApplied('ntdst_service_security_enabled')->never();

        $boot = new NTDST_Bootstrap(['services' => ['core' => [T02SecurityService::class]]]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [T02SecurityService::class],
            $GLOBALS['_ntdst_t02_constructed'],
            'A service that says nothing about `enabled` is on — the default is not a question for the database.',
        );
    }

    // ========================================================================
    // OVERRIDES — one filter, the new name
    // ========================================================================

    /**
     * FR-2 / FR-11 / AF-5 — a declared override reaches the service through
     * `ntdst/service/{slug}/config`, merged over the service's own defaults.
     *
     * The round trip stride depends on: `theme-config.php` declares
     * `overrides.security.hide_wp_version = true`, and SecurityService's
     * `apply_filters()` on its own slug must come back with it. The untouched
     * default is asserted beside it — an override that REPLACES the defaults
     * instead of merging over them is the same outage with a different cause,
     * and only a key the service did not name can tell the two apart.
     */
    public function testTheOverrideReachesTheServiceThroughTheRenamedFilter(): void
    {
        $this->wordPressDispatchesItsFilters();
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [T02SecurityService::class],
                'overrides' => ['security' => ['hide_wp_version' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $config = $GLOBALS['_ntdst_t02_config']['security'] ?? null;

        $this->assertIsArray($config, 'The service never read its config back at all.');
        $this->assertTrue(
            $config['hide_wp_version'] ?? null,
            'The declared override must win over the service default — that is the whole point of overrides.',
        );
        $this->assertSame(
            'keep',
            $config['generator'] ?? null,
            'and a default the override did not name must survive: the override MERGES, it does not replace.',
        );
        $this->assertCount(2, $config, 'No key invented, none lost: ' . var_export($config, true));
        $this->assertContains(
            'ntdst/service/security/config',
            $GLOBALS['_ntdst_t02_applied'],
            'The new hook name is the one the service asks on.',
        );
    }

    /**
     * FR-2 / FR-11 / threat row #3 — the retired config-filter name is neither
     * mounted nor applied.
     *
     * One filter, one name. A rename that leaves the old name mounted "for
     * compatibility" is two names for one decision, and 5.0.0 ships no shim
     * (D5). The mount is checked as well as the application, because an unused
     * mount is what a consumer's hook dump would show them — and they would
     * write against it.
     */
    public function testTheRetiredConfigFilterNameIsNeverMountedOrApplied(): void
    {
        $this->wordPressDispatchesItsFilters();
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [T02SecurityService::class],
                'overrides' => ['security' => ['hide_wp_version' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertArrayNotHasKey(
            'ntdst_service_security_config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'The retired name must not be mounted: a hook a consumer can still see is a hook they will still write.',
        );
        $this->assertNotContains(
            'ntdst_service_security_config',
            $GLOBALS['_ntdst_t02_applied'],
            'and core must not apply it either.',
        );
        $this->assertNotContains(
            'ntdst_service_security_enabled',
            $GLOBALS['_ntdst_t02_applied'],
            'The retired DENY filter is gone with it — see the no-third-switch case.',
        );
    }

    /**
     * FR-2 — a service that declares no `name` keys its override on the slug
     * DERIVED from its class.
     *
     * `getServiceSlug()` / `declaredServiceName()` survive this task for exactly
     * one reader: this filter. stride's PerformanceService declares no name, so
     * its override is keyed by the derivation — and a rename that quietly
     * dropped the derivation would leave that site's override matching nothing,
     * which is now a refusal (see the typo case) rather than silence.
     */
    public function testAServiceThatDeclaresNoNameKeysItsOverrideOnTheDerivedSlug(): void
    {
        $this->wordPressDispatchesItsFilters();
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [T02PerformanceService::class],
                'overrides' => ['t02_performance' => ['lazy_load' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [],
            $this->wrongs,
            'The derived slug IS a registered service slug — refusing it would be a false alarm: ' . $this->wrongsText(),
        );
        $this->assertTrue(
            ($GLOBALS['_ntdst_t02_config']['t02_performance'] ?? [])['lazy_load'] ?? null,
            'An override keyed on the derived slug must reach the service.',
        );
    }

    /**
     * FR-2 — the empty state: a service with no override declared gets its own
     * defaults, and core mounts no filter for it.
     *
     * Most services on every site are this case. A mount for a service nobody
     * overrode is a callback on every request that can only return what it was
     * given, and a defaults array that came back CHANGED here would mean core
     * is merging something the consumer never wrote.
     */
    public function testAServiceWithNoOverrideKeepsItsDefaultsAndMountsNoFilter(): void
    {
        $this->wordPressDispatchesItsFilters();
        $this->wordPressAnswersOptionReads();

        $boot = new NTDST_Bootstrap(['services' => ['core' => [T02SecurityService::class]]]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            T02SecurityService::DEFAULTS,
            $GLOBALS['_ntdst_t02_config']['security'] ?? null,
            'With nothing declared, the service reads back exactly what it asked with.',
        );
        $this->assertArrayNotHasKey(
            'ntdst/service/security/config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'No override declared is no callback mounted.',
        );
    }

    // ========================================================================
    // STRUCTURE — the accessors are gone, not merely unused
    // ========================================================================

    /**
     * FR-2 / SC-3 — Bootstrap's public surface is exactly the five lifecycle
     * methods.
     *
     * `getServiceConfig()`, `getServices()`, `getBootedServices()`,
     * `hasService()` and `isBooted()` had zero readers across daan, josworld,
     * stride, todai and netdust — they were a second, read-only copy of the
     * registry, and a copy is a thing that can disagree with the original. The
     * list is asserted WHOLE rather than name by name, because the promise is
     * "this class has five public methods", not "these five particular ones
     * left": a sixth accessor added next quarter is the same drift arriving
     * again.
     */
    public function testBootstrapsPublicSurfaceIsExactlyTheFiveLifecycleMethods(): void
    {
        $public = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NTDST_Bootstrap::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($public);

        $this->assertSame(
            ['__construct', 'bootCore', 'bootFeatures', 'config', 'register'],
            $public,
            'NTDST_Bootstrap is a lifecycle, not a registry to read back.',
        );
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * Run the callbacks `add_filter()` recorded, in priority order, the way
     * WordPress runs them.
     *
     * The suite's `add_filter()` is real and records to
     * `$GLOBALS['_ntdst_test_filters_at'][$hook][$priority]` (tests/bootstrap.php),
     * and Brain Monkey's `apply_filters()` only TRACKS a call — it never runs
     * what was mounted. Without this, "the override reached the service" could
     * not be asked at all: every filtered value would come back untouched and
     * the merge would look broken whatever core did. This is WordPress's own
     * dispatch for the one shape core uses, so the assertions above are about
     * the value a real request would carry.
     */
    private function wordPressDispatchesItsFilters(): void
    {
        Functions\when('apply_filters')->alias(static function ($hook, $value = null, ...$rest) {
            $GLOBALS['_ntdst_t02_applied'][] = (string) $hook;

            $mounted = $GLOBALS['_ntdst_test_filters_at'][(string) $hook] ?? [];
            ksort($mounted);

            foreach ($mounted as $callback) {
                $value = $callback($value, ...$rest);
            }

            return $value;
        });
    }

    /**
     * Answer an options read the way a fresh install does.
     *
     * Not a stub of the retired switch — a case that pins what core does with
     * an OVERRIDE must not fail because some unrelated read hit an undefined
     * function, so the environment answers. It is called from every case except
     * the no-third-switch one, which stubs nothing on purpose: `->never()` and
     * a `when()` in the same test cancel out, because the permissive
     * expectation matches the call first and leaves `never()` satisfied
     * vacuously. That is the trap this comment exists to keep set.
     */
    private function wordPressAnswersOptionReads(): void
    {
        Functions\when('get_option')->justReturn('1');
    }

    /** The refusals that fired, as one readable line. */
    private function wrongsText(): string
    {
        if ($this->wrongs === []) {
            return '(no _doing_it_wrong call)';
        }

        return implode(' | ', array_map(static fn(array $w) => $w[0] . ': ' . $w[1], $this->wrongs));
    }
}

/**
 * stride's SecurityService in miniature: it declares its own slug, says nothing
 * about `enabled`, and reads its config through the filter on its own slug
 * inside the constructor — which is where SecurityService:60 really reads it.
 */
final class T02SecurityService
{
    public const DEFAULTS = ['hide_wp_version' => false, 'generator' => 'keep'];

    public static function metadata(): array
    {
        return ['name' => 'security', 'description' => 'declares its slug; says nothing about enabled'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_t02_constructed'][] = static::class;
        $GLOBALS['_ntdst_t02_config']['security'] = apply_filters('ntdst/service/security/config', self::DEFAULTS);
    }
}

/** stride's PerformanceService in miniature: no declared name, so the slug is derived. */
final class T02PerformanceService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_t02_constructed'][] = static::class;
        $GLOBALS['_ntdst_t02_config']['t02_performance'] = apply_filters(
            'ntdst/service/t02_performance/config',
            ['lazy_load' => false],
        );
    }
}

/** Switched off the one way a service switches itself off. */
final class T02DisabledService
{
    public static function metadata(): array
    {
        return ['name' => 'disabled_one', 'enabled' => false];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_t02_constructed'][] = static::class;
    }
}

/** Listed under a `conditional` whose condition is false. */
final class T02ConditionalOffService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_t02_constructed'][] = static::class;
    }
}

/** Listed under a `conditional` whose condition holds. */
final class T02ConditionalOnService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_t02_constructed'][] = static::class;
    }
}
