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
 *  - module()    -> retired outright, not relocated. See the mixin rules below.
 *
 * Those owners stay REACHABLE and that is deliberate — do not "fix" it:
 * wireMixins() proxies `data`/`pages`/`response`/`log`/`mail`, so
 * `$theme->data()->register(...)` is byte-for-byte what the deleted register()
 * did, and ThemeSubjectNarrowingTest pins that proxy. The rule governs what
 * earns a NAMED METHOD here (a second surface to keep in sync), not what is
 * reachable at one hop through an owner named out loud.
 *
 * Mixin rules:
 *  - mixin() supersedes the retired module() DSL: `$theme->mixin('slug', $svc)`
 *    then `$theme->slug()` replaces the old `$theme->module('slug')->get()`, and
 *    a service's config/enable filters are registered directly.
 *  - Method-injection mixins are dispatched via __call(), so they cannot
 *    override methods that already exist on NTDST_Theme. To override a
 *    built-in method, extend the class instead.
 *
 * The accepted cost — four forwarders. templatePath(), single(), page() and
 * archive() forward onto NTDST_Template_Loader and NTDST_Pages. They pass the
 * rule (their subject is the theme; NTDST_Pages and the loader are only the mechanism)
 * so they stay — but they are a SECOND public surface that has to track its
 * owner's signature, and that tax has already been paid twice: S7 had to repair
 * apiAction() after it drifted to literal-cap-only, and S8 had to update
 * taxonomy() in the same change. Whenever NTDST_Pages or NTDST_Template_Loader
 * changes shape, check these four — they can go stale without anything here
 * failing to load.
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
    private array $mixins = [];

    public function __construct(array $config = [])
    {
        // configuration
        $this->config = $this->validate_config($config);

        // Register self as singleton in DI container
        ntdst_set(self::class, fn() => $this);

        // Wire up service mixins immediately
        $this->wireMixins();

        // Initialize theme
        $this->init();
    }

    /**
     * Wire up NTDST service instances as mixins.
     * Called automatically in constructor.
     *
     * Each helper is guarded so missing optional services (e.g. ntdst_mail
     * when the mail plugin isn't installed) don't break theme construction.
     */
    private function wireMixins(): void
    {
        if (function_exists('ntdst_data')) {
            $this->mixin('data', ntdst_data());
        }
        if (function_exists('ntdst_pages')) {
            $this->mixin('pages', ntdst_pages());
        }
        if (function_exists('ntdst_response')) {
            $this->mixin('response', ntdst_response());
        }
        if (function_exists('ntdst_log')) {
            $this->mixin('log', ntdst_log());
        }
        if (function_exists('ntdst_mail')) {
            $this->mixin('mail', ntdst_mail());
        }
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
     * Conditional configuration based on context
     *
     * @param callable $condition Function that returns boolean
     * @param callable $callback  Function to execute if condition is true
     * @return $this
     *
     * Example:
     *   $theme->when(fn() => is_front_page(), function($theme) {
     *       $theme->filter('body_class', fn($c) => [...$c, 'is-front']);
     *   });
     */
    public function when(callable $condition, callable $callback): self
    {
        if ($condition()) {
            $callback($this);
        }
        return $this;
    }

    /**
     * Add custom template path
     *
     * @param string $path Template directory path
     * @return $this
     *
     * Example:
     *   $theme->templatePath(__DIR__ . '/custom-templates');
     */
    public function templatePath(string $path): self
    {
        // One live registry (S5): the loader reads it on every resolution, so
        // a path registered here is picked up without a cache-clear workaround.
        NTDST_Template_Loader::addPath($path);
        return $this;
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
        $this->pages()->single($post_type, $callback);
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
        $this->pages()->page($slug, $callback);
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
        $this->pages()->archive($post_type, $callback);
        return $this;
    }

    /**
     * Mixin: Extend theme with additional methods or instance proxies
     *
     * Two patterns supported:
     * 1. Instance proxying: $theme->mixin('mail', ntdst_mail())
     *    Usage: $theme->mail()->to(...)
     *
     * 2. Method injection: $theme->mixin(new HelperClass())
     *    Copies all public methods from HelperClass to $theme
     *
     * @param string|object $nameOrInstance Mixin name (for instance proxy) or object (for method injection)
     * @param object|null $instance Instance to proxy (only for pattern 1)
     * @return $this
     *
     * Example (instance proxying):
     *   $theme->mixin('mail', ntdst_mail());
     *   $theme->mail()->to('user@example.com')->send();
     *
     * Example (method injection):
     *   class ThemeHelpers {
     *       public function formatDate($date) { return date('Y-m-d', strtotime($date)); }
     *       public function truncate($text, $length) { return substr($text, 0, $length) . '...'; }
     *   }
     *   $theme->mixin(new ThemeHelpers());
     *   $theme->formatDate('2024-01-01');  // Direct method call
     */
    public function mixin(string|object $nameOrInstance, ?object $instance = null): self
    {
        // Pattern 1: Instance proxying (named)
        if (is_string($nameOrInstance) && $instance !== null) {
            $name = sanitize_key($nameOrInstance);
            $this->mixins[$name] = $instance;
            return $this;
        }

        // Pattern 2: Method injection (copy methods from object)
        if (is_object($nameOrInstance) && $instance === null) {
            $class = new ReflectionClass($nameOrInstance);
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip magic methods and constructors
                if (str_starts_with($method->name, '__')) {
                    continue;
                }

                // Store as callable bound to the original instance
                $methodName = $method->name;
                $this->mixins[$methodName] = function (...$args) use ($nameOrInstance, $methodName) {
                    return $nameOrInstance->$methodName(...$args);
                };
            }
            return $this;
        }

        // Invalid usage — fail loud rather than emit a warning that gets
        // swallowed outside of WP_DEBUG_LOG.
        throw new InvalidArgumentException(
            'Invalid mixin usage. Use either mixin($name, $instance) or mixin($object)',
        );
    }

    /**
     * Magic method to handle dynamic calls to mixed-in methods/instances
     *
     * @param string $name Method name
     * @param array $arguments Method arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!isset($this->mixins[$name])) {
            throw new BadMethodCallException("Method or mixin '{$name}' does not exist on " . static::class);
        }

        $mixin = $this->mixins[$name];

        // If it's a callable (method injection), execute it
        if (is_callable($mixin)) {
            return $mixin(...$arguments);
        }

        // If it's an object (instance proxy), return it for chaining
        if (is_object($mixin)) {
            return $mixin;
        }

        throw new BadMethodCallException("Mixin '{$name}' is not callable or an object");
    }
}
