<?php

/**
 * Description: DI Container, Bootstrap, and Service System for WordPress
 * Version: 2.3.1
 * Author: Stefan Vandermeulen
 *
 * Architecture:
 * - core/     → Foundation (Container, Bootstrap, Theme, Router)
 * - api/      → Request Flow (Endpoints, Data, Response)
 * - admin/    → Admin UI (MetaboxGenerator, RelationField)
 * - services/ → Built-in services (Logger, Mailer, Scheduler)
 *
 * Loading is this explicit require_once list, NOT a directory scan — a file
 * that is not named here does not exist, and a stale path is an immediate
 * fatal rather than a silent drop.
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('NTDST_PATH', __DIR__);
define('NTDST_URL', plugins_url('', __FILE__));

// Load core foundation
require_once NTDST_PATH . '/core/Container.php';
require_once NTDST_PATH . '/core/Router.php';
require_once NTDST_PATH . '/core/Theme.php';
require_once NTDST_PATH . '/core/ServiceInterface.php';
require_once NTDST_PATH . '/core/SectorRegistry.php';
require_once NTDST_PATH . '/core/Bootstrap.php';

// Load API layer (request flow)
require_once NTDST_PATH . '/api/Data.php';
require_once NTDST_PATH . '/api/Response.php';

// Load admin UI (edit-screen rendering + persistence)
require_once NTDST_PATH . '/admin/MetaboxGenerator.php';
require_once NTDST_PATH . '/admin/RelationField.php';

// Load and initialize endpoints system
require_once NTDST_PATH . '/api/Endpoints.php';
ntdst_endpoints(); // Initialize endpoints to register REST routes

// Load services
require_once NTDST_PATH . '/services/Logger.php';
require_once NTDST_PATH . '/services/Mailer.php';
require_once NTDST_PATH . '/services/Scheduler.php';

// Register singleton instances that can't be auto-wired
ntdst_set(NTDST_SectorRegistry::class, fn() => ntdst_sectors());

/**
 * Enqueue the shared NTDST admin toolkit CSS.
 *
 * Call from admin_enqueue_scripts (or admin_head) on your plugin's settings page.
 * Uses wp_enqueue_style to prevent duplicates when multiple plugins load it.
 */
function ntdst_enqueue_admin_toolkit(): void
{
    wp_enqueue_style(
        'ntdst-admin-toolkit',
        NTDST_URL . '/assets/css/admin-toolkit.css',
        [],
        '1.0.0',
    );
}

/**
 * Enqueue the shared NTDST API client (window.ntdstAPI).
 *
 * Single source of truth for /wp-json/ntdst/v1/action calls. Use from both
 * frontend (theme) and admin pages. Localizes the wp_rest nonce as
 * window.ntdstAPIConfig.restNonce so the client can authenticate the REST
 * endpoint regardless of context.
 */
function ntdst_enqueue_api_client(): void
{
    $path = NTDST_PATH . '/assets/js/ntdst-api.js';
    wp_enqueue_script(
        'ntdst-api',
        NTDST_URL . '/assets/js/ntdst-api.js',
        [],
        file_exists($path) ? (string) filemtime($path) : '1.0.0',
        false,
    );
    wp_add_inline_script(
        'ntdst-api',
        'window.ntdstAPIConfig = ' . wp_json_encode([
            'restNonce' => wp_create_nonce('wp_rest'),
        ]) . ';',
        'before',
    );
}
