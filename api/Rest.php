<?php // api/Rest.php

/**
 * NTDST Rest — resource routes, as a thin wrapper over WordPress.
 *
 * This does NOT reimplement routing. `register_rest_route()` does the work; the
 * class exists only to close gaps WordPress leaves open, and each one is a
 * documented core behaviour rather than a preference:
 *
 *  1. WP FAILS OPEN on a missing permission_callback. Since 5.5 it fires
 *     _doing_it_wrong() and registers the route anyway; at rest-api.php:890 the
 *     check is then SKIPPED when the callback is absent, so the route is public.
 *     Here a route without a callable `permission` is never handed to
 *     register_rest_route() at all — refused, not registered-then-denied.
 *  2. WP invokes permission_callback TWICE per served request (once on dispatch,
 *     once computing the Allow header). A side-effectful permission callable
 *     would fire twice, so it is memoized per request.
 *
 * Pick the right service:
 *   page            → ntdst_pages()->path()
 *   command (ajax)  → ntdst_actions()->register()
 *   file bytes      → add_filter('ntdst/api_download/{action}', …)
 *   resource route  → ntdst_rest()   ← this
 *
 * Usage:
 *
 *   ntdst_rest('stride/v1')
 *       ->get('/editions', $handler, ['permission' => $canView])
 *       ->post('/editions', $handler, ['permission' => $canManage]);
 */

defined('ABSPATH') || exit;

final class NTDST_Rest
{
    /**
     * One wrapper per namespace, so a namespace's routes queue together.
     *
     * @var array<string, self>
     */
    private static array $instances = [];

    /** @var list<array{route: string, methods: string, handler: mixed, options: array<string, mixed>}> */
    private array $queued = [];

    private bool $hooked = false;

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
     * Queue a route and make sure it reaches WordPress on rest_api_init.
     *
     * @param array<string, mixed> $options
     */
    public function route(string $route, string $methods, $handler, array $options = []): self
    {
        $this->queued[] = [
            'route' => $route,
            'methods' => $methods,
            'handler' => $handler,
            'options' => $options,
        ];

        // If rest_api_init already fired, register NOW — a service constructed
        // late (or a route added from inside another rest_api_init callback)
        // must not silently register nothing. Checked per call, not once:
        // the wrapper is cached per namespace and outlives any single hook.
        if (function_exists('did_action') && did_action('rest_api_init')) {
            $this->flush();

            return $this;
        }

        if (!$this->hooked) {
            $this->hooked = true;
            add_action('rest_api_init', [$this, 'flush']);
        }

        return $this;
    }

    public function flush(): void
    {
        $queued = $this->queued;
        $this->queued = [];

        foreach ($queued as $entry) {
            $this->registerOne($entry);
        }
    }

    /**
     * @param array{route: string, methods: string, handler: mixed, options: array<string, mixed>} $entry
     */
    private function registerOne(array $entry): void
    {
        $permission = $entry['options']['permission'] ?? null;

        if (!is_callable($permission)) {
            // Gap 1. Refuse LOUDLY and never register: WordPress would accept
            // this route and serve it to everyone. Surfaced via
            // _doing_it_wrong() so it is caught in development rather than
            // discovered as a live open route.
            _doing_it_wrong(
                self::class . '::route',
                sprintf(
                    'Route "%s%s" was not registered — "permission" is a required option and must be callable. Refusing to register a REST route with no permission check.',
                    $this->namespace,
                    $entry['route'],
                ),
                '3.0.0',
            );

            if (function_exists('ntdst_log')) {
                ntdst_log('api')->error('REST route registration refused — missing/non-callable permission', [
                    'namespace' => $this->namespace,
                    'route' => $entry['route'],
                    'methods' => $entry['methods'],
                ]);
            }

            return;
        }

        $args = [
            'methods' => $entry['methods'],
            'callback' => $entry['handler'],
            'permission_callback' => $this->memoize($permission),
        ];

        if (array_key_exists('args', $entry['options'])) {
            $args['args'] = $entry['options']['args'];
        }

        register_rest_route($this->namespace, $entry['route'], $args);
    }

    /**
     * Gap 2 — evaluate the caller's permission ONCE per request.
     *
     * Keyed on the WP_REST_Request OBJECT via WeakMap, which makes it
     * per-request by construction: no hand-rolled cache key that could collide,
     * and the entry dies with the request rather than leaking a decision into
     * the next one. A fresh map per call means two routes never share a memo,
     * so one route's ALLOW can never answer for another.
     */
    private function memoize(callable $permission): callable
    {
        /** @var WeakMap<object, mixed> $cache */
        $cache = new WeakMap();

        return static function ($request) use ($permission, $cache) {
            // A non-object request cannot key a WeakMap; evaluate uncached
            // rather than fail. WP always passes a WP_REST_Request.
            if (!is_object($request)) {
                return $permission($request);
            }

            if (isset($cache[$request])) {
                return $cache[$request];
            }

            return $cache[$request] = $permission($request);
        };
    }
}

/**
 * Global helper — the resource router, one wrapper per namespace.
 */
if (!function_exists('ntdst_rest')) {
    function ntdst_rest(string $namespace): NTDST_Rest
    {
        return NTDST_Rest::forNamespace($namespace);
    }
}
