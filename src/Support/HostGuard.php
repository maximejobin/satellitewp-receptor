<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Support;

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
