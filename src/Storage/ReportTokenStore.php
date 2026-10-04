<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

use RuntimeException;

/**
 * One-hour tokens scoped to one extraction's report.json (the "Report data
 * key" button), so a leaked link exposes one report briefly — never all of
 * them like the shared reports.api_key. Expired entries are pruned on issue().
 */
final class ReportTokenStore
{
    private const int TTL_SECONDS = 3600;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Mints a one-hour token. $issuedBy (the analyst's name) is captured now
     * because report.json is later fetched with no session.
     */
    public function issue(string $siteId, string $extractionId, string $issuedBy = ''): string
    {
        $token = bin2hex(random_bytes(24));
        $now   = time();

        $tokens = $this->pruneExpired($this->all(), $now);
        $tokens[$token] = [
            'site_id'       => $siteId,
            'extraction_id' => $extractionId,
            'issued_by'     => $issuedBy,
            'expires_at'    => $now + self::TTL_SECONDS,
        ];

        $this->save($tokens);

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
        if (!is_file($this->file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, mixed>> $tokens
     * @return array<string, array<string, mixed>>
     */
    private function pruneExpired(array $tokens, int $now): array
    {
        return array_filter($tokens, static fn (array $t): bool => ($t['expires_at'] ?? 0) > $now);
    }

    /** @param array<string, array<string, mixed>> $tokens */
    private function save(array $tokens): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Live bearer tokens: owner-only, and a unique temp name so two
        // concurrent issue() calls can't rename each other's half-written file.
        $tmp = $this->file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, (string) json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
            || !chmod($tmp, 0600)
            || !rename($tmp, $this->file)
        ) {
            @unlink($tmp);
            throw new RuntimeException("Unable to write {$this->file}");
        }
    }
}
