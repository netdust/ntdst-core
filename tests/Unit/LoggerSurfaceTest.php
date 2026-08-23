<?php // tests/Unit/LoggerSurfaceTest.php
declare(strict_types=1);
// core-trim T05 — the Logger writes a line to a file and a line to error_log,
// and does nothing else.
//
// This is the RED contract for spec FR-5 and SC-3. Until this task the Logger
// carried a SECOND, database half beside the two file writers: the constructor
// registered a `log_entry` post type through `ntdst_data()`, an `ntdst_log_
// database_enabled` filter armed a handler that wrote REQUEST_URI, the client
// IP and the whole context array into post meta on every error, and `recent()`
// read them back. Three defects in one:
//
//   1. LOAD ORDER. Constructing a logger reached api/Data.php, so the file that
//      defines ntdst_log() could not be required before the file that defines
//      ntdst_data() — which is why every core call site guarded its logging
//      with function_exists('ntdst_log') and silently skipped it (FR-3, and the
//      Cluster A sentinel's I-2). Deleting the reach is what makes the require
//      order a straight line, so this file's FIRST behavioural assertion is
//      that constructing a logger asks the Data layer nothing at all.
//   2. A PII SINK the operator never opted into: the handler was ON whenever
//      WP_DEBUG was, and an incident is exactly when WP_DEBUG gets turned on.
//   3. THE WRONG LOAD PROFILE: every error became wp_insert_post + N meta
//      writes + a save_post cascade, during the incident that caused it.
//
// The handler API goes for a separate reason — `addHandler()` / `removeHandler()`
// let a consumer bolt a fourth sink on at runtime, so "where do the logs go" had
// no single answer; `setMinLevel()` / `setBatchingEnabled()` let it move the
// level gate and the write moment out from under the constructor. Zero readers
// on the fleet (daan, stride, josworld: none). The channel and the environment
// decide now, and they decide once.
//
// WHAT THIS FILE ASSERTS, in order:
//   1. THE SURFACE, by reflection, as an EXACT list (SC-3). An exact list, not
//      a set of absences: absences pin what left, a list also pins that nothing
//      new arrived under a different name.
//   2. THE FILE DECLARES ONE GLOBAL HELPER — `ntdst_log()`.
//   3. THE CLASS NAMES NO DATA LAYER AND NO POST TYPE REGISTRATION.
//   4. NO `ntdst_log_database_enabled` FILTER AND NO `ntdst_log` / `ntdst_log_*`
//      ACTION is reached across a real ->error().
//   5. THE KEPT BEHAVIOUR STILL WORKS — this is the half that makes the four
//      deletions above safe: one formatted line in
//      WP_CONTENT_DIR/logs/<channel>-<Y-m-d>.log, and error_log reached ONCE
//      for an error and never for the levels below it.
//   6. THE SEAM, in a SEPARATE PHP PROCESS: the shipped file, loaded whole,
//      logs an error in a process where the Data layer, the hook API and
//      WordPress itself do not exist. That is what I-2 actually promises, and
//      it is the assertion the other five cannot make (below).
//
// ── HOW THE CLASS IS LOADED, AND WHY NOT WITH require ─────────────────────────
// services/Logger.php declares `ntdst_log()` UNCONDITIONALLY (FR-3: a second,
// older copy of core on the same request must fatal by name rather than quietly
// win the declaration). tests/bootstrap.php declares its own recording
// `ntdst_log()` for the whole suite, under the rule stated in its header. So
// `require`-ing the file here is a redeclare FATAL, and the two cannot both be
// loaded — ten other test files read the bootstrap recorder.
//
// So the class half is eval'd out of the shipped source for the in-process
// tests, and the file is loaded WHOLE in the subprocess of assertion 6. The
// split is not a workaround dressed up: each half answers something the other
// cannot. In-process, Brain Monkey can watch a filter that must never be
// applied. In the subprocess, `function_exists('ntdst_log_debug')` is a real
// question — asked in the one process that ever loads the file's function half
// — and "the constructor reaches no Data layer" is proved by there being no
// Data layer to reach, rather than by a mock agreeing not to be called.
//
// (`Functions\expect('ntdst_data')->never()`, which the task brief names, is not
// available here: api/Data.php is required at load time by four other test
// files, so ntdst_data() is already defined process-wide and Patchwork refuses
// it with DefinedTooEarly. Assertions 3 and 6 replace it — one on the source,
// one in a process where the function genuinely does not exist.)
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/LogLevel.php';

// The Logger's only filesystem anchor. Defined here because no other file in
// the suite reads it, and pointed at a throwaway directory: the assertions
// below are about a real file with a real line in it, not about a mock of one.
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/ntdst-logger-' . getmypid() . '-' . uniqid());
}

final class LoggerSurfaceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const SOURCE = __DIR__ . '/../../services/Logger.php';

    /** A fixed WordPress clock, so the line's prefix is a known string. */
    private const STAMP = '2026-08-23 11:22:33';

    private string $logDir = '';

    /** Where PHP's own error_log is pointed for the duration of a test. */
    private string $sink = '';

    private string $previousErrorLog = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!class_exists('NTDST_Logger', false)) {
            eval(self::sourceParts()[0]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(WP_CONTENT_DIR);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('current_time')->justReturn(self::STAMP);
        Functions\when('wp_mkdir_p')->alias(
            static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true),
        );

        $this->logDir = WP_CONTENT_DIR . '/logs';
        self::removeTree(WP_CONTENT_DIR);
        mkdir($this->logDir, 0777, true);

        // Every test here logs an error, and an error reaches error_log() for
        // real — on CLI that is stderr, i.e. the gate's own output. Point it at
        // a file instead: it keeps the suite readable, and it is what lets the
        // error_log contract be asserted against the real function rather than
        // against a mock of it.
        $this->sink = WP_CONTENT_DIR . '/php-error-log.txt';
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->sink);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);

        // The constructor registers flushBatchedLogs() as a shutdown function
        // once per process. Draining the batch here keeps that handler a no-op
        // at the end of the run, when the WordPress stubs are gone.
        if (class_exists('NTDST_Logger', false)) {
            NTDST_Logger::flushBatchedLogs();
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    // ── 1. The surface ───────────────────────────────────────────────────────

    /**
     * SC-3: the class offers exactly eight public methods.
     *
     * `flushBatchedLogs()` is the shutdown handler and `flush()` is the
     * instance-side forwarder a long-running job calls; both stay. Everything
     * that let a caller RECONFIGURE the logger — the two handler mutators, the
     * level gate and the batching switch — goes, along with the database
     * half's `recent()`.
     */
    public function testTheClassOffersExactlyTheEightPublicMethods(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass('NTDST_Logger'))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $this->assertSame(
            ['__construct', 'critical', 'debug', 'error', 'flush', 'flushBatchedLogs', 'info', 'warning'],
            $methods,
            'NTDST_Logger must offer exactly the eight public methods FR-5 keeps — no handler API, '
                . 'no level setter, no batching switch, no database reader.',
        );
    }

    // ── 2. The global helpers ────────────────────────────────────────────────

    /**
     * `ntdst_log($channel)` is the one front door the file ships.
     *
     * `ntdst_log_debug()`, `ntdst_log_info()` and `ntdst_log_error()` wrapped
     * `ntdst_log()->debug|info|error()` — one call each, on the DEFAULT channel,
     * which is the one thing a log line most needs to name. A wrapper that
     * drops the argument that matters is worse than no wrapper.
     */
    public function testTheFileDeclaresTheOneGlobalHelperAndNoOther(): void
    {
        preg_match_all('/\bfunction\s+([A-Za-z_]\w*)\s*\(/', self::sourceParts()[1], $found);

        $this->assertSame(
            ['ntdst_log'],
            $found[1],
            'services/Logger.php must declare ntdst_log() and no other global function.',
        );
    }

    // ── 3. Construction reaches nothing ──────────────────────────────────────

    /**
     * I-2 / FR-3: the class names no Data layer and no post type.
     *
     * This is the load-order trap. While the constructor called
     * `ntdst_data()->register('log_entry', …)`, services/Logger.php could not be
     * required before api/Data.php, so every core call site had to ask whether
     * ntdst_log() existed yet. The post type is gone whichever way it would have
     * been registered — through the Data layer or straight through WordPress —
     * so both names are checked, and `register_post_type()` is also expected
     * `never()` across a real construction below.
     */
    public function testTheClassNamesNoDataLayerAndNoPostTypeRegistration(): void
    {
        $class = self::sourceParts()[0];

        $this->assertStringNotContainsString('ntdst_data', $class, 'FR-5: the Logger has no Data layer dependency.');
        $this->assertStringNotContainsString('register_post_type', $class, 'FR-5: the Logger registers no post type.');
        $this->assertStringNotContainsString('log_entry', $class, 'FR-5: the log post type is gone.');
    }

    /**
     * Constructing a logger registers no post type.
     *
     * The companion to the source read above, and to the seam test at the foot
     * of this file: a name absent from the source cannot be called, and a
     * function absent from the process cannot be reached — this one watches a
     * real construction with WordPress's own registrar armed to fail.
     */
    public function testConstructionRegistersNoPostType(): void
    {
        Functions\expect('register_post_type')->never();

        $logger = new NTDST_Logger('probe');

        $this->assertInstanceOf('NTDST_Logger', $logger);
    }

    // ── 4. No filter, no action ──────────────────────────────────────────────

    /**
     * The database switch and the log hooks are gone, across a real error.
     *
     * `ntdst_log_database_enabled` was the filter that armed the PII sink; a
     * filter still applied but no longer read would be the worst half-removal —
     * a site answering `true` and believing its errors are in the database. The
     * two actions go with it: `ntdst_log` and the per-channel `ntdst_log_{$channel}`
     * gave a listener the message, the context and a re-entrant path back into
     * logging, and FR-11 leaves core with no `ntdst_`-prefixed hook at all.
     */
    public function testAnErrorAsksNoDatabaseFilterAndFiresNoLogHook(): void
    {
        Filters\expectApplied('ntdst_log_database_enabled')->never();
        Actions\expectDone('ntdst_log')->never();
        Actions\expectDone('ntdst_log_probe')->never();

        (new NTDST_Logger('probe'))->error('probe', ['k' => 'v']);
        NTDST_Logger::flushBatchedLogs();

        // The line still landed — the deletions above removed the sink, not the
        // logging.
        $this->assertFileExists($this->logDir . '/probe-' . date('Y-m-d') . '.log');
    }

    // ── 5. The kept behaviour ────────────────────────────────────────────────

    /**
     * The file handler still writes one formatted line to today's channel file.
     */
    public function testAnErrorLandsExactlyOneFormattedLineInTodaysChannelFile(): void
    {
        $logger = new NTDST_Logger('probe');
        $logger->error('probe', ['k' => 'v']);
        NTDST_Logger::flushBatchedLogs();

        $file = $this->logDir . '/probe-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file, 'The file handler must write to WP_CONTENT_DIR/logs/<channel>-<Y-m-d>.log.');

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertCount(1, $lines, 'One error must produce one line.');
        $this->assertSame(
            '[' . self::STAMP . '] probe.ERROR: probe {"k":"v"}',
            $lines[0],
            'The line format is timestamp, channel.LEVEL, message, JSON context.',
        );
    }

    /**
     * error_log() receives the error once, and the levels below it never.
     *
     * Observed on the real sink rather than through a mock: error_log() is a PHP
     * internal, so Brain Monkey cannot redefine it without a patchwork.json
     * `redefinable-internals` entry that would instrument every internal call in
     * the suite. Pointing the ini directive at a throwaway file proves the same
     * contract against the real function.
     *
     * `warning()` carries the negative half. The suite runs with WP_DEBUG
     * undefined, so the constructor's gate sits at WARNING and `info()` is
     * dropped before any handler sees it — a true assertion, but one that would
     * stay true if the error_log handler lost its level check entirely.
     * `warning()` passes the gate, reaches the handlers, and must still not be
     * shouted at the PHP log.
     */
    public function testErrorReachesErrorLogOnceAndTheLevelsBelowItNever(): void
    {
        $logger = new NTDST_Logger('probe');

        $logger->error('probe', ['k' => 'v']);

        $this->assertFileExists($this->sink, 'An error must reach error_log().');
        $this->assertCount(1, file($this->sink, FILE_SKIP_EMPTY_LINES), 'One error, one error_log line.');
        $this->assertStringContainsString(
            '[probe] ERROR: probe {"k":"v"}',
            (string) file_get_contents($this->sink),
        );

        $logger->info('quiet');
        $logger->warning('quiet');

        $this->assertCount(
            1,
            file($this->sink, FILE_SKIP_EMPTY_LINES),
            'info() and warning() must never reach error_log().',
        );
    }

    // ── 6. The seam: the shipped file, whole, in an empty process ────────────

    /**
     * services/Logger.php loads and logs with NOTHING else in the process.
     *
     * The un-mocked chain, and the only place the promise can actually be
     * stated: a fresh PHP process requires the shipped file — no Composer, no
     * WordPress, no api/Data.php, no `apply_filters`, no `do_action`, no
     * `register_post_type` — and calls the real `ntdst_log('probe')->error()`.
     * Two WordPress functions are defined, `current_time()` and `wp_mkdir_p()`,
     * because those are the file handler's declared WordPress dependencies.
     * Anything else the Logger reaches for is undefined, so reaching for it is
     * a fatal and this test reads the exit code.
     *
     * That is the load-order promise as a behaviour rather than as a diff:
     * ntdst-core.php requires this file FIRST, before api/ and admin/, and a
     * constructor that touched the Data layer made that impossible. It is also
     * where `function_exists('ntdst_log_debug')` is a real question — this
     * process is the only one in the suite that ever loads the file's function
     * half.
     *
     * The negative case is carried by the same run: the error must reach BOTH
     * sinks (the channel file and error_log), because a Logger that silently
     * logged nothing would otherwise pass every assertion above.
     */
    public function testTheShippedFileLoadsAndLogsInAProcessWithNoWordPressAndNoDataLayer(): void
    {
        $sandbox = WP_CONTENT_DIR . '/seam';
        $content = $sandbox . '/wp-content';
        $seamLog = $sandbox . '/php-error-log.txt';
        $script = $sandbox . '/seam.php';
        mkdir($sandbox, 0777, true);

        file_put_contents($script, <<<'SEAM'
            <?php
            define('ABSPATH', '/tmp/wordpress/');
            define('WP_CONTENT_DIR', $argv[1]);

            // The file handler's two declared WordPress dependencies, and the
            // only two. Everything else core's Logger might reach for is
            // deliberately absent from this process.
            function current_time(string $type, $gmt = 0): string
            {
                return '2026-08-23 11:22:33';
            }

            function wp_mkdir_p(string $target): bool
            {
                return is_dir($target) || mkdir($target, 0777, true);
            }

            require $argv[2] . '/services/Logger.php';

            ntdst_log('probe')->error('probe', ['k' => 'v']);
            NTDST_Logger::flushBatchedLogs();

            echo json_encode([
                'class' => class_exists('NTDST_Logger'),
                'helpers' => array_values(array_filter(
                    ['ntdst_log', 'ntdst_log_debug', 'ntdst_log_info', 'ntdst_log_error'],
                    'function_exists',
                )),
            ]);
            SEAM);

        $command = sprintf(
            '%s -d error_log=%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($seamLog),
            escapeshellarg($script),
            escapeshellarg($content),
            escapeshellarg(dirname(__DIR__, 2)),
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $printed = implode("\n", $output);

        $this->assertSame(
            0,
            $status,
            "services/Logger.php must load and log in a process that has no WordPress, no hook API and no "
                . "Data layer. The process said:\n" . $printed
                // A fatal goes to the ini error_log, not to stdout, so the one
                // line that names the missing dependency is in the sink.
                . "\n" . (is_file($seamLog) ? (string) file_get_contents($seamLog) : ''),
        );

        $this->assertSame(
            ['class' => true, 'helpers' => ['ntdst_log']],
            json_decode($printed, true),
            'The file must ship NTDST_Logger and ntdst_log() — and no ntdst_log_debug/info/error().',
        );

        $file = $content . '/logs/probe-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file, 'The error must reach the channel file.');
        $this->assertStringEndsWith(
            'probe.ERROR: probe {"k":"v"}',
            trim((string) file_get_contents($file)),
        );

        $this->assertFileExists($seamLog, 'The error must reach error_log().');
        $this->assertStringContainsString('[probe] ERROR: probe {"k":"v"}', (string) file_get_contents($seamLog));
    }

    // ── Loading the shipped source ───────────────────────────────────────────

    /**
     * Split the shipped file into [the class, everything outside it].
     *
     * Comments are stripped first, so a docblock that DISCUSSES a removed
     * helper is never read as a declaration of one. The class ends at the first
     * closing brace in column 0 — PSR-12 indents every method's, and phpcs runs
     * PSR-12 over services/. If either landmark moves, this throws by name
     * rather than quietly asserting over a shorter string.
     *
     * @return array{0: string, 1: string}
     */
    private static function sourceParts(): array
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(self::SOURCE)) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+NTDST_Logger\b/m', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('services/Logger.php no longer declares class NTDST_Logger at the top level.');
        }

        $from = (int) $m[0][1];
        $end = strpos($code, "\n}\n", $from);

        if ($end === false) {
            throw new RuntimeException('Could not find the column-0 closing brace of class NTDST_Logger.');
        }

        return [
            substr($code, $from, $end - $from + 3),
            substr($code, 0, $from) . substr($code, $end + 3),
        ];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}
