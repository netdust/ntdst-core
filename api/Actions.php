<?php

declare(strict_types=1);

/**
 * NTDST action router.
 *
 * A same-origin dispatcher for `ntdst/api_data/{action}` filters, and NOTHING
 * ELSE. It decides five things — origin, rate limit, nonce, whether this actor
 * may reach this action, and which filter to fire — and holds no opinion about
 * anyone's data. Actions are registered by the services that know what their
 * rows mean.
 *
 * That boundary is the correction of 2026-08-07. This file used to ship
 * "example" data actions (`get_recent_posts`, `search_posts`, `search_users`)
 * and make two of them anonymous by default, which handed every site on this
 * framework a generic, caller-parameterised query surface it never asked for —
 * and then required a gate that re-derived WordPress's visibility semantics
 * from the registry to defend it. Five consecutive generations of security
 * review went into that gate. The actions had zero, zero and one consumer
 * respectively; the one moved to `NTDST_RelationField`, and the gate was
 * deleted with the surface.
 *
 * Routes:
 * - POST /wp-json/ntdst/v1/get_nonce
 * - POST /wp-json/ntdst/v1/action
 * - GET  /wp-json/ntdst/v1/download
 *
 * Conventions:
 *  - Filter prefixes: `ntdst/api/*` for new code. `netdust_trusted_proxies`
 *    is historical — do not propagate that naming.
 *  - Anonymous exposure is a SITE decision, made only via the
 *    `ntdst/api/public_actions` filter. A handler listed there MUST NOT assume
 *    the caller is authenticated and MUST treat all input as untrusted.
 *  - The `_files` key in request params is reserved for uploaded files and is
 *    overwritten unconditionally by `get_request_params()`. Do not pass
 *    `_files` as data.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class NTDST_Actions
{
    private const REST_NAMESPACE = 'ntdst/v1';

    /**
     * Rate limiting settings
     */
    private const RATE_LIMIT = 30; // Max requests per window
    private const RATE_WINDOW = 60; // Window in seconds

    /**
     * The two dispatch filter prefixes, spelled ONCE.
     *
     * The permission callback and the handler must ask the same question of
     * the same string: the permission callback decides whether an action is
     * registered (F1 — before any bucket key is built), and the handler
     * dispatches to it. Two spellings of these names is how those two answers
     * drift apart. The names themselves are unchanged from v2 — adopters'
     * handlers hang off them.
     */
    private const DATA_FILTER = 'ntdst/api_data/';
    private const DOWNLOAD_FILTER = 'ntdst/api_download/';

    /**
     * Actions reachable WITHOUT authentication.
     *
     * EMPTY BY DEFAULT, AND THE FRAMEWORK NEVER ADDS TO IT. Anonymous exposure
     * is a decision only a site can make, because only a site knows what its
     * data means — so it is made in one place, the `ntdst/api/public_actions`
     * filter, which is where INV-2 already said it was made.
     *
     * This list used to ship `get_recent_posts`, `search_posts` and
     * `send_magic_link`, which opted EVERY ntdst-core site into an anonymous,
     * caller-parameterised query surface it never asked for. Ground-truthed in
     * this repo before the change: `get_recent_posts` had zero consumers,
     * `send_magic_link` had no handler anywhere, and `search_posts`'s only
     * consumer was the ADMIN relation autocomplete (`NTDST_RelationField`,
     * `admin_only => true`) — authenticated by definition. Nothing needed them
     * to be anonymous, and the cost of pretending otherwise was five
     * consecutive generations of security review (T16, T23, T24, T25, plus the
     * parked T30/T31) spent defending a surface with no user.
     *
     * It was also a SECOND DOOR: a site could harden its own handler — the
     * `release` action grew a capability check and an explicit allow-list
     * projection over three tasks — while these defaults served the same rows
     * raw next to it, bypassing that work entirely.
     *
     * RETIRED 2026-08-07, with explicit sanction. `send_magic_link` went first
     * (it named no handler in any tree). `get_recent_posts` and `search_users`
     * are DELETED — zero consumers each. `search_posts` MOVED to the one
     * consumer it ever had, as `NTDST_RelationField::handleRelationSearch()`,
     * where the question it must answer is answerable: not "may this anonymous
     * caller query the type they named", but "is this type a declared relation
     * TARGET, and may this authenticated caller edit others' posts of it".
     *
     * That is what let the gate stack be DELETED rather than fixed —
     * `filterQueryablePostTypes()`, `canQueryPostType()`,
     * `canQueryUnpublishedMedia()`, `nonViewableMediaParentIds()` (T30's
     * uncached full-attachment scan) and T31's fail-open `post_parent__not_in`
     * sibling. Every one existed only because an anonymous caller could reach
     * a caller-named query. T30 and T31 are closed by deletion, not by fix.
     */
    private array $public_actions = [];

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);

        // No data actions are registered here. The framework ships a ROUTER —
        // origin check, rate limit, nonce, auth gate, dispatch — and no opinion
        // about anyone's data. Actions belong to the services that know what
        // their rows mean.
        //
        // T04: no cache-invalidation hooks either. The data layer keeps no
        // cache of its own, and core already invalidates its post, post_meta
        // and object-term entries on save/delete/trash.
    }

    // =========================================================================
    // REST ROUTE REGISTRATION
    // =========================================================================

    public function register_routes(): void
    {
        $this->register_nonce_endpoint();
        $this->register_action_endpoint();
        $this->register_download_endpoint();
    }

    private function register_nonce_endpoint(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/get_nonce', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_get_nonce'],
            'permission_callback' => [$this, 'check_nonce_permission'],
            'args'                => [
                'action' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    private function register_action_endpoint(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/action', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_action'],
            'permission_callback' => [$this, 'check_action_permission'],
            'args'                => [
                'action' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'nonce' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * The v2.3 GET download entry — a sibling of /action for file downloads.
     *
     * GET (not POST) because a download is a plain <a href> navigation: the
     * browser sends no request body and no Origin header. The nonce carried in
     * the URL is the CSRF gate (an attacker's cross-site context cannot mint a
     * valid per-action nonce), which is why check_download_permission() does
     * NOT apply the Origin check /action uses — see that method.
     */
    private function register_download_endpoint(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/download', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_download'],
            'permission_callback' => [$this, 'check_download_permission'],
            'args'                => [
                'action' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'nonce' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    // =========================================================================
    // PERMISSION & SECURITY CALLBACKS
    // =========================================================================

    /**
     * Check permission for nonce endpoint
     * Only allows nonce generation for public actions or logged-in users
     *
     * @return WP_Error|bool WP_Error(rate_limited, 429) when the rate limit is
     *                       exceeded — WP REST honours the error's status, so
     *                       the denial reaches the wire as 429 and a
     *                       legitimate client knows to back off. Auth denials
     *                       stay bare `false` (401 rest_forbidden),
     *                       deliberately indistinguishable.
     */
    public function check_nonce_permission(WP_REST_Request $request): WP_Error|bool
    {
        // Resolve action first so the rate limit can be per-action.
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? $request->get_param('action') ?? '');

        // Get public actions dynamically (allows late registration)
        $public_actions = apply_filters('ntdst/api/public_actions', $this->public_actions);
        $isPublic = in_array($action, $public_actions, true);

        // REGISTRATION BEFORE THE BUCKET (F1). A nonce is minted for BOTH
        // dispatch surfaces, so either registration form counts here — a
        // download-only action never mounts an `ntdst/api_data/` filter.
        if (!$this->isRegisteredAction($action, $isPublic, [self::DATA_FILTER, self::DOWNLOAD_FILTER])) {
            return false;
        }

        // Auth BEFORE the bucket (M2), as on the dispatch doors: an anonymous
        // caller who cannot mint this nonce may not make the site write a
        // transient by asking.
        if (!$isPublic && !is_user_logged_in()) {
            return false;
        }

        if (!$this->checkRateLimit($action, $request)) {
            return $this->rateLimitedError();
        }

        return true;
    }

    /**
     * Check permission for action endpoint
     * Verifies origin and applies rate limiting
     *
     * @return WP_Error|bool WP_Error(rate_limited, 429) when the rate limit is
     *                       exceeded; auth/origin denials stay bare `false`
     *                       (401 rest_forbidden) — see check_nonce_permission().
     */
    public function check_action_permission(WP_REST_Request $request): WP_Error|bool
    {
        // POST /action verifies the request Origin (CSRF) — a fetch() carries
        // an Origin header the browser sets and cannot be forged cross-site.
        return $this->checkDispatchPermission($request, verifyOrigin: true, dispatchFilter: self::DATA_FILTER);
    }

    /**
     * The shared dispatch gate for /action and /download: rate limit, optional
     * Origin/CSRF check, then the anonymous-only-for-public-actions auth gate.
     * ONE policy, two entry points — /action opts into the Origin check, the
     * GET /download opts out (a <a href> navigation sends no Origin; its nonce
     * is the CSRF gate instead).
     *
     * @return WP_Error|bool WP_Error(429) when rate-limited; bare false for
     *                       origin/auth denials (401 rest_forbidden).
     */
    private function checkDispatchPermission(
        WP_REST_Request $request,
        bool $verifyOrigin,
        string $dispatchFilter,
    ): WP_Error|bool {
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? '');

        $public_actions = apply_filters('ntdst/api/public_actions', $this->public_actions);
        $isPublic = in_array($action, $public_actions, true);

        // REGISTRATION BEFORE THE BUCKET (F1). Until this line has passed, the
        // action is nothing but a request parameter, and a request parameter
        // may not name a bucket — see isRegisteredAction().
        if (!$this->isRegisteredAction($action, $isPublic, [$dispatchFilter])) {
            return false;
        }

        // Auth gate, symmetric with check_nonce_permission: anonymous
        // requests may only dispatch PUBLIC actions. Previously this relied
        // indirectly on "anon can't mint a nonce for a non-public action" +
        // per-handler login checks — a handler that forgot its own check,
        // combined with any nonce leak, became an exposed surface.
        //
        // It runs BEFORE the limiter (M2). A caller who can never dispatch
        // this action must not be able to make the site write storage on
        // demand: charging first meant every doomed anonymous request left 2
        // wp_options rows behind, reaped only by a daily cron. Reading the
        // current user costs nothing and answers the question outright.
        if (!$isPublic && !is_user_logged_in()) {
            return false;
        }

        // Rate limiting check (per-action so sensitive actions can be tighter)
        if (!$this->checkRateLimit($action, $request)) {
            return $this->rateLimitedError();
        }

        // CSRF: verify request origin (POST /action only — see method doc).
        //
        // This one stays BELOW the limiter, deliberately. A caller who fails
        // it has already passed the auth gate — it is a real session being
        // driven from another site — and a CSRF flood is exactly the traffic
        // a throttle should charge. Hoisting it would hand an attacker an
        // uncharged path through the same code.
        if ($verifyOrigin && !$this->verifyOrigin()) {
            return false;
        }

        return true;
    }

    /**
     * Is this action REGISTERED — the question that must be answered before a
     * bucket key exists (F1).
     *
     * `checkRateLimit()` runs from `permission_callback`, which WordPress calls
     * before the handler and before any nonce check. With the raw `action`
     * parameter folded straight into the transient key, one varied character
     * per request bought a FRESH bucket: the site's only API throttle was
     * defeated by a for-loop, and every request wrote 2 `wp_options` rows that
     * only a daily cron reaps. The bucket key itself is unchanged — what
     * changed is that an unregistered action never reaches it. An unregistered
     * action gets NO BUCKET AT ALL; hashing the parameter harder would only
     * have made the same unbounded key space harder to read.
     *
     * Registration is the test this file already owned, in the two forms it
     * already had: the site's `ntdst/api/public_actions` declaration, and a
     * mounted dispatch filter. Both lists are written by the SITE and bounded
     * by it.
     *
     * WHAT THIS BOUNDS, EXACTLY — and what it does not (M3). An earlier
     * version of this docblock claimed the key space becomes "(callers x
     * registered actions)", offered as a bound. It is not one, and a false
     * bound in a security docblock is worse than none: it is what the next
     * reviewer checks against instead of the code. Only the ACTION axis is
     * bounded here. The caller axis is `u{id}` or `ip` + md5(client IP), and
     * an attacker on an IPv6 /64 has 2^64 caller values — unbounded by
     * construction, as it is in every per-IP throttle ever written.
     *
     * So the honest statement is three lines:
     *  - an UNREGISTERED action reaches no bucket at all (this method);
     *  - a registered NON-PUBLIC action reaches no bucket without credentials,
     *    because the auth gate now runs first (M2);
     *  - a registered PUBLIC action does let an anonymous caller create one
     *    bucket per client IP. That is not a leak, that IS a public throttle:
     *    the counter has to be per-caller or it cannot count anybody. A site
     *    that publishes an action accepts per-IP row growth on it, and the
     *    daily transient cron is what reaps it.
     *
     * The denial is a bare `false` — the same 401 `rest_forbidden` as an auth
     * denial, so refusing an unknown action tells a caller nothing that
     * refusing a known one does not.
     *
     * @param list<string> $dispatchFilters Filter prefixes to test, in the
     *                                      order the surface would dispatch.
     */
    private function isRegisteredAction(string $action, bool $isPublic, array $dispatchFilters): bool
    {
        if ($action === '') {
            return false;
        }

        if ($isPublic) {
            return true;
        }

        foreach ($dispatchFilters as $prefix) {
            if (has_filter($prefix . $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permission for the GET /download endpoint: the SAME gate as /action
     * (rate limit + public-action + auth), minus the Origin/CSRF check.
     *
     * A download is a top-level <a href> GET navigation, which browsers send
     * WITHOUT an Origin header — so verifyOrigin() would deny every legitimate
     * download (empty Origin + present auth cookie => false). The per-action
     * nonce carried in the URL, verified in handle_download(), is this
     * surface's CSRF protection: an attacker's cross-site context cannot mint
     * a valid nonce for a non-public action.
     */
    public function check_download_permission(WP_REST_Request $request): WP_Error|bool
    {
        return $this->checkDispatchPermission($request, verifyOrigin: false, dispatchFilter: self::DOWNLOAD_FILTER);
    }

    /**
     * The one denial the router makes DISTINGUISHABLE on the wire.
     *
     * A rate-limited caller gets 429 `rate_limited` instead of the 401
     * `rest_forbidden` that bare `false` produces, because a legitimate JS
     * client must know to back off — while an attacker learns nothing about
     * auth state it couldn't already infer from timing/counting. Auth and
     * origin denials deliberately stay bare `false`. Carries no request data.
     */
    private function rateLimitedError(): WP_Error
    {
        return new WP_Error(
            'rate_limited',
            __('Too many requests. Please wait a moment and try again.', 'ntdst-core'),
            ['status' => 429],
        );
    }

    /**
     * Rate limiting to prevent API abuse.
     *
     * Keying strategy:
     *  - Logged-in: bucket per (user_id, action) — fair to NAT'd users
     *  - Anonymous: bucket per (ip, action)
     *
     * Limits and windows are filterable per-action so sensitive operations
     * (e.g. magic-link send) can be much stricter than the default 30/min.
     *
     * This method owns everything that names THIS caller — the per-action
     * limit and window filters over the class constants, and the (user|ip,
     * action) bucket. The counting itself belongs to nobody in particular, so
     * it lives in the one shared primitive (support/RateLimiter.php) that the
     * todai intake counts against too. The transient key, the prefix, the
     * bucket derivation, the filters and the sliding TTL are all unchanged —
     * existing sites keep the buckets they already have (FR-3).
     *
     * The `$request` goes in as the limiter's memo scope, which is what keeps
     * the double-invocation guarantee alive after the extraction: WP invokes
     * the permission callback twice per served request (dispatch +
     * `rest_send_allow_header()`), and only the FIRST invocation may consume a
     * budget unit, or every configured limit is halved on the wire. The memo
     * can only make the limiter MORE permissive within a single request (one
     * unit instead of two), and a caller cannot steer it: the identity is the
     * server-created request object, within one PHP request lifecycle.
     *
     * Two deliberate, non-behavioural differences, declared rather than
     * hidden — both are extra resolutions of PURE value filters, and neither
     * moves a bucket, a count or a decision:
     *  - the limit/window filters now resolve on BOTH permission-callback
     *    invocations, where the old in-class memo short-circuited the second;
     *  - the bucket key is now built before the `<= 0` disabled-limit check
     *    rather than after it, so a site that has switched its limit off
     *    still resolves the current user and `ntdst/trusted_proxies`.
     * The alternative to the second is a duplicate `<= 0` branch here, which
     * would put the disabling rule in two places — the exact split this task
     * exists to remove.
     *
     * @return bool false when limit exceeded
     */
    private function checkRateLimit(string $action, WP_REST_Request $request): bool
    {
        $limit = (int) apply_filters("ntdst/api/rate_limit/{$action}", self::RATE_LIMIT, $action);
        $window = (int) apply_filters("ntdst/api/rate_window/{$action}", self::RATE_WINDOW, $action);

        // A filter can explicitly disable the limit with <= 0; the limiter
        // honours that and keeps no counter for it.
        return NTDST_RateLimiter::attempt(
            $this->rateBucketKey($action),
            $limit,
            $window,
            $request,
        );
    }

    /**
     * The transient key for this caller's bucket: per (user_id, action) when
     * logged in — fair to NAT'd users — and per (ip, action) otherwise.
     *
     * Shape is byte-identical to the pre-extraction key, prefix included, so
     * no live counter is orphaned by this change.
     */
    private function rateBucketKey(string $action): string
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $bucket = $userId > 0
            ? "u{$userId}"
            : 'ip' . md5($this->getClientIp());

        return 'ntdst_rate_' . md5($bucket . '|' . $action);
    }

    /**
     * Verify request origin for CSRF protection.
     * Only allows requests from same origin or with valid referer.
     * Rejects missing Origin+Referer when auth cookies are present.
     */
    private function verifyOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // If no origin/referer, only allow if no auth cookie present.
        // Browsers always send Origin on cross-origin requests with credentials.
        if (empty($origin) && empty($referer)) {
            return !$this->hasCookieAuth();
        }

        $homeHost = parse_url(home_url(), PHP_URL_HOST);
        $siteHost = parse_url(site_url(), PHP_URL_HOST);

        // Exact hostname match on Origin header
        if (!empty($origin)) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost === $homeHost || $originHost === $siteHost) {
                return true;
            }
        }

        // Referer must start with our full URL — use trailing slash so
        // `https://example.com.evil.com/x` does NOT match
        // `https://example.com` via simple prefix.
        if (!empty($referer)) {
            $homeUrl = home_url('/');
            $siteUrl = site_url('/');
            if (str_starts_with($referer, $homeUrl) || str_starts_with($referer, $siteUrl)) {
                return true;
            }
        }

        // Allow custom origins via filter
        $allowed_origins = apply_filters('ntdst/api/allowed_origins', []);
        if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the request contains WordPress authentication cookies.
     */
    private function hasCookieAuth(): bool
    {
        foreach ($_COOKIE as $name => $value) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get client IP for rate limiting.
     *
     * Delegates to the one canonical resolver (support/ClientIp.php). The
     * right-to-left skip-trusted walk this method used to carry now lives
     * there, joined to CIDR-aware trust matching, and the local exact-match
     * copy is gone — one implementation, one place to review.
     *
     * `NTDST_ClientIp::detect()` applies `ntdst/trusted_proxies` and then the
     * historical `netdust_trusted_proxies`, over the same loopback default
     * this method used, so a site's existing filter keeps working unchanged.
     *
     * An unusable REMOTE_ADDR now yields '' rather than the old '0.0.0.0'
     * placeholder — see support/ClientIp.php. The only consumer is the
     * rate-bucket hash below, which takes any string.
     */
    private function getClientIp(): string
    {
        return NTDST_ClientIp::detect($_SERVER);
    }

    // =========================================================================
    // ENDPOINT HANDLERS
    // =========================================================================

    /**
     * Extract request params from the query string, form-data, or JSON body.
     *
     * Three transports, precedence low to high — query params (FALLBACK),
     * then body params, then JSON params — mirroring the merge order
     * WP_REST_Request::get_params() itself uses. Query params are the
     * fallback because a real GET navigation (e.g. `<a href="...?action=X">`)
     * sends no body at all: /download is reached this way, never via
     * JSON/form body, so without this layer every real anchor click 400s
     * with `missing_params` (v2.3.0 regression, found by Stride's Task-10
     * browser drive — invisible to filter-level tests because they never
     * exercise the query-string-only shape a browser navigation actually
     * sends). JSON/body still WIN over query params: /action is POSTed with
     * a JSON or form body today, and that body must remain authoritative
     * over anything also present in the query string.
     *
     * Supports both application/json and multipart/form-data content types,
     * allowing the same ntdst/api_data filters to handle file uploads.
     * File params are available as $params['_files'].
     */
    private function get_request_params(WP_REST_Request $request): array
    {
        $params = $request->get_query_params();

        $body = $request->get_body_params();
        if (!empty($body)) {
            $params = array_merge($params, $body);
        }

        $json = $request->get_json_params();
        if (!empty($json)) {
            $params = array_merge($params, $json);
        }

        // `_files` is RESERVED, so it is written unconditionally — including
        // when there are no uploads. Guarding this with `if (!empty($files))`
        // made the reservation true only in the case that did not need it: with
        // no file attached, a caller-supplied `_files` key in the JSON body
        // survived into `$params` verbatim, so the first handler to trust
        // `$params['_files'][…]['tmp_name']` would read an attacker-chosen
        // path. No handler does today; the docblock's promise is what the next
        // one would rely on, so the promise is made true rather than softened.
        $params['_files'] = $request->get_file_params();

        return $params;
    }

    public function handle_get_nonce(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->get_request_params($request);
        $action = $params['action'] ?? $request->get_param('action');

        if (empty($action)) {
            return $this->error('No action specified', 'missing_action');
        }

        return $this->success([
            'nonce' => wp_create_nonce($action),
        ]);
    }

    public function handle_action(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? '');
        $nonce  = sanitize_text_field($params['nonce'] ?? '');

        if (empty($action) || empty($nonce)) {
            return $this->error('Missing action or nonce', 'missing_params');
        }

        if (!wp_verify_nonce($nonce, $action)) {
            return $this->error('Invalid or expired nonce', 'invalid_nonce');
        }

        // Distinguish "no handler registered" from "handler returned nothing"
        // so a legitimate empty result (e.g. zero search hits) isn't a 404.
        if (!has_filter(self::DATA_FILTER . $action)) {
            return $this->error('Unknown action request', 'unknown_action');
        }

        $data = apply_filters(self::DATA_FILTER . $action, [], $params);

        if (is_wp_error($data)) {
            // Honour a status the handler declared in the WP_Error's data
            // (WP convention: new WP_Error(code, msg, ['status' => 403])).
            $errorData = $data->get_error_data();
            $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 400;

            return $this->error($data->get_error_message(), (string) $data->get_error_code(), $status);
        }

        return $this->success(is_array($data) ? $data : []);
    }

    /**
     * Dispatch a GET download to its ntdst/api_download/{action} handler.
     *
     * Same gate order as handle_action: reject missing params, verify the
     * per-action nonce (the CSRF protection for this GET surface), refuse an
     * unregistered action. The registered handler is expected to emit the file
     * via Response::download()/inline() and EXIT — so control never returns
     * here. If it DOES return, the download action is misconfigured: this
     * fails loud with a 500 rather than shipping a blank 200.
     *
     * The dispatcher never reads a filename or path from the request: the
     * handler supplies both to Response, and fileHeaders() sanitizes the name.
     * No path-traversal surface.
     */
    public function handle_download(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? '');
        $nonce  = sanitize_text_field($params['nonce'] ?? '');

        if (empty($action) || empty($nonce)) {
            return $this->error('Missing action or nonce', 'missing_params');
        }

        if (!wp_verify_nonce($nonce, $action)) {
            return $this->error('Invalid or expired nonce', 'invalid_nonce');
        }

        if (!has_filter(self::DOWNLOAD_FILTER . $action)) {
            return $this->error('Unknown download request', 'unknown_action', 404);
        }

        // The handler emits via Response::download()/inline() and exits;
        // control does not return past this line on success. A handler MAY
        // instead deny with a WP_Error (same convention as handle_action):
        // honour its declared status/code/message rather than collapsing
        // every denial to a 500 — a denial is not a server fault (Stride
        // Phase-2 B3 review, finding C1). Default to 403 (not handle_action's
        // 400) when the handler didn't declare a status: this surface's own
        // denial default, since a download is always a permission question.
        $result = apply_filters(self::DOWNLOAD_FILTER . $action, null, $params);

        if (is_wp_error($result)) {
            $errorData = $result->get_error_data();
            $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 403;

            return $this->error($result->get_error_message(), (string) $result->get_error_code(), $status);
        }

        return $this->error('Download handler did not emit a file', 'download_not_emitted', 500);
    }


    // =========================================================================
    // RESPONSE HELPERS
    //
    // NTDST_Actions owns the envelope DECISION; Response owns EMISSION. These ask
    // Response for a WP_REST_Response carrying the api envelope + HTTP status,
    // so an error leaves as a real 4xx instead of a 200 with a bare array.
    // =========================================================================

    private function success(array $data): WP_REST_Response
    {
        return NTDST_Response::apiSuccessResponse($data);
    }

    private function error(string $message, string $code = 'error', int $status = 400): WP_REST_Response
    {
        return NTDST_Response::apiErrorResponse($message, $code, $status);
    }
    /**
     * Register a COMMAND — the ajax idiom, dispatched through /ntdst/v1/action.
     *
     * Pick the right service:
     *   command (this)  → ntdst_actions()->register()
     *   resource route  → ntdst_rest()
     *   file bytes      → add_filter('ntdst/api_download/{action}', …)
     *   page            → ntdst_pages()->path()
     *
     * The `ntdst/api_data/{action}` filter name is UNCHANGED from v2 on purpose:
     * adopters' handlers hang off it, and renaming it would silently unmount
     * every one of them while the code still looked correct.
     *
     * @param array{cap_type?: string, public?: bool, capability?: string, priority?: int} $opts
     */
    public function register(string $action, callable $handler, array $opts = []): void
    {
            $action   = sanitize_key($action);
            $priority = (int) ($opts['priority'] ?? 10);

            // Public wins (threat T1): a public action is unified onto the site's one
            // `ntdst/api/public_actions` filter and is NEVER floored — anonymous
            // reachability is not conditional on a capability.
            if (($opts['public'] ?? false) === true) {
                add_filter('ntdst/api/public_actions', static function (array $actions) use ($action): array {
                    $actions[] = $action;
                    return $actions;
                });

                add_filter(self::DATA_FILTER . $action, $handler, $priority, 2);
                return;
            }

            // A declared cap floor bites at DISPATCH, ahead of the real handler, so it
            // protects even a handler that forgot to check — ALONGSIDE, not replacing,
            // the handler's own check. `cap_type` is TYPE-DERIVED (threat T2); a literal
            // `capability` is the form S7 reconciled onto this one path from the Theme
            // wrapper S9 has since retired.
            $capType    = isset($opts['cap_type']) && is_string($opts['cap_type']) ? $opts['cap_type'] : '';
            $literalCap = isset($opts['capability']) && is_string($opts['capability']) ? $opts['capability'] : '';

            if ($capType !== '' || $literalCap !== '') {
                $floored = static function ($data, $params) use ($handler, $capType, $literalCap) {
                    $cap = $capType !== '' ? ntdst_api_floor_cap($capType) : $literalCap;

                    // FAIL-CLOSED (threat T3): an unresolvable/empty cap denies everyone, admin included.
                    if ($cap === '' || !current_user_can($cap)) {
                        return new \WP_Error('forbidden', 'Insufficient permissions', ['status' => 403]);
                    }

                    return $handler($data, $params);
                };

                add_filter(self::DATA_FILTER . $action, $floored, $priority, 2);
                return;
            }

            // Neither opt: login-required. The router's binary floor already refuses an
            // anonymous caller for any action not on public_actions; the handler keeps
            // whatever per-row/per-type check it has.
            add_filter(self::DATA_FILTER . $action, $handler, $priority, 2);
    }
}

/**
 * Global helper — the command service (singleton).
 */
if (!function_exists('ntdst_actions')) {
    function ntdst_actions(): NTDST_Actions
    {
        static $actions = null;
        return $actions ??= new NTDST_Actions();
    }
}

if (!function_exists('ntdst_api_floor_cap')) {
    /**
     * The type's OWN `edit_others_posts`-mapped capability, or `''` when it cannot
     * be resolved (fail-closed). Mirrors `AccessGrantService::manageCapability()`;
     * never the literal `edit_others_posts`.
     */
    function ntdst_api_floor_cap(string $post_type): string
    {
        $type = get_post_type_object($post_type);

        if (!$type instanceof \WP_Post_Type) {
            return '';
        }

        $cap = $type->cap->edit_others_posts ?? null;

        return is_string($cap) && $cap !== '' ? $cap : '';
    }
}
