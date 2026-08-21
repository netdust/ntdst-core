<?php // api/Rest.php

/**
 * NTDST Rest — a thin front for register_rest_route().
 *
 * What it adds over raw WordPress:
 *  - a route with no permission is REFUSED, not silently published;
 *  - permission shorthands ('public', 'logged_in', a capability name) so the
 *    common cases need no closure;
 *  - namespace-level defaults, so you declare permission once;
 *  - a real CORS allow-list replacing core's reflect-any-origin default,
 *    site-wide and decided by a pure function so it can be unit-tested;
 *  - the permission callback runs once per request, not twice;
 *  - a rate limit per route, spendable from outside via charge() so a
 *    consumer's own pre-dispatch refusals are not free.
 *
 * Everything else is passed straight through to WordPress.
 *
 *   ntdst_rest('shop/v1')
 *       ->defaults(['permission' => 'logged_in'])
 *       ->cors(['https://app.example.com'])
 *       ->get('/orders', [$c, 'index'], ['permission' => 'public'])
 *       ->post('/orders', [$c, 'store'], ['args' => [...]])
 *       ->delete('/orders/(?P<id>\d+)', [$c, 'destroy'], ['permission' => 'manage_options']);
 */

defined('ABSPATH') || exit;

final class NTDST_Rest
{
    /** Options this class consumes; everything else goes to WP verbatim. */
    private const OWN = ['permission', 'rate_limit', 'rate_window'];

    /** Options this class used to accept, mapped to what replaced them. */
    private const RETIRED = [
        'cors' => 'declare it once with ntdst_rest(...)->cors([...]) — it is site-wide now',
        'before_dispatch' => 'filter rest_pre_dispatch and bill with ->charge($route, $methods, $request)',
    ];

    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<string, bool> Refusals already reported this process. */
    private static array $reported = [];

    /**
     * Site-wide CORS allow-list, merged from every namespace that declared one.
     *
     * @var array{origins: list<string>|callable|null, credentials: bool, max_age: int}
     */
    private static array $cors = ['origins' => null, 'credentials' => false, 'max_age' => 0];

    /** @var array<string, mixed> Namespace-level option defaults. */
    private array $defaults = [];

    /**
     * Declared limits, so charge() can bill a route without the caller
     * restating numbers it already wrote down.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    private static array $limits = [];

    /**
     * Every route that actually registered, with the permission it DECLARED.
     *
     * `NTDST_Actions` had one property nothing else replaces: a site's whole
     * anonymous surface was a single list (`ntdst/api/public_actions`), and a
     * test could assert on it. Routes scatter that decision across
     * registrations, so without this, "is anything on this site reachable
     * anonymously?" stops being a question code can answer.
     *
     * DECLARED, not resolved — and that distinction is the point. A closure
     * permission is opaque: `fn() => true` and a real capability check have the
     * same type. Filing a closure as merely "not public" would let a site's own
     * "nothing is anonymous" test pass over a wide-open route, which is worse
     * than no introspection at all. Closures are recorded as `callable` and
     * surfaced by `opaqueSurface()` so they must be answered for, not skipped.
     *
     * @var array<string, array{namespace: string, route: string, methods: list<string>, permission: string}>
     */
    private static array $surface = [];

    public function __construct(private string $namespace) {}

    public static function forNamespace(string $namespace): self
    {
        return self::$instances[$namespace] ??= new self($namespace);
    }

    /**
     * Options every route in this namespace inherits. Per-route options win.
     *
     * @param array<string, mixed> $options
     */
    public function defaults(array $options): self
    {
        $this->defaults = $options + $this->defaults;

        return $this;
    }

    /**
     * Declare the cross-origin policy.
     *
     * This REMOVES core's rest_send_cors_headers, which echoes ANY origin back
     * with Access-Control-Allow-Credentials: true — meaning any site can read a
     * logged-in visitor's authenticated responses. The replacement is site-wide
     * and fails closed: an origin not on the list gets no CORS headers at all,
     * on every REST route including other plugins'. That is the intended trade;
     * if a plugin on this site needs its own cross-origin policy, it must be
     * added here too.
     *
     * Never '*' — with credentials the browser rejects it anyway, and without
     * credentials it means "the whole internet may read this", which is a thing
     * to write out on purpose, not to reach by shorthand.
     *
     * @param list<string>|callable $origins Exact 'scheme://host[:port]' strings, or fn(string): bool.
     */
    public function cors(array|callable $origins, bool $credentials = false, int $maxAge = 0): self
    {
        if (is_array($origins)) {
            $origins = array_values(array_filter($origins, 'is_string'));

            if (in_array('*', $origins, true)) {
                $this->refuse('(cors)', '-', '"*" is never a valid allow-list entry');

                return $this;
            }

            $existing = is_array(self::$cors['origins']) ? self::$cors['origins'] : [];
            $origins  = array_values(array_unique([...$existing, ...$origins]));
        }

        self::$cors = [
            'origins'     => $origins,
            'credentials' => $credentials || self::$cors['credentials'],
            'max_age'     => max($maxAge, self::$cors['max_age']),
        ];

        // No "already hooked" flag. Such a flag claims "this process mounted
        // it", which is not the same as "it is mounted": anything that rebuilds
        // $wp_filter — WP_UnitTestCase snapshots and restores it around every
        // test — drops the callback while the flag stays true, and the policy
        // silently stops running from test two onward. Named static callbacks
        // get a stable id from _wp_filter_build_unique_id(), so WordPress
        // de-duplicates these itself and a second cors() call is free. A
        // closure here would NOT dedupe, which is why this is a method.
        add_action('rest_api_init', [self::class, 'mountCors'], 15);

        return $this;
    }

    /** Take core's reflect-any-origin handler off the bus and put ours on it. */
    public static function mountCors(): void
    {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', [self::class, 'sendCors'], 10, 1);
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
        $register = fn () => $this->registerOne($route, $methods, $handler, $options + $this->defaults);

        // Before rest_api_init, register_rest_route() is _doing_it_wrong; after
        // it, the hook never fires again.
        did_action('rest_api_init') ? $register() : add_action('rest_api_init', $register);

        return $this;
    }

    /**
     * Resolve the 'permission' option to a callable, or null if unusable.
     *
     * 'public'    → open, and said out loud rather than implied by omission
     * 'logged_in' → any authenticated user
     * anything else that is a string → treated as a capability
     */
    private function permission(mixed $permission): ?callable
    {
        if (is_callable($permission)) {
            return $permission;
        }

        if (!is_string($permission) || $permission === '') {
            return null;
        }

        return match ($permission) {
            'public'    => '__return_true',
            'logged_in' => static fn (): bool => is_user_logged_in(),
            default     => static fn (): bool => current_user_can($permission),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function registerOne(string $route, string $methods, $handler, array $options): void
    {
        $permission = $this->permission($options['permission'] ?? null);

        if ($permission === null) {
            $this->refuse($route, $methods, '"permission" is required — a callable, a capability, "logged_in" or "public"');

            return;
        }

        if (!is_callable($handler)) {
            // WP guards invalid handlers too, but a wrapped callback slips past
            // that guard and fatals mid-request instead.
            $this->refuse($route, $methods, 'the handler must be callable');

            return;
        }

        // A typo'd option is a control the author believes is on and isn't, so
        // it refuses. A RETIRED one is different: it worked when they wrote it,
        // and refusing takes their endpoint away over a name.
        $known   = [...self::OWN, 'args', 'schema', 'show_in_index', 'allow_batch'];
        $unknown = array_diff(array_keys($options), $known, array_keys(self::RETIRED));

        if ($unknown !== []) {
            $this->refuse($route, $methods, 'unknown option(s): ' . implode(', ', $unknown));

            return;
        }

        foreach (array_intersect(array_keys($options), array_keys(self::RETIRED)) as $name) {
            $this->refuse($route, $methods, sprintf('"%s" is retired — %s.', $name, self::RETIRED[$name]), false);
            unset($options[$name]);
        }

        if (($options['rate_limit'] ?? null) !== null) {
            self::$limits[$this->key($route, $methods)] = [
                'limit'  => (int) $options['rate_limit'],
                'window' => (int) ($options['rate_window'] ?? 60),
            ];
        }

        register_rest_route($this->namespace, $route, array_diff_key($options, array_flip(self::OWN)) + [
            'methods'             => $methods,
            'callback'            => $handler,
            'permission_callback' => $this->guard($permission, $route, $methods, $options),
        ]);

        // Recorded HERE, below every refusal path: a route the wrapper turned
        // away never registered, and must not read as surface.
        $declared = $options['permission'] ?? null;

        self::$surface[$this->key($route, $methods)] = [
            'namespace'  => $this->namespace,
            'route'      => $route,
            'methods'    => array_map('strtoupper', array_map('trim', explode(',', $methods))),
            'permission' => is_string($declared) && $declared !== '' ? $declared : 'callable',
        ];
    }

    /**
     * The allow-list decision, as headers to set and to remove. PURE.
     *
     * Separate from the emitter because `header()` is invisible to a unit
     * test: a policy observable only over a socket is one nobody can
     * unit-test. Byte-exact against the full `scheme://host[:port]`; never a
     * substring, never case-folded. `Origin: null` identifies nobody. The
     * list is string-only — a stray `true` would match every origin under a
     * loose comparison.
     *
     * @param array{origins: list<string>|callable|null, credentials: bool, max_age: int} $policy
     * @return array{set: list<string>, remove: list<string>}
     */
    public static function corsDecision(?string $origin, array $policy): array
    {
        $revoke = ['set' => [], 'remove' => ['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials']];
        if ($origin === null || $origin === '' || $origin === 'null') {
            return $revoke;
        }

        $origins = $policy['origins'] ?? null;
        $allowed = is_callable($origins)
            ? (bool) $origins($origin)
            : (is_array($origins) && in_array($origin, array_filter($origins, 'is_string'), true));

        if (!$allowed) {
            return $revoke;
        }

        $set = [
            'Access-Control-Allow-Origin: ' . $origin,
            'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE',
        ];

        if (($policy['max_age'] ?? 0) > 0) {
            $set[] = 'Access-Control-Max-Age: ' . (int) $policy['max_age'];
        }

        // Credentials are OFF unless the site asks. Granting them is only ever
        // safe beside an exact-origin match, which is the only way to get here.
        if (($policy['credentials'] ?? false) === true) {
            return ['set' => [...$set, 'Access-Control-Allow-Credentials: true'], 'remove' => []];
        }

        return ['set' => $set, 'remove' => ['Access-Control-Allow-Credentials']];
    }

    /**
     * The decision for the site policy, or null when none was declared. One
     * allow-list, site-wide — nothing to look up, so no route to pass.
     *
     * @return array{set: list<string>, remove: list<string>}|null
     */
    public static function corsDecisionFor(?string $origin): ?array
    {
        return self::$cors['origins'] === null ? null : self::corsDecision($origin, self::$cors);
    }

    /**
     * Emit the decision. Mounted in place of core's own function.
     *
     * @param bool $served
     * @return bool Untouched.
     */
    public static function sendCors($served)
    {
        // Not a policy decision: caches need this whether or not we grant.
        header('Vary: Origin', false);

        $origin = function_exists('get_http_origin') ? (string) get_http_origin() : '';
        $decision = self::corsDecisionFor($origin);

        if ($decision === null) {
            return $served;
        }

        foreach ($decision['remove'] as $header) {
            header_remove($header);
        }

        foreach ($decision['set'] as $header) {
            header($header);
        }

        return $served;
    }

    /**
     * The caller's permission, then the rate limit — memoized together.
     *
     * AUTH BEFORE THE BUCKET. This order is the whole point, and it is the same
     * correction `api/Actions.php` already made (M2): a caller who can never
     * pass this route's permission must not be able to make the site WRITE
     * storage by asking. Charging first meant every doomed request left a
     * `wp_options` row behind, reaped only by a daily cron — and once the
     * budget ran out the route answered 429 to a caller who should have been
     * refused, which is both the wrong answer and a small disclosure: it
     * confirms the route exists and is counting.
     *
     * Evaluating the permission costs a capability check and answers the
     * question outright, so there is nothing to buy by deferring it.
     *
     * The residual is deliberate: a refused caller is no longer throttled and
     * may repeat the refusal freely. That request still pays WordPress's REST
     * bootstrap, which this limiter never prevented either — what it may no
     * longer do is make us write.
     *
     * @param array<string, mixed> $options
     */
    private function guard(callable $permission, string $route, string $methods, array $options): callable
    {
        $limit  = $options['rate_limit'] ?? null;
        $window = (int) ($options['rate_window'] ?? 60);
        $verbs  = array_map('strtoupper', array_map('trim', explode(',', $methods)));

        return $this->memoize(function ($request) use ($permission, $limit, $window, $route, $verbs) {
            $allowed = $permission($request);

            // Refusal — a WP_Error or anything falsy. Returned UNTOUCHED: the
            // permission's own refusal shape is what WordPress must see, and
            // re-wrapping it would change the status a route already chose.
            if (!$allowed || $allowed instanceof WP_Error) {
                return $allowed;
            }

            // Only the handler that matched spends budget. WP calls every
            // sibling's permission to build the Allow header, so without this a
            // GET drains the POST route's limit.
            $matched = is_object($request)
                && method_exists($request, 'get_method')
                && in_array(strtoupper((string) $request->get_method()), $verbs, true);

            if ($limit !== null && $matched && class_exists('NTDST_RateLimiter')) {
                // Bucket resolved HERE: REST auth has not run at
                // rest_api_init, so the user would always look anonymous.
                $key = 'ntdst_rest_' . md5($this->key($route, implode(',', $verbs)) . '|' . self::bucket());

                if (!NTDST_RateLimiter::attempt($key, (int) $limit, $window, $request)) {
                    return new WP_Error('rate_limited', 'Too many requests. Please wait a moment and try again.', [
                        'status'      => 429,
                        'retry_after' => $window,
                    ]);
                }
            }

            return $allowed;
        });
    }

    /**
     * The identity of a route's budget, without the per-request bucket.
     *
     * @internal Shape is not a contract. Use charge() rather than rebuilding it.
     */
    private function key(string $route, string $methods): string
    {
        $verbs = array_map('strtoupper', array_map('trim', explode(',', $methods)));

        return $this->namespace . '|' . $route . '|' . implode(',', $verbs);
    }

    /**
     * Spend one unit of a route's declared budget. PUBLIC on purpose.
     *
     * A consumer with its own pre-dispatch guard needs to bill refusals to the
     * SAME bucket the permission callback uses, or its rejections are free and
     * the limit protects nothing. Without a public entry point the only options
     * are to hand-copy the key formula or open a second bucket — both wrong,
     * and both a reason to grow this class a route option it does not need.
     *
     *   add_filter('rest_pre_dispatch', function ($result, $server, $request) {
     *       if ($result !== null || $this->allows($request)) {
     *           return $result;
     *       }
     *
     *       ntdst_rest('shop/v1')->charge('/orders', 'POST', $request);
     *
     *       return new WP_Error('forbidden', '…', ['status' => 403]);
     *   }, 10, 3);
     *
     * Returns TRUE when the route declared no limit — nothing to spend is not a
     * refusal.
     */
    public function charge(string $route, string $methods, $request = null): bool
    {
        $key = $this->key($route, $methods);

        if (!isset(self::$limits[$key]) || !class_exists('NTDST_RateLimiter')) {
            return true;
        }

        ['limit' => $limit, 'window' => $window] = self::$limits[$key];

        return NTDST_RateLimiter::attempt('ntdst_rest_' . md5($key . '|' . self::bucket()), $limit, $window, $request);
    }

    /**
     * Every route registered through this wrapper, with its declared permission.
     *
     * @return list<array{namespace: string, route: string, methods: list<string>, permission: string}>
     */
    public static function surface(): array
    {
        return array_values(self::$surface);
    }

    /**
     * The routes a site declared ANONYMOUS. This is the list `public_actions`
     * used to be, and the reason to assert on it is the same: anonymous reach
     * is the one property worth being able to check in one place.
     *
     * @return list<array{namespace: string, route: string, methods: list<string>, permission: string}>
     */
    public static function publicSurface(): array
    {
        return array_values(array_filter(
            self::$surface,
            static fn (array $r): bool => $r['permission'] === 'public',
        ));
    }

    /**
     * The routes whose permission is a CALLABLE, and therefore unknowable here.
     *
     * Read this together with `publicSurface()` or the pair lies to you. A
     * closure may be a careful capability check or it may be `fn() => true`,
     * and nothing at this layer can tell them apart. Reporting it as merely
     * "not public" would let a site assert "we expose nothing anonymously" and
     * be wrong. So it gets its own list, and a site's surface test answers for
     * both: no route declares public, AND these callables are ones somebody
     * has read.
     *
     * @return list<array{namespace: string, route: string, methods: list<string>, permission: string}>
     */
    public static function opaqueSurface(): array
    {
        return array_values(array_filter(
            self::$surface,
            static fn (array $r): bool => $r['permission'] === 'callable',
        ));
    }

    /** Drop the recorded surface. For tests; a process registers routes once. */
    public static function forgetSurface(): void
    {
        self::$surface = [];
    }

    /**
     * Per user when logged in, else per client IP. 'unknown' rather than an
     * empty hash: pooling every unusable address into one bucket lets a single
     * caller starve the rest.
     *
     * PUBLIC: a consumer keying its own counter on the same identity is a
     * legitimate need, and hiding it only pushes them to reimplement it wrong.
     */
    public static function bucket(): string
    {
        $userId = (int) get_current_user_id();

        if ($userId > 0) {
            return 'u' . $userId;
        }

        $ip = class_exists('NTDST_ClientIp') ? NTDST_ClientIp::detect($_SERVER) : '';

        return 'ip' . md5($ip ?: 'unknown');
    }

    /**
     * Evaluate once per request. Keyed on the request OBJECT so the entry dies
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

    /** @param bool $fatal False for a retired option: say so, but register. */
    private function refuse(string $route, string $methods, string $why, bool $fatal = true): void
    {
        $id = $this->namespace . '|' . $route . '|' . $methods . '|' . (int) $fatal;

        // Once per process: registerOne() runs on every REST request.
        if (isset(self::$reported[$id])) {
            return;
        }

        self::$reported[$id] = true;

        _doing_it_wrong(
            self::class . '::route',
            $fatal ? sprintf('Route was not registered — %s.', $why) : $why,
            $fatal ? '3.0.0' : '5.0.0',
        );

        if (!$fatal) {
            return;
        }

        if (function_exists('ntdst_log')) {
            ntdst_log('api')->error('REST route registration refused', [
                'namespace' => $this->namespace,
                'route'     => $route,
                'methods'   => $methods,
                'reason'    => $why,
            ]);
        }
    }
}

if (!function_exists('ntdst_rest')) {
    function ntdst_rest(string $namespace): NTDST_Rest
    {
        return NTDST_Rest::forNamespace($namespace);
    }
}
