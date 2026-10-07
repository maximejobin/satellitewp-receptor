<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

use SatelliteWP\Manager\Support\JsonFileStore;

/**
 * One-hour tokens scoped to one extraction's report.json (the "Report data
 * key" button), so a leaked link exposes one report briefly — never all of
 * them like the shared reports.api_key. Expired entries are pruned on issue().
 */
final class ReportTokenStore
{
    private const int TTL_SECONDS = 3600;

    private readonly JsonFileStore $json;

    /** Live bearer tokens: the file is owner-only. */
    public function __construct(string $file)
    {
        $this->json = new JsonFileStore($file, 0600);
    }

    /**
     * Mints a one-hour token. $issuedBy (the analyst's name) is captured now
     * because report.json is later fetched with no session.
     */
    public function issue(string $siteId, string $extractionId, string $issuedBy = ''): string
    {
        $token = bin2hex(random_bytes(24));
        $now   = time();

        $this->json->mutate(function (array $tokens) use ($token, $now, $siteId, $extractionId, $issuedBy): array {
            $tokens = $this->pruneExpired($tokens, $now);
            $tokens[$token] = [
                'site_id'       => $siteId,
                'extraction_id' => $extractionId,
                'issued_by'     => $issuedBy,
                'expires_at'    => $now + self::TTL_SECONDS,
            ];

            return [null, $tokens];
        });

        return $token;
    }

    /**
     * True when $token is unexpired and was issued for exactly this site +
     * extraction — a token for one report must not open a different one.
     */
    public function verify(string $token, string $siteId, string $extractionId): bool
    {
        $entry = $this->all()[$token] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        return ($entry['expires_at'] ?? 0) > time()
            && ($entry['site_id'] ?? null) === $siteId
            && ($entry['extraction_id'] ?? null) === $extractionId;
    }

    /** The name captured at mint time, or ''. Does not validate: call verify() first. */
    public function issuedBy(string $token): string
    {
        $entry = $this->all()[$token] ?? null;

        return is_array($entry) ? (string) ($entry['issued_by'] ?? '') : '';
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->json->read();
    }

    /**
     * @param array<mixed> $tokens
     * @return array<mixed>
     */
    private function pruneExpired(array $tokens, int $now): array
    {
        return array_filter($tokens, static fn (mixed $t): bool => is_array($t) && ($t['expires_at'] ?? 0) > $now);
    }
}
