<?php // tests/Unit/BootstrapLoadsNothingByGuessingTest.php
// core-trim Cluster A — FEATURE tests, written against the cluster's promise
// rather than against any one task.
//
// THE PROMISE (cluster-a-behaviour.md): "Bootstrap registers exactly the classes
// the consumer listed and PHP can already resolve — loaded by require_once,
// Composer, or any autoloader — and refuses an unresolvable one loudly, reading
// no file and deriving no path."
//
// The task suites pin the pieces: T01 pins the refusal and the dead scanner
// (BootstrapResolvesOnlyLoadedClassesTest), T02 pins the override filter and the
// two remaining ways off (BootstrapOverridesTest), T03 pins the guards that went
// with the load order. NONE of them boots a config shaped like a real consumer's
// — several services across three sectors, an overrides block, a stale
// discovery pair left over from 3.x, a product key core never owned — and asks
// what the whole boot did. That is what this file is: one config, one
// `register()->bootFeatures()`, and the four questions a site owner asks
// afterwards.
//
//   1. Did exactly my services come up, each once, in the order I listed them?
//   2. Did my overrides reach them?
//   3. Was every dead entry named out loud — once, with its own key?
//   4. Did core touch anything on disk that I did not list?
//
// The config below is daan's shape: daan-core has no PSR-4 autoloader and loads
// every service with a plain `require_once`, so the services here are declared
// at the bottom of THIS FILE — findable by no autoloader, known to PHP only
// because the declaring file ran. A refusal on that path is a site down at boot,
// which is why the happy half of this file matters as much as the denials.
//
// WHAT "READ NO FILE" MEANS HERE. Not `Functions\expect('glob')->never()`:
// `glob()` and `file_get_contents()` are internal PHP functions and Patchwork
// will not redefine an internal without a `patchwork.json` this package does not
// ship. Every planted file records its own execution on the first line of its
// body instead, so the assertion fails if core reaches the file by ANY route —
// a glob, a derived path, an autoloader core installed, a stream wrapper — and
// not merely by the one function today's code happens to call.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapLoadsNothingByGuessingTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use NtdstRecordsRefusals;
    use NtdstPlantsServiceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->recordRefusals();
        $this->plantingRoot('ca', '_ntdst_ca_included', '_ntdst_ca_constructed');

        $GLOBALS['_ntdst_ca_included'] = [];      // files core executed
        $GLOBALS['_ntdst_ca_constructed'] = [];   // services core booted, in order
        $GLOBALS['_ntdst_ca_config'] = [];        // slug => config the service read back
        $GLOBALS['_ntdst_ca_is_admin'] = true;    // the config below lists admin services
        $GLOBALS['_ntdst_test_log'] = [];         // the suite's real ntdst_log() recorder

        // `add_filter()` is the suite's REAL recorder and Brain Monkey never
        // resets it, so a mount from an earlier file is still on the bus. Clear
        // only the per-service hooks: every claim below is about THIS run.
        foreach (['_ntdst_test_filters', '_ntdst_test_filters_at'] as $bag) {
            foreach (array_keys($GLOBALS[$bag] ?? []) as $hook) {
                if (str_starts_with((string) $hook, 'ntdst/service/')) {
                    unset($GLOBALS[$bag][$hook]);
                }
            }
        }

        // ntdst_log(), ntdst_set(), ntdst_get() and add_filter() are the suite's
        // REAL implementations — tests/bootstrap.php defines them before
        // Patchwork, so no test may stub them. See the note there.
        Functions\when('is_admin')->alias(static fn() => (bool) $GLOBALS['_ntdst_ca_is_admin']);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_option')->justReturn('1');

        // WordPress's own filter dispatch for the one shape core uses. Brain
        // Monkey's apply_filters() only TRACKS a call — it never runs what was
        // mounted — so without this "the override reached the service" could not
        // be asked at all: every filtered value would come back untouched and
        // the merge would look broken whatever core did.
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
        $this->sweepLitter();

        Monkey\tearDown();
        parent::tearDown();
    }

    // ========================================================================
    // 1. THE WHOLE BOOT — one realistic consumer config, end to end
    // ========================================================================

    /**
     * The cluster's promise, asked as one question.
     *
     * A config with the four things a real one has and a test config usually
     * does not: services spread over `core` / `admin` / `conditional`, one class
     * listed TWICE (daan lists a shared service in two places), an `overrides`
     * block, a stale `auto_discover` + `discovery_paths` pair from 3.x, and a
     * `sectors` key core has not owned since 3.0.0. Plus the two dead entries a
     * fleet config really carries: a class nobody requires any more, and an
     * override key with a typo in it.
     *
     * Six promises ride on the one boot, and each is a different way this could
     * be wrong:
     *
     *  - EXACTLY the listed, resolvable classes came up — not the planted
     *    `*Service.php` sitting in the configured discovery directory, not the
     *    conditional whose condition said no, not the file lying at the path the
     *    ghost class name spells out.
     *  - EACH ONCE. A class listed under two keys is one service, not two
     *    constructions of the same object.
     *  - IN LIST ORDER. Equal priorities must not reshuffle a consumer's list;
     *    a service that reads another's state at construction time depends on it.
     *  - THE OVERRIDES ARRIVED, through the renamed filter, at the service that
     *    owns the slug — and a service with no override keeps its own defaults.
     *  - EVERY DEAD ENTRY WAS NAMED ONCE, with ITS OWN key. Two dead entries of
     *    different kinds are two notices; a boot that prints one and swallows
     *    the other is the silence this cluster exists to end.
     *  - NOTHING WAS READ FROM DISK.
     */
    public function testARealisticConsumerConfigBootsExactlyWhatItListsAndNamesEveryDeadEntry(): void
    {
        $this->plantTheDiscoveryTrap();

        $boot = new NTDST_Bootstrap($this->consumerConfig());
        $boot->register()->bootFeatures();

        $this->assertSame(
            [
                CAProfileService::class,     // services.core, first
                CASecurityService::class,    // services.core, second
                CAPressKitService::class,    // services.admin (is_admin() is true)
                CARestService::class,        // services.conditional, condition holds
            ],
            $GLOBALS['_ntdst_ca_constructed'],
            'The boot must construct exactly the listed, resolvable services — once each, in the order the '
                . 'consumer listed them (core, then admin, then conditional). The conditional whose condition '
                . 'returned false, the class listed a second time, and the *Service.php planted in the '
                . 'configured discovery directory must all be absent.',
        );

        $this->assertSame(
            ['profile' => ['cache_ttl' => 900, 'eager' => false]],
            [
                'profile' => $GLOBALS['_ntdst_ca_config']['profile'] ?? null,
            ],
            'services.overrides.profile must reach ProfileService through ntdst/service/profile/config, '
                . 'merged OVER the service\'s own defaults and leaving the keys it did not name alone.',
        );

        $this->assertSame(
            ['hide_wp_version' => true, 'generator' => 'keep'],
            $GLOBALS['_ntdst_ca_config']['security'] ?? null,
            'The second override must reach its own service on its own slug — one filter per service, '
                . 'not one shared bag every service reads.',
        );

        $this->assertSame(
            CAPressKitService::DEFAULTS,
            $GLOBALS['_ntdst_ca_config']['press_kit'] ?? null,
            'A service nobody overrode must read back its own declared defaults, untouched.',
        );

        $this->assertCount(
            2,
            $this->wrongs,
            'Two dead entries of two different kinds are two notices — one for the class nobody requires '
                . 'any more, one for the override key that answers to no service: ' . $this->wrongsText(),
        );

        $this->assertNotSame(
            '',
            $this->refusalNaming('Daan\\Musician\\GhostService'),
            'The unresolvable class must be named in a notice of its own: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            'services.core',
            $this->refusalNaming('Daan\\Musician\\GhostService'),
            'That notice must also name the config key the class came from — a fleet config lists services '
                . 'in three places, so a notice naming only the class sends the reader hunting.',
        );

        $this->assertStringContainsString(
            'services.overrides.securty',
            $this->refusalNaming('securty'),
            'The typo\'d override key must be refused with the FULL dotted key, because that is the string '
                . 'the site owner greps their config for: ' . $this->wrongsText(),
        );
        $this->assertArrayNotHasKey(
            'ntdst/service/securty/config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'and it must mount NOTHING. Refusing loudly and wiring the key up anyway is the worst of both: a '
                . 'callback left on the hook of a service that does not exist fires the day someone else '
                . 'applies that name.',
        );

        $this->assertSame(
            [],
            $GLOBALS['_ntdst_ca_included'],
            'Core executed a file nobody listed: ' . implode(', ', $GLOBALS['_ntdst_ca_included']),
        );
        $this->assertFalse(
            class_exists('Daan\\Musician\\GhostService', false),
            'A class core could not resolve must still be unresolvable after the boot — a name in a config '
                . 'array is not a path core may require.',
        );
        $this->assertFalse(
            class_exists('NtdstCaDiscovery\\ProbeService', false),
            'A *Service.php in a configured discovery directory must stay unloaded: that directory is not '
                . 'core\'s to execute.',
        );
    }

    /**
     * AF-14 — re-entry: the whole boot is idempotent.
     *
     * `register()` latches, so a consumer that wires it to two hooks (or a
     * plugin that boots twice on the same request) gets one registration and one
     * copy of each notice. A notice that repeats turns a config typo into a log
     * flood, and with `WP_DEBUG_DISPLAY` on, into repeated output on the page.
     * A service that constructs twice is worse: the second construction
     * re-registers hooks and re-runs whatever the constructor does.
     */
    public function testASecondRegisterOnTheSameConfigChangesNothing(): void
    {
        $this->plantTheDiscoveryTrap();

        $boot = new NTDST_Bootstrap($this->consumerConfig());

        $boot->register()->bootFeatures();

        $constructedAfterFirstBoot = $GLOBALS['_ntdst_ca_constructed'];
        $wrongsAfterFirstBoot = count($this->wrongs);

        $boot->register()->bootFeatures();

        $this->assertSame(
            $constructedAfterFirstBoot,
            $GLOBALS['_ntdst_ca_constructed'],
            'A second register()->bootFeatures() must construct nothing further — the latch is what keeps '
                . 'a double-wired boot from running every service constructor twice.',
        );
        $this->assertCount(
            $wrongsAfterFirstBoot,
            $this->wrongs,
            'One misconfiguration is one notice, however many times the boot runs: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // 2. AF-13 — the refusal is not gated on WP_DEBUG
    // ========================================================================

    /**
     * AF-13 — with debug off, the refusal still fires; WordPress decides display.
     *
     * The failure this guards against is a refusal written inside
     * `if (defined('WP_DEBUG') && WP_DEBUG)`, which is how the debug chatter
     * around it IS written. That version passes every other test in this
     * cluster — the suite runs with WP_DEBUG undefined — and then goes silent on
     * exactly the machine where a missing service is expensive: production.
     * `_doing_it_wrong()` is WordPress's own channel; whether it prints, logs or
     * throws is `WP_DEBUG` + `WP_DEBUG_DISPLAY` + `wp_doing_it_wrong_run`, and
     * that decision is WordPress's, not core's.
     *
     * The debug-gated log lines are asserted ABSENT in the same breath. That is
     * what makes this test mean something: it proves debug really is off in this
     * process, so "the notice fired anyway" is a statement about the notice and
     * not about the environment.
     */
    public function testWithDebugOffTheRefusalStillFiresWhileDebugChatterStaysSilent(): void
    {
        $this->assertFalse(
            defined('WP_DEBUG') && WP_DEBUG,
            'This case is only meaningful with WP_DEBUG off; the suite runs with it undefined. If a later '
                . 'test file defines it, this case has to be moved to its own process.',
        );

        $this->plantTheDiscoveryTrap();

        $boot = new NTDST_Bootstrap($this->consumerConfig());
        $boot->register()->bootFeatures();

        $this->assertCount(
            2,
            $this->wrongs,
            'A dead class and a dead override key must be refused with debug off — the notice is how a '
                . 'production site learns a service is missing: ' . $this->wrongsText(),
        );

        $booting = array_filter(
            $GLOBALS['_ntdst_test_log'],
            static fn(array $entry) => str_contains((string) $entry[2], 'Booting'),
        );

        $this->assertSame(
            [],
            array_values($booting),
            'The per-service boot chatter IS debug-gated, so with WP_DEBUG off it must be silent. Seeing it '
                . 'here means the gate is not in effect and the assertion above proved nothing.',
        );

        $this->assertSame(
            [
                CAProfileService::class,
                CASecurityService::class,
                CAPressKitService::class,
                CARestService::class,
            ],
            $GLOBALS['_ntdst_ca_constructed'],
            'Debug off changes what is LOGGED, never what is booted — the refused service is absent either '
                . 'way and every good one still comes up.',
        );
    }

    // ========================================================================
    // 3. AF-4 — a stale discovery pair in a LIVE config is inert
    // ========================================================================

    /**
     * AF-4 — `auto_discover` + `discovery_paths` left in a working config are
     * unread, and unread is SILENT.
     *
     * The upgrade path this protects. Every one of the five consumer sites still
     * carries the pair (all five set `auto_discover => false`), and 5.0.0 asks
     * nobody to delete it. So the boundary has two sides and both are the
     * feature: the keys must do nothing, and they must SAY nothing. A notice
     * here would fire on every request of every site on the fleet the day they
     * update — training the reader to ignore the notices that matter, which is
     * the whole reason the two real ones above are worth printing.
     *
     * `auto_discover => true` is the stronger form of the stale key: not a
     * leftover that was already off, but the exact configuration the deleted
     * scanner existed to serve. The planted `ProbeService.php` is a perfect
     * match for the retired `*Service.php` glob.
     */
    public function testAStaleDiscoveryPairBesideLiveServicesIsInertAndSilent(): void
    {
        $this->plantTheDiscoveryTrap();

        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [CAProfileService::class],
                'auto_discover' => true,
                'discovery_paths' => [$this->root . '/plugin/services'],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [CAProfileService::class],
            $GLOBALS['_ntdst_ca_constructed'],
            'The listed service boots and the discovered one does not exist — discovery is deleted, not '
                . 'switched off.',
        );
        $this->assertSame(
            [],
            $GLOBALS['_ntdst_ca_included'],
            'Core read a file out of a configured discovery path: ' . implode(', ', $GLOBALS['_ntdst_ca_included']),
        );
        $this->assertSame(
            [],
            $this->wrongs,
            'A key core does not read is inert, not an error. A notice here fires on every request of every '
                . 'site that has not yet deleted the pair: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // 4. DENIAL / SHAPE — what a listed class has to BE
    // ========================================================================

    /**
     * The admission test is resolvability and nothing else — a listed class with
     * no service shape at all is registered and booted.
     *
     * Pinned because it is the boundary a reader will guess wrong. `metadata()`
     * is OPTIONAL (`getServiceMetadata()` falls back to defaults) and
     * `NTDST_Service_Meta` is never checked, so a plain class with no metadata,
     * no boot method and no interface is a valid service: its constructor IS the
     * boot. That is what daan's services look like, and tightening it would
     * break three sites, so this test exists to make the looseness DELIBERATE —
     * a later task that adds an interface check has to come here and argue with
     * it rather than discover it in production.
     *
     * The cost is stated in the cluster report: a listed name that resolves to
     * an unrelated class — a typo landing on a real class, a name colliding with
     * another plugin's global class — is CONSTRUCTED, silently. `class_exists()`
     * cannot tell those apart, and the consumer's own require list is the trust
     * boundary the threat model names (row #1).
     */
    public function testAListedClassWithNoServiceShapeIsStillRegisteredAndBooted(): void
    {
        $boot = new NTDST_Bootstrap(['services' => ['core' => [CAPlainClassWithNoServiceShape::class]]]);
        $boot->register()->bootFeatures();

        $this->assertFalse(
            method_exists(CAPlainClassWithNoServiceShape::class, 'metadata'),
            'The premise: this class declares no metadata().',
        );
        $this->assertFalse(
            is_a(CAPlainClassWithNoServiceShape::class, 'NTDST_Service_Meta', true),
            'The premise: it implements no service interface either.',
        );

        $this->assertSame(
            [CAPlainClassWithNoServiceShape::class],
            $GLOBALS['_ntdst_ca_constructed'],
            'Resolvability is the whole admission test: a plain class the consumer listed is constructed. '
                . 'daan\'s services declare no metadata() and must keep booting.',
        );
        $this->assertSame(
            [],
            $this->wrongs,
            'No shape is demanded, so no notice is owed: ' . $this->wrongsText(),
        );
    }

    /**
     * DENIAL — a listed class PHP resolves but cannot CONSTRUCT fails loudly at
     * `error` level, and takes nothing else down.
     *
     * The gap between "resolvable" and "usable". An abstract class, a class
     * whose constructor throws, a constructor whose type-hinted dependency
     * cannot be autowired: `class_exists()` says yes to all three, so none of
     * them is refused at `register()`. What must hold is the mid-flow-failure
     * shape — the failure is written at ERROR level (not the debug level that
     * production discards), the request survives, and every other service in the
     * list still boots. A boot that fataled here would be a whole site down for
     * one bad entry.
     */
    public function testAListedClassThatCannotBeConstructedFailsLoudlyWithoutTakingTheBootDown(): void
    {
        $boot = new NTDST_Bootstrap([
            'services' => ['core' => [CAAbstractService::class, CAProfileService::class]],
        ]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [CAProfileService::class],
            $GLOBALS['_ntdst_ca_constructed'],
            'The service after the unconstructable one still boots — one bad entry is one lost service, '
                . 'never a lost request.',
        );

        $errors = array_values(array_filter(
            $GLOBALS['_ntdst_test_log'],
            static fn(array $entry) => $entry[1] === 'error',
        ));

        $this->assertCount(
            1,
            $errors,
            'The failure is written once, at error level, so it survives a production log threshold that '
                . 'drops debug: ' . json_encode(array_column($GLOBALS['_ntdst_test_log'], 2)),
        );
        $this->assertStringContainsString(
            CAAbstractService::class,
            (string) $errors[0][2],
            'The error line must name the service that failed; anything less is a log entry nobody can act on.',
        );
    }

    /**
     * DENIAL — an interface or trait name in the services list is refused like
     * any other unresolvable name.
     *
     * `class_exists()` answers false for both, which is the correct answer:
     * neither can be constructed, so neither is a service. This pins that the
     * refusal MESSAGE is the same one a missing class gets — the reader learns
     * the name and the key, and fixes their config either way.
     */
    public function testAnInterfaceNameListedAsAServiceIsRefusedLikeAnyUnresolvableName(): void
    {
        $boot = new NTDST_Bootstrap(['services' => ['core' => [CAServiceContract::class]]]);
        $boot->register()->bootFeatures();

        $this->assertSame(
            [],
            $GLOBALS['_ntdst_ca_constructed'],
            'An interface cannot be a service — nothing may be constructed from it.',
        );
        $this->assertStringContainsString(
            'services.core',
            $this->refusalNaming(CAServiceContract::class),
            'The refusal names the interface and the key it was listed under: ' . $this->wrongsText(),
        );
    }

    // ========================================================================
    // 5. THE LOAD ORDER, AS A PROPERTY OF THE LOADER
    // ========================================================================

    /**
     * FR-3 / SC-2 — `services/Logger.php` is required before EVERY file on the
     * list that calls `ntdst_log()`.
     *
     * The property, computed, not a pair of line numbers hand-picked today.
     * FR-3 deleted 14 `function_exists('ntdst_log')` guards, and every one of
     * them existed because Logger was required last: without the guards, a call
     * from a file that loads first is a fatal on every request. `bin/guard.sh`
     * pins Logger against `api/FieldTypes.php`, the first `api/` require. That
     * one pair goes green the moment somebody moves a NEW ntdst_log() caller
     * above Logger — a support/ file, a second core/ file, anything requires
     * earlier than FieldTypes. This reads the require list out of
     * `ntdst-core.php`, greps each required file for the call, and demands the
     * ordering of every caller it finds.
     *
     * The `assertGreaterThan` on the caller count is the anti-vacuity clause: a
     * regex that stops matching would otherwise turn this into a test that
     * passes by finding nothing.
     */
    public function testEveryRequiredFileThatCallsTheLogHelperIsRequiredAfterTheLogger(): void
    {
        $root = dirname(__DIR__, 2);
        $requires = [];

        foreach (file($root . '/ntdst-core.php') as $i => $line) {
            if (preg_match("/^\\s*require_once\\s+NTDST_PATH\\s*\\.\\s*'([^']+)'/", $line, $m) === 1) {
                $requires[$m[1]] = $i + 1;
            }
        }

        $this->assertArrayHasKey(
            '/services/Logger.php',
            $requires,
            'ntdst-core.php must require services/Logger.php by name — the loading model is one explicit '
                . 'list, never a directory scan (INV-10), so the list is what this property is read from.',
        );

        $loggerAt = $requires['/services/Logger.php'];

        $this->assertMatchesRegularExpression(
            '/function\s+ntdst_log\s*\(/',
            (string) file_get_contents($root . '/services/Logger.php'),
            'services/Logger.php is the file that DEFINES ntdst_log(); if the definition moves, this test '
                . 'is measuring the wrong file.',
        );

        $callers = [];
        foreach ($requires as $relative => $at) {
            if ($relative === '/services/Logger.php') {
                continue;
            }

            $source = (string) file_get_contents($root . $relative);

            if (preg_match('/(?<![\w$>])ntdst_log\s*\(/', $source) === 1) {
                $callers[$relative] = $at;
            }
        }

        $this->assertGreaterThanOrEqual(
            5,
            count($callers),
            'This property is only worth asserting while core really does call ntdst_log() from its own '
                . 'files; finding almost none means the grep stopped matching, not that the callers left.',
        );

        foreach ($callers as $relative => $at) {
            $this->assertGreaterThan(
                $loggerAt,
                $at,
                "ntdst-core.php requires {$relative} (line {$at}) BEFORE services/Logger.php (line {$loggerAt}), "
                    . 'and that file calls ntdst_log(). FR-3 deleted the function_exists() guards that used to '
                    . 'hide this, so the call is now a fatal on every request — or, if the file only logs on an '
                    . 'error path, a fatal on exactly the request that was already going wrong.',
            );
        }
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * The consumer config this file is about: daan's shape, with the two dead
     * entries a real one accumulates.
     *
     * @return array<string, mixed>
     */
    private function consumerConfig(): array
    {
        return [
            // A product key from 3.x that core has not owned since the sector
            // system left the package. Core must walk past it without a word.
            'sectors' => ['musician', 'press'],
            'services' => [
                'core' => [
                    CAProfileService::class,
                    CASecurityService::class,
                    'Daan\\Musician\\GhostService',   // required by nothing since the 4.x refactor
                ],
                'admin' => [
                    CAPressKitService::class,
                ],
                'conditional' => [
                    'rest' => ['condition' => static fn() => true, 'service' => CARestService::class],
                    'cli' => ['condition' => static fn() => false, 'service' => CACliOnlyService::class],
                    // The same class the core list already names. A consumer
                    // that moved a service and forgot to delete the old entry.
                    'profile_again' => ['condition' => static fn() => true, 'service' => CAProfileService::class],
                ],
                'overrides' => [
                    'profile' => ['cache_ttl' => 900],
                    'security' => ['hide_wp_version' => true],
                    'securty' => ['hide_wp_version' => true],   // the typo that used to do nothing, quietly
                ],
                // Left over from 3.x. Both keys are unread (AF-4).
                'auto_discover' => true,
                'discovery_paths' => [$this->root . '/plugin/services'],
            ],
        ];
    }

    /**
     * Plant the files a guessing loader would find: one under the configured
     * discovery directory, and one at each path the ghost class NAME spells out.
     *
     * The retired path-guessing branch built `dirname(<discovery path>)` and
     * then tried the class as a relative path twice — as the namespace spells
     * it, and again with the leading segment dropped. Either one executing is
     * arbitrary code running because a string in a config array looked like a
     * directory.
     */
    private function plantTheDiscoveryTrap(): void
    {
        $this->plant('/plugin/services', 'ProbeService.php', 'NtdstCaDiscovery', 'ProbeService');
        $this->plant('/plugin/Daan/Musician', 'GhostService.php', 'Daan\\Musician', 'GhostService');
        $this->plant('/plugin/Musician', 'GhostService.php', 'Daan\\Musician', 'GhostService');
    }

    /** The message of the one refusal that names $needle, or '' when none does. */
    private function refusalNaming(string $needle): string
    {
        $matching = array_values(array_filter(
            $this->wrongs,
            static fn(array $wrong) => str_contains($wrong[1], $needle),
        ));

        $this->assertCount(
            1,
            $matching,
            "Exactly one notice must name \"{$needle}\": " . $this->wrongsText(),
        );

        return $matching[0][1];
    }

}

// ===========================================================================
// The consumer's services — declared by an executed file, findable by no
// autoloader, exactly the way daan-core ships them.
//
// Each one reads its config the way stride's SecurityService:60 and
// PerformanceService:39 really do: apply_filters() on its own slug, inside the
// constructor. The slug is DECLARED rather than derived, because a config
// override is a public key and the derivation is not something a consumer
// should have to predict (BootstrapServiceSlugTest owns the derivation).
// ===========================================================================

/** Listed under services.core, and again — by mistake — under conditional. */
final class CAProfileService
{
    public const DEFAULTS = ['cache_ttl' => 60, 'eager' => false];

    public static function metadata(): array
    {
        return ['name' => 'profile', 'description' => 'musician profiles'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
        $GLOBALS['_ntdst_ca_config']['profile'] = apply_filters('ntdst/service/profile/config', self::DEFAULTS);
    }
}

/** stride's SecurityService in miniature — the second overridden service. */
final class CASecurityService
{
    public const DEFAULTS = ['hide_wp_version' => false, 'generator' => 'keep'];

    public static function metadata(): array
    {
        return ['name' => 'security', 'description' => 'header hardening'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
        $GLOBALS['_ntdst_ca_config']['security'] = apply_filters('ntdst/service/security/config', self::DEFAULTS);
    }
}

/** services.admin — registered only because is_admin() is true. */
final class CAPressKitService
{
    public const DEFAULTS = ['collections' => []];

    public static function metadata(): array
    {
        return ['name' => 'press_kit', 'description' => 'press kit assembly'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
        $GLOBALS['_ntdst_ca_config']['press_kit'] = apply_filters('ntdst/service/press_kit/config', self::DEFAULTS);
    }
}

/** services.conditional, condition holds. */
final class CARestService
{
    public static function metadata(): array
    {
        return ['name' => 'rest', 'description' => 'REST surface'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
    }
}

/** services.conditional, condition returns false — must never construct. */
final class CACliOnlyService
{
    public static function metadata(): array
    {
        return ['name' => 'cli_only', 'description' => 'WP-CLI commands'];
    }

    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
    }
}

/** No metadata(), no interface, no boot method: daan's ordinary shape. */
final class CAPlainClassWithNoServiceShape
{
    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
    }
}

/** Resolvable by class_exists(), impossible to construct. */
abstract class CAAbstractService
{
    public function __construct()
    {
        $GLOBALS['_ntdst_ca_constructed'][] = static::class;
    }
}

/** A name class_exists() answers false for. */
interface CAServiceContract
{
    public function boot(): void;
}
