<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Domain;

/**
 * Everything a probe needs to know about a site, derived from an extraction payload.
 */
final readonly class SiteContext
{
    /**
     * @param list<array<string, mixed>> $plugins raw payload.plugins entries (unnormalized slugs)
     * @param list<array<string, mixed>> $themes  raw payload.themes entries
     * @param array{username: string, password: string}|null $httpAuth per-site Basic Auth for
     *        HttpProbe (KeyStore::setHttpAuth()), not part of the payload
     * @param ?string $locale the analyst's chosen report language (meta.json), null = config default
     * @param ?string $blogvaultSiteId the BlogVault site id the blogvault probe resolved
     *        for this extraction, handed to probes that link on it (CrmProbe)
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
        public ?string $locale = null,
        public ?string $blogvaultSiteId = null,
    ) {
    }

    public function withBlogvaultSiteId(?string $id): self
    {
        return new self(
            $this->siteId,
            $this->siteUrl,
            $this->homeUrl,
            $this->host,
            $this->registrableDomain,
            $this->plugins,
            $this->themes,
            $this->wpVersion,
            $this->httpAuth,
            $this->locale,
            $id,
        );
    }

    /**
     * Public suffixes spanning more than one label, where "last two labels"
     * is wrong (example.co.uk, example.qc.ca). A curated subset of the Public
     * Suffix List for this client base, not the full list.
     */
    private const array MULTI_LABEL_SUFFIXES = [
        'qc.ca', 'on.ca', 'bc.ca', 'ab.ca', 'mb.ca', 'nb.ca', 'nl.ca', 'ns.ca', 'pe.ca', 'sk.ca', 'nt.ca', 'nu.ca', 'yk.ca',
        'gouv.qc.ca',
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz',
        'co.za', 'org.za', 'net.za',
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
        'com.br', 'net.br', 'org.br',
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in',
        'com.mx', 'com.ar', 'com.co', 'com.pe',
        'co.il', 'org.il', 'net.il',
        'com.sg', 'com.hk', 'co.kr', 'co.id', 'co.th',
        'com.tw', 'org.tw',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array{username: string, password: string}|null $httpAuth see the constructor docblock
     */
    public static function fromExtractionPayload(string $siteId, array $payload, ?array $httpAuth = null, ?string $locale = null): self
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
            locale: $locale,
        );
    }

    /**
     * The registered domain WHOIS/RDAP and NS/MX/CAA/DMARC lookups must
     * target, never the full hostname: the public suffix plus one label.
     */
    public static function registrableDomain(string $host): string
    {
        $labels = array_values(array_filter(explode('.', strtolower($host)), static fn (string $s): bool => $s !== ''));
        $count  = count($labels);

        $suffixLabels = 1;
        foreach (self::MULTI_LABEL_SUFFIXES as $suffix) {
            $length = substr_count($suffix, '.') + 1;
            if ($length > $suffixLabels && $count > $length && implode('.', array_slice($labels, -$length)) === $suffix) {
                $suffixLabels = $length;
            }
        }

        return implode('.', array_slice($labels, -min($count, $suffixLabels + 1)));
    }
}
