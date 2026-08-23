<?php // tests/Unit/BootstrapRefusesMalformedServiceListsTest.php
// core-trim Cluster A gate — the fail-closed half of the services list.
//
// THE CONTRACT (cluster-a-fix-brief.md A1, A3, A4, A5, A9; sentinels I-1, S-1,
// S-2, S-3; reviewer S4). Bootstrap reads a consumer's `services` array. Every
// value in it is config a human typed, and 5.0.0 made that array the ONLY thing
// core reads before it registers anything. So each malformed shape has to have
// an answer, and the answer is always the same one: ONE `_doing_it_wrong()`
// naming the offending value and the config key it came from, the entry
// skipped, and the rest of the boot untouched.
//
// THE FAILURES THIS FILE EXISTS TO CATCH, in the order they cost.
//
// 1. A CONFIG TYPO IS A WHITE SCREEN (I-1). `registerService(string $class)`
//    lives in a `declare(strict_types=1)` file, so a stray `0`, a `null` left by
//    a trailing comma edit, or a nested array is a TypeError thrown out of
//    `register()` — before the theme has rendered anything. The class docblock
//    promises a notice. A fatal is not a notice, and a site owner who mistyped
//    one line in `theme-config.php` gets no page and no clue.
//
// 2. A CLASS NAME IS AN AUTOLOADER ARGUMENT (S-1). `class_exists($name)` hands
//    whatever string it was given to every registered autoloader, and a PSR-4
//    autoloader turns that string into a FILE PATH. PHP itself refuses to
//    autoload some malformed shapes and happily forwards others — an empty
//    namespace segment (`Acme\\Evil`) and a digit-initial segment both reach
//    the loader today. So the shape check has to run BEFORE `class_exists()`,
//    and the assertion below is not "the name was refused" but "no autoloader
//    was ever asked", which is the only form of the promise an attacker cares
//    about.
//
// 3. A STRING CONDITION IS A CALL (S-2). `is_callable('phpinfo')` is true. A
//    `conditional` entry whose `condition` is a string therefore invokes an
//    arbitrary named function during registration, chosen by a config value.
//    Only a Closure or an [object|class, method] array is a condition.
//
// 4. TWO SERVICES, ONE SLUG (S-3). The slug is the public extension key. Two
//    listed classes that derive or declare the same one silently share a config
//    filter: the site owner's override reaches whichever registered first, and
//    the other service reads a config that belongs to something else.
//
// 5. AN OVERRIDE THAT IS NOT AN ARRAY KILLS ITS SERVICE QUIETLY (reviewer S4).
//    `overrides.security => true` mounts a callback that runs
//    `array_merge($defaults, true)` INSIDE the service's constructor. The
//    TypeError is swallowed by bootService()'s catch, so the service simply
//    never comes up — with a log line about "failed to boot" that names the
//    constructor, not the config line that broke it.
//
// HOW THE REFUSALS ARE OBSERVED. `_doing_it_wrong()` is recorded through
// `Functions\when()->alias()`, the idiom the other Bootstrap suites use: a
// refusal is judged on WHAT IT SAYS, and a count failure has to be able to print
// the refusals that did fire (the recorder is shared: tests/Support/BootstrapHarness.php).
// `add_filter()`, `ntdst_log()`, `ntdst_set()` and
// `ntdst_get()` are the suite's REAL implementations (tests/bootstrap.php) and
// are never stubbed.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapRefusesMalformedServiceListsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use NtdstRecordsRefusals;

    /** The autoloader this test installs, so tearDown can take it back off. */
    private $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->recordRefusals();

        $GLOBALS['_ntdst_gate_constructed'] = [];   // services core booted, in order
        $GLOBALS['_ntdst_gate_config'] = [];        // slug => the config the service read back
        $GLOBALS['_ntdst_gate_autoloaded'] = [];    // every name handed to an autoloader
        $GLOBALS['_ntdst_gate_condition_calls'] = []; // every condition that was invoked
        $GLOBALS['_ntdst_gate_is_admin'] = false;
        $GLOBALS['_ntdst_test_log'] = [];           // the suite's real ntdst_log() recorder

        // An earlier file's mount is still on the real add_filter bus; every
        // claim below is about THIS run.
        foreach (['_ntdst_test_filters', '_ntdst_test_filters_at'] as $bag) {
            foreach (array_keys($GLOBALS[$bag] ?? []) as $hook) {
                if (str_starts_with((string) $hook, 'ntdst/service/')) {
                    unset($GLOBALS[$bag][$hook]);
                }
            }
        }

        Functions\when('is_admin')->alias(static fn() => (bool) $GLOBALS['_ntdst_gate_is_admin']);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_option')->justReturn('1');

        // WordPress's own filter dispatch for the one shape core uses. Brain
        // Monkey's apply_filters() only TRACKS a call — it never runs what was
        // mounted — so without this, "the override reached the service" and "the
        // bad override blew the service up" could not be asked at all.
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

        Monkey\tearDown();
        parent::tearDown();
    }

    // ========================================================================
    // A1 / I-1 — a malformed list entry is a notice, never a fatal
    // ========================================================================

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: list<string>}>
     */
    public static function malformedEntryProvider(): array
    {
        return [
            'services.core holds an integer' => [
                ['core' => [0, GateGoodService::class]],
                false,
                ['services.core', 'non-string entry'],
            ],
            'services.core holds a null left by a trailing-comma edit' => [
                ['core' => [null, GateGoodService::class]],
                false,
                ['services.core', 'non-string entry'],
            ],
            'services.core holds a nested array' => [
                ['core' => [['x'], GateGoodService::class]],
                false,
                ['services.core', 'non-string entry'],
            ],
            'services.admin holds an integer' => [
                ['core' => [GateGoodService::class], 'admin' => [0]],
                true,
                ['services.admin', 'non-string entry'],
            ],
            'a conditional spec names no service' => [
                [
                    'core' => [GateGoodService::class],
                    'conditional' => ['broken' => ['condition' => static fn(): bool => true]],
                ],
                false,
                ['services.conditional'],
            ],
        ];
    }

    /**
     * A1 / I-1 — a non-string entry draws ONE notice and the walk carries on.
     *
     * Every row is a real edit: a stray `0`, a `null` a trailing comma left
     * behind, a nested array from a half-finished conditional, and a
     * `conditional` spec that lost its `service` key. Today each one is a
     * TypeError out of `register()` — a white screen on a site whose only
     * mistake was one mistyped line. The notice has to name the SECTOR, because
     * a fleet config lists services in three places and "one of your entries is
     * malformed" sends the reader hunting through all of them.
     *
     * The good service registered beside it is the other half of the promise:
     * the entry is skipped, not the boot.
     *
     * @dataProvider malformedEntryProvider
     * @param array<string, mixed> $services
     * @param list<string> $needles
     */
    public function testAMalformedListEntryIsRefusedWithANoticeAndTheBootContinues(
        array $services,
        bool $isAdmin,
        array $needles,
    ): void {
        $GLOBALS['_ntdst_gate_is_admin'] = $isAdmin;

        $boot = new NTDST_Bootstrap(['services' => $services]);
        $boot->register()->bootFeatures();

        $this->assertCount(
            1,
            $this->wrongs,
            'A malformed entry is exactly one notice — not none, and not a fatal instead: ' . $this->wrongsText(),
        );

        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $this->wrongs[0][1],
                "The notice must name \"{$needle}\" so the site owner knows which key to open: " . $this->wrongsText(),
            );
        }

        $this->assertStringContainsString(
            'NTDST_Bootstrap',
            $this->wrongs[0][0],
            '_doing_it_wrong()\'s first argument is what WordPress prints as the function at fault.',
        );
        $this->assertSame('5.0.0', $this->wrongs[0][2], 'The @since marker for the refusal.');

        $this->assertSame(
            [GateGoodService::class],
            $GLOBALS['_ntdst_gate_constructed'],
            'One malformed entry must not take the rest of the list with it: the well-formed service still boots.',
        );
    }

    // ========================================================================
    // A3 / S-1 — a class name is never handed to an autoloader unchecked
    // ========================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function malformedClassNameProvider(): array
    {
        // The second element says whether PHP itself would forward this string
        // to the registered autoloaders. PHP refuses some malformed shapes on
        // its own and forwards others, which is exactly why core cannot rely on
        // it: the two forwarded rows are the ones a PSR-4 loader turns into a
        // path.
        return [
            'parent-directory traversal' => ['Acme\\..\\..\\Evil', false],
            'a forward slash instead of a separator' => ['Acme/Evil', false],
            'a space in the name' => ['Acme Evil', false],
            'an empty namespace segment' => ['Acme\\\\Evil', true],
            'a digit-initial segment' => ['Acme\\1Evil', true],
        ];
    }

    /**
     * A3 / S-1 — a name that is not a legal class name is refused, and no
     * autoloader is ever asked for it.
     *
     * `class_exists()` is not a validator: it lowercases the string and hands it
     * to every registered autoloader, and a PSR-4 autoloader's whole job is to
     * turn that string into a file path. `Acme\\Evil` — an EMPTY namespace
     * segment — reaches the loader today and maps to a doubled directory
     * separator; `Acme\1Evil` reaches it too. The shape check therefore has to
     * run BEFORE `class_exists()`, and the recorder below is registered as a
     * real autoloader so it answers the question an attacker asks: was my string
     * ever handed to something that resolves paths?
     *
     * The refusal is still one notice naming the value and its sector — a
     * malformed name the consumer typed is a config line they have to find.
     *
     * @dataProvider malformedClassNameProvider
     */
    public function testAMalformedClassNameIsRefusedAndNeverReachesAnAutoloader(string $name): void
    {
        $this->recordEveryAutoloadRequest();

        $boot = new NTDST_Bootstrap(['services' => ['core' => [$name, GateGoodService::class]]]);
        $boot->register()->bootFeatures();

        $this->assertNotContains(
            ltrim($name, '\\'),
            $GLOBALS['_ntdst_gate_autoloaded'],
            "Core handed \"{$name}\" to an autoloader. A PSR-4 loader turns that string into a file path, so "
                . 'the shape check must run BEFORE class_exists(), not after it. Names asked for: '
                . implode(', ', $GLOBALS['_ntdst_gate_autoloaded']),
        );

        $this->assertCount(
            1,
            $this->wrongs,
            'A malformed class name is refused exactly once: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            $name,
            $this->wrongs[0][1],
            'The notice must quote the value the consumer wrote — that is the string they grep their config for.',
        );
        $this->assertStringContainsString(
            'services.core',
            $this->wrongs[0][1],
            'and the key it came from.',
        );

        $this->assertSame(
            [GateGoodService::class],
            $GLOBALS['_ntdst_gate_constructed'],
            'A refused name skips its entry, not the list.',
        );
    }

    /**
     * A3, the other side — a legal name IS resolved, autoloader and all.
     *
     * The shape check is a filter on a hot path that every real service passes
     * through, and a regex that is one character too strict is a site down at
     * boot: three of five consumer sites load their services with a plain
     * `require_once` and no Composer map, and a namespaced, underscore-carrying,
     * digit-carrying class name is ordinary on the fleet. So a well-formed name
     * that PHP cannot resolve must still take the ORDINARY not-loaded path —
     * asked for, refused for the right reason — and a well-formed name that PHP
     * CAN resolve must register.
     */
    public function testAWellFormedNameIsStillResolvedTheOrdinaryWay(): void
    {
        $this->recordEveryAutoloadRequest();

        $boot = new NTDST_Bootstrap([
            'services' => ['core' => ['Ntdst_Gate\\Absent_Service2', GateGoodService::class]],
        ]);
        $boot->register()->bootFeatures();

        $this->assertContains(
            'Ntdst_Gate\\Absent_Service2',
            $GLOBALS['_ntdst_gate_autoloaded'],
            'A legal class name must still reach the consumer\'s autoloader — that is how four of five sites '
                . 'resolve their services. A shape check that rejects underscores or digits is a site down at boot.',
        );
        $this->assertCount(
            1,
            $this->wrongs,
            'It is refused, once, for the ordinary reason: nothing loaded it. ' . $this->wrongsText(),
        );
        $this->assertSame(
            [GateGoodService::class],
            $GLOBALS['_ntdst_gate_constructed'],
            'and the resolvable neighbour still boots.',
        );
    }

    // ========================================================================
    // A4 / S-2 — a string `condition` is refused, not called
    // ========================================================================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function stringConditionProvider(): array
    {
        return [
            'a callable function name' => ['ntdst_gate_condition_marker'],
            'a name nothing defines' => ['ntdst_gate_no_such_function_at_all'],
        ];
    }

    /**
     * A4 / S-2 — a `condition` that is a string is refused, and the function it
     * names is never invoked.
     *
     * `is_callable('phpinfo')` is true. Today a config value therefore chooses a
     * function that core calls during registration — the config file becomes a
     * call site. A condition is a Closure or an [object|class, method] array;
     * anything else is a misconfiguration, and the entry is skipped rather than
     * evaluated. The marker below records its own invocation, so this fails if
     * core reaches it by any route.
     *
     * @dataProvider stringConditionProvider
     */
    public function testAStringConditionIsRefusedAndNeverInvoked(string $condition): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [GateGoodService::class],
                'conditional' => [
                    'gated' => ['condition' => $condition, 'service' => GateConditionalService::class],
                ],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [],
            $GLOBALS['_ntdst_gate_condition_calls'],
            'Core invoked a function a config value named. A `condition` string is not a callback: '
                . implode(', ', $GLOBALS['_ntdst_gate_condition_calls']),
        );
        $this->assertCount(
            1,
            $this->wrongs,
            'A string condition is a misconfiguration and says so, once: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            'services.conditional',
            $this->wrongs[0][1],
            'The notice names the key the reader has to fix.',
        );
        $this->assertStringContainsString(
            'condition',
            $this->wrongs[0][1],
            'and says the condition is what is wrong with it.',
        );
        $this->assertSame(
            [GateGoodService::class],
            $GLOBALS['_ntdst_gate_constructed'],
            'The refused entry does not register its service, and the rest of the boot is untouched.',
        );
    }

    /**
     * A4, the other side — the two shapes that ARE conditions still work.
     *
     * Refusing too much here is the same outage as refusing too little, arriving
     * quietly: a `conditional` block that stopped evaluating would switch off
     * every conditional service on the fleet. A Closure and a static-method
     * array both hold, and both services come up.
     */
    public function testAClosureAndAMethodArrayAreStillValidConditions(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'conditional' => [
                    'closure' => ['condition' => static fn(): bool => true, 'service' => GateConditionalService::class],
                    'array' => [
                        'condition' => [GateCondition::class, 'holds'],
                        'service' => GateSecondConditionalService::class,
                    ],
                ],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [GateConditionalService::class, GateSecondConditionalService::class],
            $GLOBALS['_ntdst_gate_constructed'],
            'A Closure and an [class, method] array are conditions and must still be evaluated.',
        );
        $this->assertSame(
            [],
            $this->wrongs,
            'A well-formed conditional is not a misconfiguration: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // A5 / S-3 — two services may not share one slug
    // ========================================================================

    /**
     * @return array<string, array{0: list<class-string>, 1: string}>
     */
    public static function duplicateSlugProvider(): array
    {
        return [
            'two classes that DERIVE the same slug' => [
                [GateTwinService::class, GateTwin::class],
                'gate_twin',
            ],
            'two classes that DECLARE the same name' => [
                [GateDeclaredOneService::class, GateDeclaredTwoService::class],
                'gate_declared_twin',
            ],
        ];
    }

    /**
     * A5 / S-3 — the second class to claim a slug is refused, and the notice
     * names both.
     *
     * The slug is the public extension key: one slug is one service's config
     * filter. Two classes holding it means the site owner's override reaches
     * whichever registered first while the second service reads a config that
     * belongs to something else — and no message anywhere says why. Both halves
     * of the notice matter: naming only the loser leaves the reader looking for
     * a conflict they cannot see.
     *
     * The mount is observed through the service that owns it rather than by
     * counting callbacks: the suite's `add_filter()` records by hook AND
     * priority, so two mounts at the same priority collapse into one entry and a
     * count there would prove nothing. "Exactly one service constructed, and it
     * read the override back" is the same promise, stated where a consumer can
     * see it.
     *
     * @dataProvider duplicateSlugProvider
     * @param list<class-string> $classes
     */
    public function testTwoServicesClaimingOneSlugAreRefusedWithBothNamesInTheNotice(
        array $classes,
        string $slug,
    ): void {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => $classes,
                'overrides' => [$slug => ['claimed' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [$classes[0]],
            $GLOBALS['_ntdst_gate_constructed'],
            "Two classes answering to \"{$slug}\" is one service too many: the second must not register, or the "
                . 'site owner\'s override silently reaches the wrong object.',
        );

        $this->assertCount(
            1,
            $this->wrongs,
            'A duplicate slug is exactly one notice: ' . $this->wrongsText(),
        );

        foreach ($classes as $class) {
            $this->assertStringContainsString(
                $class,
                $this->wrongs[0][1],
                'The notice must name BOTH classes — a collision the reader cannot see both halves of is a '
                    . 'riddle: ' . $this->wrongsText(),
            );
        }

        $this->assertTrue(
            ($GLOBALS['_ntdst_gate_config'][$slug] ?? [])['claimed'] ?? null,
            'and the service that DID register still receives the override on that slug.',
        );
    }

    /**
     * A5, the boundary — the same class listed twice is not a duplicate slug.
     *
     * Every consumer config on the fleet has one: daan lists a shared service
     * under `core` and again under a `conditional` it forgot to delete. That is
     * already handled by the registry (one class, one entry) and must stay
     * SILENT. A duplicate-slug check that cannot tell "the same service named
     * twice" from "two services fighting over one key" prints a notice on a
     * config that is merely untidy, which trains the reader to ignore the one
     * that matters.
     */
    public function testTheSameClassListedTwiceIsNotADuplicateSlug(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [GateTwinService::class],
                'conditional' => [
                    'again' => ['condition' => static fn(): bool => true, 'service' => GateTwinService::class],
                ],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [GateTwinService::class],
            $GLOBALS['_ntdst_gate_constructed'],
            'One class is one service however many times the consumer listed it.',
        );
        $this->assertSame(
            [],
            $this->wrongs,
            'A class listed twice is untidy, not a collision: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // A9 / reviewer S4 — an override value that is not an array
    // ========================================================================

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonArrayOverrideProvider(): array
    {
        return [
            'a bare true' => [true],
            'a string' => ['x'],
            'a null' => [null],
            'an integer' => [42],
        ];
    }

    /**
     * A9 / reviewer S4 — `services.overrides.{slug}` that is not an array is
     * refused, and the service still boots on its own defaults.
     *
     * `overrides.security => true` is the shape a consumer writes when they
     * think the key is a switch. Today it mounts a callback that runs
     * `array_merge($defaults, true)` INSIDE the service's constructor: the
     * TypeError is caught by bootService(), logged as "failed to boot", and the
     * service is gone. On stride that service is the one that hides the
     * WordPress version. The config line that caused it is named nowhere.
     *
     * `null` is the same misconfiguration wearing a different mask — today it is
     * simply invisible, because `isset()` reads it as absent. Both must be one
     * notice naming the key, and neither may cost the site its service.
     *
     * @dataProvider nonArrayOverrideProvider
     */
    public function testANonArrayOverrideValueIsRefusedAndTheServiceKeepsItsDefaults(mixed $value): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [GateOverriddenService::class],
                'overrides' => ['gate_overridden' => $value],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertCount(
            1,
            $this->wrongs,
            'An override that is not an array is a misconfiguration and must say so, once: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            'services.overrides.gate_overridden',
            $this->wrongs[0][1],
            'The notice carries the FULL dotted key — that is the string the site owner greps for.',
        );
        $this->assertStringContainsString(
            'must be an array',
            $this->wrongs[0][1],
            'and says what the value should have been. A bare key with no reason is a riddle.',
        );

        $this->assertSame(
            GateOverriddenService::DEFAULTS,
            $GLOBALS['_ntdst_gate_config']['gate_overridden'] ?? null,
            'A broken override must cost the site its OVERRIDE, never its SERVICE: the service boots and reads '
                . 'back exactly its own defaults.',
        );
        $this->assertArrayNotHasKey(
            'ntdst/service/gate_overridden/config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'Refusing loudly and mounting the broken value anyway is the worst of both.',
        );

        $failures = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'],
            static fn(array $entry) => str_contains((string) $entry[2], 'Failed to boot'),
        ));

        $this->assertSame(
            [],
            $failures,
            'and nothing may reach the service as a constructor-time TypeError: ' . var_export($failures, true),
        );
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * Install a real autoloader that records every name it is asked for.
     *
     * Not a `Functions\expect('spl_autoload_register')->never()`: the question is
     * not which function core called, it is whether the consumer's LOADER — the
     * thing that resolves strings to files — ever saw the value. A recorder
     * registered on the real stack answers that whatever route core takes.
     */
    private function recordEveryAutoloadRequest(): void
    {
        $this->recorder = static function (string $name): void {
            $GLOBALS['_ntdst_gate_autoloaded'][] = $name;
        };

        spl_autoload_register($this->recorder);
    }
}

/**
 * The condition a config value could name. It records its own invocation, so
 * "core never called it" fails whatever route core took to reach it.
 */
function ntdst_gate_condition_marker(): bool
{
    $GLOBALS['_ntdst_gate_condition_calls'][] = 'ntdst_gate_condition_marker';

    return true;
}

/** A well-formed service that must survive every refusal beside it. */
final class GateGoodService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
    }
}

/** Listed under a `conditional` whose condition is the thing under test. */
final class GateConditionalService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
    }
}

/** The second conditional, for the shapes that ARE valid conditions. */
final class GateSecondConditionalService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
    }
}

/** A static-method condition — the [class, method] shape that stays valid. */
final class GateCondition
{
    public static function holds(): bool
    {
        return true;
    }
}

/**
 * Half of the derived collision: `GateTwinService` and `GateTwin` both lose
 * their `Service` suffix and derive `gate_twin`.
 */
final class GateTwinService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
        $GLOBALS['_ntdst_gate_config']['gate_twin'] = apply_filters('ntdst/service/gate_twin/config', []);
    }
}

/** The other half of the derived collision. */
final class GateTwin
{
    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
        $GLOBALS['_ntdst_gate_config']['gate_twin'] = apply_filters('ntdst/service/gate_twin/config', []);
    }
}

/** Half of the declared collision: both classes claim the same `name`. */
final class GateDeclaredOneService
{
    public static function metadata(): array
    {
        return ['name' => 'gate declared twin'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
        $GLOBALS['_ntdst_gate_config']['gate_declared_twin'] = apply_filters(
            'ntdst/service/gate_declared_twin/config',
            [],
        );
    }
}

/** The other half of the declared collision. */
final class GateDeclaredTwoService
{
    public static function metadata(): array
    {
        return ['name' => 'gate declared twin'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
        $GLOBALS['_ntdst_gate_config']['gate_declared_twin'] = apply_filters(
            'ntdst/service/gate_declared_twin/config',
            [],
        );
    }
}

/** Reads its config the way stride's SecurityService:60 really does. */
final class GateOverriddenService
{
    public const DEFAULTS = ['cache_ttl' => 60, 'eager' => false];

    public function __construct()
    {
        $GLOBALS['_ntdst_gate_constructed'][] = static::class;
        $GLOBALS['_ntdst_gate_config']['gate_overridden'] = apply_filters(
            'ntdst/service/gate_overridden/config',
            self::DEFAULTS,
        );
    }
}
