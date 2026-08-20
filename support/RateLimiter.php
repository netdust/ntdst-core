<?php

declare(strict_types=1);

/**
 * The one canonical rate limiter for the fleet (spec `intake-to-core`, FR-2 /
 * FR-3), extracted from `NTDST_Actions::checkRateLimit()` +
 * `consumeRateBudget()` so the todai intake and every future consumer count
 * against the same primitive instead of re-deriving it.
 *
 * ── EVERYTHING THAT NAMES A CALLER STAYS WITH THE CALLER (FR-3) ───────────
 * The limiter receives the FINISHED transient key and the FINISHED numbers.
 * It resolves no user, no client IP, and applies no filters of its own:
 *  - NTDST_Actions keys `u{userId}` when logged in, else `ip` + md5(client
 *    IP), under the `ntdst_rate_` prefix, with the limit and the window taken
 *    from `ntdst/api/rate_limit/{$action}` and `ntdst/api/rate_window/{$action}`
 *    over its own class constants;
 *  - the todai intake keys (ip + form_key) under `todai_intake_rate_`, with
 *    its own `todai/intake/*` filters.
 * Two different bucket shapes, two different filter namespaces. A limiter that
 * hashed, prefixed or re-filtered anything here would silently move every
 * consuming site's buckets and filter the callers' numbers twice.
 *
 * ── WHY THE MEMO EXISTS — a fact this code cannot show ────────────────────
 * (The full statement lives at api/Endpoints.php's checkRateLimit() docblock; it is repeated here
 * because the guarantee moved into this class.) WordPress invokes a route's
 * `permission_callback` TWICE per served HTTP request:
 *  1. in `WP_REST_Server::respond_to_request()` (the dispatch-time check), and
 *  2. in `rest_send_allow_header()` on `rest_post_dispatch`, which re-invokes
 *     every matched handler's permission callback to build the `Allow` header
 *     — on every response, error responses included, because
 *     `set_matched_route()` is unconditional.
 * Unmemoized, each served request consumes TWO budget units and every
 * configured limit is HALVED on the wire: the fleet default 30 behaves as 15,
 * and a limit of 3 was measured live passing exactly 2 requests.
 *
 * ── WHY THE MEMO IS NOT A BYPASS (threat 11, other half) ──────────────────
 * The memo identity is an OBJECT the caller supplies — the WP_REST_Request for
 * Endpoints. Both core invocations receive the same instance, and two
 * different HTTP requests can never share one, so the memo is per-request BY
 * CONSTRUCTION: it needs no reset, it cannot be steered by a caller, and its
 * entries die with the object (WeakMap). A decision is cached per (scope, key)
 * — one scope may check several buckets, and the answer for one must never
 * stand in for another. A caller with no per-request object passes nothing and
 * gets the plain, unmemoized limiter; nothing is ever cached process-wide on
 * an anonymous caller's behalf, which would be a bypass with no owner.
 */

defined('ABSPATH') || exit;

final class NTDST_RateLimiter
{
    /**
     * Window used when a caller asks for one that cannot be honoured (<= 0).
     * Matches NTDST_Actions' own RATE_WINDOW so a clamped bucket behaves
     * like the fleet default rather than inventing a third number.
     */
    private const FALLBACK_WINDOW = 60;

    /**
     * Memoized decisions: memo scope object => [transient key => decision].
     *
     * @var \WeakMap<object, array<string, bool>>|null
     */
    private static ?\WeakMap $decisions = null;

    /**
     * Read the bucket and consume one unit when the caller is under the limit.
     *
     * The TTL is re-applied on every consumed unit (sliding window) — the
     * long-standing NTDST_Actions behaviour, deliberately carried over
     * unchanged, since FR-3 forbids any behaviour change for the eleven
     * existing call sites.
     *
     * A DENIED attempt consumes nothing. If a denial also incremented, a
     * caller under sustained load could never come back inside the window,
     * because every rejected attempt would push the counter further up.
     *
     * @param string       $key       The full transient key, built by the caller.
     * @param int          $limit     Attempts allowed per window; <= 0 disables the limit.
     * @param int          $window    Window in seconds — the transient TTL.
     * @param object|null  $memoScope Per-request identity (Endpoints passes the
     *                                WP_REST_Request). Null means no memoization.
     *
     * @return bool True when the attempt is allowed and one unit was consumed.
     */
    public static function attempt(
        string $key,
        int $limit,
        int $window,
        ?object $memoScope = null,
    ): bool {
        if ($limit <= 0) {
            // The escape hatch a filter uses to switch the limit off. No
            // counter is read and none is kept: a disabled limit must leave
            // no bucket behind to expire later.
            return true;
        }

        if ($memoScope === null) {
            return self::consume($key, $limit, $window);
        }

        self::$decisions ??= new \WeakMap();

        $scopeDecisions = self::$decisions[$memoScope] ?? [];

        // array_key_exists, not isset/??: a memoized FALSE is a real decision
        // the scope earned, and replaying it is the point — the Allow header
        // must not advertise a method the dispatch just refused.
        if (array_key_exists($key, $scopeDecisions)) {
            return $scopeDecisions[$key];
        }

        $decision = self::consume($key, $limit, $window);

        $scopeDecisions[$key]           = $decision;
        self::$decisions[$memoScope]    = $scopeDecisions;

        return $decision;
    }

    /**
     * Clear a bucket: the caller succeeded, so the count against them is void.
     *
     * RESET-ON-SUCCESS is a different primitive from a request budget, and it
     * is the whole reason a failure counter could not converge here. A budget
     * decays only with its window — thirty requests a minute, and the
     * thirty-first waits. A failure counter is a lockout: five bad passwords
     * lock the account, and the first GOOD one must clear the record
     * immediately, because the caller has proven they are not the attacker the
     * counter was written for. Without this verb, `ntdst-baseline`'s login
     * lockout kept its own `delete_transient()` and stayed a Deliberate
     * Exception to the one-limiter rule.
     *
     * Note what is NOT the difference, because the first version of this
     * reasoning got it wrong: a denied attempt already consumes nothing and
     * extends no TTL — in this limiter and in baseline's. A non-extending TTL
     * was never the gap. This method is.
     *
     * The bucket is DELETED, not zeroed: a zero would leave the option row and
     * its timeout row in place, still expiring on the old schedule.
     *
     * The memoized decision goes with it. `attempt()` caches its answer per
     * (scope, key) so WordPress's double permission-callback invocation cannot
     * halve a limit — but a reset that left that cache standing would be a
     * half-reset, answering the next question from the denial it just cleared.
     * Scopes are walked because a key is not owned by one of them; every other
     * key's memo, in every scope, is untouched.
     *
     * @param string $key The full transient key, built by the caller — the
     *                    same key it passes to attempt().
     */
    public static function reset(string $key): void
    {
        delete_transient($key);

        if (self::$decisions === null) {
            return;
        }

        foreach (self::$decisions as $scope => $scopeDecisions) {
            if (!array_key_exists($key, $scopeDecisions)) {
                continue;
            }

            unset($scopeDecisions[$key]);
            self::$decisions[$scope] = $scopeDecisions;
        }
    }

    /**
     * The unmemoized decision against the transient store.
     *
     * `get_transient()` returns false for an expired or never-written key —
     * that is "no attempts yet", not a poisoned counter — and returns the
     * stored value as a string from the options table, so it is cast, never
     * compared as text.
     */
    private static function consume(string $key, int $limit, int $window): bool
    {
        // A window of 0 means NO EXPIRATION to set_transient(), so the bucket
        // would never reset and the caller would be denied forever — a
        // permanent lockout removable only by hand from wp_options. A negative
        // window writes a timeout in the past, so get_transient() treats the
        // counter as already expired and the limit is silently never enforced.
        // Both are one filter typo away (`fn() => 0`), and (int) casting turns
        // any non-numeric filter return into 0. Neither failure is one a
        // limiter may accept: clamp to a sane window and say so.
        if ($window <= 0) {
            $window = self::FALLBACK_WINDOW;

            if (function_exists('error_log')) {
                error_log(sprintf(
                    'NTDST_RateLimiter: a window of <= 0 was requested for a bucket and would '
                    . 'never expire; clamped to %ds. Check the rate-window filter for this action.',
                    self::FALLBACK_WINDOW
                ));
            }
        }

        $count = (int) get_transient($key);

        if ($count >= $limit) {
            // Denial path: the bucket is read and nothing is written.
            return false;
        }

        set_transient($key, $count + 1, $window);

        return true;
    }
}
