<?php

declare(strict_types=1);

/**
 * Recurring WP-Cron registration through a single, self-healing seam (INV-10).
 *
 * Reusable primitive: any recurring cron job registers through this
 * function instead of hand-rolling wp_schedule_event directly. Idempotent —
 * calling it repeatedly (e.g. on every page load / plugin init) never
 * double-schedules the event, because it only schedules when nothing is
 * already pending for the hook.
 *
 * Only built-in WP intervals ('hourly', 'twicedaily', 'daily', 'weekly')
 * are supported here — no custom `cron_schedules` interval is registered
 * by this helper.
 *
 * The callback receives no request data: WP-Cron invokes hooks outside
 * any HTTP request context, so no superglobals are threaded through.
 */

defined('ABSPATH') || exit;

final class NTDST_Scheduler
{
    /** @var array<string, string> hook => interval, registered through this seam this request */
    private array $hooks = [];

    /**
     * @param string   $hook     The cron hook name.
     * @param string   $interval A built-in WP-Cron interval ('daily', 'hourly', ...).
     * @param callable $cb       The callback to run when the hook fires.
     */
    public function schedule(string $hook, string $interval, callable $cb): void
    {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), $interval, $hook);
        }

        add_action($hook, $cb);

        $this->hooks[$hook] = $interval;
    }

    /**
     * Unschedule a recurring WP-Cron job registered via schedule().
     *
     * @param string $hook The cron hook name.
     */
    public function clear(string $hook): void
    {
        wp_clear_scheduled_hook($hook);

        unset($this->hooks[$hook]);
    }

    /**
     * Whether a WP-Cron event is currently pending for this hook.
     */
    public function isScheduled(string $hook): bool
    {
        return wp_next_scheduled($hook) !== false;
    }

    /**
     * Unix timestamp of the next run for this hook, or null when nothing is pending.
     */
    public function nextRun(string $hook): ?int
    {
        $timestamp = wp_next_scheduled($hook);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * The hook => interval registry built through this seam this request.
     *
     * @return array<string, string>
     */
    public function hooks(): array
    {
        return $this->hooks;
    }
}

if (!function_exists('ntdst_scheduler')) {
    function ntdst_scheduler(): NTDST_Scheduler
    {
        static $scheduler = null;
        return $scheduler ??= new NTDST_Scheduler();
    }
}

if (!function_exists('ntdst_schedule_recurring')) {
    function ntdst_schedule_recurring(string $hook, string $interval, callable $cb): void
    {
        ntdst_scheduler()->schedule($hook, $interval, $cb);
    }
}

if (!function_exists('ntdst_clear_recurring')) {
    function ntdst_clear_recurring(string $hook): void
    {
        ntdst_scheduler()->clear($hook);
    }
}
