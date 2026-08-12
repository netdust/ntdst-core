<?php

declare(strict_types=1);

/**
 * Service Metadata Interface
 *
 * Implement this interface to provide metadata about your service
 * Enables service discovery, enable/disable controls, and priority loading
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
