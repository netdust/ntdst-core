<?php

declare(strict_types=1);

/**
 * NTDST Pages — front-end URL routing and WordPress template integration.
 *
 * This routes PAGES: a URL pattern resolves to a callable that renders a
 * template for a human. It is NOT the HTTP API surface.
 *
 * Its verb methods are deliberately absent. `get()`/`post()` used to live here
 * and meant "a page pattern matched on this request method" — which collides
 * with ntdst_rest(), where get() means an HTTP GET resource route. Since
 * v3.0.0 an HTTP verb in this package means a REST route and nothing else, and
 * a page route declares its method as an argument to path().
 *
 * Pick the right service:
 *   page / template   → ntdst_pages()
 *   command (ajax)    → ntdst_actions()->register()
 *   resource route    → ntdst_rest()
 *
 * Usage:
 *
 * // Simple route (GET by default)
 * ntdst_pages()->path('/projects/:slug', function($params) {
 *     $project = get_post($params['slug']);
 *     return ntdst_response()->with('project', $project)->template('project/single');
 * });
 *
 * // A page that only answers POST
 * ntdst_pages()->path('/projects/submit', $handler, 'POST');
 *
 * // With specific template type
 * ntdst_pages()->single('project', function($post) {
 *     return ntdst_response()->with('project', $post)->template('project/detail');
 * });
 *
 * // With conditions
 * ntdst_pages()->when(fn() => is_singular('project'), function($post) {
 *     // Custom handling
 * });
 */

defined('ABSPATH') || exit;

class NTDST_Pages
{
    protected array $routes = [];
    protected array $template_hooks = [];

    public function __construct()
    {
        add_filter('redirect_canonical', [$this, 'preventRedirectForRoutes'], 10, 2);
        add_filter('template_include', [$this, 'handleTemplateInclude'], 999);
    }

    /**
     * Prevent WordPress from redirecting URLs that match our routes
     */
    public function preventRedirectForRoutes(string|false $redirect_url, ?string $requested_url = null): string|false
    {
        // Check both current URL and redirect target. Guards: $_SERVER keys
        // can be missing under CLI/test SAPIs, and parse_url returns null on
        // malformed URLs.
        $urls_to_check = [
            trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/'),
        ];

        if ($redirect_url) {
            $urls_to_check[] = trim(parse_url($redirect_url, PHP_URL_PATH) ?? '', '/');
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            foreach ($urls_to_check as $url) {
                if (preg_match($route['regex'], $url)) {
                    return false;
                }
            }
        }

        return $redirect_url;
    }

    /**
     * Register a page route.
     *
     * The request method is an ARGUMENT, not the method name — HTTP verbs are
     * ntdst_rest()'s vocabulary, and a page route matched on POST is still a
     * page, not a resource.
     *
     * The callback receives (array $params, string $template) — $params holds
     * the named URL placeholders. Query-string parameters are NOT passed;
     * callbacks must read $_GET directly when needed.
     *
     * Return contract (resolved by resolveRouteResult(), shared with the
     * template()/when() paths):
     *  - string (existing file path) → success: the 404 WordPress pre-set is
     *    cleared, a 200 committed, and the path used as the resolved template
     *  - NTDST_Response, 2xx → success: 404 cleared, then rendered (exits)
     *  - NTDST_Response, >=400 → REFUSE: WordPress's not-found state is left
     *    intact and its own 404 template renders — the route says "no" through
     *    the output class, with no status_header() hand-rolling in the callback
     *  - null / true → the callback handled its own output (status included);
     *    the request exits. A callback that STREAMS and returns null must set
     *    its own status before it echoes — the 404 commit is deferred, not
     *    pre-sent, so nothing sets 200 on its behalf beforehand
     *  - false / anything else → no match: the 404 is left intact, fall
     *    through to the next matching route
     *
     * @param string $pattern URL pattern (/path/:param/:id)
     * @param callable $callback Handler function
     * @param string $method HTTP method (GET, POST, etc.)
     */
    public function path(string $pattern, callable $callback, string $method = 'GET'): self
    {
        $regex = $this->compilePattern($pattern);

        $this->routes[] = [
            'pattern' => $pattern,
            'regex' => $regex,
            'callback' => $callback,
            'method' => strtoupper($method),
        ];

        return $this;
    }

    /**
     * Hook into specific WordPress template type
     * Smart wrapper around {$type}_template filters
     *
     * @param string $type Template type (single, page, archive, etc.)
     * @param callable $callback Handler receives $post or $template
     * @param string|null $post_type Optional post type to filter
     */
    public function template(string $type, callable $callback, ?string $post_type = null): self
    {
        $hook = $type . '_template';

        // Store for smart filtering
        $this->template_hooks[] = [
            'type' => $type,
            'hook' => $hook,
            'callback' => $callback,
            'post_type' => $post_type,
        ];

        add_filter($hook, function ($template) use ($callback, $post_type) {
            // Filter by post type if specified
            if ($post_type && get_post_type() !== $post_type) {
                return $template;
            }

            global $post;
            $result = $callback($post, $template);

            // If string returned, use as template path
            if (is_string($result)) {
                return $result;
            }

            // If Response object, render it
            if ($result instanceof NTDST_Response) {
                $template_name = $result->getTemplate();
                if ($template_name) {
                    $result->render($template_name);
                }
                exit;
            }

            return $template;
        }, 10, 1);

        return $this;
    }

    /**
     * Shorthand for single template
     */
    public function single(?string $post_type = null, ?callable $callback = null): self
    {
        if ($callback === null && is_callable($post_type)) {
            $callback = $post_type;
            $post_type = null;
        }

        return $this->template('single', $callback, $post_type);
    }

    /**
     * Shorthand for page template
     */
    public function page(string|callable $slug_or_callback, ?callable $callback = null): self
    {
        // page('about', fn() => ...) or page(fn() => ...)
        if (is_callable($slug_or_callback)) {
            return $this->template('page', $slug_or_callback);
        }

        return $this->template('page', function ($post) use ($slug_or_callback, $callback) {
            if ($post->post_name === $slug_or_callback) {
                return $callback($post);
            }
        });
    }

    /**
     * Shorthand for archive template
     */
    public function archive(?string $post_type = null, ?callable $callback = null): self
    {
        if ($callback === null && is_callable($post_type)) {
            $callback = $post_type;
            $post_type = null;
        }

        return $this->template('archive', $callback, $post_type);
    }

    /**
     * Conditional route - executes when condition is true.
     *
     * Note: every call to when() registers a new template_include filter.
     * Call it once per condition; do not invoke in a loop.
     *
     * Callback receives (?WP_Post $post, string $template). See register()
     * for the return-value contract.
     */
    public function when(callable $condition, callable $callback): self
    {
        add_filter('template_include', function ($template) use ($condition, $callback) {
            if (!$condition()) {
                return $template;
            }

            global $post;
            $result = $callback($post, $template);

            // Return string template path
            if (is_string($result)) {
                return $result;
            }

            // Handle Response object
            if ($result instanceof NTDST_Response) {
                $template_name = $result->getTemplate();
                if ($template_name) {
                    $result->render($template_name);
                }
                exit;
            }

            return $template;
        }, 10);

        return $this;
    }

    /**
     * Handle template_include filter
     * Matches URL patterns and executes callbacks
     */
    public function handleTemplateInclude(string $template): string
    {
        // $_SERVER keys can be missing under CLI/test SAPIs; default safely.
        $url = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        foreach ($this->routes as $route) {
            // Check method
            if ($route['method'] !== $method) {
                continue;
            }

            // Try to match pattern
            if (preg_match($route['regex'], $url, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // The 404 commit is DEFERRED to resolveRouteResult(): WordPress
                // marked this request 404 before routing, and that 404 is only
                // cleared once the callback's RESULT proves the route handled
                // the request. That is the whole seam — a callback can now
                // refuse (return a >=400 Response) and WordPress renders its
                // own not-found template, instead of the route having to
                // hand-roll status_header(404) back after a premature 200.
                $result = call_user_func($route['callback'], $params, $template);

                $resolved = $this->resolveRouteResult($result, $template);
                if ($resolved === false) {
                    continue;
                }
                if ($resolved === null) {
                    exit;
                }

                return $resolved;
            }
        }

        return $template;
    }

    /**
     * Turn a route callback's return value into a routing decision.
     *
     * Path-agnostic on purpose: the input is only (callback-result,
     * original-template) and the output is a pure decision, so all three
     * dispatch mechanisms — this regex path plus the template()/when()
     * hierarchy filters — can share ONE implementation. (template()/when()
     * still carry their own inline block today; wiring them to call this is a
     * separate follow-up. This handler is already shaped for it.)
     *
     * Returns:
     *  - string → render this template path (caller returns it to WordPress)
     *  - null   → handled / exit (caller exits)
     *  - false  → no match, pass through to the next candidate
     *
     * It OWNS the status/404 side effects so every caller gets them identically
     * (see the register() docblock for the full contract). The Response arm
     * honours getStatus(): a 2xx renders, a >=400 refuses — leaving is_404
     * intact and handing back WordPress's own not-found template — regardless
     * of whether the Response names a template.
     */
    protected function resolveRouteResult(mixed $result, string $template): string|false|null
    {
        if ($result instanceof NTDST_Response) {
            if ($result->getStatus() >= 400) {
                // Refuse: keep the 404 WordPress already set, honour the
                // status, and let WordPress render its own not-found template.
                status_header($result->getStatus());

                return $template;
            }

            // Success: clear the pre-set 404, then render (render() exits when
            // a template is set; with none, the caller exits — when() parity).
            $this->commitOk();
            $this->renderResponse($result);

            return null;
        }

        if (is_string($result) && file_exists($result)) {
            $this->commitOk();

            return $result;
        }

        if ($result === null || $result === true) {
            // Handled output: the callback owns its status. commitOk() is a
            // no-op if the callback already committed — which the framework's
            // own render-and-exit path DOES via NTDST_Response::render()
            // (commitRenderStatus), so a callback that renders through the
            // Response object never depends on this deferred commit.
            $this->commitOk();

            return null;
        }

        // false or any unrecognized type → leave the 404 intact, pass through.
        return false;
    }

    /**
     * Commit the "OK" status: clear the 404 WordPress pre-set for an unmatched
     * URL and send a 200. Guarded, so it is a safe no-op when nothing set 404
     * or a streaming callback already committed.
     */
    protected function commitOk(): void
    {
        global $wp_query;
        if ($wp_query && $wp_query->is_404()) {
            $wp_query->is_404 = false;
            status_header(200);
        }
    }

    /**
     * Render a Response returned by a route callback.
     *
     * render() never returns (it exits). A Response with no template renders
     * nothing — the caller then exits, in parity with template()/when().
     * Protected so tests (and the future template()/when() callers) can seam it.
     */
    protected function renderResponse(NTDST_Response $response): void
    {
        $template_name = $response->getTemplate();
        if ($template_name) {
            $response->render($template_name); // never returns
        }
    }

    /**
     * Compile URL pattern to regex.
     *
     * Converts /path/:param/:id to regex with named groups. Literal segments
     * are preg_quote'd so dots/plus-signs/parens in the URL pattern aren't
     * treated as regex metacharacters (e.g. "v1.0/users" matches that path
     * literally, not "v1X0/users").
     */
    protected function compilePattern(string $pattern): string
    {
        $pattern = trim($pattern, '/');

        // Split on :param placeholders while keeping them via PREG_SPLIT_DELIM_CAPTURE.
        $tokens = preg_split('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '';
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token[0] === ':') {
                $name = substr($token, 1);
                $regex .= '(?P<' . $name . '>[^/]+)';
            } else {
                $regex .= preg_quote($token, '#');
            }
        }

        // Allow optional trailing slash
        return '#^' . $regex . '/?$#';
    }

    /**
     * Generate URL from pattern and parameters.
     *
     * Param values are urlencoded so slashes / spaces / hashes don't break
     * routing. Params that don't match a :placeholder in the pattern are
     * silently ignored (no query-string append).
     */
    public function url(string $pattern, array $params = []): string
    {
        $url = $pattern;

        foreach ($params as $key => $value) {
            $url = str_replace(':' . $key, urlencode((string) $value), $url);
        }

        return home_url($url);
    }

    /**
     * Redirect to URL.
     *
     * Uses wp_safe_redirect() by default — restricts the target to the same
     * host as the site, blocking open-redirect attacks when $url is derived
     * from user input. Pass $allowExternal=true only when the destination is
     * trusted and intentionally off-site.
     */
    public function redirect(string $url, int $status = 302, bool $allowExternal = false): never
    {
        if ($allowExternal) {
            wp_redirect($url, $status);
        } else {
            wp_safe_redirect($url, $status);
        }
        exit;
    }
}

/**
 * Global helper — the page router (singleton).
 *
 * v3.0.0 removed ntdst_router() and ntdst_route() outright. No aliases, no
 * forwarders: an adopter still calling them fails at the call site instead of
 * silently riding a shim (FR-6).
 */
if (!function_exists('ntdst_pages')) {
    function ntdst_pages(): NTDST_Pages
    {
        static $pages = null;
        return $pages ??= new NTDST_Pages();
    }
}

// Initialise early to register the redirect-prevention hook.
//
// Guarded: this file is also loaded outside WordPress (the package's own unit
// suite requires it directly). Defining a stub add_action() in the test instead
// breaks Patchwork, which must be the one to define it so Brain Monkey can
// reroute the call — SchedulerTest patches add_action and fails with
// DefinedTooEarly if anything defines it first.
if (function_exists('add_action')) {
    add_action('init', 'ntdst_pages', 1);
}
