<?php // api/Rest.php

/**
 * NTDST Rest — resource routes, wrapping WordPress rather than replacing it.
 *
 * register_rest_route() does the work. This adds two things WP does not:
 *  - a route without a callable permission is never registered (WP registers it
 *    and then skips the check, leaving it public);
 *  - the permission runs once per request (WP calls it twice, for the Allow
 *    header), so a side-effectful permission does not fire twice.
 *
 * Rate limiting is opt-in per route and delegates to support/RateLimiter.php.
 * It is only sound when `ntdst/trusted_proxies` matches the deployment and the
 * proxy overwrites X-Forwarded-For; otherwise a caller can pick their bucket.
 *
 * Pick the right service:
 *   page            → ntdst_pages()->path()
 *   command (ajax)  → ntdst_actions()->register()
 *   file bytes      → add_filter('ntdst/api_download/{action}', …)
 *   resource route  → ntdst_rest()
 */

defined('ABSPATH') || exit;

final class NTDST_Rest
{
    /** Options this class consumes; everything else passes through to WP. */
    private const OWN_OPTIONS = ['permission', 'rate_limit', 'rate_window'];

    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<string, bool> Refusals already reported this process. */
    private static array $reported = [];

    /**
     * Rate-limited route patterns, for the preflight charge (F4).
     *
     * `/{namespace}{route}` => ['limit' => int, 'window' => int]. Only routes
     * that DECLARED a rate_limit appear here, so preflight charging inherits
     * the opt-in: a consumer declares nothing new to get it, and nothing new
     * to stay out of it. When several verbs of one route declare different
     * limits, the HIGHEST wins — a preflight precedes any of them, and it must
     * not throttle a client below what the verb they are about to use allows.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    private static array $preflightRoutes = [];

    /** The pre-dispatch filter is mounted once per process, not per route. */
    private static bool $preflightHooked = false;

    public function __construct(private string $namespace) {}

    public static function forNamespace(string $namespace): self
    {
        return self::$instances[$namespace] ??= new self($namespace);
    }

    public function get(string $route, $handler, array $options = []): self
    {
        return $this->route($route, 'GET', $handler, $options);
    }

    public function post(string $route, $handler, array $options = []): self
    {
        return $this->route($route, 'POST', $handler, $options);
    }

    public function put(string $route, $handler, array $options = []): self
    {
        return $this->route($route, 'PUT', $handler, $options);
    }

    public function patch(string $route, $handler, array $options = []): self
    {
        return $this->route($route, 'PATCH', $handler, $options);
    }

    public function delete(string $route, $handler, array $options = []): self
    {
        return $this->route($route, 'DELETE', $handler, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function route(string $route, string $methods, $handler, array $options = []): self
    {
        $register = fn () => $this->registerOne($route, $methods, $handler, $options);

        // register_rest_route() before rest_api_init is _doing_it_wrong; after
        // it, the hook will never fire again.
        did_action('rest_api_init') ? $register() : add_action('rest_api_init', $register);

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function registerOne(string $route, string $methods, $handler, array $options): void
    {
        $permission = $options['permission'] ?? null;

        if (!is_callable($permission)) {
            $this->refuse($route, $methods, '"permission" is required and must be callable');

            return;
        }

        if (!is_callable($handler)) {
            // WP has its own invalid-handler guard, but a wrapped callback would
            // slip past it and fatal mid-request instead.
            $this->refuse($route, $methods, 'the handler must be callable');

            return;
        }

        // A typo'd option is a control the author believes is on and isn't, so
        // it gets the same loud treatment as a missing permission.
        $unknown = array_diff(array_keys($options), array_merge(self::OWN_OPTIONS, ['args', 'schema', 'show_in_index', 'allow_batch']));
        if ($unknown !== []) {
            $this->refuse($route, $methods, 'unknown option(s): ' . implode(', ', $unknown));

            return;
        }

        // Pass everything WP understands straight through — narrowing its API
        // would send consumers back to raw register_rest_route().
        $args = array_diff_key($options, array_flip(self::OWN_OPTIONS)) + [
            'methods' => $methods,
            'callback' => $handler,
            'permission_callback' => $this->guard($permission, $route, $methods, $options),
        ];

        register_rest_route($this->namespace, $route, $args);

        // Read from $options, not from guard()'s locals: rate limiting is
        // opt-in, so a route with no rate_limit stays out of the table.
        if (($options['rate_limit'] ?? null) !== null) {
            $this->rememberForPreflight(
                $route,
                (int) $options['rate_limit'],
                (int) ($options['rate_window'] ?? 60),
            );
        }
    }

    /**
     * Charge a CORS preflight once per request — F4.
     *
     * `guard()` spends budget only for the handler whose verb MATCHED, and an
     * `OPTIONS` preflight never matches `POST`. Measured against a clean
     * bucket: 40 consecutive preflights left it unset, and 5 preflights
     * carrying a 1.1 MB JSON body returned 200 each for nothing, while the
     * same body as a POST was charged correctly. A preflight is not free to
     * serve — WP's `rest_handle_options_request()` sets a matched route, so
     * `rest_send_allow_header()` runs the permission callback for every
     * sibling handler.
     *
     * WHY NOT SIMPLY WIDEN `$matched`. Two reasons, and they are the whole
     * design. WP invokes every sibling handler's permission callback to build
     * the `Allow` header, so a route registered for GET+POST+DELETE would
     * charge THREE units for one preflight. And the preflight would spend the
     * POST budget the real request needs a moment later, making every CORS
     * write cost two units — the same halving the memo exists to prevent,
     * just relocated. So the charge is made ONCE, here, in the one place that
     * runs once per HTTP request, into a bucket of the preflight's own.
     * `$matched` is untouched.
     *
     * THE 429 IS DELIBERATE, and it is not the trap it resembles. A
     * content-type gate that refuses EVERY preflight with a 415 breaks CORS
     * outright and is a known way to lose an afternoon. This refuses only a
     * preflight that is already over its own budget, which is the same answer
     * its POST would get one request later. A throttled caller is meant to be
     * stopped.
     *
     * The hook sees every REST request on the site, including namespaces this
     * package never registered. It acts only on OPTIONS, only on a route it
     * put in the table itself, and it returns `$result` untouched otherwise —
     * a filter that alters a request it does not own is a bug with a wide
     * blast radius.
     *
     * @param mixed  $result  Whatever an earlier filter produced; null normally.
     * @param mixed  $server  The REST server (unused).
     * @param object $request The request being dispatched.
     * @return mixed `$result` unchanged, or a 429 WP_Error.
     */
    public static function chargePreflight($result, $server = null, $request = null)
    {
        // Someone already answered this request. Do not charge for a dispatch
        // that is not going to happen, and do not stomp their result.
        if ($result !== null) {
            return $result;
        }

        if (!is_object($request) || !method_exists($request, 'get_method') || !method_exists($request, 'get_route')) {
            return $result;
        }

        if (strtoupper((string) $request->get_method()) !== 'OPTIONS') {
            return $result;
        }

        $route = (string) $request->get_route();

        foreach (self::$preflightRoutes as $pattern => $numbers) {
            // Case-INSENSITIVE, exactly as WP matches routes
            // (`preg_match('@^…$@i')`). A scope check that is case-sensitive
            // silently stops running for `/NS/V1/THING` while WordPress
            // dispatches it happily — that is how a consumer's CORS
            // correction went offline and handed WP core's
            // reflect-any-origin-with-credentials default back to the wire.
            if (!preg_match('@^' . $pattern . '$@i', $route)) {
                continue;
            }

            $key = 'ntdst_rest_pf_' . md5($pattern . '|' . self::bucket());

            if (!NTDST_RateLimiter::attempt($key, $numbers['limit'], $numbers['window'], $request)) {
                return new WP_Error(
                    'rate_limited',
                    'Too many requests. Please wait a moment and try again.',
                    ['status' => 429, 'retry_after' => $numbers['window']],
                );
            }

            // One charge per request. The first pattern that matches owns it.
            return $result;
        }

        return $result;
    }

    /**
     * Put a rate-limited route in the preflight table, and mount the hook the
     * first time anything lands there.
     */
    private function rememberForPreflight(string $route, int $limit, int $window): void
    {
        $pattern = '/' . trim($this->namespace, '/') . $route;
        $existing = self::$preflightRoutes[$pattern]['limit'] ?? 0;

        if ($limit > $existing) {
            self::$preflightRoutes[$pattern] = ['limit' => $limit, 'window' => $window];
        }

        if (!self::$preflightHooked) {
            self::$preflightHooked = true;
            // Priority 5: ahead of WP's own rest_handle_options_request() at
            // 10, which answers the preflight and ends the dispatch.
            add_filter('rest_pre_dispatch', [self::class, 'chargePreflight'], 5, 3);
        }
    }

    private function refuse(string $route, string $methods, string $why): void
    {
        $id = $this->namespace . '|' . $route . '|' . $methods;

        // Once per process: registerOne() runs on every REST request.
        if (isset(self::$reported[$id])) {
            return;
        }
        self::$reported[$id] = true;

        _doing_it_wrong(
            self::class . '::route',
            sprintf('Route was not registered — %s.', $why),
            '3.0.0',
        );

        if (function_exists('ntdst_log')) {
            ntdst_log('api')->error('REST route registration refused', [
                'namespace' => $this->namespace,
                'route' => $route,
                'methods' => $methods,
                'reason' => $why,
            ]);
        }
    }

    /**
     * Rate limit, then the caller's permission — both memoized together.
     *
     * @param array<string, mixed> $options
     */
    private function guard(callable $permission, string $route, string $methods, array $options): callable
    {
        $limit = $options['rate_limit'] ?? null;
        $window = (int) ($options['rate_window'] ?? 60);
        $verbs = array_map('strtoupper', array_map('trim', explode(',', $methods)));

        return $this->memoize(function ($request) use ($permission, $limit, $window, $route, $verbs) {
            // Only the handler that matched the request spends budget. WP calls
            // every sibling handler's permission for the Allow header, so
            // without this a GET drains the POST route's limit.
            $matched = is_object($request) && method_exists($request, 'get_method')
                && in_array(strtoupper((string) $request->get_method()), $verbs, true);

            if ($limit !== null && $matched) {
                // Bucket resolved HERE, not at registration: REST auth has not
                // run at rest_api_init, so the user would always look anonymous.
                $key = 'ntdst_rest_' . md5($this->namespace . '|' . $route . '|' . implode(',', $verbs) . '|' . self::bucket());

                if (!NTDST_RateLimiter::attempt($key, (int) $limit, $window)) {
                    return new WP_Error(
                        'rate_limited',
                        'Too many requests. Please wait a moment and try again.',
                        ['status' => 429, 'retry_after' => $window],
                    );
                }
            }

            return $permission($request);
        });
    }

    /**
     * Per user when logged in, else per client IP. 'unknown' rather than an
     * empty hash: support/ClientIp.php returns '' for an unusable address, and
     * pooling every such caller into one bucket lets one starve the rest.
     */
    private static function bucket(): string
    {
        $userId = (int) get_current_user_id();

        return $userId > 0 ? 'u' . $userId : 'ip' . md5(NTDST_ClientIp::detect($_SERVER) ?: 'unknown');
    }

    /**
     * Evaluate once per request. Keyed on the request OBJECT, so the entry dies
     * with it; boxed, because isset() on a WeakMap is false for a stored null —
     * and null is WP's own deny value.
     */
    private function memoize(callable $decide): callable
    {
        /** @var WeakMap<object, array{0: mixed}> $cache */
        $cache = new WeakMap();

        return static function ($request) use ($decide, $cache) {
            if (!is_object($request)) {
                return $decide($request);
            }

            $cache[$request] ??= [$decide($request)];

            return $cache[$request][0];
        };
    }
}

if (!function_exists('ntdst_rest')) {
    function ntdst_rest(string $namespace): NTDST_Rest
    {
        return NTDST_Rest::forNamespace($namespace);
    }
}
