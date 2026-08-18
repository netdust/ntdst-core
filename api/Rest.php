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
                $key = 'ntdst_rest_' . md5($this->namespace . '|' . $route . '|' . implode(',', $verbs) . '|' . $this->bucket());

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
    private function bucket(): string
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
