<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

final class HostGuard
{
    /**
     * host => vetted IP, for the process lifetime. The cached address is both
     * the one checked and the one pinned, so this never reopens a DNS-rebinding
     * window. Failures are not cached: a transient lookup error is retried.
     *
     * @var array<string, string>
     */
    private static array $resolved = [];

    /**
     * True when every address a hostname resolves to (or the address itself,
     * if $host is already an IP literal) is publicly routable. False for an
     * unresolvable host — refusing to guess is safer than assuming "fine".
     */
    public static function isPubliclyRoutable(string $host): bool
    {
        return self::publicIpFor($host) !== null;
    }

    /** Same check, applied to a URL's host component — used per redirect hop. */
    public static function isSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && self::isPubliclyRoutable($host);
    }

    /**
     * The one vetted address to connect to for $host, or null when the host
     * does not resolve or ANY of its addresses is non-public. Callers pin the
     * connection to it (CURLOPT_RESOLVE, or connecting to the IP with the host
     * as peer name) so the address that was checked is the address that is
     * used — a second, independent lookup by curl could otherwise be answered
     * differently (DNS rebinding).
     */
    public static function publicIpFor(string $host): ?string
    {
        if ($host === '') {
            return null;
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return self::selectPublicIp([$literal]);
        }

        $key = strtolower($host);
        if (isset(self::$resolved[$key])) {
            return self::$resolved[$key];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips     = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $ip    = $record['ip'] ?? $record['ipv6'] ?? null;
            $ips[] = is_string($ip) ? $ip : '';
        }

        $ip = self::selectPublicIp($ips);
        if ($ip !== null) {
            self::$resolved[$key] = $ip;
        }

        return $ip;
    }

    /**
     * Pure: null when the list is empty or holds any non-public (or invalid)
     * address, else the first IPv4 address, else the first address.
     *
     * @param list<string> $ips
     */
    public static function selectPublicIp(array $ips): ?string
    {
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }

        return $ips[0];
    }

    /** Pure: one CURLOPT_RESOLVE entry, "host:port:ip" — an IPv6 address in brackets. */
    public static function curlResolveEntry(string $host, int $port, string $ip): string
    {
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$ip}]" : $ip;

        return "{$host}:{$port}:{$address}";
    }

    private static function isPublicIp(string $ip): bool
    {
        // PHP's own reserved-range tables, no hand-rolled CIDR list. GLOBAL_RANGE
        // adds what NO_PRIV/NO_RES miss, notably CGNAT 100.64.0.0/10 (which
        // holds cloud metadata endpoints such as 100.100.100.200).
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        ) !== false;
    }
}
