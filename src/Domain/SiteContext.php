<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Domain;

/**
 * Everything a probe needs to know about a site, derived from an extraction payload.
 */
final readonly class SiteContext
{
    /**
     * @param list<array<string, mixed>> $plugins raw payload.plugins entries (unnormalized slugs)
     * @param list<array<string, mixed>> $themes  raw payload.themes entries
     * @param array{username: string, password: string}|null $httpAuth HTTP Basic Auth this
     *        server should send when probing the site directly (HttpProbe). Not part of the
     *        extraction payload — set by hand per site (KeyStore::setHttpAuth(), the site's
     *        own "⚙ Site settings" panel) for a site that sits behind Basic Auth (a staging
     *        environment, an IP-restriction bypass, …). Without it, HttpProbe's passive
     *        exposure checks (xmlrpc/REST-enum/sensitive-files/…) would all 401 and read as
     *        a false "clean" instead of "couldn't check" — see HttpProbe::exposureCheck().
     */
    public function __construct(
        public string $siteId,
        public string $siteUrl,
        public string $homeUrl,
        public string $host,
        public string $registrableDomain,
        public array $plugins = [],
        public array $themes = [],
        public ?string $wpVersion = null,
        public ?array $httpAuth = null,
    ) {
    }

    /**
     * Public suffixes that take **two** labels, not one — the exceptions a
     * plain "last two labels" rule gets wrong (`example.co.uk` would
     * otherwise come out as `co.uk`). Not the full Mozilla Public Suffix
     * List (thousands of entries, almost all single-label and already
     * handled correctly by the default): a curated set of the ccTLDs likely
     * to show up for this project's actual client base. Extend as a real
     * site surfaces one that's missing rather than importing the whole PSL
     * pre-emptively.
     */
    private const array TWO_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz',
        'co.za', 'org.za', 'net.za',
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
        'com.br', 'net.br', 'org.br',
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in',
        'com.mx', 'com.ar', 'com.co', 'com.pe', 'com.br',
        'co.il', 'org.il', 'net.il',
        'com.sg', 'com.hk', 'co.kr', 'co.id', 'co.th',
        'com.tw', 'org.tw',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array{username: string, password: string}|null $httpAuth see the constructor docblock
     */
    public static function fromExtractionPayload(string $siteId, array $payload, ?array $httpAuth = null): self
    {
        $siteUrl = (string) ($payload['site_url'] ?? '');
        $homeUrl = (string) ($payload['home_url'] ?? $siteUrl);

        $host = (string) (parse_url($homeUrl !== '' ? $homeUrl : $siteUrl, PHP_URL_HOST) ?? '');

        $registrableDomain = self::registrableDomain($host);

        $wpVersion = isset($payload['wp_version']) && $payload['wp_version'] !== ''
            ? (string) $payload['wp_version']
            : null;

        return new self(
            $siteId,
            $siteUrl,
            $homeUrl,
            $host,
            $registrableDomain,
            plugins: is_array($payload['plugins'] ?? null) ? $payload['plugins'] : [],
            themes: is_array($payload['themes'] ?? null) ? $payload['themes'] : [],
            wpVersion: $wpVersion,
            httpAuth: $httpAuth,
        );
    }

    /**
     * The registered domain a WHOIS/RDAP lookup and NS/MX/CAA/DMARC DNS
     * queries must target — never the full hostname. A leading "www." is
     * stripped like any other subdomain rather than as a special case.
     *
     * Was previously "strip a leading www., otherwise use the host
     * verbatim" — correct only for a bare or www-prefixed domain. Any other
     * subdomain depth (`latest.1.example.ca`, a hosting panel's internal
     * vhost alias, live) was queried as-is: RDAP/WHOIS have no record of a
     * "domain" with three extra subdomain labels prepended, so the probe
     * always came back empty for a site behind one — silently, since an
     * empty-but-well-formed WHOIS/RDAP response isn't a probe error.
     */
    public static function registrableDomain(string $host): string
    {
        $labels = array_values(array_filter(explode('.', strtolower($host)), static fn (string $s): bool => $s !== ''));
        $count  = count($labels);

        if ($count <= 2) {
            return implode('.', $labels);
        }

        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        $take    = in_array($lastTwo, self::TWO_LABEL_SUFFIXES, true) ? 3 : 2;
        $take    = min($take, $count);

        return implode('.', array_slice($labels, -$take));
    }
}
