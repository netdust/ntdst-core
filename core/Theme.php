<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

// ========================================
// THEME BOOTSTRAP CLASS
// ========================================

/**
 * The theme's wiring surface — a facade over exactly ONE subject: the theme's
 * own wiring (its config, assets, hooks, template paths, which template renders
 * which type, and the helpers templates call). The fluent, chainable style is
 * deliberate — composing that wiring in one readable chain is the value this
 * facade provides, and the only reason a facade exists here at all.
 *
 * THE GOVERNING RULE — apply it before adding anything to this class:
 *
 *     A method belongs on NTDST_Theme iff its subject dies when you switch themes.
 *
 * Name the thing the method is *about*, then ask whether that thing survives a
 * `switch_theme()`. Theme hook wiring, template paths, template selection and
 * template helpers do not survive — they ARE the theme, so they belong here. A
 * post type, a taxonomy, an api_data action, a service: all of those survive.
 * Each has its own owner, and a door onto them from here is not a convenience,
 * it is a second door onto someone else's concern.
 *
 * The rule agrees with the dependency graph, which is the sign it is real rather
 * than merely tidy: services register CPTs and api_data actions and cannot reach
 * Theme (they hold no reference to it), while nothing but a theme registers theme
 * hooks. Both tests fail for the same members — and those members are gone:
 *  - register()  -> `ntdst_data()->register(...)` — a CPT outlives the theme.
 *  - taxonomy()  -> the `taxonomies` config key, or
 *                   `NTDST_Data_Manager::registerTaxonomy(...)`.
 *  - apiAction() -> `ntdst_actions()->register(...)`, which is also
 *                   where the api_data capability floor lives.
 *  - module()    -> retired outright, not relocated.
 *
 * Those owners are reached BY NAME, at the call site (FR-8, 5.0.0). A theme
 * that wants the Data layer writes `ntdst_data()->register(...)`; it does not
 * ask the theme for it. This class used to proxy `data`/`pages`/`response`/
 * `log`/`mail` through a mixin() registry dispatched by __call(), and that
 * surface could not be READ: nothing in the file said which names resolved, so
 * the only way to learn the theme's API was to run it. The rule above governs
 * what earns a NAMED METHOD here; the answer for another layer's front door is
 * now "nothing", because the global helper already is one.
 *
 * Two more members went with the mechanism:
 *  - when()         -> an `if` statement with a fluent return. PHP has an `if`.
 *  - templatePath() -> `NTDST_Template_Loader::addPath($path)`, which is the
 *                      same call one hop shorter and names its owner.
 *
 * The accepted cost — three forwarders. single(), page() and archive() forward
 * onto NTDST_Pages. They pass the rule (their subject is the theme; NTDST_Pages
 * is only the mechanism) so they stay — but they are a SECOND public surface
 * that has to track its owner's signature, and that tax has already been paid
 * twice: S7 had to repair apiAction() after it drifted to literal-cap-only, and
 * S8 had to update taxonomy() in the same change. Whenever NTDST_Pages changes
 * shape, check these three — they can go stale without anything here failing to
 * load. (core-shape FR-12 deletes all three; until it lands they call
 * ntdst_pages() by name, because the proxy they used to go through is gone.)
 *
 * Hook + filter naming conventions:
 *  - Actions: `ntdst_*` for new code. Theme registers no `netdust_*` hook of
 *    its own; service config/enable filters are Bootstrap's API, not Theme's.
 *
 * I18n note: register_nav_menus receives translated labels via __($desc).
 * Because the descriptions come from a variable, xgettext/wp i18n make-pot
 * cannot extract them. Put the literal strings somewhere static for
 * translators if you need full coverage.
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

        // Excerpt settings
        $this->filter('excerpt_length', function () {
            return (int) $this->config['excerpt']['length'];
        }, 999);

        $this->filter('excerpt_more', function () {
            return sprintf($this->config['excerpt']['more'], esc_url(get_permalink()));
        });

        // Remove WordPress version
        $this->filter('the_generator', function () {
            return '';
        });
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
            'excerpt' => ['length' => 55, 'more' => ''],
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
     * filter vocabulary is namespaced (`ntdst/api/public_actions`) and a
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

    /**
     * Enqueue a stylesheet on `wp_enqueue_scripts` — a thin, explicit
     * pass-through to wp_enqueue_style(), deferred to the hook. Arguments
     * reach WordPress verbatim; compute versions and conditions at the call
     * site. Call at theme load, before `wp_enqueue_scripts` fires.
     *
     * These two helpers replaced the config-driven asset loader (the
     * `assets` config key, its `ntdst_theme_assets` filter and the attrs →
     * loader-tag rewriting): ~120 lines of machinery with zero consumers
     * across the fleet, running on every request to iterate empty arrays.
     * An asset is one explicit line here instead.
     *
     * `$priority` orders this enqueue among `wp_enqueue_scripts` listeners —
     * a child theme overriding its parent's CSS needs a late one (YOOtheme
     * children use 20 to land after the parent's own enqueues).
     *
     * @param string[]         $deps
     * @param string|bool|null $ver  false = WP version, null = no version
     */
    public function style(
        string $handle,
        string $src,
        array $deps = [],
        string|bool|null $ver = false,
        string $media = 'all',
        int $priority = 10,
    ): self {
        return $this->on('wp_enqueue_scripts', static function () use ($handle, $src, $deps, $ver, $media): void {
            wp_enqueue_style($handle, $src, $deps, $ver, $media);
        }, $priority);
    }

    /**
     * Enqueue a script on `wp_enqueue_scripts` — same contract as style().
     *
     * @param string[]         $deps
     * @param string|bool|null $ver  false = WP version, null = no version
     */
    public function script(
        string $handle,
        string $src,
        array $deps = [],
        string|bool|null $ver = false,
        bool $in_footer = true,
        int $priority = 10,
    ): self {
        return $this->on('wp_enqueue_scripts', static function () use ($handle, $src, $deps, $ver, $in_footer): void {
            wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
        }, $priority);
    }


    /**
     * Register single template handler
     *
     * The post type is `?string` — NOT `string|callable`. This signature tracks
     * its owner NTDST_Pages::single() exactly; a wider one here would advertise
     * a callable-first form the owner refuses under strict_types, raising a
     * TypeError from a class the caller never named (Cluster B review, F1).
     *
     * @param string|null   $post_type Post type, or null for every single view
     * @param callable|null $callback  Handler function
     * @return $this
     *
     * Example:
     *   $theme->single('project', function($post) {
     *       return ntdst_response()->with('project', $post)->template('project/detail');
     *   });
     */
    public function single(?string $post_type = null, ?callable $callback = null): self
    {
        ntdst_pages()->single($post_type, $callback);
        return $this;
    }

    /**
     * Register page template handler
     *
     * @param string|callable $slug Page slug or callback
     * @param callable|null $callback Handler function
     * @return $this
     *
     * Example:
     *   $theme->page('about', function($post) {
     *       return get_template_directory() . '/templates/about.php';
     *   });
     */
    public function page(string|callable $slug, ?callable $callback = null): self
    {
        ntdst_pages()->page($slug, $callback);
        return $this;
    }

    /**
     * Register archive template handler
     *
     * The post type is `?string` — NOT `string|callable`, for the same reason
     * `single()` above is not: this signature tracks its owner
     * NTDST_Pages::archive() exactly (Cluster B review, F1).
     *
     * @param string|null   $post_type Post type, or null for every archive view
     * @param callable|null $callback  Handler function
     * @return $this
     *
     * Example:
     *   $theme->archive('project', function() {
     *       $projects = ntdst_data()->get('project')->all();
     *       return ntdst_response()->with('projects', $projects)->template('project/archive');
     *   });
     */
    public function archive(?string $post_type = null, ?callable $callback = null): self
    {
        ntdst_pages()->archive($post_type, $callback);
        return $this;
    }

}
