<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Support;

/**
 * Refuses to let a probe connect to a private/loopback/link-local/reserved
 * address (2026-08-31 — a known gap flagged in a security review, closed on
 * request).
 *
 * `HttpProbe`/`TlsProbe` target `$site->host`, which comes straight from the
 * extraction payload's `home_url` — a legitimately-paired but compromised or
 * malicious site could point it at an internal address (`127.0.0.1`,
 * `169.254.169.254`, a `10.x`/`192.168.x` box on this server's own network)
 * and get this server to probe it on its behalf. Requires an already-valid
 * signed API key (not an anonymous attack surface), but that is a barrier a
 * compromised site clears by definition.
 *
 * Deliberately simple, not DNS-rebinding-proof: this checks the host's
 * *currently* resolved address once, before a probe run starts, and again on
 * every redirect hop `HttpProbe` follows (a public site could redirect to an
 * internal one). It does **not** pin the connection to that exact resolved
 * IP the way a fully hardened client would (e.g. cURL's `CURLOPT_RESOLVE`),
 * so a sufficiently motivated attacker controlling DNS for the paired
 * domain, with a very low TTL, could in principle swap the answer between
 * this check and the probe's own connection a moment later. Not fixed here
 * — see "Keep it simple" in CLAUDE.md; this closes the straightforward case
 * (a home_url that is simply an internal address, or redirects to one)
 * without taking on a DNS-pinning rewrite of the HTTP client.
 */
final class HostGuard
{
    /**
     * True when every address a hostname resolves to (or the address itself,
     * if $host is already an IP literal) is publicly routable. False for an
     * unresolvable host — refusing to guess is safer than assuming "fine".
     */
    public static function isPubliclyRoutable(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || !self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /** Same check, applied to a URL's host component — used per redirect hop. */
    public static function isSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && self::isPubliclyRoutable($host);
    }

    private static function isPublicIp(string $ip): bool
    {
        // PHP's own reserved-range table: excludes RFC1918 private space
        // (NO_PRIV_RANGE) and loopback/link-local/documentation/etc. ranges
        // (NO_RES_RANGE) for both IPv4 and IPv6 — no hand-rolled CIDR list.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
