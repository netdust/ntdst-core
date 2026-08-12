<?php

declare(strict_types=1);

/**
 * NTDST Sector Registry
 *
 * Centralized sector configuration for the NTDST platform.
 * Defines sectors, their enable options, tier options, and auto-discovery paths.
 *
 * Sectors are independent platforms (gallery, artist, musician, theater) that can be
 * enabled/disabled independently. Each sector can have its own tier system.
 *
 * @package ntdst-core
 */

defined('ABSPATH') || exit;

final class NTDST_SectorRegistry
{
    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Registered sectors
     */
    private array $sectors = [];

    /**
     * Tier hierarchy (lower index = lower tier)
     */
    private const TIER_HIERARCHY = ['essential', 'professional', 'premium'];

    /**
     * Cache for enabled state checks
     */
    private array $enabledCache = [];

    /**
     * Cache for tier lookups
     */
    private array $tierCache = [];

    /**
     * Get singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton)
     */
    private function __construct()
    {
        $this->registerDefaultSectors();
        $this->registerAdminBarIndicator();
    }

    /**
     * Register admin bar indicator for active sectors
     */
    private function registerAdminBarIndicator(): void
    {
        add_action('admin_bar_menu', function ($wp_admin_bar) {
            if (!current_user_can('manage_options')) {
                return;
            }

            $enabled = $this->getEnabled();
            if (empty($enabled)) {
                return;
            }

            // Build sector info
            $sectorLabels = [];
            foreach ($enabled as $sector) {
                $config = $this->get($sector);
                $tier = $this->getTier($sector);
                $label = $config['label'] ?? ucfirst($sector);

                // Shorten label for admin bar
                $shortLabel = str_replace(' Platform', '', $label);

                if ($tier) {
                    $sectorLabels[] = "{$shortLabel} ({$tier})";
                } else {
                    $sectorLabels[] = $shortLabel;
                }
            }

            // Main node
            $wp_admin_bar->add_node([
                'id'     => 'ntdst-sectors',
                'title'  => '<span class="ab-icon dashicons dashicons-screenoptions" style="font: normal 20px/1 dashicons; margin-right: 5px;"></span>' . implode(' | ', $sectorLabels),
                'href'   => admin_url('options-general.php'),
                'parent' => 'top-secondary',
                'meta'   => [
                    'title' => 'Active NTDST Sectors',
                ],
            ]);

            // Subnodes for each sector
            foreach ($enabled as $sector) {
                $config = $this->get($sector);
                $tier = $this->getTier($sector);
                $label = $config['label'] ?? ucfirst($sector);

                $title = $label;
                if ($tier) {
                    $title .= " <em style='opacity: 0.7'>({$tier})</em>";
                }

                $wp_admin_bar->add_node([
                    'id'     => "ntdst-sector-{$sector}",
                    'title'  => $title,
                    'parent' => 'ntdst-sectors',
                    'href'   => admin_url("{$sector}-dashboard") ?: '#',
                ]);
            }
        }, 999);

        // Add some minimal styling
        add_action('admin_head', function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            echo '<style>
                #wpadminbar #wp-admin-bar-ntdst-sectors > .ab-item {
                    background: rgba(255,255,255,0.1);
                    border-radius: 3px;
                }
                #wpadminbar #wp-admin-bar-ntdst-sectors:hover > .ab-item {
                    background: rgba(255,255,255,0.2);
                }
            </style>';
        });

        add_action('wp_head', function () {
            if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
                return;
            }
            echo '<style>
                #wpadminbar #wp-admin-bar-ntdst-sectors > .ab-item {
                    background: rgba(255,255,255,0.1);
                    border-radius: 3px;
                }
                #wpadminbar #wp-admin-bar-ntdst-sectors:hover > .ab-item {
                    background: rgba(255,255,255,0.2);
                }
            </style>';
        });
    }

    /**
     * Register default sectors
     *
     * Hook: ntdst/sectors/register allows themes to add/modify sectors
     */
    private function registerDefaultSectors(): void
    {
        // Core sector definitions
        // Naming convention: sector key is singular, lowercase (gallery, artist, musician)
        $defaults = [
            'gallery' => [
                'label'           => 'Gallery Platform',
                'enable_option'   => 'ntdst_enable_gallery',
                'tier_option'     => 'ntdst_gallery_tier',
                'default_enabled' => true,
                'default_tier'    => 'essential',
                'tiers'           => ['essential', 'professional', 'premium'],
                'discovery_path'  => 'services/gallery',
            ],
            'artist' => [
                'label'           => 'Artist Platform',
                'enable_option'   => 'ntdst_enable_artist_platform',
                'tier_option'     => 'ntdst_artist_tier',
                'default_enabled' => false,
                'default_tier'    => 'essential',
                'tiers'           => ['essential', 'professional'],
                'discovery_path'  => 'services/artist',
            ],
            'musician' => [
                'label'           => 'Musician Platform',
                'enable_option'   => 'ntdst_enable_musician_platform',
                'tier_option'     => 'ntdst_musician_tier',
                'default_enabled' => false,
                'default_tier'    => 'essential',
                'tiers'           => ['essential', 'professional'],
                'discovery_path'  => 'services/musician',
            ],
            'theater' => [
                'label'           => 'Theater Platform',
                'enable_option'   => 'ntdst_enable_theater_platform',
                'tier_option'     => 'ntdst_theater_tier',
                'default_enabled' => false,
                'default_tier'    => 'essential',
                'tiers'           => ['essential', 'professional', 'premium'],
                'discovery_path'  => 'services/theater',
            ],
            'printshop' => [
                'label'           => 'Print Shop',
                'enable_option'   => 'ntdst_enable_print_shop',
                'tier_option'     => null, // No tiers - just enabled/disabled
                'default_enabled' => false,
                'default_tier'    => null,
                'tiers'           => [],
                'discovery_path'  => 'services/printshop',
            ],
        ];

        // Allow themes/plugins to modify sector definitions
        $this->sectors = apply_filters('ntdst/sectors/register', $defaults);
    }

    /**
     * Register a new sector
     *
     * @param string $key Sector key (e.g., 'gallery', 'artist')
     * @param array $config Sector configuration
     * @return self
     */
    public function register(string $key, array $config): self
    {
        $this->sectors[$key] = array_merge([
            'label'           => ucfirst($key) . ' Platform',
            'enable_option'   => "ntdst_enable_{$key}",
            'tier_option'     => "ntdst_{$key}_tier",
            'default_enabled' => false,
            'default_tier'    => 'essential',
            'tiers'           => ['essential', 'professional', 'premium'],
            'discovery_path'  => "services/{$key}",
        ], $config);

        // Clear caches when sector is registered
        $this->clearCache();

        return $this;
    }

    /**
     * Get all registered sectors
     *
     * @return array
     */
    public function all(): array
    {
        return $this->sectors;
    }

    /**
     * Get a specific sector config
     *
     * @param string $key Sector key
     * @return array|null
     */
    public function get(string $key): ?array
    {
        return $this->sectors[$key] ?? null;
    }

    /**
     * Check if a sector exists
     *
     * @param string $key Sector key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->sectors[$key]);
    }

    /**
     * Check if a sector is enabled
     *
     * @param string $sector Sector key
     * @return bool
     */
    public function isEnabled(string $sector): bool
    {
        if (isset($this->enabledCache[$sector])) {
            return $this->enabledCache[$sector];
        }

        $config = $this->get($sector);
        if (!$config) {
            $this->enabledCache[$sector] = false;
            return false;
        }

        // Cast to string for comparison (WordPress options can be '0' or '1')
        $value = get_option($config['enable_option'], $config['default_enabled'] ? '1' : '0');
        $enabled = $value === '1' || $value === 1 || $value === true;

        $this->enabledCache[$sector] = $enabled;
        return $enabled;
    }

    /**
     * Get current tier for a sector
     *
     * @param string $sector Sector key
     * @return string|null Null if sector has no tiers
     */
    public function getTier(string $sector): ?string
    {
        if (isset($this->tierCache[$sector])) {
            return $this->tierCache[$sector];
        }

        $config = $this->get($sector);
        if (!$config || empty($config['tier_option'])) {
            $this->tierCache[$sector] = null;
            return null;
        }

        $tier = get_option($config['tier_option'], $config['default_tier']);
        $this->tierCache[$sector] = $tier;
        return $tier;
    }

    /**
     * Check if sector meets a minimum tier requirement.
     *
     * Asymmetric handling of unknown tiers (intentional):
     *  - Unknown current tier → treated as lowest (degrade gracefully on
     *    misconfigured options so the site keeps running).
     *  - Unknown required tier → returns false (fail-safe; an unknown
     *    requirement can't be guaranteed to be met).
     *
     * @param string $sector Sector key
     * @param string $minTier Minimum tier required
     * @return bool
     */
    public function meetsTier(string $sector, string $minTier): bool
    {
        $currentTier = $this->getTier($sector);

        // No tiers configured = always meets requirement (sector just enabled/disabled)
        if ($currentTier === null) {
            return true;
        }

        $currentIndex = array_search($currentTier, self::TIER_HIERARCHY);
        $requiredIndex = array_search($minTier, self::TIER_HIERARCHY);

        // Unknown tiers: current defaults to lowest, required defaults to not meeting
        if ($currentIndex === false) {
            $currentIndex = 0;
        }
        if ($requiredIndex === false) {
            return false;
        }

        return $currentIndex >= $requiredIndex;
    }

    /**
     * Check if sector has professional tier or higher
     *
     * @param string $sector Sector key
     * @return bool
     */
    public function isProfessionalOrHigher(string $sector): bool
    {
        return $this->meetsTier($sector, 'professional');
    }

    /**
     * Check if sector has premium tier
     *
     * @param string $sector Sector key
     * @return bool
     */
    public function isPremium(string $sector): bool
    {
        return $this->getTier($sector) === 'premium';
    }

    /**
     * Get all enabled sectors
     *
     * @return array Array of enabled sector keys
     */
    public function getEnabled(): array
    {
        return array_filter(
            array_keys($this->sectors),
            fn($key) => $this->isEnabled($key),
        );
    }

    /**
     * Get discovery paths for enabled sectors
     *
     * @param string $basePath Base theme/plugin path
     * @return array Map of sector => full path
     */
    public function getDiscoveryPaths(string $basePath): array
    {
        $paths = [];

        foreach ($this->getEnabled() as $sector) {
            $config = $this->get($sector);
            if ($config && !empty($config['discovery_path'])) {
                $fullPath = rtrim($basePath, '/') . '/' . ltrim($config['discovery_path'], '/');
                if (is_dir($fullPath)) {
                    $paths[$sector] = $fullPath;
                }
            }
        }

        return $paths;
    }

    /**
     * Check if service sector requirements are met
     *
     * Supports:
     * - null            = always load (backwards compatible, no requirements)
     * - 'all' or 'core' = always load (explicit core service)
     * - ['gallery' => 'essential'] = gallery enabled with at least essential tier
     * - ['gallery' => 'professional', 'artist' => 'essential'] = gallery pro OR artist essential
     *
     * Logic: OR between sectors (any match = load), AND between sector + tier
     *
     * @param array|string|null $requirements Service sector requirements from metadata
     * @return bool
     */
    public function checkRequirements(array|string|null $requirements): bool
    {
        // No requirements = always load (core/universal service, backwards compatible)
        if ($requirements === null) {
            return true;
        }

        // 'all' or 'core' = always load
        if ($requirements === 'all' || $requirements === 'core') {
            return true;
        }

        // Must be an array at this point
        if (!is_array($requirements)) {
            return true;
        }

        // Empty array = always load
        if (empty($requirements)) {
            return true;
        }

        // Check each sector requirement (OR logic - any match = load)
        foreach ($requirements as $sector => $minTier) {
            // Check if sector is enabled
            if (!$this->isEnabled($sector)) {
                continue;
            }

            // Check tier requirement
            if ($this->meetsTier($sector, $minTier)) {
                return true;
            }
        }

        // No requirements met
        return false;
    }

    /**
     * Clear internal caches
     *
     * Called when options change or sectors are registered
     */
    public function clearCache(): void
    {
        $this->enabledCache = [];
        $this->tierCache = [];
    }

    /**
     * Get available tiers for a sector
     *
     * @param string $sector Sector key
     * @return array
     */
    public function getTiers(string $sector): array
    {
        $config = $this->get($sector);
        return $config['tiers'] ?? [];
    }

    /**
     * Debug: Get current state of all sectors
     *
     * @return array
     */
    public function getState(): array
    {
        $state = [];
        foreach ($this->sectors as $key => $config) {
            $state[$key] = [
                'enabled' => $this->isEnabled($key),
                'tier'    => $this->getTier($key),
                'tiers'   => $config['tiers'],
            ];
        }
        return $state;
    }
}

/**
 * Global helper function to get SectorRegistry instance
 *
 * @return NTDST_SectorRegistry
 */
if (!function_exists('ntdst_sectors')) {
    function ntdst_sectors(): NTDST_SectorRegistry
    {
        return NTDST_SectorRegistry::instance();
    }
}
