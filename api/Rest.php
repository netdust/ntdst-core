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
    private const OWN_OPTIONS = ['permission', 'rate_limit', 'rate_window', 'cors', 'before_dispatch'];

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

    /**
     * Consumer pre-dispatch guards — F11.
     *
     * `/{namespace}{route}` => ['callback' => callable, 'seed' => string,
     * 'limit' => int|null, 'window' => int]. `seed` is the exact key material
     * `guard()` uses, minus the per-request bucket, so a refusal charges the
     * SAME bucket the request itself would have — not a second one.
     *
     * @var array<string, array{callback: callable, seed: string, limit: int|null, window: int}>
     */
    private static array $beforeDispatchRoutes = [];

    /** Mounted once per process, not per route. */
    private static bool $beforeDispatchHooked = false;

    /**
     * Declared CORS policies: `/{namespace}{route}` => policy array.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $corsRoutes = [];

    /** The serve filter is mounted once per process, not per route. */
    private static bool $corsHooked = false;

    /**
     * Request headers a cross-origin caller may send when a policy names none.
     *
     * WordPress sends NO `Access-Control-Allow-Headers` at all, so a
     * cross-origin `Content-Type: application/json` POST fails its preflight
     * out of the box. That is why every consumer that needed one hand-rolled
     * this line. These three are what they all converged on.
     */
    private const DEFAULT_CORS_HEADERS = ['Content-Type', 'Authorization', 'X-WP-Nonce'];

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

        // A CORS policy naming '*' is a misconfiguration, not a shorthand for
        // "allow everything". Failing closed silently would leave the author
        // believing cross-origin works; this refuses the route the same way a
        // missing permission does. Misconfiguration refuses, loudly.
        $cors = $options['cors'] ?? null;
        if ($cors !== null) {
            $declared = is_array($cors) && array_key_exists('origins', $cors) ? $cors['origins'] : $cors;

            if (!is_array($cors)) {
                $this->refuse($route, $methods, 'the "cors" option must be an array of origins or a policy array');

                return;
            }

            if (is_array($declared) && in_array('*', $declared, true)) {
                $this->refuse($route, $methods, '"cors" must name exact origins — "*" is never a valid allow-list entry');

                return;
            }
        }

        // Same reasoning as `permission`: a guard the author believes is
        // running and isn't is worse than no guard, so a non-callable refuses
        // the route rather than being ignored.
        if (array_key_exists('before_dispatch', $options) && !is_callable($options['before_dispatch'])) {
            $this->refuse($route, $methods, 'the "before_dispatch" option must be callable');

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
        if (($options['cors'] ?? null) !== null) {
            $this->rememberCors($route, $options['cors']);
        }

        if (($options['rate_limit'] ?? null) !== null) {
            $this->rememberForPreflight(
                $route,
                (int) $options['rate_limit'],
                (int) ($options['rate_window'] ?? 60),
            );
        }

        if (($options['before_dispatch'] ?? null) !== null) {
            $this->rememberBeforeDispatch(
                $route,
                $options['before_dispatch'],
                $methods,
                isset($options['rate_limit']) ? (int) $options['rate_limit'] : null,
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
     * The CORS policy declared for a route path, or null.
     *
     * Matching is case-INSENSITIVE, exactly as WordPress matches routes
     * (`preg_match('@^…$@i')`). A case-sensitive scope check silently stops
     * running for `/NS/V1/THING` while WordPress dispatches it happily — the
     * bug that took a consumer's CORS correction offline and handed core's
     * reflect-any-origin default back to the wire.
     *
     * @return array<string, mixed>|null
     */
    public static function corsFor(string $route): ?array
    {
        foreach (self::$corsRoutes as $pattern => $policy) {
            if (preg_match('@^' . $pattern . '$@i', $route)) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * Decide the CORS headers for one origin under one policy. PURE.
     *
     * Returns `['set' => list<string>, 'remove' => list<string>]`. Nothing is
     * emitted here, which is what makes this control testable at the unit tier
     * at all — an isolated test cannot observe a real `header()` call. Same
     * seam NTDST_Response::fileHeaders() uses.
     *
     * WHAT IT IS CORRECTING. WP's `rest_send_cors_headers()` runs at priority
     * 10 and echoes ANY origin with `Access-Control-Allow-Credentials: true`,
     * so any site can read a logged-in visitor's authenticated responses. This
     * runs after it. On a match it re-states the origin and adds the `Vary`
     * and `Allow-Headers` core omits or under-sends; on a NON-match it REMOVES
     * core's grant, because leaving it is the whole vulnerability.
     *
     * Matching is byte-exact against the full `scheme://host[:port]`. Never a
     * substring, never case-folded, never a wildcard: `'*'` in a policy is a
     * misconfiguration and grants nothing rather than everything. `Origin:
     * null` — a file:// page or a sandboxed iframe — is never allowed, even if
     * a policy lists it, because it identifies nobody.
     *
     * @param array<string, mixed>|list<string> $policy Origins, or a policy array.
     * @return array{set: list<string>, remove: list<string>}
     */
    public static function corsDecision(?string $origin, array $policy): array
    {
        $revoke = [
            'set' => [],
            'remove' => ['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials'],
        ];

        // A list of origins is the shorthand for ['origins' => [...]].
        $origins = array_key_exists('origins', $policy) ? $policy['origins'] : $policy;

        if ($origin === null || $origin === '' || $origin === 'null') {
            return $revoke;
        }

        // Strict, and string-only. A non-string in the list — `true`, `1`, a
        // stray `0` from a malformed config — would match EVERY origin under a
        // loose comparison. The list is byte-exact `scheme://host[:port]`
        // strings or it is not a list.
        $allowed = is_callable($origins)
            ? (bool) $origins($origin)
            : (is_array($origins) && in_array($origin, array_filter($origins, 'is_string'), true));

        if (!$allowed) {
            return $revoke;
        }

        $headers = $policy['headers'] ?? self::DEFAULT_CORS_HEADERS;

        $set = [
            'Access-Control-Allow-Origin: ' . $origin,
            'Vary: Origin',
            'Access-Control-Allow-Headers: ' . implode(', ', (array) $headers),
        ];

        if (isset($policy['max_age'])) {
            $set[] = 'Access-Control-Max-Age: ' . (int) $policy['max_age'];
        }

        // Credentials are OFF unless the site asks. Granting them is only ever
        // safe beside an exact-origin match, which is the only way to get here.
        if (($policy['credentials'] ?? false) === true) {
            $set[] = 'Access-Control-Allow-Credentials: true';

            return ['set' => $set, 'remove' => []];
        }

        return ['set' => $set, 'remove' => ['Access-Control-Allow-Credentials']];
    }

    /**
     * Emit the decision for the request being served. Mounted at priority 20,
     * after WP's own `rest_send_cors_headers()` at 10 — the only position from
     * which core's grant can be corrected.
     *
     * Routes this package never registered are left completely alone.
     *
     * @param bool  $served
     * @return bool $served, untouched.
     */
    public static function applyCors($served, $result = null, $request = null)
    {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $served;
        }

        $decision = self::corsDecisionFor(
            (string) $request->get_route(),
            function_exists('get_http_origin') ? (string) get_http_origin() : '',
        );

        if ($decision === null) {
            return $served;
        }

        foreach ($decision['remove'] as $name) {
            header_remove($name);
        }

        foreach ($decision['set'] as $header) {
            // Vary appends; everything else replaces core's line.
            header($header, stripos($header, 'Vary:') !== 0);
        }

        return $served;
    }

    /**
     * The decision for a route, or NULL when this package declared no policy
     * for it — which is most of the REST API, including every other plugin's.
     *
     * A filter that touches a request it does not own is a bug with a very
     * wide blast radius: removing `Access-Control-Allow-Origin` from another
     * plugin's route breaks that plugin's clients and nothing points here.
     * Null is the seam that makes "did nothing" an assertable outcome rather
     * than an unobserved one.
     *
     * @return array{set: list<string>, remove: list<string>}|null
     */
    public static function corsDecisionFor(string $route, ?string $origin): ?array
    {
        $policy = self::corsFor($route);

        return $policy === null ? null : self::corsDecision($origin, $policy);
    }

    /** Record a route's CORS policy, and mount the serve filter once. */
    private function rememberCors(string $route, mixed $policy): void
    {
        self::$corsRoutes['/' . trim($this->namespace, '/') . $route] = $policy;

        if (!self::$corsHooked) {
            self::$corsHooked = true;
            add_filter('rest_pre_serve_request', [self::class, 'applyCors'], 20, 3);
        }
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

    /**
     * Remember a consumer's pre-dispatch guard, and mount the hook once.
     *
     * @param callable $callback Receives the request; returns null to allow,
     *                           or a WP_Error to refuse.
     */
    private function rememberBeforeDispatch(
        string $route,
        callable $callback,
        string $methods,
        ?int $limit,
        int $window,
    ): void {
        $pattern = '/' . trim($this->namespace, '/') . $route;
        $verbs = array_map('strtoupper', array_map('trim', explode(',', $methods)));

        self::$beforeDispatchRoutes[$pattern] = [
            'callback' => $callback,
            // The SAME key material guard() builds, minus the per-request
            // bucket (which cannot be resolved at registration — REST auth has
            // not run yet). Storing the seed is what makes a refusal charge the
            // request's own bucket instead of opening a second one.
            'seed' => $this->namespace . '|' . $route . '|' . implode(',', $verbs),
            'limit' => $limit,
            'window' => $window,
        ];

        if (!self::$beforeDispatchHooked) {
            self::$beforeDispatchHooked = true;
            // Priority 6, one after the preflight charge at 5, so an OPTIONS
            // request is still billed to the preflight bucket before any
            // consumer guard can answer it.
            add_filter('rest_pre_dispatch', [self::class, 'runBeforeDispatch'], 6, 3);
        }
    }

    /**
     * Run a route's `before_dispatch` guard, and charge the request budget when
     * it refuses — F11.
     *
     * WHY THE CHARGE IS ON REFUSAL ONLY. A request the callback ALLOWS goes on
     * to `guard()`, which bills it in the permission callback; billing here too
     * would charge every legitimate request twice. A request the callback
     * REFUSES short-circuits `dispatch()` and never reaches `guard()`, so this
     * is the only place it can be billed at all. Exactly one unit either way,
     * into the one bucket both paths share.
     *
     * Before this existed, a consumer's own `rest_pre_dispatch` filter made its
     * refusals FREE: measured on a real consumer's public write route, 100
     * rejected requests carrying ~100 MB of body moved the bucket by zero and a
     * legitimate POST straight after still succeeded. The consumer could not fix
     * it either — `bucket()` is private and the key is built inline in
     * `guard()`, leaving only two bad options: hand-copy the key formula, or
     * open a second bucket.
     *
     * The hook sees every REST request on the site. It acts only on a route it
     * put in the table itself and returns `$result` untouched otherwise.
     *
     * @param mixed  $result  Whatever an earlier filter produced; null normally.
     * @param mixed  $server  The REST server (unused).
     * @param object $request The request being dispatched.
     * @return mixed `$result` unchanged, or the callback's WP_Error.
     */
    public static function runBeforeDispatch($result, $server = null, $request = null)
    {
        // Someone already answered. Do not run a guard for a dispatch that is
        // not going to happen, and do not stomp their result.
        if ($result !== null) {
            return $result;
        }

        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $result;
        }

        $route = (string) $request->get_route();

        foreach (self::$beforeDispatchRoutes as $pattern => $entry) {
            // Case-INSENSITIVE, exactly as WP matches routes. A consumer
            // writing this by hand has already got it wrong twice — once
            // case-sensitively, so `/NS/V1/THING` skipped the guard entirely,
            // and once by prefix, so the guard answered on paths its own CORS
            // policy did not cover.
            if (!preg_match('@^' . $pattern . '$@i', $route)) {
                continue;
            }

            $decision = ($entry['callback'])($request);

            if ($decision instanceof WP_Error) {
                if ($entry['limit'] !== null) {
                    $key = 'ntdst_rest_' . md5($entry['seed'] . '|' . self::bucket());
                    NTDST_RateLimiter::attempt($key, $entry['limit'], $entry['window'], $request);
                }

                return $decision;
            }

            // The first pattern that matches owns the request.
            return $result;
        }

        return $result;
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
