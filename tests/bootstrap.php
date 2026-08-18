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
        $GLOBALS['_ntdst_test_filters'][$hook] = $cb;
        return true;
    }
}
require_once __DIR__ . '/../core/Container.php';
require_once __DIR__ . '/../support/Cidr.php';
require_once __DIR__ . '/../support/ClientIp.php';
require_once __DIR__ . '/../support/RateLimiter.php';
require_once __DIR__ . '/../api/Data.php';
require_once __DIR__ . '/../admin/MetaboxGenerator.php';
require_once __DIR__ . '/../services/Scheduler.php';
