<?php // tests/Unit/SchedulerTest.php
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
}
