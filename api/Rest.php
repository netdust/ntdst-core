<?php // api/Rest.php

/**
 * NTDST Rest — a thin front for register_rest_route().
 *
 * What it adds over raw WordPress:
 *  - a route that names no permission is INTERNAL, never anonymous by
 *    omission: it registers as 'is_user_logged_in', which is WordPress's own
 *    wp_ajax_ posture. A WRITE verb (POST, PUT, PATCH, DELETE) that names none
 *    is REFUSED outright — on a site with open registration "logged in" is
 *    "anyone" — and ->public() is the one way a route reaches anonymous;
 *  - permission shorthands ('public', 'logged_in', a capability name) so the
 *    common cases need no closure;
 *  - namespace-level defaults, so you declare permission once;
 *  - a real CORS allow-list replacing core's reflect-any-origin default,
 *    site-wide and decided by a pure function so it can be unit-tested;
 *  - a consumer's own permission callable runs once per request, not twice;
 *  - a rate limit per route, spendable from outside via charge() so a
 *    consumer's own pre-dispatch refusals are not free.
 *
 * Everything else is passed straight through to WordPress.
 *
 *   ntdst_rest('shop/v1')
 *       ->defaults(['permission' => 'logged_in'])
 *       ->cors(['https://app.example.com'])
 *       ->get('/prices', [$c, 'prices'])->public()
 *       ->get('/orders', [$c, 'index'])
 *       ->post('/orders', [$c, 'store'], ['permission' => 'edit_shop_orders', 'args' => [...]])
 *       ->delete('/orders/(?P<id>\d+)', [$c, 'destroy'], ['permission' => 'manage_options']);
 */

defined('ABSPATH') || exit;

final class NTDST_Rest
{
    /** Options this class consumes; everything else goes to WP verbatim. */
    private const OWN = ['permission', 'rate_limit', 'rate_window'];

    /**
     * The two postures WordPress can already name for itself.
     *
     * They are the functions THEMSELVES, not closures over them, because
     * rest_get_server()->get_routes() is the only place a site can read back
     * what it published — and a closure there is opaque, so "is anything on
     * this site anonymous?" stops being a question code can answer.
     */
    private const INTERNAL  = 'is_user_logged_in';
    private const ANONYMOUS = '__return_true';

    /** The verbs that mutate. None of them opens on a shorthand. */
    private const WRITE = ['POST', 'PUT', 'PATCH', 'DELETE'];

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
     * The ONE declaration this handle carries and has not registered yet — the
     * only thing ->public() is allowed to reach back and change.
     *
     * It belongs to the object a VERB returned, never to the cached namespace
     * facade. ntdst_rest('shop/v1') hands the same object to every module in
     * the request, so a pending slot living there would let one module's
     * public() publish a route another module declared, from a line that
     * module's author never sees. Each verb returns its own clone instead, so
     * public() can only reach the declaration it was chained onto, and
     * ntdst_rest('shop/v1')->public() finds nothing and says so.
     */
    private ?object $pending = null;

    /**
     * The cached namespace facade this handle was declared through, or null on
     * the facade itself. A clone keeps the link so it can still read the
     * namespace's defaults() at flush time rather than a copy frozen when the
     * verb ran.
     */
    private ?self $facade = null;

    /**
     * Declared limits, so charge() can bill a route without the caller
     * restating numbers it already wrote down.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    private static array $limits = [];

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
        // Written to the FACADE, so it reaches every route in the namespace and
        // not just the ones declared through the handle this was called on.
        $facade           = $this->facade();
        $facade->defaults = $options + $facade->defaults;

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
     * Publish the declaration this call follows. The ONE way to anonymous.
     *
     * It marks a PENDING declaration, which means before rest_api_init or from
     * inside it — the idiomatic registration point, and the one did_action()
     * cannot distinguish from "long after". Only once the hook has FINISHED is
     * there nothing left to mark: WordPress holds the route and its callback
     * cannot be swapped behind it. With nothing to mark, this refuses out loud
     * rather than returning quietly — the silent version leaves an author
     * believing a route is open when it is internal, or the reverse, and only
     * one of those two mistakes is discovered by using the site.
     *
     * It reaches the declaration it was CHAINED ONTO and no other. Called on
     * ntdst_rest('ns') itself it finds nothing, because that object is shared
     * by every module in the namespace and publishing a stranger's route is the
     * bug this shape exists to make impossible.
     *
     * A write verb stays unpublishable: registerOne() refuses it, because
     * "anyone may write" is the threat itself and not an exception to it.
     *
     * Returns $this even when it refuses, so a consumer's chain cannot fatal.
     */
    public function public(): self
    {
        // Three causes, three dedup ids, so the first misuse in a process does
        // not silence a different one on the next line.
        if ($this->pending === null) {
            if (did_action('rest_api_init') && !self::doingRestApiInit()) {
                $this->refuse(
                    '(public:after-hook)',
                    '-',
                    'public() came too late — rest_api_init has finished, WordPress holds the route and its callback cannot be swapped now',
                    false,
                    '5.0.0',
                );

                return $this;
            }

            $this->refuse(
                '(public:nothing-pending)',
                '-',
                'public() found no pending declaration — chain it onto the verb whose route it publishes, not onto ntdst_rest(), which every module in the namespace shares',
                false,
                '5.0.0',
            );

            return $this;
        }

        // A stated permission is a decision, not a suggestion. The two lines
        // contradict each other and only one direction is safe to guess: taking
        // public() as the last word would turn a route whose author named a gate
        // into an anonymous one, which is the open direction and is reachable by
        // a stray public() a merge left behind. Reported, and the gate stands.
        if (($this->pending->options['permission'] ?? null) !== null) {
            $this->refuse(
                '(public:stated-permission)',
                '-',
                'the declaration already names its own permission, so public() contradicts it — the named permission stands and the route is NOT published',
                false,
                '5.0.0',
            );

            return $this;
        }

        $this->pending->options['permission'] = 'public';

        // Cleared, so public() reaches exactly one declaration: not the route
        // declared after it, and not the same one a second time.
        $this->pending = null;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function route(string $route, string $methods, $handler, array $options = []): self
    {
        // The handle this returns owns the declaration; the facade never does.
        // Its defaults copy is emptied so the namespace has exactly one place
        // to read them from — facade()->defaults — and a clone can never drift
        // from the namespace it was declared through.
        $handle           = clone $this;
        $handle->facade   = $this->facade();
        $handle->pending  = null;
        $handle->defaults = [];

        // THREE states, and did_action() cannot tell the last two apart: the
        // counter is incremented before the first callback, so it already reads
        // 1 inside the running hook — the same value it reads an hour after the
        // hook finished. Only doing_action() separates them.
        //
        //   before  0 / false  → pending
        //   inside  1 / TRUE   → pending, flushed later in the same firing
        //   after   1 / false  → register now; the hook never fires again
        if (did_action('rest_api_init') && !self::doingRestApiInit()) {
            $this->registerOne($route, $methods, $handler, $options + $this->facade()->defaults);

            return $handle;
        }

        $declaration     = (object) ['options' => $options];
        $handle->pending = $declaration;

        // Mutable, so public() can change the permission between here and the
        // flush; and the namespace defaults are merged at FLUSH time, so a
        // defaults() call made after this route still reaches it. The
        // declaration's own permission wins the merge, which is what lets
        // ->public() beat defaults(['permission' => 'logged_in']).
        $register = function () use ($route, $methods, $handler, $declaration): void {
            $this->registerOne($route, $methods, $handler, $declaration->options + $this->facade()->defaults);
        };

        // Inside the running hook the flush goes at the LAST priority. WP_Hook
        // picks up callbacks added while it iterates, but it never walks
        // backwards — mount at the priority currently firing and the iteration
        // may already have passed it, and the route then simply never registers.
        add_action('rest_api_init', $register, self::doingRestApiInit() ? PHP_INT_MAX : 10);

        return $handle;
    }

    /** This handle's namespace facade — itself, when it IS the facade. */
    private function facade(): self
    {
        return $this->facade ?? $this;
    }

    /**
     * True only WHILE rest_api_init's callbacks run.
     *
     * function_exists() guarded because a unit harness that stubs did_action()
     * and not doing_action() must read "not running" rather than fatal.
     */
    private static function doingRestApiInit(): bool
    {
        return function_exists('doing_action') && (bool) doing_action('rest_api_init');
    }

    /**
     * Resolve the 'permission' option to what WordPress will be handed, or null
     * when the option was declared and is unusable.
     *
     * absent               → 'is_user_logged_in' — internal is the default
     * 'logged_in'          → 'is_user_logged_in'
     * 'public'             → '__return_true'
     * a global function's name → itself, as a callable
     * any other string     → fn(): bool => current_user_can($string)
     * a callable           → as given
     *
     * That fourth line is a real ambiguity, not an oversight: a capability slug
     * and a function name are both plain strings, and PHP cannot tell them
     * apart, so a capability that happens to share a name with a defined global
     * function is handed to WordPress as that function. Nothing at this layer
     * can separate them; a site with such a capability must pass a closure.
     *
     * The two shorthands resolve to the core functions as STRINGS (see the
     * INTERNAL/ANONYMOUS constants). A capability is the one case that must be
     * a closure, because core has no function that names it — so a capability
     * route reads as opaque in get_routes(), and a site that wants to answer
     * for it has to read the route's declaration.
     *
     * Returns `mixed` rather than `?callable` because a string is only of type
     * `callable` while the function it names happens to be defined, and the
     * resolution must not change with load order.
     */
    private function permission(mixed $permission): mixed
    {
        // Absent is no longer a mistake; it is the internal default. This one
        // line is the permission default of every route in this package.
        if ($permission === null) {
            return self::INTERNAL;
        }

        if (is_string($permission) && $permission !== '') {
            return match ($permission) {
                'public'    => self::ANONYMOUS,
                'logged_in' => self::INTERNAL,
                // A capability slug and a typo'd function name are
                // byte-identical, so an unrecognised string becomes a
                // capability check — false for everyone, rather than true for
                // anyone.
                default     => is_callable($permission) ? $permission : static fn (): bool => current_user_can($permission),
            };
        }

        // Declared and unusable: true, 1, an options-shaped array. `true` is
        // the dangerous one — it reads like "allowed" and would recreate the
        // fail-open if it were passed through.
        return is_callable($permission) ? $permission : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function registerOne(string $route, string $methods, $handler, array $options): void
    {
        $permission = $this->permission($options['permission'] ?? null);

        if ($permission === null) {
            $this->refuse($route, $methods, '"permission" must be a callable, a capability, "logged_in" or "public"');

            return;
        }

        // A shorthand is a POSTURE, not a gate — both name a core function that
        // holds nobody to anything in particular.
        $shorthand = $permission === self::INTERNAL || $permission === self::ANONYMOUS;

        // On a site with open registration "logged in" is "anyone", so an
        // unnamed write endpoint is world-writable, and ->public() on one is
        // that threat said out loud. The rule is about the RESOLVED posture, not
        // the spelling: absent, 'logged_in', 'public', a namespace default and
        // ->public() all land here. It refuses rather than registering a denying
        // callback, because a route that was never handed to WordPress cannot be
        // reached by a filter somebody removes later.
        if ($shorthand && array_intersect($this->verbs($methods), self::WRITE) !== []) {
            $this->refuse($route, $methods, sprintf(
                'a write verb must name a capability or hand over its own callable — "%s" is not a gate',
                $permission === self::ANONYMOUS ? 'public' : 'logged_in',
            ), true, '5.0.0');

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
            $this->refuse($route, $methods, sprintf('"%s" is retired — %s.', $name, self::RETIRED[$name]), false, '5.0.0');
            unset($options[$name]);
        }

        if (($options['rate_limit'] ?? null) !== null) {
            self::$limits[$this->key($route, $methods)] = [
                'limit'  => (int) $options['rate_limit'],
                'window' => (int) ($options['rate_window'] ?? 60),
            ];
        }

        // A shorthand goes to WordPress as the string itself, so get_routes()
        // stays readable — but only on a route with no rate_limit: a core
        // function has no side effect worth memoizing, while a budget still has
        // to be spent, so a limited route registers the guard() closure as
        // before and trades that readability for the limiter.
        $literal = $shorthand && ($options['rate_limit'] ?? null) === null;

        register_rest_route($this->namespace, $route, array_diff_key($options, array_flip(self::OWN)) + [
            'methods'             => $methods,
            'callback'            => $handler,
            'permission_callback' => $literal ? $permission : $this->guard($permission, $route, $methods, $options),
        ]);
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
        $verbs  = $this->verbs($methods);

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
        return $this->namespace . '|' . $route . '|' . implode(',', $this->verbs($methods));
    }

    /**
     * WP's methods option ('GET', or 'GET,POST') as normalized verbs.
     *
     * @return list<string>
     */
    private function verbs(string $methods): array
    {
        return array_map('strtoupper', array_map('trim', explode(',', $methods)));
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

    /**
     * @param bool   $fatal False when the route still registers — a retired
     *                      option, or a public() the declaration overrules. The
     *                      message is then used as written, because "Route was
     *                      not registered" would be a lie.
     * @param ?string $since The version whose rule this refusal enforces, which
     *                       is what WordPress prints; it is not always this
     *                       class's own age. Null takes the floor below, and
     *                       that floor is written at the _doing_it_wrong() call
     *                       rather than in this signature because the package's
     *                       own release-marker audit reads the call.
     */
    private function refuse(string $route, string $methods, string $why, bool $fatal = true, ?string $since = null): void
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
            $since ?? '3.0.0',
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
