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
 * Endpoints:
 * - POST /wp-json/ntdst/v1/get_nonce
 * - POST /wp-json/ntdst/v1/action
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

final class NTDST_Endpoints
{
    private const REST_NAMESPACE = 'ntdst/v1';

    /**
     * Rate limiting settings
     */
    private const RATE_LIMIT = 30; // Max requests per window
    private const RATE_WINDOW = 60; // Window in seconds

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

    /**
     * Memoized rate decisions: request object => [action => decision].
     *
     * WP core invokes a route's `permission_callback` TWICE per served HTTP
     * request — a fact this code cannot show, so it is stated here:
     *  1. in `WP_REST_Server::respond_to_request()` (the dispatch-time
     *     permission check), and
     *  2. in `rest_send_allow_header()` (hooked on `rest_post_dispatch`),
     *     which re-invokes every matched handler's permission_callback to
     *     compute the `Allow` response header — on every response, error
     *     responses included, since `set_matched_route()` is unconditional.
     * Without memoization each invocation ran the transient increment, so one
     * HTTP request consumed TWO budget units and every configured limit was
     * halved on the wire (fleet default 30 behaved as 15; verified live:
     * limit 3 passed exactly 2 requests).
     *
     * Keyed on the WP_REST_Request OBJECT because both core invocations
     * receive the same request instance, while two different HTTP requests
     * can never share one — the memo is per-request by construction, needs no
     * reset, and cannot become a persistent bypass. WeakMap entries die with
     * the request object.
     *
     * @var \WeakMap<\WP_REST_Request, array<string, bool>>
     */
    private \WeakMap $rateDecisions;

    public function __construct()
    {
        $this->rateDecisions = new \WeakMap();

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

        if (!$this->checkRateLimit($action, $request)) {
            return $this->rateLimitedError();
        }

        // Get public actions dynamically (allows late registration)
        $public_actions = apply_filters('ntdst/api/public_actions', $this->public_actions);

        // Allow public actions without authentication
        if (in_array($action, $public_actions, true)) {
            return true;
        }

        // For non-public actions, require authentication
        return is_user_logged_in();
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
        $params = $this->get_request_params($request);
        $action = sanitize_text_field($params['action'] ?? '');

        // Rate limiting check (per-action so sensitive actions can be tighter)
        if (!$this->checkRateLimit($action, $request)) {
            return $this->rateLimitedError();
        }

        // CSRF: Verify request origin
        if (!$this->verifyOrigin()) {
            return false;
        }

        // Auth gate, symmetric with check_nonce_permission: anonymous
        // requests may only dispatch PUBLIC actions. Previously this relied
        // indirectly on "anon can't mint a nonce for a non-public action" +
        // per-handler login checks — a handler that forgot its own check,
        // combined with any nonce leak, became an exposed surface.
        $public_actions = apply_filters('ntdst/api/public_actions', $this->public_actions);
        if (!in_array($action, $public_actions, true) && !is_user_logged_in()) {
            return false;
        }

        return true;
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
     * The decision is memoized per (request, action) — see $rateDecisions:
     * WP invokes the permission callback twice per served request (dispatch +
     * `rest_send_allow_header()`), and only the FIRST invocation may consume
     * a budget unit, or every limit is effectively halved. The memo can only
     * make the limiter MORE permissive within a single request (one unit
     * instead of two); a caller cannot steer it, since the key is the
     * server-created request object plus the action within one PHP request
     * lifecycle.
     *
     * @return bool false when limit exceeded
     */
    private function checkRateLimit(string $action, WP_REST_Request $request): bool
    {
        $decisions = $this->rateDecisions[$request] ?? [];

        if (array_key_exists($action, $decisions)) {
            return $decisions[$action];
        }

        $decision = $this->consumeRateBudget($action);

        $decisions[$action]            = $decision;
        $this->rateDecisions[$request] = $decisions;

        return $decision;
    }

    /**
     * The unmemoized decision: read the (user|ip, action) bucket and consume
     * one unit when under the limit. The transient TTL resets on every
     * increment (sliding window) — long-standing behaviour, deliberately
     * unchanged here.
     *
     * @return bool false when limit exceeded
     */
    private function consumeRateBudget(string $action): bool
    {
        $limit = (int) apply_filters("ntdst/api/rate_limit/{$action}", self::RATE_LIMIT, $action);
        $window = (int) apply_filters("ntdst/api/rate_window/{$action}", self::RATE_WINDOW, $action);

        if ($limit <= 0) {
            // A filter explicitly disabled the limit.
            return true;
        }

        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $bucket = $userId > 0
            ? "u{$userId}"
            : 'ip' . md5($this->getClientIp());
        $key = 'ntdst_rate_' . md5($bucket . '|' . $action);

        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, $window);

        return true;
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
     * Get client IP for rate limiting (secure implementation)
     *
     * The LEFTMOST X-Forwarded-For end is attacker-authored: under the
     * standard nginx→FPM topology, nginx's default
     * `$proxy_add_x_forwarded_for` APPENDS the connecting address to whatever
     * header the client sent, so anything left of the infrastructure-appended
     * hops is client-supplied fiction. Only the RIGHTMOST hop NOT in the
     * trusted-proxy list — the address that actually connected to trusted
     * infrastructure — is load-bearing for rate-bucket identity.
     */
    private function getClientIp(): string
    {
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Define trusted proxies
        $trusted_proxies = apply_filters('netdust_trusted_proxies', ['127.0.0.1', '::1']);

        // Only trust X-Forwarded-For if behind trusted proxy
        if (!in_array($remote_ip, $trusted_proxies, true) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote_ip;
        }

        // Walk the chain right-to-left, skipping trusted proxies; the first
        // untrusted hop is the client. Attacker-prepended garbage on the left
        // is never reached. A malformed candidate terminates the walk — fall
        // back to the trusted proxy's address rather than trust anything to
        // its left.
        $forwarded_ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));

        foreach (array_reverse($forwarded_ips) as $candidate) {
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $remote_ip;
            }
            if (in_array($candidate, $trusted_proxies, true)) {
                continue;
            }
            return $candidate;
        }

        // Every hop in the chain is a trusted proxy — internal traffic.
        return $remote_ip;
    }

    // =========================================================================
    // ENDPOINT HANDLERS
    // =========================================================================

    /**
     * Extract request params from JSON body or form-data.
     *
     * Supports both application/json and multipart/form-data content types,
     * allowing the same ntdst/api_data filters to handle file uploads.
     * File params are available as $params['_files'].
     */
    private function get_request_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();

        if (empty($params)) {
            $params = $request->get_body_params();
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
        if (!has_filter("ntdst/api_data/{$action}")) {
            return $this->error('Unknown action request', 'unknown_action');
        }

        $data = apply_filters("ntdst/api_data/{$action}", [], $params);

        if (is_wp_error($data)) {
            // Honour a status the handler declared in the WP_Error's data
            // (WP convention: new WP_Error(code, msg, ['status' => 403])).
            $errorData = $data->get_error_data();
            $status = is_array($errorData) && isset($errorData['status']) ? (int) $errorData['status'] : 400;

            return $this->error($data->get_error_message(), (string) $data->get_error_code(), $status);
        }

        return $this->success(is_array($data) ? $data : []);
    }


    // =========================================================================
    // RESPONSE HELPERS
    //
    // Endpoints owns the envelope DECISION; Response owns EMISSION. These ask
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
}

/**
 * Global helper - get endpoints instance
 */
if (!function_exists('ntdst_endpoints')) {
    function ntdst_endpoints(): NTDST_Endpoints
    {
        static $manager = null;
        return $manager ??= new NTDST_Endpoints();
    }
}

// Back-compat: keep the old unprefixed class name working for callers
// outside this codebase. New code should use NTDST_Endpoints.
if (!class_exists('Endpoints', false)) {
    class_alias(NTDST_Endpoints::class, 'Endpoints');
}

/**
 * Register an `ntdst/api_data/{action}` handler, optionally with a declared
 * per-action capability floor and/or public (anonymous) reachability. The
 * registration path for data actions in `daan-core` — services call it
 * directly. (S9 retired the `Theme::apiAction()` wrapper that used to delegate
 * here: an api_data action outlives a theme switch, so registering one was
 * never Theme's job.)
 *
 * NOT the only mechanical path, and the claim is scoped rather than absolute
 * for that reason (Cluster B review finding F6): `ntdst/api_data/{action}` is
 * an ordinary WordPress filter, so a plugin can attach a handler with a raw
 * `add_filter()` and one does — `ntdst-baseline`'s `CacheHeadersService`
 * registers `ntdst/api_data/baseline_purge` that way, gating it itself. Doing
 * so forfeits everything below (the declared floor, the public allowlist entry)
 * and puts the whole burden of the gate on the handler; prefer this helper.
 *
 *  - `$opts['cap_type']` — a capability FLOOR enforced at DISPATCH, ahead of the
 *    real handler, so it protects even a handler that forgot to check (defense in
 *    depth, ALONGSIDE the handler's own gate). The cap is TYPE-DERIVED from
 *    `get_post_type_object($type)->cap->edit_others_posts` and FAIL-CLOSED (an
 *    unresolvable/empty/absent cap denies EVERYONE, admin included) — never the
 *    literal `edit_others_posts`. Mirrors `AccessGrantService::manageCapability()`.
 *  - `$opts['public'] === true` — adds `$action` to the `ntdst/api/public_actions`
 *    filter (the site's one place for anonymous exposure) and NEVER floors it:
 *    public reachability wins over any declared `cap_type`.
 *  - `$opts['capability']` — a LITERAL cap floor. It exists because S7 reconciled
 *    the retired `Theme::apiAction()` wrapper's literal-cap option onto this one
 *    path rather than dropping it; it keeps that wrapper's fail-open-on-empty
 *    semantics, which is why `cap_type` (fail-CLOSED) is the form to prefer.
 *  - neither — login-required: the router's binary floor refuses anonymous
 *    callers, and the handler keeps its own per-row/per-type check.
 *
 * @param array{cap_type?: string, public?: bool, capability?: string, priority?: int} $opts
 */
if (!function_exists('ntdst_api_action')) {
    function ntdst_api_action(string $action, callable $handler, array $opts = []): void
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

            add_filter('ntdst/api_data/' . $action, $handler, $priority, 2);
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

            add_filter('ntdst/api_data/' . $action, $floored, $priority, 2);
            return;
        }

        // Neither opt: login-required. The router's binary floor already refuses an
        // anonymous caller for any action not on public_actions; the handler keeps
        // whatever per-row/per-type check it has.
        add_filter('ntdst/api_data/' . $action, $handler, $priority, 2);
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
