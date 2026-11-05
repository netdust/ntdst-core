<?php
declare(strict_types=1);

namespace Ntdst\Tests\Unit\Cron;

defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ntdst\Cron\RunLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/services/RunLog.php';

/** The capped ring buffer behind the cron registry. */
final class RunLogTest extends TestCase
{
    private const OPTION = 'ntdst_cron_runs';

    private RunLog $log;

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $autoload = [];

    /** @var string[] */
    private array $sql = [];

    private mixed $lockResult = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->options = [];
        $this->autoload = [];
        $this->sql = [];
        $this->lockResult = 1;

        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value, $autoload = null) {
            $this->options[$name] = $value;
            $this->autoload[$name] = $autoload;

            return true;
        });

        $test = $this;
        $GLOBALS['wpdb'] = new class ($test) {
            public function __construct(private RunLogTest $test)
            {
            }

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_var(string $query): mixed
            {
                return $this->test->recordSql($query);
            }
        };

        $this->log = new RunLog();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Called by the $wpdb double. */
    public function recordSql(string $query): mixed
    {
        $this->sql[] = $query;

        return str_contains($query, 'GET_LOCK') ? $this->lockResult : null;
    }

    private function entry(int $at, string $summary): array
    {
        return ['at' => $at, 'ms' => 12, 'ok' => true, 'summary' => $summary, 'manual' => false];
    }

    private function released(): bool
    {
        return array_filter($this->sql, static fn (string $q): bool => str_contains($q, 'RELEASE_LOCK')) !== [];
    }

    public function testTwentyFirstEntryEvictsTheOldestAndKeepsNewestFirst(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->log->append('stride_gate_reminder', $this->entry(1000 + $i, "run {$i}"));
        }

        $runs = $this->log->runsFor('stride_gate_reminder');

        $this->assertCount(RunLog::MAX_RUNS, $runs, 'the buffer is capped at MAX_RUNS');
        $this->assertSame('run 21', $runs[0]['summary'], 'newest first');
        $this->assertSame('run 2', $runs[RunLog::MAX_RUNS - 1]['summary'], 'oldest surviving run is #2');
        $this->assertSame(
            [],
            array_filter($runs, static fn (array $r): bool => $r['summary'] === 'run 1'),
            'the oldest entry is evicted',
        );
    }

    public function testASecondHooksEntriesAreUntouched(): void
    {
        $this->log->append('other_hook', $this->entry(500, 'other run'));

        for ($i = 1; $i <= 21; $i++) {
            $this->log->append('stride_gate_reminder', $this->entry(1000 + $i, "run {$i}"));
        }

        $other = $this->log->runsFor('other_hook');

        $this->assertCount(1, $other);
        $this->assertSame('other run', $other[0]['summary']);
        $this->assertCount(RunLog::MAX_RUNS, $this->log->runsFor('stride_gate_reminder'));
    }

    public function testRunsForAnUnknownHookIsEmpty(): void
    {
        $this->assertSame([], $this->log->runsFor('never_ran'));
    }

    public function testAppendPersistsTheWholeEntryShape(): void
    {
        $this->log->append('stride_gate_reminder', [
            'at' => 1700000000,
            'ms' => 340,
            'ok' => false,
            'summary' => 'mislukt: geen verbinding',
            'manual' => true,
        ]);

        $runs = $this->log->runsFor('stride_gate_reminder');

        $this->assertSame(1700000000, $runs[0]['at']);
        $this->assertSame(340, $runs[0]['ms']);
        $this->assertFalse($runs[0]['ok']);
        $this->assertSame('mislukt: geen verbinding', $runs[0]['summary']);
        $this->assertTrue($runs[0]['manual']);
    }

    public function testTheOptionIsWrittenWithAutoloadDisabled(): void
    {
        $this->log->append('stride_gate_reminder', $this->entry(1000, 'run'));

        $this->assertArrayHasKey(self::OPTION, $this->options, 'runs live in one option');
        $this->assertFalse($this->autoload[self::OPTION], 'the run log must never be autoloaded');
    }

    public function testAnEntryIsStillRecordedWhenTheLockIsUnavailable(): void
    {
        $this->lockResult = 0;

        $this->log->append('h', $this->entry(1, 'run'));

        $this->assertCount(1, $this->log->runsFor('h'));
        $this->assertFalse($this->released(), 'a lock never taken is never released');
    }

    public function testTheLockIsReleasedWhenTheWriteThrows(): void
    {
        Functions\when('update_option')->alias(function () {
            throw new RuntimeException('write failed');
        });

        try {
            $this->log->append('h', $this->entry(1, 'run'));
            $this->fail('the write exception must propagate');
        } catch (RuntimeException) {
            $this->assertTrue($this->released(), 'RELEASE_LOCK ran despite the throw');
        }
    }
}
