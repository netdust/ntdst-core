<?php

declare(strict_types=1);

/**
 * The one canonical client-IP resolver for the fleet (spec `intake-to-core`,
 * FR-1 / FR-1b; threat 10 — X-Forwarded-For spoofing).
 *
 * It merges the correct half of each of the copies it replaces:
 *  - CIDR-aware trust matching (ntdst-baseline), because Ploi and Cloudflare
 *    front from a RANGE. An exact-match allow-list silently fails to match
 *    behind them — and then tempts operators to widen the list instead.
 *  - the right-to-left, skip-trusted walk (NTDST_Endpoints), because nginx's
 *    `$proxy_add_x_forwarded_for` APPENDS the observed peer. Everything left
 *    of the infrastructure-appended hops is client-authored fiction, so
 *    leftmost-wins hands the attacker the identity it asked for.
 *
 * Trust is decided on REMOTE_ADDR alone — the one value in the request the
 * caller cannot choose. The header is evidence only when the peer that
 * delivered it is trusted infrastructure.
 *
 * FALLBACK, decided in the T03 contract: an unusable REMOTE_ADDR resolves to
 * '' (the empty string), NOT '0.0.0.0'. 0.0.0.0 is a real address, so handing
 * it back invents one shared identity for every unidentifiable caller — CLI,
 * cron and malformed requests would collide into a single rate-limit bucket
 * and a single audit-log subject. A consumer that wants a placeholder
 * coalesces at its own call site (`?: 'unknown'`); this primitive must not
 * decide that for eleven packages.
 */

defined('ABSPATH') || exit;

final class NTDST_ClientIp
{
    /** Loopback v4 + v6 — NTDST_Endpoints' and NTDST_Logger's historical default. */
    private const DEFAULT_TRUSTED_PROXIES = ['127.0.0.1', '::1'];

    /**
     * The WordPress-aware entry point: resolve against the site's filtered
     * trusted-proxy list.
     *
     * `ntdst/trusted_proxies` is the current name. `netdust_trusted_proxies`
     * runs AFTER it, on its output, so the historical name keeps the final
     * word (FR-1b) and the eleven existing consumers stay effective without
     * an edit.
     *
     * @param array<string, mixed> $server Typically $_SERVER.
     */
    public static function detect(array $server): string
    {
        $trustedProxies = apply_filters('ntdst/trusted_proxies', self::DEFAULT_TRUSTED_PROXIES);
        $trustedProxies = apply_filters('netdust_trusted_proxies', $trustedProxies);

        return self::resolve($server, is_array($trustedProxies) ? $trustedProxies : []);
    }

    /**
     * PURE resolution: the caller's list is the whole truth, no filters are
     * applied. A packaged consumer (ntdst-baseline) passes its own configured
     * list here, and a site-level filter must never silently widen it.
     *
     * Signature is byte-identical to ntdst-baseline's ClientIp::resolve() so
     * that package can delegate without touching its own config surface.
     *
     * @param array<string, mixed> $server
     * @param array<int, mixed> $trustedProxies Bare IPs and/or CIDR ranges.
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remoteAddr = $server['REMOTE_ADDR'] ?? null;

        // An unidentifiable peer is untrusted by definition: no usable
        // REMOTE_ADDR means the header is never consulted either.
        if (!is_string($remoteAddr) || !self::isIp($remoteAddr)) {
            return '';
        }

        if (!self::isTrusted($remoteAddr, $trustedProxies)) {
            // Denial path (threat 10): untrusted peer, X-Forwarded-For
            // ignored entirely regardless of what it claims.
            return $remoteAddr;
        }

        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? null;

        if (!is_string($forwardedFor) || trim($forwardedFor) === '') {
            return $remoteAddr;
        }

        // Walk right-to-left, skipping trusted hops; the first untrusted hop
        // is the client. A malformed or empty field TERMINATES the walk — the
        // proxy that wrote it is the last hop we can reason about, so nothing
        // to its left may be believed.
        foreach (array_reverse(explode(',', $forwardedFor)) as $candidate) {
            $candidate = trim($candidate);

            if (!self::isIp($candidate)) {
                return $remoteAddr;
            }

            if (self::isTrusted($candidate, $trustedProxies)) {
                continue;
            }

            return $candidate;
        }

        // Every hop in the chain is trusted — internal traffic.
        return $remoteAddr;
    }

    /**
     * @param array<int, mixed> $trustedProxies
     */
    private static function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            // Fail-closed on a non-string entry: a filter returning junk
            // grants no trust rather than raising a TypeError.
            if (is_string($proxy) && NTDST_Cidr::contains($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One validity predicate for every address in this file, matching the
     * binary form NTDST_Cidr compares. Rejects host:port, textual junk and
     * the empty string.
     */
    private static function isIp(string $value): bool
    {
        return $value !== '' && inet_pton($value) !== false;
    }
}
