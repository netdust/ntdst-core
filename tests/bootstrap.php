<?php // tests/bootstrap.php
PHP_SAPI === 'cli' || exit; // phpunit runs CLI; a web hit is not CLI
require_once __DIR__ . '/../vendor/autoload.php';
define('ABSPATH', '/tmp/wordpress/'); // satisfies the defined-guard in class files

// ---------------------------------------------------------------------------
// REAL, NOT STUBBED: the four WordPress functions below, for the whole suite.
//
// THE RULE. A WordPress function is defined HERE, before Patchwork, when any of
// these three is true, and it is then never Monkey-patched by any test:
//   1. shipped code calls it at LOAD time — Brain Monkey cannot patch a
//      function that was defined before Patchwork (DefinedTooEarly);
//   2. shipped code guards it with function_exists() — while no test stubs it
//      the guard is FALSE, and the moment ONE test stubs it Patchwork defines
//      it PROCESS-WIDE, so the guard turns true for every LATER test file and
//      those files fail having changed nothing;
//   3. it is not a question any test asks — it is a rule every test needs the
//      same answer to, and a per-file copy is a copy that drifts.
// Each definition below reproduces WordPress's own algorithm for the input this
// suite uses, so it is a real-equivalent and never an approximation of one. A
// test that needs to know what a service DID with one of them reads the global
// the recorder writes; it does not patch the function.
// ---------------------------------------------------------------------------

// Bite: class files mount filters at load time, and a second mount on the same
// hook must not silently overwrite the first — so this records by hook AND by
// priority, keeping `[$hook]` as the FIRST-mounted callback for older helpers.
if (!function_exists('add_filter')) {
    function add_filter($hook, $cb = null, $priority = 10, $args = 1)
    {
        if (!isset($GLOBALS['_ntdst_test_filters'][$hook])) {
            $GLOBALS['_ntdst_test_filters'][$hook] = $cb;
        }

        $GLOBALS['_ntdst_test_filters_at'][$hook][(int) $priority] = $cb;

        return true;
    }
}

// Bite: shipped code guards its logging with function_exists('ntdst_log'), so
// one stubbing test turns logging on for every later file — and the context is
// recorded because the actionable half of a warning lives there.
if (!function_exists('ntdst_log')) {
    function ntdst_log(string $channel = 'app'): object
    {
        return new class ($channel) {
            public function __construct(private string $channel) {}

            public function __call(string $level, array $args): void
            {
                $GLOBALS['_ntdst_test_log'][] = [$this->channel, $level, $args[0] ?? '', $args[1] ?? []];
            }
        };
    }
}

// Bite: this is THE KEY RULE every stored repeater cell already went through,
// and six test files each kept their own copy of it — one of which wrote the
// strip and the lowercase in the other order.
if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}

// Bite: the metabox save path calls it unguarded while support/ClientIp.php
// guards it — patching it for the save test broke six REST files that had not
// changed.
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

require_once __DIR__ . '/../core/Container.php';
require_once __DIR__ . '/../support/Cidr.php';
require_once __DIR__ . '/../support/ClientIp.php';
require_once __DIR__ . '/../support/RateLimiter.php';
require_once __DIR__ . '/../api/FieldTypes.php';
require_once __DIR__ . '/../api/Data.php';
require_once __DIR__ . '/../admin/MetaboxGenerator.php';
require_once __DIR__ . '/../services/Scheduler.php';
require_once __DIR__ . '/Support/RestApiInitHarness.php';
require_once __DIR__ . '/Support/BootstrapHarness.php';
