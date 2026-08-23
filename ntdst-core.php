<?php

/**
 * Description: DI Container, Bootstrap, and Service System for WordPress
 * Version: 5.0.0
 * Author: Stefan Vandermeulen
 *
 * Architecture:
 * - core/     → Foundation (Container, Bootstrap, Theme, Pages)
 * - support/  → Shared primitives with no dependencies (ClientIp, Cidr, RateLimiter)
 * - api/      → Request flow (Rest, Data, Response)
 * - admin/    → Admin UI (MetaboxGenerator, RelationField)
 * - services/ → Built-in services (Logger)
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

// Logger loads FIRST — immediately after the container, before core/, support/,
// api/ and admin/. It used to load LAST, which is why every caller in this
// package guarded its logging with function_exists('ntdst_log'): by the time
// Logger existed, the api/ file booted below had already RUN and anything it
// wanted to say went nowhere. Those guards are deleted (FR-3) and this line
// is what makes that safe. bin/guard.sh asserts both halves — no call-site
// function_exists() guard on a core helper, and this require above api/.
//
// services/Logger.php has no dependency on api/Data.php — it logs to file
// and error_log() only. The require order above is pinned by
// BootstrapLoadsNothingByGuessingTest::testEveryRequiredFileThatCallsTheLogHelperIsRequiredAfterTheLogger.
require_once NTDST_PATH . '/services/Logger.php';

require_once NTDST_PATH . '/core/Pages.php';
require_once NTDST_PATH . '/core/Theme.php';
require_once NTDST_PATH . '/core/ServiceInterface.php';
require_once NTDST_PATH . '/core/Bootstrap.php';

// Load shared support primitives (no dependencies; consumed by api/ + services/)
require_once NTDST_PATH . '/support/Cidr.php';
require_once NTDST_PATH . '/support/ClientIp.php';
require_once NTDST_PATH . '/support/RateLimiter.php';

// Load API layer (request flow)
require_once NTDST_PATH . '/api/FieldTypes.php'; // the field vocabulary; Data reads it
require_once NTDST_PATH . '/api/Data.php';
require_once NTDST_PATH . '/api/Response.php';

// Load admin UI (edit-screen rendering + persistence)
require_once NTDST_PATH . '/admin/MetaboxGenerator.php';
require_once NTDST_PATH . '/admin/RelationField.php';

// Load the REST surface
require_once NTDST_PATH . '/api/Rest.php';

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

