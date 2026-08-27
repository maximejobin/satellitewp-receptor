<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use InvalidArgumentException;

/**
 * Minimal structural checks per payload type. Deliberately tolerant:
 * unknown keys pass through untouched (schema evolves plugin-side).
 */
final class PayloadValidator
{
    public const string TYPE_EXTRACTION = 'extraction';
    public const string TYPE_EVENT      = 'event';
    public const string TYPE_INTEGRITY  = 'integrity';

    public const array TYPES = [self::TYPE_EXTRACTION, self::TYPE_EVENT, self::TYPE_INTEGRITY];

    /**
     * @return array<string, mixed> the decoded payload
     * @throws InvalidArgumentException on invalid payloads (HTTP 422)
     */
    public function validate(string $rawBody, string $type, string $headerSiteId): array
    {
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            throw new InvalidArgumentException('Body is not a JSON object');
        }

        if (empty($payload['schema_version']) || !is_string($payload['schema_version'])) {
            throw new InvalidArgumentException('Missing schema_version');
        }

        if (empty($payload['site_id']) || $payload['site_id'] !== $headerSiteId) {
            throw new InvalidArgumentException('Body site_id does not match X-SWP-Site header');
        }

        match ($type) {
            self::TYPE_EXTRACTION => null, // flat object; presence of site_id/schema_version is enough
            self::TYPE_EVENT      => is_array($payload['events'] ?? null)
                ? null : throw new InvalidArgumentException('Event payload requires an "events" array'),
            self::TYPE_INTEGRITY  => is_array($payload['integrity'] ?? null)
                ? null : throw new InvalidArgumentException('Integrity payload requires an "integrity" object'),
            default => throw new InvalidArgumentException('Unknown payload type'),
        };

        return $payload;
    }

    /**
     * Normalizes a site address for comparison with a bound origin.
     *
     * Mirrors ConfigFile::normalize_url() in the plugin — scheme and a leading
     * "www." dropped, trailing slash trimmed — so an http->https move or a www
     * redirect does not read as a different site. Keep the two in step.
     */
    public static function normalizeOrigin(string $url): string
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $url);
        $url = (string) preg_replace('#^www\.#', '', $url);

        return rtrim($url, '/');
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
