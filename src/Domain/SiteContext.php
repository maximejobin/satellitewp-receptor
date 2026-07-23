<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Domain;

/**
 * Everything a probe needs to know about a site, derived from an extraction payload.
 */
final readonly class SiteContext
{
    public function __construct(
        public string $siteId,
        public string $siteUrl,
        public string $homeUrl,
        public string $host,
        public string $registrableDomain,
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

        return new self($siteId, $siteUrl, $homeUrl, $host, $registrableDomain);
    }
}
