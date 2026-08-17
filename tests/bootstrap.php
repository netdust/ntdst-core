<?php // tests/bootstrap.php
PHP_SAPI === 'cli' || exit; // phpunit runs CLI; a web hit is not CLI
require_once __DIR__ . '/../vendor/autoload.php';
define('ABSPATH', '/tmp/wordpress/'); // satisfies the defined-guard in class files
require_once __DIR__ . '/../core/Container.php';
require_once __DIR__ . '/../support/Cidr.php';
require_once __DIR__ . '/../support/ClientIp.php';
require_once __DIR__ . '/../support/RateLimiter.php';
require_once __DIR__ . '/../api/Data.php';
require_once __DIR__ . '/../admin/MetaboxGenerator.php';
require_once __DIR__ . '/../services/Scheduler.php';
