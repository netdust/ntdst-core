<?php
defined('ABSPATH') || exit;

// The old ntdst-cron plugin's helper, as it shipped: guarded, so core's wins.
if (!function_exists('ntdst_cron_register')) {
    function ntdst_cron_register(string $hook, array $config): void
    {
        throw new RuntimeException('the old plugin helper must never answer');
    }
}
