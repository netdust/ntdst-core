<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

// ========================================
// THEME BOOTSTRAP CLASS
// ========================================

/**
 * The theme's wiring surface — a facade over exactly ONE subject: the theme's
 * own wiring (config, assets, hooks, template paths, template selection, and
 * the helpers templates call). The chainable style is why a facade exists here.
 *
 * THE GOVERNING RULE — apply it before adding anything to this class:
 *
 *     A method belongs on NTDST_Theme iff its subject dies when you switch themes.
 *
 * Until 5.0.0 the class broke that rule: it proxied `data`/`pages`/`response`/
 * `log`/`mail` through a mixin() registry dispatched by __call(), opening
 * another layer's front door under names no reader could see. That mechanism is
 * gone, with register(), taxonomy(), module() and templatePath().
 * Callers name the owning layer themselves now — `ntdst_data()`,
 * `ntdst_pages()`, `ntdst_rest()`, `NTDST_Template_Loader::addPath()`
 * (FR-8, 5.0.0; the reasoning lives in the core-shape spec).
 *
 * 5.0.0 applies the same rule twice more (FR-12). single(), page() and
 * archive() forwarded onto NTDST_Pages — a second public surface that had to
 * track its owner's signature — and a theme writes `ntdst_pages()->single(...)`
 * instead. style() and script() were two `wp_enqueue_scripts` closures with no
 * decision in them; `$theme->on('wp_enqueue_scripts', fn() => wp_enqueue_style(...))`
 * says the same thing in WordPress's own words.
 *
 * setup_theme() wires what the CONFIG asks for and nothing else. The
 * `the_generator` filter left with the same rule — hiding the WordPress
 * version is a site-wide head decision, not theme wiring — and the excerpt
 * filters mount only when the config sets a length or a more-string. They used
 * to mount unconditionally off core's own defaults, so every site that
 * constructed a theme silently overrode WordPress's excerpt length with 55.
 *
 * Hooks: `ntdst_*` actions for new code; Theme registers no `netdust_*` hook.
 *
 * I18n: register_nav_menus gets translated labels via __($desc). They come from
 * a variable, so `wp i18n make-pot` cannot extract them; put the literals
 * somewhere static if you need full coverage.
 */
class NTDST_Theme
{
    private array $config;

    public function __construct(array $config = [])
    {
        // configuration
        $this->config = $this->validate_config($config);

        // Register self as singleton in DI container
        ntdst_set(self::class, fn() => $this);

        // Initialize theme
        $this->init();
    }

    private function init(): void
    {
        // Theme setup
        add_action('after_setup_theme', [$this, 'setup_theme']);
    }

    public function setup_theme(): void
    {
        // Load text domain for translations
        if (!empty($this->config['textdomain'])) {
            load_theme_textdomain(sanitize_key($this->config['textdomain']), get_template_directory() . '/languages');
        }

        // Set content width
        if (!isset($GLOBALS['content_width']) && !empty($this->config['content_width'])) {
            $GLOBALS['content_width'] = (int) $this->config['content_width'];
        }

        // Theme support
        foreach ($this->config['theme_support'] as $feature => $args) {
            if (is_bool($args)) {
                add_theme_support($feature);
            } else {
                add_theme_support($feature, $args);
            }
        }

        // Image sizes
        foreach ($this->config['image_sizes'] as $name => $settings) {
            add_image_size(sanitize_key($name), (int) $settings[0], (int) $settings[1], (bool) $settings[2]);
        }

        // Make image sizes selectable
        $this->filter('image_size_names_choose', function ($sizes) {
            $custom_sizes = [];
            foreach ($this->config['image_sizes'] as $name => $settings) {
                $custom_sizes[sanitize_key($name)] = sanitize_text_field($settings[3] ?? $name);
            }
            return array_merge($sizes, $custom_sizes);
        });

        // Register menus
        register_nav_menus(array_map(function ($desc) {
            return __($desc, $this->config['textdomain']);
        }, $this->config['menus']));

        // Register sidebars
        foreach ($this->config['sidebars'] as $sidebar) {
            register_sidebar([
                'name' => $sidebar['name'] ?? '',
                'id' => sanitize_key($sidebar['id'] ?? ''),
                'description' => $sidebar['description'] ?? '',
                'before_widget' => $sidebar['before_widget'] ?? '<div id="%1$s" class="widget %2$s">',
                'after_widget' => $sidebar['after_widget'] ?? '</div>',
                'before_title' => $sidebar['before_title'] ?? '<h2 class="widget-title">',
                'after_title' => $sidebar['after_title'] ?? '</h2>',
            ]);
        }

        // Excerpt settings — mounted only for the value the config actually
        // set. A theme that never mentions excerpts leaves WordPress's own
        // length and more-string alone (FR-12).
        if (isset($this->config['excerpt']['length'])) {
            $this->filter('excerpt_length', function () {
                return (int) $this->config['excerpt']['length'];
            }, 999);
        }

        if (isset($this->config['excerpt']['more'])) {
            $this->filter('excerpt_more', function () {
                return sprintf($this->config['excerpt']['more'], esc_url(get_permalink()));
            });
        }
    }

    /**
     * Validate configuration array
     *
     * @param array $config
     * @return array
     */
    private function validate_config(array $config): array
    {
        $defaults = [
            'textdomain' => 'ntdst_theme',
            'content_width' => 1200,
            'theme_support' => [],
            'image_sizes' => [],
            'menus' => [],
            'sidebars' => [],
        ];

        // Force expected shapes for keys we iterate later — fail upfront
        // instead of crashing inside a foreach with a confusing message.
        foreach (['theme_support', 'image_sizes', 'menus', 'sidebars'] as $arrayKey) {
            if (isset($config[$arrayKey]) && !is_array($config[$arrayKey])) {
                throw new InvalidArgumentException(
                    "NTDST_Theme config['{$arrayKey}'] must be an array",
                );
            }
        }

        return array_merge($defaults, $config);
    }


    /**
     * Get configuration settings
     *
     * @return array
     */
    public function get_config(): array
    {
        return $this->config;
    }

    /**
     * Add WordPress action with fluent API
     *
     * The hook name is passed through VERBATIM — this wrapper is transparent
     * (FR-11). WordPress does not sanitise hook names: they are keys in
     * `$wp_filter`, not output, not a query, not a capability. Normalising one
     * would silently register `myPlugin_Action` as `myplugin_action` and strip
     * the `/` out of a namespaced `ntdst/...` name, so the callback would never
     * fire under the name the caller wrote.
     *
     * @param string   $action   Action name (used exactly as given)
     * @param callable $callback Callback function
     * @param int      $priority Priority (default: 10)
     * @param int      $args     Number of arguments (default: 1)
     * @return $this
     *
     * Example:
     *   $theme->on('wp_footer', function() {
     *       echo '<div>Footer content</div>';
     *   });
     */
    public function on(string $action, callable $callback, int $priority = 10, int $args = 1): self
    {
        add_action($action, $callback, $priority, $args);
        return $this;
    }

    /**
     * Add WordPress filter with fluent API
     *
     * The filter name is passed through VERBATIM, for the same reason `on()`
     * does (FR-11) — and it matters more here, because this framework's own
     * filter vocabulary is namespaced (`ntdst/service/{slug}/config`) and a
     * normaliser would strip the separators straight out of it.
     *
     * @param string   $filter   Filter name (used exactly as given)
     * @param callable $callback Callback function
     * @param int      $priority Priority (default: 10)
     * @param int      $args     Number of arguments (default: 1)
     * @return $this
     *
     * Example:
     *   $theme->filter('body_class', function($classes) {
     *       $classes[] = 'custom-class';
     *       return $classes;
     *   });
     */
    public function filter(string $filter, callable $callback, int $priority = 10, int $args = 1): self
    {
        add_filter($filter, $callback, $priority, $args);
        return $this;
    }
}
