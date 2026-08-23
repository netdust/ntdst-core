<?php

declare(strict_types=1);

/**
 * NTDST Logger — PSR-3-shaped logging over two sinks.
 *
 * Five levels (debug, info, warning, error, critical) and a channel per
 * caller. A line goes to the channel's daily file, and an error or worse also
 * goes to PHP's own error_log. That is the whole surface: there is no runtime
 * way to add a third sink, move the level gate or change the write moment, so
 * "where do the logs go" has one answer, and the channel plus the environment
 * decide it.
 *
 * This file has NO dependency on any other part of core. ntdst-core.php
 * requires it FIRST, before api/ and admin/, so every later file can call
 * ntdst_log() without asking whether it exists yet (FR-3). Keep it that way:
 * a require added here is a require added to the front of the whole package.
 *
 * Production note:
 *  - Log files land in WP_CONTENT_DIR/logs. On Bedrock that's web/app/logs,
 *    which sits inside the webroot. .htaccess + index.html block direct
 *    access on Apache; on Nginx, the server config MUST deny /logs.
 *
 * Usage:
 *   ntdst_log()->info('User logged in', ['user_id' => 123]);
 *   ntdst_log()->error('Payment failed', ['order_id' => 456, 'error' => $e]);
 *   ntdst_log('debug')->debug('API response', ['data' => $response]);
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/../core/LogLevel.php';

class NTDST_Logger
{
    /** 0=debug, 1=info, 2=warning, 3=error, 4=critical */
    protected int $min_level = 0;

    /**
     * PERFORMANCE: entries collected per file and written once on shutdown.
     * An error skips the batch — during an incident the line has to be on
     * disk before the request that produced it can die.
     *
     * @var array<string, list<string>>
     */
    protected static array $batchedLogs = [];

    protected static bool $shutdownRegistered = false;

    public function __construct(protected readonly string $channel = 'app')
    {
        // Set minimum level based on environment
        $this->min_level = (defined('WP_DEBUG') && WP_DEBUG) ? LogLevel::Debug->value : LogLevel::Warning->value;

        // PERFORMANCE: Register shutdown handler once for batched writes
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'flushBatchedLogs']);
            self::$shutdownRegistered = true;
        }
    }

    // PSR-3 Interface Methods

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug->value, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info->value, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning->value, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error->value, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical->value, $message, $context);
    }

    /**
     * Force immediate flush of batched logs
     * Use before long-running operations or when immediate logging is needed
     */
    public function flush(): void
    {
        self::flushBatchedLogs();
    }

    /**
     * PERFORMANCE: Flush all batched logs to files
     * Called on shutdown to write all collected logs at once
     */
    public static function flushBatchedLogs(): void
    {
        if (empty(self::$batchedLogs)) {
            return;
        }

        $dir = self::logDir();

        foreach (self::$batchedLogs as $filename => $entries) {
            file_put_contents($dir . '/' . $filename, implode('', $entries), FILE_APPEND | LOCK_EX);
        }

        self::$batchedLogs = [];
    }

    /**
     * Write one line to the two sinks.
     *
     * Both writes are inside one try/catch, and that is not decoration: a site
     * running WP_DEBUG with an error handler that promotes warnings to
     * ErrorException turns an unwritable logs directory into a throw, and a
     * request must never die because it tried to say something. The fallback
     * bypasses ntdst_log() to avoid recursion.
     */
    protected function log(int $level, string $message, array $context = []): void
    {
        if ($level < $this->min_level) {
            return;
        }

        $message = $this->interpolate($message, $context);
        $label = LogLevel::tryFrom($level)?->label() ?? 'UNKNOWN';
        $suffix = !empty($context) ? ' ' . json_encode($context) : '';

        try {
            $line = '[' . current_time('Y-m-d H:i:s') . "] {$this->channel}.{$label}: {$message}{$suffix}\n";

            if ($level >= LogLevel::Error->value) {
                $this->writeToLogFile($line);
                error_log("[{$this->channel}] {$label}: {$message}{$suffix}");

                return;
            }

            self::$batchedLogs[$this->channel . '-' . date('Y-m-d') . '.log'][] = $line;
        } catch (\Throwable $e) {
            error_log('Logger write failed: ' . $e->getMessage());
        }
    }

    /**
     * Interpolate context values into message placeholders
     */
    protected function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $val) {
            if (is_null($val) || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            } elseif (is_object($val)) {
                $replace['{' . $key . '}'] = get_class($val);
            } elseif (is_array($val)) {
                $replace['{' . $key . '}'] = json_encode($val);
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Write one line to the channel's daily file immediately.
     *
     * Uses file_put_contents with FILE_APPEND | LOCK_EX for consistency
     * with the batched-flush path. error_log(_, 3, _) is supported but
     * inconsistent style for the same operation.
     */
    protected function writeToLogFile(string $line): void
    {
        $file = self::logDir() . '/' . $this->channel . '-' . date('Y-m-d') . '.log';

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * The logs directory, created and protected on the way out.
     *
     * .htaccess + an empty index.html block directory listings and direct
     * fetches where they are honoured. On Nginx these files are inert; server
     * config must deny the /logs path explicitly.
     */
    protected static function logDir(): string
    {
        $dir = WP_CONTENT_DIR . '/logs';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }

        return $dir;
    }

    // A database half lived here: a post type registered from the constructor,
    // a handler that wrote REQUEST_URI, the client IP and the whole context
    // array into post meta on every error, a filter that armed it, and a
    // reader that queried it back. core-trim FR-5 removed all of it. It was a
    // PII sink switched on by WP_DEBUG — which is what an operator turns on
    // DURING an incident — and it answered every error with wp_insert_post
    // plus N meta writes plus a save_post cascade, which is the wrong load
    // profile at exactly the wrong moment. Its reader had no callers on the
    // fleet, and the one that queried by date was the last caller of a query
    // method FR-4 had already deleted. Reaching the Data layer from this
    // constructor is also what forced this file to load LAST, and every core
    // call site to guard its logging with function_exists() (FR-3).
    //
    // The runtime handler API went with it, for its own reason: a consumer
    // could bolt on a fourth sink, move the level gate or switch batching off
    // from anywhere, so no call site could say where a line would end up.
    // Nobody on the fleet did. The channel and the environment decide now.
}

/**
 * Global helper - get logger instance.
 *
 * Declared UNCONDITIONALLY. ntdst-core.php requires this file once, first, and
 * every core caller now calls ntdst_log() without asking whether it exists
 * (FR-3). A !function_exists() wrapper here would let a second, older copy of
 * core on the same request win the declaration and silently serve every log
 * line — a redeclare fatal names that collision instead.
 */
function ntdst_log(string $channel = 'app'): NTDST_Logger
{
    static $loggers = [];

    if (!isset($loggers[$channel])) {
        $loggers[$channel] = new NTDST_Logger($channel);
    }

    return $loggers[$channel];
}
