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
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromExtractionPayload(string $siteId, array $payload): self
    {
        $siteUrl = (string) ($payload['site_url'] ?? '');
        $homeUrl = (string) ($payload['home_url'] ?? $siteUrl);

        $host = (string) (parse_url($homeUrl !== '' ? $homeUrl : $siteUrl, PHP_URL_HOST) ?? '');

        // Naive registrable domain: strip a leading "www.". Good enough for v1.
        $registrableDomain = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

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
        );
    }
}
