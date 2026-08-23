<?php

declare(strict_types=1);

/**
 * Service Metadata Interface
 *
 * Implement this interface to DECLARE your service to NTDST_Bootstrap.
 *
 * The declaration is `static`, and Bootstrap reads it exactly once per boot: it
 * is what the class says about itself, not a question core asks twice. It names
 * the service (a declared `name` pins the slug of its one config filter,
 * `ntdst/service/{slug}/config`), says whether it may boot outside the admin,
 * whether it is enabled at all, and when in the boot order it comes up.
 *
 * It does not make the service FOUND: core discovers nothing and scans no
 * directory. A service registers because a consumer's config lists its class in
 * `services.core`, `services.admin` or `services.conditional` — and `enabled`
 * below, with that conditional's condition, is the whole of "off".
 *
 * @package ntdst-core
 */

defined('ABSPATH') || exit;

interface NTDST_Service_Meta
{
    /**
     * Get service metadata
     *
     * @return array [
     *   'name' => 'Service Name',
     *   'description' => 'What this service does',
     *   'admin_only' => false,  // Only load in admin context
     *   'enabled' => true,       // Default enabled state
     *   'priority' => 10,        // Boot priority (lower = earlier)
     * ]
     */
    public static function metadata(): array;
}
