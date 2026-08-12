<?php // tests/Unit/SchedulerTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    // Adds Mockery/Brain Monkey expectation verification to PHPUnit's
    // assertion count — without this, Functions\expect(...)->once() checks
    // are verified (and can fail) but PHPUnit's failOnRisky="true" flags
    // the test as risky for "no assertions", breaking the strict gate.
    use MockeryPHPUnitIntegration;

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_schedules_once_when_nothing_pending(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), 'daily', 'my_hook');
        Functions\expect('add_action')->once()->with('my_hook', Mockery::type('callable'));

        (new NTDST_Scheduler())->schedule('my_hook', 'daily', fn() => null);
    }

    public function test_never_double_schedules(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(1754900000);
        Functions\expect('wp_schedule_event')->never();
        Functions\expect('add_action')->once();

        (new NTDST_Scheduler())->schedule('my_hook', 'daily', fn() => null);
    }

    public function test_clear_unschedules(): void
    {
        Functions\expect('wp_clear_scheduled_hook')->once()->with('my_hook');
        (new NTDST_Scheduler())->clear('my_hook');
    }

    public function test_facades_exist_and_delegate(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_event')->once();
        Functions\expect('add_action')->once();

        $this->assertTrue(function_exists('ntdst_schedule_recurring'));
        $this->assertTrue(function_exists('ntdst_clear_recurring'));
        ntdst_schedule_recurring('my_hook', 'daily', fn() => null);
    }

    public function test_registry_tracks_and_clears_hooks(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);

        $scheduler = new NTDST_Scheduler();
        $scheduler->schedule('a', 'daily', fn() => null);
        $scheduler->schedule('b', 'hourly', fn() => null);

        $this->assertSame(['a' => 'daily', 'b' => 'hourly'], $scheduler->hooks());

        $scheduler->clear('a');

        $this->assertSame(['b' => 'hourly'], $scheduler->hooks());
    }

    public function test_is_scheduled_reflects_wp_next_scheduled(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(1754900000);
        $this->assertTrue((new NTDST_Scheduler())->isScheduled('my_hook'));

        Functions\when('wp_next_scheduled')->justReturn(false);
        $this->assertFalse((new NTDST_Scheduler())->isScheduled('my_hook'));
    }

    public function test_next_run_returns_timestamp_or_null(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(1754900000);
        $this->assertSame(1754900000, (new NTDST_Scheduler())->nextRun('my_hook'));

        Functions\when('wp_next_scheduled')->justReturn(false);
        $this->assertNull((new NTDST_Scheduler())->nextRun('my_hook'));
    }

    public function test_scheduler_facade_returns_same_instance(): void
    {
        $this->assertSame(ntdst_scheduler(), ntdst_scheduler());
    }

    public function test_schedule_recurring_facade_registers_into_shared_instance(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('add_action')->justReturn(true);

        ntdst_schedule_recurring('shared_hook', 'weekly', fn() => null);

        $this->assertSame('weekly', ntdst_scheduler()->hooks()['shared_hook'] ?? null);
    }
}
