<?php

declare(strict_types=1);

/**
 * NTDST Pages — front-end URL routing and WordPress template integration.
 *
 * This routes PAGES: a URL pattern resolves to a callable that returns a
 * template for a human. It is NOT the HTTP API surface.
 *
 * A PAGE URL IS A REWRITE RULE (5.0.0, FR-9 / INV-6). path() hands its pattern
 * to add_rewrite_rule() and names the pattern's placeholders on the query_vars
 * filter, so WordPress parses the URL the way it parses every other URL on the
 * site. Dispatch is one template_redirect callback reading get_query_var().
 * Before 5.0.0 this class compiled its own regex, re-matched REQUEST_URI inside
 * template_include — after WordPress had already given up and marked the
 * request not-found — then cleared that flag again and answered the canonical
 * redirect filter to stop the loader undoing it. That was two fights with
 * WordPress to make one unknown URL work. There is nothing to fight now.
 *
 * A CALLBACK RETURNS A PATH AND NEVER EXITS. The return contract is the same
 * for path(), template() and when():
 *   - an existing file path  → that file is the template WordPress includes
 *   - null (or true)         → the callback answered the request itself, and
 *                              the DISPATCHER then ends the request (nothing
 *                              of WordPress's own render follows those bytes)
 *   - false / anything else  → WordPress's own not-found, through set_404()
 * Returning an NTDST_Response no longer renders-and-exits from inside a
 * template filter; build the path with NTDST_Template_Loader::page() instead,
 * which stashes the data ntdst_page_data() reads.
 *
 * Its verb methods are deliberately absent. `get()`/`post()` used to live here
 * and meant "a page pattern matched on this request method" — which collides
 * with ntdst_rest(), where get() means an HTTP GET resource route. Since
 * v3.0.0 an HTTP verb in this package means a REST route and nothing else, and
 * a page route declares its method as an argument to path().
 *
 * Pick the right service:
 *   page / template   → ntdst_pages()
 *   resource route    → ntdst_rest()
 *
 * FLUSH ONCE, WHEN THE RULES CHANGE. Rewrite rules live in an option, so a new
 * or edited path() is invisible until WordPress rewrites that option. This
 * class hashes its own rule set at the end of `init` and flushes only when the
 * hash moved (option `ntdst_pages_rules_hash`) — the plugin idiom. A consumer
 * that prefers to control it can still flush on activation or run
 * `wp rewrite flush`.
 *
 * Usage:
 *
 * // Simple route (GET by default). Declare it on `init`.
 * ntdst_pages()->path('/projects/:slug', function(array $params) {
 *     $project = get_page_by_path($params['slug'], OBJECT, 'project');
 *     return NTDST_Template_Loader::page('project/single', ['project' => $project]);
 * });
 *
 * // A page that only answers POST
 * ntdst_pages()->path('/projects/submit', $handler, 'POST');
 *
 * // With specific template type
 * ntdst_pages()->single('project', function($post) {
 *     return NTDST_Template_Loader::page('project/detail', ['project' => $post]);
 * });
 *
 * // With conditions
 * ntdst_pages()->when(fn() => is_singular('project'), function($post) {
 *     return NTDST_Template_Loader::page('project/detail', ['project' => $post]);
 * });
 */

defined('ABSPATH') || exit;

class NTDST_Pages
{
    /**
     * The hash of the rule set this site last flushed for.
     *
     * One option, one question: "are the rules WordPress stored the rules this
     * code declares?" A per-route option would be a second registry of what is
     * already in `rewrite_rules`.
     */
    private const RULES_HASH_OPTION = 'ntdst_pages_rules_hash';

    protected array $routes = [];
    protected array $template_hooks = [];

    public function __construct()
    {
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('template_redirect', [$this, 'dispatch']);
        // LAST on init: routes are declared on init, and WordPress runs a
        // callback added to an action it is already running. So every path()
        // call is in before the rule set is hashed.
        add_action('init', [$this, 'flushWhenRulesChanged'], PHP_INT_MAX);
    }

    /**
     * Register a page route.
     *
     * The request method is an ARGUMENT, not the method name — HTTP verbs are
     * ntdst_rest()'s vocabulary, and a page route matched on POST is still a
     * page, not a resource.
     *
     * The callback receives (array $params) — the named URL placeholders, as
     * WordPress parsed them out of the URL. Query-string parameters are NOT
     * passed; callbacks read $_GET directly when they need one. See the class
     * docblock for the return contract.
     *
     * Call this on `init` (or earlier): add_rewrite_rule() is only heard while
     * WordPress is still building its rule set.
     *
     * @param string   $pattern  URL pattern (/path/:param/:id)
     * @param callable $callback Handler function
     * @param string   $method   HTTP method (GET, POST, etc.)
     */
    public function path(string $pattern, callable $callback, string $method = 'GET'): self
    {
        $rule = $this->compileRule($pattern);

        if ($rule === null) {
            return $this;
        }

        $index = count($this->routes);
        $query = 'index.php?ntdst_page=' . $index;

        foreach ($rule['params'] as $position => $name) {
            $query .= '&ntdst_p_' . $name . '=$matches[' . ($position + 1) . ']';
        }

        $this->routes[] = [
            'pattern' => $pattern,
            'regex' => $rule['regex'],
            'query' => $query,
            'params' => $rule['params'],
            'callback' => $callback,
            'method' => strtoupper($method),
        ];

        add_rewrite_rule($rule['regex'], $query, 'top');

        return $this;
    }

    /**
     * The query vars this router's rules write.
     *
     * WordPress drops a query var nobody declared, so the rule and this filter
     * are two halves of one registration — the rule writes `ntdst_page` and one
     * `ntdst_p_{name}` per placeholder, and this is where they are named.
     *
     * @param  list<string> $vars
     * @return list<string>
     */
    public function queryVars(array $vars): array
    {
        $vars[] = 'ntdst_page';

        foreach ($this->routes as $route) {
            foreach ($route['params'] as $name) {
                $vars[] = 'ntdst_p_' . $name;
            }
        }

        return array_values(array_unique($vars));
    }

    /**
     * Dispatch the route WordPress matched, on template_redirect.
     *
     * The URL is not re-parsed here: `ntdst_page` is the route index the
     * rewrite rule wrote, so a request either carries one of ours or it does
     * not. An index naming NO route of ours is a pass-through: nothing of ours
     * claimed this URL. A MATCHED route whose verb is wrong is a 404 — the
     * rule owns the URL, the URL has no representation for this method, and
     * leaving WordPress to render its fallback would answer 200 with the blog
     * index instead.
     */
    public function dispatch(): void
    {
        $index = get_query_var('ntdst_page');

        if (!is_scalar($index) || !ctype_digit((string) $index)) {
            return;
        }

        $route = $this->routes[(int) $index] ?? null;

        if ($route === null) {
            return;
        }

        if ($route['method'] !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
            $this->notFound();

            return;
        }

        // `ntdst_page` and `ntdst_p_*` are PUBLIC query vars — naming them on
        // the query_vars filter is what stops WordPress dropping them, and it
        // also means anyone can hand-write them onto any URL on the site
        // (`/?ntdst_page=0&ntdst_p_slug[]=x`). Such a request never went
        // through the rewrite rule, so its params never went through
        // `([^/]+)`. Each one must look like what the rule WOULD have
        // produced — present, a non-empty scalar, no slash — or this is not a
        // request to this route at all.
        $params = [];
        foreach ($route['params'] as $name) {
            $value = get_query_var('ntdst_p_' . $name);

            if (!is_scalar($value)) {
                $this->notFound();

                return;
            }

            $value = (string) $value;

            if ($value === '' || str_contains($value, '/')) {
                $this->notFound();

                return;
            }

            $params[$name] = $value;
        }

        $result = call_user_func($route['callback'], $params);

        // The callback handled its own output (status included), so the
        // request is finished. Returning here would leave WordPress to render
        // the query it had already resolved — the theme's blog index appended
        // to bytes that were sent, after a declared Content-Length, after a
        // vCard. This is the ONE place in the package that ends a request, and
        // it ends it the way WordPress's own template_redirect consumers
        // (feeds, canonical redirects) end theirs. A CALLBACK still never
        // exits; the dispatcher does. (INV-6 `## Deliberate exceptions`.)
        if ($result === null || $result === true) {
            $this->terminate();
        }

        if (is_string($result) && $result !== '') {
            if (file_exists($result)) {
                add_filter('template_include', static fn (): string => $result, PHP_INT_MAX);

                return;
            }

            _doing_it_wrong(
                __CLASS__ . '::path',
                "the route for \"{$route['pattern']}\" returned \"{$result}\", which is not a file that exists. "
                    . 'Build the path with NTDST_Template_Loader::page() / ::locate(), which return null when the '
                    . 'template is missing.',
                '5.0.0',
            );
        }

        $this->notFound();
    }

    /**
     * End the request.
     *
     * A seam, not decoration: `exit` is untestable and a dispatcher that ends
     * the request is the one behaviour a test most needs to observe, so the
     * terminator is a method a double can override. It is `never` because a
     * caller that could continue past it is the defect this closes.
     */
    protected function terminate(): never
    {
        exit;
    }

    /**
     * Hand the request back to WordPress as a 404.
     *
     * WordPress's own three lines (WP::handle_404()), because this runs after
     * that method already decided the request was fine: the flag alone would
     * leave a 200 on the wire.
     */
    protected function notFound(): void
    {
        global $wp_query;

        if (is_object($wp_query) && method_exists($wp_query, 'set_404')) {
            $wp_query->set_404();
        }

        status_header(404);
        nocache_headers();
    }

    /**
     * Flush the rewrite rules when — and only when — this router's rule set
     * changed since the last flush.
     *
     * flush_rewrite_rules() rebuilds and re-saves every rule on the site, so
     * running it on each request is a write per page view. The hash is the
     * cheap question: same rules, no flush. Soft (`false`): the `.htaccess`
     * file has nothing to learn from a rule that lives in the option.
     */
    public function flushWhenRulesChanged(): void
    {
        $hash = md5(implode(
            '|',
            array_map(
                static fn (array $route): string => $route['regex'] . '=>' . $route['query'] . ':' . $route['method'],
                $this->routes,
            ),
        ));

        if (get_option(self::RULES_HASH_OPTION) === $hash) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::RULES_HASH_OPTION, $hash);
    }

    /**
     * Compile a URL pattern into a rewrite regex and its placeholder names.
     *
     * Literal text is preg_quote'd so dots/plus-signs/parens in the pattern
     * aren't regex metacharacters (`v1.0/users` matches that path literally,
     * not `v1X0/users`). WordPress applies the result with `#` as the
     * delimiter, which is why that is the delimiter quoted for.
     *
     * REFUSED, both with a _doing_it_wrong() and no rule: a pattern whose first
     * segment is entirely a placeholder, and the site root. `^([^/]+)/?$` and
     * `^/?$` at the TOP of the rule list match every one-segment URL and the
     * front page — every post, page, feed and admin-facing pretty URL on the
     * site would resolve to that one route. A route needs a literal first
     * segment of its own to own.
     *
     * @return array{regex: string, params: list<string>}|null
     */
    protected function compileRule(string $pattern): ?array
    {
        $path = trim($pattern, '/');

        if ($path === '' || preg_match('#^:[a-zA-Z_][a-zA-Z0-9_]*(/|$)#', $path) === 1) {
            _doing_it_wrong(
                __CLASS__ . '::path',
                "\"{$pattern}\" is refused: a page route's first segment must be literal text. A rule for the site "
                    . 'root, or one that opens with a :placeholder, sits at the top of the rewrite list and matches '
                    . 'every URL of that shape on the site.',
                '5.0.0',
            );

            return null;
        }

        // Split on :param placeholders while keeping them via PREG_SPLIT_DELIM_CAPTURE.
        $tokens = preg_split('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '';
        $params = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token[0] === ':') {
                $params[] = substr($token, 1);
                $regex .= '([^/]+)';
            } else {
                $regex .= preg_quote($token, '#');
            }
        }

        return ['regex' => '^' . $regex . '/?$', 'params' => $params];
    }

    /**
     * Hook into specific WordPress template type
     * Smart wrapper around {$type}_template filters
     *
     * The callback returns a template PATH (or null to leave WordPress's own
     * candidate alone). It does not render and it does not exit — the file it
     * names is what WordPress includes, so the theme's own header/footer run.
     *
     * @param string      $type      Template type (single, page, archive, etc.)
     * @param callable    $callback  Handler receives ($post, $template)
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

            return $this->templateFrom($callback($post, $template), $template);
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
     * Callback receives (?WP_Post $post, string $template) and returns a
     * template path — see the class docblock for the contract.
     */
    public function when(callable $condition, callable $callback): self
    {
        add_filter('template_include', function ($template) use ($condition, $callback) {
            if (!$condition()) {
                return $template;
            }

            global $post;

            return $this->templateFrom($callback($post, $template), $template);
        }, 10);

        return $this;
    }

    /**
     * What a template-filter callback's return value means.
     *
     * ONE implementation for template() and when(), because two copies of a
     * return contract are two contracts. A string is the path WordPress
     * includes; anything else leaves WordPress's own candidate in place.
     *
     * The warning is for the 5.0.0 break and nothing else: an NTDST_Response
     * (or any other object) used to be RENDERED here and then exit()ed, and a
     * callback that still returns one would otherwise fall through in silence
     * to a template with none of its data. null is not warned about — it is
     * how page() says "not my slug".
     */
    protected function templateFrom(mixed $result, string $template): string
    {
        if (is_string($result) && $result !== '') {
            return $result;
        }

        if ($result !== null && $result !== false) {
            _doing_it_wrong(
                __CLASS__ . '::template',
                'a template callback returned ' . get_debug_type($result) . '. Since 5.0.0 it must return a '
                    . 'template PATH — build it with NTDST_Template_Loader::page($name, $data), which stashes the '
                    . 'data ntdst_page_data() reads. Nothing renders or exits from inside a template filter.',
                '5.0.0',
            );
        }

        return $template;
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

// Initialise early on init: the router's own hooks — the query_vars filter, the
// template_redirect dispatcher and the end-of-init flush check — have to be
// mounted before a consumer declares its first path().
//
// Guarded: this file is also loaded outside WordPress (the package's own unit
// suite requires it directly). Defining a stub add_action() in the test instead
// breaks Patchwork, which must be the one to define it so Brain Monkey can
// reroute the call — a test that patches add_action fails with DefinedTooEarly
// if anything defines it first.
if (function_exists('add_action')) {
    add_action('init', 'ntdst_pages', 1);
}
