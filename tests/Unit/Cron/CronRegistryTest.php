<?php
declare(strict_types=1);

namespace Ntdst\Tests\Unit\Cron;

defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ntdst\Cron\CronRegistry;
use Ntdst\Cron\RunLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Stand-in credentials, never real ones; the harness defines none of them.
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ntdst-cron-' . getmypid() . '-' . uniqid());
defined('DB_PASSWORD') || define('DB_PASSWORD', 'unit-test-DB_PASSWORD');
defined('DB_USER') || define('DB_USER', 'unit-test-DB_USER');
defined('AUTH_KEY') || define('AUTH_KEY', 'unit-test-AUTH_KEY');
defined('SECURE_AUTH_KEY') || define('SECURE_AUTH_KEY', 'unit-test-SECURE_AUTH_KEY');
defined('LOGGED_IN_KEY') || define('LOGGED_IN_KEY', 'unit-test-LOGGED_IN_KEY');
defined('NONCE_KEY') || define('NONCE_KEY', 'unit-test-NONCE_KEY');
defined('AUTH_SALT') || define('AUTH_SALT', 'unit-test-AUTH_SALT');
defined('SECURE_AUTH_SALT') || define('SECURE_AUTH_SALT', 'unit-test-SECURE_AUTH_SALT');
defined('LOGGED_IN_SALT') || define('LOGGED_IN_SALT', 'unit-test-LOGGED_IN_SALT');
defined('NONCE_SALT') || define('NONCE_SALT', 'unit-test-NONCE_SALT');

require_once dirname(__DIR__, 3) . '/services/RunLog.php';

/** The registration seam: schedule once, wrap, record every fire, pause. */
final class CronRegistryTest extends TestCase
{
    private const PAUSED_OPTION = 'ntdst_cron_paused';

    private RunLog $log;

    private CronRegistry $registry;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $autoload = [];

    /** @var array<string, int> hook => next timestamp */
    private array $events = [];

    /** @var array<string, callable[]> */
    private array $actions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The file mounts an action at load; Brain Monkey defines add_action only inside setUp.
        require_once dirname(__DIR__, 3) . '/services/CronRegistry.php';

        $this->options = [];
        $this->autoload = [];
        $this->events = [];
        $this->actions = [];
        $GLOBALS['_ntdst_test_log'] = [];

        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) {
            $this->options[$name] = $value;
            $this->autoload[$name] = $autoload;

            return true;
        });
        Functions\when('add_action')->alias(function ($hook, $cb) {
            $this->actions[$hook][] = $cb;
        });
        // The recurrences WordPress itself ships; anything else is refused.
        Functions\when('wp_schedule_event')->alias(function ($ts, $recurrence, $hook) {
            if (!in_array($recurrence, ['hourly', 'twicedaily', 'daily', 'weekly'], true)) {
                return new \WP_Error('invalid_schedule', 'Event schedule does not exist.');
            }
            $this->events[$hook] = $ts;

            return true;
        });
        Functions\when('wp_next_scheduled')->alias(fn ($hook) => $this->events[$hook] ?? false);
        Functions\when('wp_clear_scheduled_hook')->alias(function ($hook) {
            unset($this->events[$hook]);
        });
        Functions\when('is_wp_error')->alias(fn ($v) => $v instanceof \WP_Error);

        $GLOBALS['wpdb'] = new class () {
            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_var(string $query): mixed
            {
                return null;
            }
        };

        $this->log = new RunLog();
        $this->registry = new CronRegistry($this->log);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** What WordPress does when cron fires the hook. */
    private function fire(string $hook): void
    {
        foreach ($this->actions[$hook] ?? [] as $callback) {
            $callback();
        }
    }

    /** @return array<int, array{level: string, channel: string, context: array}> */
    private function logEntries(): array
    {
        return array_map(
            static fn (array $e): array => ['channel' => $e[0], 'level' => $e[1], 'context' => $e[3]],
            $GLOBALS['_ntdst_test_log'] ?? [],
        );
    }

    private function config(callable $callback, string $recurrence = 'daily'): array
    {
        return [
            'label' => 'Poortherinneringen',
            'description' => 'Stuurt herinneringen voor open poorten',
            'recurrence' => $recurrence,
            'callback' => $callback,
        ];
    }

    public function testAReturnedStringBecomesTheSummaryOfOneOkEntry(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => '40 gescand',
        ));

        $this->fire('stride_gate_reminders');

        $runs = $this->log->runsFor('stride_gate_reminders');

        $this->assertCount(1, $runs, 'one fire records exactly one entry');
        $this->assertSame('40 gescand', $runs[0]['summary']);
        $this->assertTrue($runs[0]['ok']);
        $this->assertFalse($runs[0]['manual'], 'a scheduled fire is not manual');
        $this->assertIsInt($runs[0]['at']);
        $this->assertIsInt($runs[0]['ms']);
    }

    public function testAThrowingJobRecordsTheMessageAndDoesNotReThrow(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static function (): string {
                throw new RuntimeException('geen verbinding');
            },
        ));

        $this->fire('stride_gate_reminders');

        $runs = $this->log->runsFor('stride_gate_reminders');

        $this->assertCount(1, $runs);
        $this->assertFalse($runs[0]['ok']);
        $this->assertStringContainsString('geen verbinding', $runs[0]['summary']);
        $this->assertStringContainsString(RuntimeException::class, $runs[0]['summary']);
    }

    /**
     * S1: an exception message is not a safe summary. The auditor proved a job
     * throwing a message that interpolated DB_PASSWORD wrote the live database
     * password into the run-log option, where the panel renders it and DB dumps
     * carry it. Escaping (T4) stops script execution, not disclosure.
     */
    public function testTheDatabasePasswordIsNotStoredInAFailureSummary(): void
    {
        $secret = constant('DB_PASSWORD');

        $this->registry->register('stride_gate_reminders', $this->config(
            static function () use ($secret): string {
                throw new RuntimeException(
                    'SQLSTATE[HY000] [1045] Access denied for user root@localhost using password ' . $secret,
                );
            },
        ));

        $this->fire('stride_gate_reminders');

        $runs = $this->log->runsFor('stride_gate_reminders');

        $this->assertCount(1, $runs);
        $this->assertFalse($runs[0]['ok'], 'the failure is still recorded as a failure');
        $this->assertStringNotContainsString(
            $secret,
            $runs[0]['summary'],
            'the database password must not be persisted to the run log',
        );
        $this->assertStringContainsString(
            RuntimeException::class,
            $runs[0]['summary'],
            'the exception class keeps the failure diagnosable',
        );
        $this->assertStringContainsString(
            'Access denied',
            $runs[0]['summary'],
            'the rest of the message survives — only the credential is redacted',
        );
    }

    /** S1: the full message stays available where only operators read it. */
    public function testTheUnredactedFailureIsSentToTheLogger(): void
    {
        $GLOBALS['_ntdst_test_log'] = [];

        $this->registry->register('stride_gate_reminders', $this->config(
            static function (): string {
                throw new RuntimeException('geen verbinding met de mailserver');
            },
        ));

        $this->fire('stride_gate_reminders');

        $errors = array_values(array_filter(
            $this->logEntries(),
            static fn (array $e): bool => $e['level'] === 'error' && $e['channel'] === 'cron',
        ));

        $this->assertCount(1, $errors, 'a failed run is logged once on the cron channel');
        $this->assertSame('stride_gate_reminders', $errors[0]['context']['hook']);
        $this->assertSame('geen verbinding met de mailserver', $errors[0]['context']['error']);
    }

    /** S1: ABSPATH leaks the server layout; the summary is rendered to admins. */
    public function testAnAbsolutePathIsStrippedFromAFailureSummary(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static function (): string {
                throw new RuntimeException('kon ' . ABSPATH . 'wp-config.php niet lezen');
            },
        ));

        $this->fire('stride_gate_reminders');

        $summary = $this->log->runsFor('stride_gate_reminders')[0]['summary'];

        $this->assertStringNotContainsString(ABSPATH, $summary);
        $this->assertStringContainsString('wp-config.php', $summary);
    }

    /**
     * S2: MAX_RUNS caps how MANY entries are stored, not how big one is. The
     * auditor built a 20 MB option from twenty 1 MB summaries, and RunLog
     * unserialises the whole option on every append, so each fire loads it.
     */
    public function testAHugeSuccessSummaryIsTruncatedBeforeItIsStored(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => str_repeat('x', 10000),
        ));

        $this->fire('stride_gate_reminders');

        $summary = $this->log->runsFor('stride_gate_reminders')[0]['summary'];

        $this->assertLessThanOrEqual(
            500,
            mb_strlen($summary),
            'a job-supplied summary is bounded on write',
        );
        $this->assertTrue($this->log->runsFor('stride_gate_reminders')[0]['ok']);
    }

    public function testAHugeFailureSummaryIsAlsoTruncated(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static function (): string {
                throw new RuntimeException(str_repeat('y', 10000));
            },
        ));

        $this->fire('stride_gate_reminders');

        $summary = $this->log->runsFor('stride_gate_reminders')[0]['summary'];

        $this->assertLessThanOrEqual(
            500 + strlen(RuntimeException::class) + 2,
            mb_strlen($summary),
            'the redacted message is bounded too',
        );
    }

    public function testACallbackReturningNothingRecordsOk(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static function (): void {},
        ));

        $this->fire('stride_gate_reminders');

        $runs = $this->log->runsFor('stride_gate_reminders');

        $this->assertSame('ok', $runs[0]['summary']);
        $this->assertTrue($runs[0]['ok']);
    }

    public function testAnEmptyStringAlsoRecordsOk(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => '',
        ));

        $this->fire('stride_gate_reminders');

        $this->assertSame('ok', $this->log->runsFor('stride_gate_reminders')[0]['summary']);
    }

    public function testRegisterSchedulesTheEventWhenNoneExists(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
            'hourly',
        ));

        $this->assertNotFalse(wp_next_scheduled('stride_gate_reminders'));
    }

    public function testRegisterDoesNotRescheduleAnExistingEvent(): void
    {
        $this->events['stride_gate_reminders'] = 500;

        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
        ));

        $this->assertSame(500, wp_next_scheduled('stride_gate_reminders'), 'the existing schedule is left alone');
    }

    public function testAPausedJobIsNotScheduledOnRegistration(): void
    {
        $this->options[self::PAUSED_OPTION] = ['stride_gate_reminders' => true];

        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
        ));

        $this->assertFalse(
            wp_next_scheduled('stride_gate_reminders'),
            'FR-9: a paused job must not silently re-schedule itself on boot',
        );
    }

    /**
     * D1: wp_schedule_event() refuses any recurrence absent from
     * wp_get_schedules(), which is where a plugin-supplied one sits until the
     * cron_schedules filter that adds it has run. The old code dropped the
     * return, so the panel showed "niet ingepland" with no way to tell a
     * never-scheduled job from a failed one.
     */
    public function testAnUnschedulableRecurrenceIsLoggedInsteadOfSilentlyDropped(): void
    {
        $GLOBALS['_ntdst_test_log'] = [];

        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
            'fortnightly',
        ));

        $this->assertFalse(
            wp_next_scheduled('stride_gate_reminders'),
            'an unknown recurrence really does fail to schedule',
        );

        $errors = array_values(array_filter(
            $this->logEntries(),
            static fn (array $e): bool => $e['level'] === 'error' && $e['channel'] === 'cron',
        ));

        $this->assertCount(1, $errors, 'the refusal is recorded');
        $this->assertSame('stride_gate_reminders', $errors[0]['context']['hook']);
        $this->assertSame('fortnightly', $errors[0]['context']['recurrence']);
        $this->assertNotEmpty($errors[0]['context']['error'], 'the reason survives');
    }

    public function testThePausedOptionIsWrittenWithAutoloadDisabled(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
        ));

        $this->registry->pause('stride_gate_reminders');

        $this->assertFalse(
            $this->autoload[self::PAUSED_OPTION],
            'the paused map must never be autoloaded',
        );
    }

    /**
     * Parity with core: 'weekly' is a core recurrence (wp_get_schedules(),
     * @since 5.4.0), and both real jobs use it. A stub that omits it would make
     * the refusal tests above pass against an environment production never has.
     */
    public function testAWeeklyRecurrenceSchedulesWithoutLoggingAnError(): void
    {
        $GLOBALS['_ntdst_test_log'] = [];

        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
            'weekly',
        ));

        $this->assertNotFalse(
            wp_next_scheduled('stride_gate_reminders'),
            'weekly is a core recurrence, so it must schedule',
        );

        $errors = array_values(array_filter(
            $this->logEntries(),
            static fn (array $e): bool => $e['level'] === 'error' && $e['channel'] === 'cron',
        ));

        $this->assertSame([], $errors, 'a core recurrence must not report a refusal');
    }

    /** D1: resume() schedules through the same call and must report the same way. */
    public function testAFailedResumeIsLogged(): void
    {
                $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
            'fortnightly',
        ));

        $GLOBALS['_ntdst_test_log'] = [];
        $this->registry->resume('stride_gate_reminders');

        $errors = array_values(array_filter(
            $this->logEntries(),
            static fn (array $e): bool => $e['level'] === 'error' && $e['channel'] === 'cron',
        ));

        $this->assertCount(1, $errors, 'a resume that cannot schedule says so');
        $this->assertSame('stride_gate_reminders', $errors[0]['context']['hook']);
    }

    public function testAKnownRecurrenceSchedulesWithoutLoggingAnError(): void
    {
        $GLOBALS['_ntdst_test_log'] = [];

        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
            'daily',
        ));

        $this->assertNotFalse(wp_next_scheduled('stride_gate_reminders'));
        $this->assertSame([], array_filter(
            $this->logEntries(),
            static fn (array $e): bool => $e['level'] === 'error',
        ), 'a successful schedule logs nothing');
    }

    /**
     * Suggestion: register() bound a fresh closure every call, so three
     * registrations of one hook ran the job three times per fire. Latent today,
     * but this codebase has a known double-registration hazard.
     */
    public function testRegisteringTheSameHookTwiceStillFiresTheJobOnce(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): string {
            $calls++;

            return 'ok';
        };

        $this->registry->register('stride_gate_reminders', $this->config($callback));
        $this->registry->register('stride_gate_reminders', $this->config($callback));
        $this->registry->register('stride_gate_reminders', $this->config($callback));

        $this->fire('stride_gate_reminders');

        $this->assertSame(1, $calls, 'one fire runs the job once, however often it registered');
        $this->assertCount(1, $this->log->runsFor('stride_gate_reminders'), 'and writes one entry');
    }

    /** Suggestion: a malformed registration warned, then TypeError'd on every fire. */
    public function testARegistrationWithoutACallableIsRefused(): void
    {
        $this->registry->register('stride_gate_reminders', [
            'label' => 'Kapotte job',
            'recurrence' => 'daily',
            'callback' => 'not-a-function',
        ]);

        $this->assertFalse(
            $this->registry->has('stride_gate_reminders'),
            'a job with no callable is not registered at all',
        );
        $this->assertFalse(wp_next_scheduled('stride_gate_reminders'), 'and is never scheduled');
    }

    public function testHasIsFalseForAnUnregisteredHook(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
        ));

        $this->assertTrue($this->registry->has('stride_gate_reminders'));
        $this->assertFalse(
            $this->registry->has('evil_hook'),
            'the T1 allow-list: an unregistered hook is never a registry member',
        );
    }

    public function testAllExposesTheRegisteredConfig(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => 'ok',
            'hourly',
        ));

        $all = $this->registry->all();

        $this->assertArrayHasKey('stride_gate_reminders', $all);
        $this->assertSame('Poortherinneringen', $all['stride_gate_reminders']['label']);
        $this->assertSame('hourly', $all['stride_gate_reminders']['recurrence']);
        $this->assertFalse($all['stride_gate_reminders']['paused'], 'the panel reads scheduling state from all()');
    }

    public function testRunFiresTheCallbackThroughTheWrapperWithTheManualFlag(): void
    {
        $this->registry->register('stride_gate_reminders', $this->config(
            static fn (): string => '40 gescand',
        ));

        $this->registry->run('stride_gate_reminders', true);

        $runs = $this->log->runsFor('stride_gate_reminders');

        $this->assertCount(1, $runs, 'run() records exactly one entry');
        $this->assertSame('40 gescand', $runs[0]['summary']);
        $this->assertTrue($runs[0]['manual']);
    }

    public function testRunOnAnUnregisteredHookRecordsNothing(): void
    {
        $this->registry->run('evil_hook', true);

        $this->assertSame([], $this->log->runsFor('evil_hook'));
    }

    public function testEveryCredentialConstantIsRedacted(): void
    {
        $names = [
            'DB_PASSWORD',
            'DB_USER',
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        ];
        $message = implode(' ', array_map(static fn (string $n): string => constant($n), $names));

        $this->registry->register('h', $this->config(static function () use ($message): string {
            throw new RuntimeException($message);
        }));
        $this->fire('h');

        $summary = $this->log->runsFor('h')[0]['summary'];

        foreach ($names as $name) {
            $this->assertStringNotContainsString(constant($name), $summary, "{$name} must not be stored");
        }
        $this->assertSame(10, substr_count($summary, '[REDACTED]'));
    }

    public function testAHookNameNeverBecomesPartOfAnOptionName(): void
    {
        $hook = "x'; DROP--";

        $this->registry->register($hook, $this->config(static fn (): string => 'ok'));
        $this->registry->pause($hook);
        $this->fire($hook);

        $this->assertSame([self::PAUSED_OPTION, 'ntdst_cron_runs'], array_keys($this->options));
        $this->assertArrayHasKey($hook, $this->options[self::PAUSED_OPTION]);
        $this->assertArrayHasKey($hook, $this->options['ntdst_cron_runs']);
    }

    public function testTheBindingResolvesOneRegistryAtLoad(): void
    {
        $r = $this->runIsolated(<<<'PHP'
            $first = ntdst_get(Ntdst\Cron\CronRegistry::class);
            $log = (new ReflectionProperty($first, 'log'))->getValue($first);
            return [
                'isRegistry' => $first instanceof Ntdst\Cron\CronRegistry,
                'same' => $first === ntdst_get(Ntdst\Cron\CronRegistry::class),
                'boundLog' => $log === ntdst_get(Ntdst\Cron\RunLog::class),
            ];
        PHP);

        $this->assertTrue($r['isRegistry']);
        $this->assertTrue($r['same'], 'a second resolution is the same instance');
        $this->assertTrue($r['boundLog'], 'the registry is built with the bound RunLog');
    }

    public function testAnEarlyHelperCallStillReachesTheRegistryEveryoneResolves(): void
    {
        $r = $this->runIsolated(<<<'PHP'
            ntdst_cron_register('h', ['callback' => static fn () => 'ok']);
            foreach ($GLOBALS['hooks']['ntdst/services_registered'] ?? [] as $cb) { $cb(); }
            return ['has' => array_keys(ntdst_get(Ntdst\Cron\CronRegistry::class)->all())];
        PHP);

        $this->assertSame(['h'], $r['has'], 'a job registered before any hook fired is not lost');
    }

    public function testASuccessSummaryHasTheDatabasePasswordRedacted(): void
    {
        $secret = constant('DB_PASSWORD');
        $this->registry->register('h', $this->config(static fn (): string => 'synced with ' . $secret));

        $this->fire('h');

        $summary = $this->log->runsFor('h')[0]['summary'];
        $this->assertStringNotContainsString($secret, $summary, 'a returned string is stored like a failure is');
        $this->assertStringContainsString('[REDACTED]', $summary);
    }

    public function testOverlappingSecretsLeaveNoFragment(): void
    {
        $r = $this->runIsolated(<<<'PHP'
            ntdst_get(Ntdst\Cron\CronRegistry::class)->register('h', ['callback' => static function () {
                throw new RuntimeException('login dbsecret-long-tail failed');
            }]);
            ntdst_get(Ntdst\Cron\CronRegistry::class)->run('h', false);
            return ['summary' => ntdst_get(Ntdst\Cron\RunLog::class)->runsFor('h')[0]['summary']];
        PHP, "define('DB_PASSWORD', 'dbsecret'); define('AUTH_KEY', 'dbsecret-long-tail');");

        $this->assertStringNotContainsString('long-tail', $r['summary'], 'the longer secret goes first, so no tail survives');
        $this->assertStringNotContainsString('dbsecret', $r['summary']);
    }

    public function testAnAnonymousExceptionClassPutsNoPathOrNulByteInTheSummary(): void
    {
        $this->registry->register('h', $this->config(static function (): string {
            throw new class ('boom') extends RuntimeException {
            };
        }));

        $this->fire('h');

        $summary = $this->log->runsFor('h')[0]['summary'];
        $this->assertStringNotContainsString("\0", $summary);
        $this->assertStringNotContainsString('CronRegistryTest', $summary, 'the anonymous class name carries the file path');
        $this->assertStringContainsString('boom', $summary);
    }

    public function testAProjectPathOutsideBothConstantsIsStripped(): void
    {
        $r = $this->runIsolated(<<<'PHP'
            ntdst_get(Ntdst\Cron\CronRegistry::class)->register('h', ['callback' => static function () {
                throw new RuntimeException('Fatal in /srv/site/vendor/acme/lib/Client.php');
            }]);
            ntdst_get(Ntdst\Cron\CronRegistry::class)->run('h', false);
            return ['summary' => ntdst_get(Ntdst\Cron\RunLog::class)->runsFor('h')[0]['summary']];
        PHP, "define('WP_CONTENT_DIR', '/srv/site/web/app');");

        $this->assertStringNotContainsString('/srv/site', $r['summary'], 'the project root is stripped, vendor/ included');
        $this->assertStringContainsString('Client.php', $r['summary']);
    }

    /**
     * Loads core's cron files in a bare process (no Brain Monkey) with the WordPress
     * and container calls recorded, runs $code in it and returns its JSON result.
     */
    private function runIsolated(string $code, string $preamble = ''): array
    {
        $root = dirname(__DIR__, 3);
        $script = tempnam(sys_get_temp_dir(), 'cron-iso-') . '.php';
        file_put_contents($script, <<<PHP
            <?php
            define('ABSPATH', '/tmp/wordpress/');
            \$GLOBALS['hooks'] = []; \$GLOBALS['opts'] = [];
            function add_action(\$h, \$cb) { \$GLOBALS['hooks'][\$h][] = \$cb; }
            function get_option(\$n, \$d = false) { return \$GLOBALS['opts'][\$n] ?? \$d; }
            function update_option(\$n, \$v, \$a = null) { \$GLOBALS['opts'][\$n] = \$v; return true; }
            \$GLOBALS['wpdb'] = new class { public function prepare(\$q) { return \$q; } public function get_var(\$q) { return null; } };
            {$preamble}
            defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', '/tmp/wp-content');
            function wp_next_scheduled(\$h) { return false; }
            function wp_schedule_event() { return true; }
            function is_wp_error(\$v) { return false; }
            function ntdst_log(\$c = 'app') { return new class { public function __call(\$m, \$a) {} }; }
            require '{$root}/core/Container.php';
            require '{$root}/services/RunLog.php';
            require '{$root}/services/CronRegistry.php';
            \$result = (static function () { {$code} })();
            echo json_encode(\$result);
            PHP);

        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        unlink($script);

        $decoded = json_decode((string) $out, true);
        $this->assertIsArray($decoded, 'the subprocess printed JSON, got: ' . $out);

        return $decoded;
    }
}
