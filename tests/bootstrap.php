<?php // tests/bootstrap.php
PHP_SAPI === 'cli' || exit; // phpunit runs CLI; a web hit is not CLI
require_once __DIR__ . '/../vendor/autoload.php';
define('ABSPATH', '/tmp/wordpress/'); // satisfies the defined-guard in class files

// add_filter must be a REAL function for the whole suite: several class files
// call it at load time, and Brain Monkey cannot patch a function defined before
// Patchwork (DefinedTooEarly). Defining it once HERE — rather than per test file,
// where the first-loaded definition silently won — makes it record, so a test can
// assert what a service mounted. Do not Monkey-patch add_filter; read the global.
if (!function_exists('add_filter')) {
    function add_filter($hook, $cb = null, $priority = 10, $args = 1)
    {
        // Keyed by hook AND priority. One-per-hook was enough while this
        // package mounted a single callback per hook; it stops being enough
        // the moment two do, and the failure is silent — the second mount
        // overwrites the first and a test drives the wrong callback while
        // still passing. Both shapes are kept: `[$hook]` stays FIRST-mounted
        // so existing helpers keep meaning what they meant.
        if (!isset($GLOBALS['_ntdst_test_filters'][$hook])) {
            $GLOBALS['_ntdst_test_filters'][$hook] = $cb;
        }

        $GLOBALS['_ntdst_test_filters_at'][$hook][(int) $priority] = $cb;

        return true;
    }
}

// ntdst_log() must be a REAL function for the same reason add_filter is, and
// the failure mode is nastier: shipped code guards its logging with
// function_exists('ntdst_log'), so while NO test stubs it the guard is false
// and every log call is skipped. The moment ONE test stubs it through Brain
// Monkey, Patchwork defines it PROCESS-WIDE, the guard turns true for every
// later test file, and those files fail with "ntdst_log is not defined nor
// mocked in this test" — a failure in a file that changed nothing. Defined
// here, before Patchwork, it is the same null logger for the whole suite.
if (!function_exists('ntdst_log')) {
    function ntdst_log(string $channel = 'app'): object
    {
        return new class ($channel) {
            public function __construct(private string $channel) {}

            public function __call(string $level, array $args): void
            {
                $GLOBALS['_ntdst_test_log'][] = [$this->channel, $level, $args[0] ?? ''];
            }
        };
    }
}

require_once __DIR__ . '/../core/Container.php';
require_once __DIR__ . '/../support/Cidr.php';
require_once __DIR__ . '/../support/ClientIp.php';
require_once __DIR__ . '/../support/RateLimiter.php';
require_once __DIR__ . '/../api/Data.php';
require_once __DIR__ . '/../admin/MetaboxGenerator.php';
require_once __DIR__ . '/../services/Scheduler.php';
require_once __DIR__ . '/Support/RestApiInitHarness.php';
