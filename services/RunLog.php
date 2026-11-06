<?php

declare(strict_types=1);

namespace Ntdst\Cron;

defined('ABSPATH') || exit;

/**
 * Per-hook ring buffer of cron runs, capped at MAX_RUNS, newest first.
 * One option, never autoloaded: written on every fire, read only in the panel.
 */
class RunLog
{
    public const MAX_RUNS = 20;

    private const OPTION = 'ntdst_cron_runs';

    private const LOCK = 'ntdst_cron_runlog';

    private const LOCK_TIMEOUT = 5;

    public function append(string $hook, array $entry): void
    {
        // The lock only guards the rare two-fire interleave. When it is
        // unavailable, recording anyway beats dropping the entry.
        $locked = $this->acquireLock();

        try {
            $all = $this->all();
            $runs = $all[$hook] ?? [];
            array_unshift($runs, $entry);
            $all[$hook] = array_slice($runs, 0, self::MAX_RUNS);

            update_option(self::OPTION, $all, false);
        } finally {
            if ($locked) {
                $this->releaseLock();
            }
        }
    }

    public function runsFor(string $hook): array
    {
        return $this->all()[$hook] ?? [];
    }

    private function all(): array
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? $all : [];
    }

    private function acquireLock(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL advisory lock: touches no table, and a lock answer must never be cached.
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            self::LOCK,
            self::LOCK_TIMEOUT,
        )) === 1;
    }

    private function releaseLock(): void
    {
        global $wpdb;

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the advisory lock above; touches no table, never cacheable.
    }
}
