<?php

declare(strict_types=1);

namespace Ntdst\Cron {

    defined('ABSPATH') || exit;

    use Throwable;

    /**
     * The seam: the one place a scheduled job is registered.
     * Registration schedules the event, wraps the callback, and records every fire.
     */
    class CronRegistry
    {
        /**
         * Paused hooks live in their own option, keyed by hook name. A hook name is
         * only ever an array key, never part of an option name (threat model T6).
         */
        private const PAUSED_OPTION = 'ntdst_cron_paused';

        /**
         * A summary is bounded here, where it is composed — RunLog owns the ring
         * buffer, not content policy. MAX_RUNS caps entry COUNT; without this an
         * unbounded summary makes the option itself unbounded, and RunLog
         * unserialises all of it on every append (S2).
         */
        private const MAX_SUMMARY = 500;

        /** Credential constants stripped from a stored failure summary (S1). */
        private const SECRET_CONSTANTS = [
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

        private array $jobs = [];

        public function __construct(private readonly RunLog $log) {}

        public function register(string $hook, array $config): void
        {
            $callback = $config['callback'] ?? null;

            if (!is_callable($callback)) {
                ntdst_log('cron')->error('registratie zonder geldige callback geweigerd', ['hook' => $hook]);

                return;
            }

            // A second registration replaces the config but must not bind a second
            // listener: that would run the job once per registration on one fire.
            $alreadyBound = $this->has($hook);

            $this->jobs[$hook] = [
                'label' => (string) ($config['label'] ?? $hook),
                'description' => (string) ($config['description'] ?? ''),
                'recurrence' => (string) ($config['recurrence'] ?? 'daily'),
                'callback' => $callback,
            ];

            if (!$alreadyBound) {
                add_action($hook, function () use ($hook): void {
                    $this->fire($hook, false);
                });
            }

            if ($this->isPaused($hook) || wp_next_scheduled($hook)) {
                return;
            }

            $this->schedule($hook);
        }

        /**
         * The registry as the panel reads it: config plus scheduling state, callback dropped.
         */
        public function all(): array
        {
            $out = [];

            foreach ($this->jobs as $hook => $job) {
                unset($job['callback']);
                $job['paused'] = $this->isPaused($hook);
                $out[$hook] = $job;
            }

            return $out;
        }

        public function has(string $hook): bool
        {
            return isset($this->jobs[$hook]);
        }

        public function run(string $hook, bool $manual): void
        {
            if (!$this->has($hook)) {
                return;
            }

            $this->fire($hook, $manual);
        }

        public function isPaused(string $hook): bool
        {
            return !empty($this->paused()[$hook]);
        }

        /**
         * Pause a job: drop every scheduled occurrence and persist the flag so the
         * next boot's register() does not schedule it again (FR-9).
         *
         * wp_clear_scheduled_hook() rather than wp_unschedule_event() on the next
         * timestamp: it removes ALL occurrences, so SC-6's "0 scheduled events"
         * holds even if a duplicate ever crept in, and it is a no-op when nothing
         * is scheduled, which makes a second pause harmless.
         */
        public function pause(string $hook): void
        {
            if (!$this->has($hook)) {
                return;
            }

            wp_clear_scheduled_hook($hook);

            $paused = $this->paused();
            $paused[$hook] = true;
            $this->savePaused($paused);
        }

        /**
         * Resume a job: clear the flag and restore exactly one event. The guard
         * keeps a resume of an already-running job from adding a second one.
         */
        public function resume(string $hook): void
        {
            if (!$this->has($hook)) {
                return;
            }

            $paused = $this->paused();
            unset($paused[$hook]);
            $this->savePaused($paused);

            if (!wp_next_scheduled($hook)) {
                $this->schedule($hook);
            }
        }

        /**
         * The fifth argument is what makes a refusal readable — without it the
         * return is a bare false and the reason is gone. wp_schedule_event()
         * refuses any recurrence absent from wp_get_schedules(), so a job carrying
         * a plugin-supplied recurrence registers and never schedules when the
         * cron_schedules filter that adds it has not run yet (D1).
         */
        private function schedule(string $hook): void
        {
            $recurrence = $this->jobs[$hook]['recurrence'];
            $scheduled = wp_schedule_event(time(), $recurrence, $hook, [], true);

            if (is_wp_error($scheduled)) {
                ntdst_log('cron')->error('kon taak niet inplannen', [
                    'hook' => $hook,
                    'recurrence' => $recurrence,
                    'error' => $scheduled->get_error_message(),
                ]);
            }
        }

        /**
         * One fire, one log entry. A throwing job must never break the cron run or
         * stop the next scheduled fire, so nothing escapes here (SC-3).
         */
        private function fire(string $hook, bool $manual): void
        {
            $start = microtime(true);
            $ok = true;

            try {
                $result = ($this->jobs[$hook]['callback'])();
                $summary = is_string($result) && $result !== ''
                    ? mb_substr($result, 0, self::MAX_SUMMARY)
                    : 'ok';
            } catch (Throwable $e) {
                $ok = false;
                $summary = $this->failureSummary($e);

                ntdst_log('cron')->error('taak mislukt', [
                    'hook' => $hook,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->log->append($hook, [
                'at' => (int) $start,
                'ms' => (int) round((microtime(true) - $start) * 1000),
                'ok' => $ok,
                'summary' => $summary,
                'manual' => $manual,
            ]);
        }

        /**
         * The run log is persisted to an option and rendered to admins, so an
         * exception message goes to the logger and only a redacted form is stored.
         * wpdb, PDO, mailer and HTTP-client messages carry credentials and absolute
         * paths, and escaping them stops script execution but not disclosure.
         *
         * Redaction is exact-match against the credentials this install actually
         * holds. Guessing at secret-shaped substrings in free text is not decidable,
         * so a message carrying a THIRD-PARTY secret still reaches the option —
         * truncation bounds that, and the logger keeps the untouched message for
         * diagnosis.
         */
        private function failureSummary(Throwable $e): string
        {
            $message = str_replace([ABSPATH, WP_CONTENT_DIR], '', $e->getMessage());
            $message = str_replace($this->secrets(), '[REDACTED]', $message);

            return $e::class . ': ' . mb_substr($message, 0, self::MAX_SUMMARY);
        }

        /** @return array<int, string> the non-empty credential constants defined here */
        private function secrets(): array
        {
            $secrets = [];

            foreach (self::SECRET_CONSTANTS as $constant) {
                $value = defined($constant) ? constant($constant) : null;

                // A one- or two-character value would redact half the message.
                if (is_string($value) && strlen($value) > 3) {
                    $secrets[] = $value;
                }
            }

            return $secrets;
        }

        private function paused(): array
        {
            $paused = get_option(self::PAUSED_OPTION, []);

            return is_array($paused) ? $paused : [];
        }

        /** Never autoloaded: the panel is the only reader (T04's choice, kept). */
        private function savePaused(array $paused): void
        {
            update_option(self::PAUSED_OPTION, $paused, false);
        }
    }

    // The container gives every consumer the same registry and run log.
    add_action('ntdst/services_registered', static function (): void {
        ntdst_set(RunLog::class);
        ntdst_set(CronRegistry::class, static fn ($c): CronRegistry => new CronRegistry($c->get(RunLog::class)));
    });
}

namespace {

    /**
     * Register a recurring job: schedules it once, wraps the callback, records every fire.
     * Config keys: label, description, recurrence (a WordPress schedule name, default daily), callback.
     * Callable from `ntdst/features_ready` onward; earlier, the registry is not bound yet.
     *
     * Declared UNCONDITIONALLY, as ntdst_log() is: a !function_exists() wrapper would let an
     * older copy win the declaration silently, a redeclare fatal names the collision instead.
     */
    function ntdst_cron_register(string $hook, array $config): void
    {
        ntdst_get(Ntdst\Cron\CronRegistry::class)->register($hook, $config);
    }
}
