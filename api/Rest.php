<?php // api/Rest.php

/**
 * NTDST Rest — a thin front for register_rest_route().
 *
 * What it adds over raw WordPress:
 *  - a route that names no permission is INTERNAL, never anonymous by
 *    omission: it registers as 'is_user_logged_in', which is WordPress's own
 *    wp_ajax_ posture. Only a READ verb may reach a posture that way; every
 *    other verb that names no capability is REFUSED outright — on a site with
 *    open registration "logged in" is "anyone" — and ->public() is the one way
 *    a route reaches anonymous;
 *  - permission shorthands ('public', 'logged_in', a capability name) so the
 *    common cases need no closure;
 *  - namespace-level defaults, so you declare permission once;
 *  - CORS declared here and KEPT BY WORDPRESS: cors() adds to the
 *    allowed_http_origins list WordPress already keeps, and the decision asks
 *    is_allowed_http_origin() over it. What core gets wrong is only the REST
 *    emitter, which reflects any origin back with credentials; that one is
 *    replaced, site-wide, by a pure function so it can be unit-tested;
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
     * The two postures WordPress can already name for itself, registered as
     * the functions THEMSELVES so rest_get_server()->get_routes() stays
     * readable — a closure there is opaque, and "is anything on this site
     * anonymous?" stops being a question code can answer.
     */
    private const INTERNAL  = 'is_user_logged_in';
    private const ANONYMOUS = '__return_true';

    /**
     * The verbs that may default to a posture — an ALLOW-LIST, because a deny
     * list of the four writes reads every custom verb (PURGE, LINK, SEARCH) as
     * safe, and a custom verb is exactly where a destructive action hides.
     */
    private const READ = ['GET', 'HEAD', 'OPTIONS'];

    /** Options this class used to accept, mapped to what replaced them. */
    private const RETIRED = [
        'cors' => 'declare it once with ntdst_rest(...)->cors([...]) — it is site-wide now',
        'before_dispatch' => 'filter rest_pre_dispatch and bill with ->charge($route, $methods, $request)',
    ];

    /**
     * Why a public() call had nothing to publish, in the author's words. The
     * cause is part of the message because _doing_it_wrong() is the only thing
     * a consumer reads, and "public() did nothing" is not a remediation.
     */
    private const PUBLIC_REFUSALS = [
        'after-hook'         => 'public:after-hook — rest_api_init has finished, WordPress holds the route and its callback cannot be swapped now',
        'nothing-pending'    => 'public:nothing-pending — chain public() onto the verb whose route it publishes, not onto ntdst_rest(), which every module in the namespace shares',
        'already-registered' => 'public:already-registered — this declaration has already been handed to WordPress, so its permission can no longer be changed',
        'stated-permission'  => 'public:stated-permission — the declaration already names its own permission, so public() contradicts it; the named permission stands and the route is NOT published',
    ];

    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<string, bool> Refusals already reported this process. */
    private static array $reported = [];

    /**
     * What WordPress has no answer for, merged from every namespace that
     * declared one. The ORIGINS are not here: they go to WordPress's own
     * allowed_http_origins list (INV-5 — this class keeps no table WordPress
     * already keeps). Two allow-lists is one too many, because the one
     * WordPress reads would drift from the one we check.
     *
     * @var array{declared: bool, max_age: int}
     */
    private static array $cors = ['declared' => false, 'max_age' => 0];

    /**
     * Declared origin => whether the declaration that named it asked for
     * credentials. INPUT for the two filters below, never consulted for
     * allowed-ness — that question has one address, is_allowed_http_origin().
     * It is a map rather than a list because credentials belong to the module
     * that asked for them, not to the site.
     *
     * @var array<string, bool>
     */
    private static array $corsOrigins = [];

    /** @var list<callable> Resolvers, asked per request about one origin. */
    private static array $corsResolvers = [];

    /** @var array<string, array<string, mixed>> Option defaults, per namespace. */
    private static array $defaults = [];

    /**
     * The ONE declaration this handle carries and has not registered yet — the
     * only thing ->public() is allowed to reach back and change.
     *
     * It belongs to the object a VERB returned, never to the cached namespace
     * facade. ntdst_rest('shop/v1') hands the same object to every module in
     * the request, so a pending slot living there would let one module's
     * public() publish a route another module declared, from a line that
     * module's author never sees.
     */
    private ?object $pending = null;

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
     * Options every route in this namespace inherits. Per-route options win,
     * and a route reads them as they stand WHEN ITS VERB RUNS (see route()).
     *
     * A default may set a POSTURE ('logged_in', a capability); it may not OPEN.
     * defaults() is the most distant place a permission can be written from —
     * one line in a bootstrap file, inherited by routes added months later by
     * someone who never read it — and a callable default would additionally
     * satisfy the "this route named its own gate" rule for every unnamed write
     * in the namespace. An opening default is refused and dropped; the other
     * defaults are kept, because taking show_in_index away over an unrelated
     * key punishes the wrong line.
     *
     * @param array<string, mixed> $options
     */
    public function defaults(array $options): self
    {
        $permission = $options['permission'] ?? null;

        if ($permission !== null && (!is_string($permission) || $permission === '' || $permission === 'public')) {
            $this->refuse(
                '(defaults)',
                '-',
                'defaults:opening — a namespace default may narrow the posture ("logged_in", a capability) but never open it, so the "permission" default was dropped',
                false,
                '5.0.0',
                'defaults',
                true,
            );

            unset($options['permission']);
        }

        // Written per NAMESPACE, so it reaches every route declared there and
        // not just the ones declared through the handle this was called on.
        self::$defaults[$this->namespace] = $options + (self::$defaults[$this->namespace] ?? []);

        return $this;
    }

    /**
     * Declare the cross-origin policy.
     *
     * The origins go where WordPress already keeps origins: they are ADDED to
     * `allowed_http_origins`, the list `is_allowed_http_origin()` answers over
     * (wp-includes/http.php). This class keeps none of its own.
     *
     * SCOPED TO REST, and that is the whole of threat row #9. That list is
     * site-wide: admin-ajax.php, admin-post.php and the customizer all call
     * send_origin_headers(), which grants Access-Control-Allow-Credentials:
     * true to every allowed origin — unconditionally, whatever $credentials
     * says here. A declared origin could therefore fetch
     * admin-ajax.php?action=rest-nonce with the victim's cookies and read the
     * answer. So the declaration applies only while WordPress is serving a REST
     * request; those surfaces keep WordPress's own defaults.
     *
     * A CALLABLE is a per-request question, so it goes on the other end of the
     * same function: `allowed_http_origin`, which is handed THE ORIGIN BEING
     * ASKED ABOUT. It only ever ADDS: a resolver that says no must not take
     * WordPress's own origins away with it. Credentials follow a NAMED origin,
     * so a resolver never grants them.
     *
     * This also REMOVES core's rest_send_cors_headers, which echoes ANY origin
     * back with Access-Control-Allow-Credentials: true — meaning any site can
     * read a logged-in visitor's authenticated responses. The replacement is
     * site-wide and fails closed: an origin WordPress does not allow gets no
     * CORS headers at all, on every REST route including other plugins'. That
     * is the intended trade; if a plugin on this site needs its own
     * cross-origin policy, it must be declared here too.
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
            // '' is not an origin and 'null' identifies nobody — a file:// page
            // and a sandboxed iframe both send it. A non-string could never
            // match under WordPress's strict in_array() and has no business on
            // a site-wide list either way.
            $declared = array_values(array_filter(
                $origins,
                static fn($origin): bool => is_string($origin) && $origin !== '' && $origin !== 'null',
            ));

            if (in_array('*', $declared, true)) {
                $this->refuse(
                    '(cors)',
                    '-',
                    '"*" was refused as an allow-list entry: allowed_http_origins is site-wide, so a wildcard hands every origin this site\'s REST surface. Write the origins out, or hand cors() a resolver',
                    false,
                    '5.0.0',
                    'cors',
                );

                // The WHOLE declaration goes down, not the wildcard alone:
                // half-honouring a bad list is the worst outcome, because the
                // author reads "some of it worked" and never fixes it. The
                // emitter is still mounted below — abstaining would leave
                // core's reflect-any-origin handler standing, which is the
                // very '*' this just refused.
                $declared    = [];
                $credentials = false;
                $maxAge      = 0;
            }

            // A set: WordPress reads the list with in_array(), so a second copy
            // changes no answer. Credentials are OR'd per origin, so the module
            // that asked for them grants them to the origin IT named and to no
            // other module's.
            foreach ($declared as $origin) {
                self::$corsOrigins[$origin] = $credentials || (self::$corsOrigins[$origin] ?? false);
            }
        } else {
            self::$corsResolvers[] = $origins;
        }

        self::$cors = ['declared' => true, 'max_age' => max($maxAge, self::$cors['max_age'])];

        // Mounted unconditionally, and mounted TOGETHER with the swap below:
        // widening the site-wide list while core's reflect-any-origin emitter
        // is still on the bus is the open direction. Named static callbacks get
        // a stable id from _wp_filter_build_unique_id(), so WordPress
        // de-duplicates these itself and a second cors() call is free — which
        // is why they are methods rather than closures over the declaration.
        add_filter('allowed_http_origins', [self::class, 'filterAllowedOrigins'], 10, 1);
        add_filter('allowed_http_origin', [self::class, 'filterAllowedOrigin'], 10, 2);

        // SCHEDULED for priority 15 whenever 15 is still ahead — WP_Hook picks
        // up a callback added at a priority it has not reached yet. Swapping
        // earlier is a no-op: core does not mount rest_send_cors_headers()
        // before the request either, it is added by rest_api_default_filters()
        // at rest_api_init:10 (wp-includes/rest-api.php). A cors() at priority
        // 5 would remove a handler that is not on the bus yet, and core would
        // mount it five priorities later — reflect-any-origin for the whole
        // request, over a list this same call has already widened.
        $timing   = self::timing();
        $priority = self::hookPriority();

        if ($timing === 'before' || ($timing === 'inside' && $priority !== null && $priority < 15)) {
            add_action('rest_api_init', [self::class, 'mountCors'], 15);

            return $this;
        }

        // At 15 or later, or after the hook: 15 is behind us, WP_Hook never
        // walks backwards, and core has mounted its emitter. Swap here.
        self::mountCors();

        return $this;
    }

    /** Take core's reflect-any-origin handler off the bus and put ours on it. */
    public static function mountCors(): void
    {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

        // The LAST word: rest_pre_serve_request is a filter any plugin can
        // join, and the last writer of a header wins on the wire.
        add_filter('rest_pre_serve_request', [self::class, 'sendCors'], PHP_INT_MAX, 1);
    }

    /**
     * Add the declared origins to WordPress's own allow-list, on REST requests.
     *
     * WordPress's entries stay first and in their order; ours follow, once
     * each. Nothing else is added — this list also answers for admin-ajax,
     * which is why it is untouched there (see cors()).
     *
     * @param mixed $origins
     * @return list<string>
     */
    public static function filterAllowedOrigins($origins): array
    {
        $list = is_array($origins) ? array_values($origins) : [];

        if (!self::servingRest()) {
            return $list;
        }

        foreach (array_keys(self::$corsOrigins) as $origin) {
            if (!in_array((string) $origin, $list, true)) {
                $list[] = (string) $origin;
            }
        }

        return $list;
    }

    /**
     * Let a declared resolver answer for the origin WordPress was asked about,
     * on REST requests.
     *
     * ADDITIVE ONLY, in both directions: an origin WordPress already allows is
     * returned untouched — a resolver grants, it never revokes — and a
     * resolver that declines leaves the verdict exactly as WordPress made it.
     * Only `true` grants; on an allow-list an ambiguous yes is a no.
     *
     * @param mixed $allowed The origin when WordPress allows it, '' when not.
     * @param mixed $origin  The origin asked about, or null for the request's own.
     * @return mixed
     */
    public static function filterAllowedOrigin($allowed, $origin = null)
    {
        if ((is_string($allowed) && $allowed !== '') || !self::servingRest()) {
            return $allowed;
        }

        $candidate = is_string($origin) && $origin !== ''
            ? $origin
            : (function_exists('get_http_origin') ? (string) get_http_origin() : '');

        // Same two refusals corsDecision() makes, for the same reasons: an
        // absent origin is not a question, and 'null' is not an identity.
        if ($candidate === '' || $candidate === 'null') {
            return $allowed;
        }

        foreach (self::$corsResolvers as $resolver) {
            if (self::resolverAllows($resolver, $candidate)) {
                return $candidate;
            }
        }

        return $allowed;
    }

    /**
     * Ask one resolver, and survive it.
     *
     * A resolver is consumer code called from inside a WordPress filter on a
     * request already being served: one that talks to a database or an HTTP API
     * will throw eventually, and an exception here takes the whole request down
     * — ajax surfaces included. It fails closed and leaves a trace.
     *
     * @param callable $resolver fn(string $origin): bool — a full-string match on
     *                           'scheme://host[:port]', never a substring, and it
     *                           must not throw.
     */
    private static function resolverAllows(callable $resolver, string $origin): bool
    {
        try {
            return $resolver($origin) === true;
        } catch (Throwable $error) {
            if (function_exists('ntdst_log')) {
                ntdst_log('api')->error('CORS resolver threw — the origin is refused', [
                    'origin' => $origin,
                    'error'  => $error->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * True only while WordPress is serving a REST request.
     *
     * function_exists() guarded because the function arrived in WP 6.5, and the
     * missing-function answer must be "not REST" — the declaration then widens
     * nothing, which is the closed direction.
     */
    private static function servingRest(): bool
    {
        return function_exists('wp_is_serving_rest_request') && (bool) wp_is_serving_rest_request();
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
     * It marks a PENDING declaration — one that has not yet reached
     * register_rest_route() — and it reaches the declaration it was CHAINED
     * ONTO and no other. Every other case refuses OUT LOUD rather than
     * returning quietly: a silent no-op leaves an author believing a route is
     * open when it is internal, or the reverse, and only one of those two
     * mistakes is discovered by using the site.
     *
     * A verb outside GET, HEAD and OPTIONS stays unpublishable: registerOne()
     * refuses it, because "anyone may write" is the threat itself and not an
     * exception to it.
     *
     * Returns $this even when it refuses, so a consumer's chain cannot fatal.
     */
    public function public(): self
    {
        $cause = match (true) {
            $this->pending === null    => self::timing() === 'after' ? 'after-hook' : 'nothing-pending',
            $this->pending->registered => 'already-registered',
            ($this->pending->options['permission'] ?? null) !== null => 'stated-permission',
            default                    => null,
        };

        if ($cause !== null) {
            // Reported PER CALL SITE (the last argument): two misuses in one
            // namespace are two things to fix, and a namespace-keyed report
            // tells the author about the first one only.
            $this->refuse(
                '(public)',
                '-',
                self::PUBLIC_REFUSALS[$cause],
                false,
                '5.0.0',
                'public',
                true,
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
        $handle          = clone $this;
        $handle->pending = null;

        // The namespace defaults are SNAPSHOT here, not read at flush time: a
        // defaults() call made later — by another module, in another file —
        // must not reach back and rewrite a route already declared. They are
        // kept beside the stated options rather than merged into them, so
        // ->public() can still tell "this route named a permission" from "this
        // route inherited one".
        $inherited = self::$defaults[$this->namespace] ?? [];
        $timing    = self::timing();

        if ($timing === 'after') {
            $this->registerOne($route, $methods, $handler, $options + $inherited);

            return $handle;
        }

        if ($timing === 'inside' && self::hookPriority() === PHP_INT_MAX) {
            // WP_Hook re-reads its callback list as it walks, but it never walks
            // backwards, and PHP_INT_MAX is where the flush below goes. A
            // consumer already hooked there declares into a flush that can never
            // run: the route would vanish with no error and 404 in production.
            $this->refuse(
                $route,
                $methods,
                'rest_api_init is already running at PHP_INT_MAX, which is where this declaration would be flushed — nothing mounted now can still run, so hook earlier (priority 10 is the documented place)',
                true,
                '5.0.0',
            );

            return $handle;
        }

        $declaration     = (object) ['options' => $options, 'inherited' => $inherited, 'registered' => false];
        $handle->pending = $declaration;

        // Mutable, so public() can change the permission between here and the
        // flush. The MARK is what makes a declaration a statement rather than
        // an instruction to be replayed: rest_api_init is reached more than
        // once in ordinary use (rest_get_server() fires it while building the
        // server), and a route handed to WordPress twice is counted twice by
        // every audit that reads the register back.
        $register = function () use ($route, $methods, $handler, $declaration): void {
            if ($declaration->registered) {
                return;
            }

            $declaration->registered = true;

            $this->registerOne($route, $methods, $handler, $declaration->options + $declaration->inherited);
        };

        add_action('rest_api_init', $register, $timing === 'inside' ? PHP_INT_MAX : 10);

        return $handle;
    }

    /**
     * Where this call stands relative to rest_api_init — the ONE place the
     * question is asked.
     *
     *   before  did_action 0 / doing_action false  → queue it, flush at 10
     *   inside  did_action 1 / doing_action TRUE   → queue it, flush at the end
     *   after   did_action 1 / doing_action false  → do it now; the hook is done
     *
     * did_action() alone cannot separate the last two: the counter is
     * incremented before the first callback, so it reads 1 inside the running
     * hook and the same 1 an hour later. function_exists() guarded so a harness
     * that stubs one and not the other reads a state instead of fatalling.
     */
    private static function timing(): string
    {
        if (function_exists('doing_action') && doing_action('rest_api_init')) {
            return 'inside';
        }

        return function_exists('did_action') && did_action('rest_api_init') ? 'after' : 'before';
    }

    /**
     * The priority rest_api_init is firing at, when WordPress can say.
     *
     * `null` — no WP_Hook, or `current_priority()` answering `false`
     * off-iteration (class-wp-hook.php:404) — reads as "assume 15 is behind
     * us": cors() swaps now instead of scheduling into a priority that may
     * have passed, and route() reports no collision it cannot prove.
     */
    private static function hookPriority(): ?int
    {
        $hook = $GLOBALS['wp_filter']['rest_api_init'] ?? null;

        if (!is_object($hook) || !method_exists($hook, 'current_priority')) {
            return null;
        }

        $priority = $hook->current_priority();

        return is_int($priority) ? $priority : null;
    }

    /**
     * The consumer line that reached us — in the message, so an author reading
     * one refusal knows which of their calls made it, and in the dedup id.
     *
     * Four frames is the whole distance — this call, refuse(), the consumer's
     * public()/defaults() — and refuse() reaches it only once a refusal is a
     * candidate. Unbounded, it walked the whole WordPress stack to find a
     * frame it already knew was three up.
     */
    private static function callSite(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            if (isset($frame['file']) && $frame['file'] !== __FILE__) {
                return basename((string) $frame['file']) . ':' . ($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }

    /**
     * Resolve the 'permission' option to what WordPress will be handed, or null
     * when the option was declared and is unusable.
     *
     * absent           → 'is_user_logged_in' — internal is the default
     * 'logged_in'      → 'is_user_logged_in'
     * 'public'         → '__return_true'
     * any other string → fn(): bool => current_user_can($string)
     * a callable       → as given
     *
     * A STRING IS A CAPABILITY, with no is_callable() check in front of it.
     * Capability slugs and function names are the same bytes, and WordPress
     * itself ships edit_post(), delete_plugins() and activate_plugins() — every
     * one of them a capability slug too, and every one of them loaded on an
     * admin request. Asking is_callable() first would run somebody's admin
     * function as an authorization check and take its return value as the
     * answer; 'wp_is_json_request' would admit every REST client alive. A
     * consumer with a gate of its own passes the callable itself.
     *
     * Returns `mixed` rather than `?callable` because the two shorthands
     * resolve to core function NAMES, which are of type `callable` only while
     * the function they name happens to be defined.
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
                default     => static fn (): bool => current_user_can($permission),
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
        if ($shorthand && array_diff(self::verbs($methods), self::READ) !== []) {
            $this->refuse($route, $methods, sprintf(
                'only %s may carry a posture — every other verb must name a capability or hand over its own callable, and "%s" is not a gate',
                implode(', ', self::READ),
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
     * The policy decision, as headers to set and to remove.
     *
     * Separate from the emitter because `header()` is invisible to a unit
     * test: a policy observable only over a socket is one nobody can
     * unit-test. Allowed-ness is neither decided here nor carried in the
     * policy — it is asked of is_allowed_http_origin(), one source of truth
     * for the whole site, which compares byte-exact against the full
     * `scheme://host[:port]`: never a substring, never case-folded. A
     * leftover 'origins' key in $policy decides nothing, in either direction.
     *
     * Access-Control-Allow-Headers is never sent, which is core's gap too:
     * WP_REST_Server::serve_request() answers preflights with the headers the
     * request asked for, and this decision does not join that conversation.
     *
     * @param array{credentials: bool, max_age: int} $policy
     * @return array{set: list<string>, remove: list<string>}
     */
    public static function corsDecision(?string $origin, array $policy): array
    {
        $revoke = ['set' => [], 'remove' => ['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials']];

        // Refused BEFORE WordPress is asked, rather than by it. `Origin: null`
        // identifies nobody — and it is not a question worth putting to a
        // filter another plugin can answer 'yes' to. An absent origin is worse:
        // is_allowed_http_origin(null) falls back to get_http_origin() and
        // answers about whatever the request carries, which is a different
        // question than the one in hand.
        if ($origin === null || $origin === '' || $origin === 'null') {
            return $revoke;
        }

        // Asked ONCE, with the exact origin. Read for truthiness the way core's
        // own callers read it: the function returns the origin when it allows
        // and '' when it does not.
        if (!is_allowed_http_origin($origin)) {
            return $revoke;
        }

        // The origin is attacker-controlled and it is about to be written into
        // a response header. sanitize_url() is what core's own
        // send_origin_headers() runs it through first.
        $set = [
            'Access-Control-Allow-Origin: ' . sanitize_url($origin),
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
     * The decision for this origin, or null when no policy was declared.
     *
     * Credentials are read PER ORIGIN, from the declaration that named it: one
     * site-wide flag would hand a third-party origin whitelisted for a public
     * feed the credentials another module asked for. max_age is shared and
     * takes the highest — it is a cache hint, and the worst a longer one does
     * is make a preflight rarer.
     *
     * A declaration that granted nothing — an empty list, a refused wildcard —
     * still decides. It fails CLOSED, which means overriding core's headers,
     * not abstaining and leaving them standing.
     *
     * @return array{set: list<string>, remove: list<string>}|null
     */
    public static function corsDecisionFor(?string $origin): ?array
    {
        if (!self::$cors['declared']) {
            return null;
        }

        return self::corsDecision($origin, [
            'credentials' => (bool) (self::$corsOrigins[(string) $origin] ?? false),
            'max_age'     => self::$cors['max_age'],
        ]);
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
        $verbs  = self::verbs($methods);

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
        return $this->namespace . '|' . $route . '|' . implode(',', self::verbs($methods));
    }

    /**
     * WP's methods option ('GET', or 'get , post') as normalized verbs.
     *
     * @return list<string>
     */
    private static function verbs(string $methods): array
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
     * Say it once, and say it where it can be read.
     *
     * The dedup id carries the REASON as well as the route: a route-only key
     * swallows the SECOND, different fault on that route, and the author fixes
     * the one they were told about while the route still does not exist.
     *
     * Both channels, because neither is enough alone: _doing_it_wrong() is
     * SUPPRESSED inside a REST request (core's doing_it_wrong_trigger_error is
     * false there), so a production refusal is invisible without the log.
     *
     * @param bool    $fatal False when the route still registers — a retired
     *                       option, a refused CORS entry, a public() the
     *                       declaration overrules. The message is then used as
     *                       written, because "Route was not registered" would
     *                       be a lie.
     * @param ?string $since The version whose rule this refusal enforces, which
     *                       is what WordPress prints; it is not always this
     *                       class's own age.
     * @param string  $from  The method that refused, so the report names the
     *                       call the author has to look at.
     * @param bool $perCallSite True for public() and defaults(): a consumer
     *                       writes those twice in one namespace from two files
     *                       that never met, and the second misuse is the one in
     *                       the file its author is editing, so the caller's line
     *                       joins the id. Everything else is settled by the
     *                       cheap id and never pays for a backtrace.
     */
    private function refuse(
        string $route,
        string $methods,
        string $why,
        bool $fatal = true,
        ?string $since = null,
        string $from = 'route',
        bool $perCallSite = false
    ): void {
        $id = implode('|', [$this->namespace, $route, $methods, (int) $fatal, $why]);

        // The cheap test FIRST, and for most refusals the only one.
        if (isset(self::$reported[$id])) {
            return;
        }

        if ($perCallSite) {
            // The site-less id is deliberately never RECORDED for these: a
            // second call from a different line must still report, and a third
            // from the first line is settled by the refined id below.
            $at   = self::callSite();
            $id  .= '|' . $at;
            $why .= ' (called at ' . $at . ')';

            if (isset(self::$reported[$id])) {
                return;
            }
        }

        self::$reported[$id] = true;

        _doing_it_wrong(
            self::class . '::' . $from,
            $fatal ? sprintf('Route was not registered — %s.', $why) : $why,
            $since ?? '3.0.0',
        );

        if (!function_exists('ntdst_log')) {
            return;
        }

        $entry = [
            'namespace' => $this->namespace,
            'route'     => $route,
            'methods'   => $methods,
            'reason'    => $why,
        ];

        if ($fatal) {
            ntdst_log('api')->error('REST route registration refused', $entry);

            return;
        }

        ntdst_log('api')->warning('REST declaration refused', $entry);
    }
}

if (!function_exists('ntdst_rest')) {
    function ntdst_rest(string $namespace): NTDST_Rest
    {
        return NTDST_Rest::forNamespace($namespace);
    }
}
