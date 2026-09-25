<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

use RuntimeException;

/**
 * Disposable tokens for the "report data key" button on an extraction page —
 * lets a script (the Google Docs report template's Apps Script) fetch one
 * extraction's report.json without ever holding config's shared
 * `reports.api_key`. Each token is scoped to exactly one site + extraction
 * and expires quickly: a leaked one exposes one report for a short window,
 * never every report forever the way the shared key would.
 *
 * Stored in data/report-tokens.json, same flat-JSON style as KeyStore.
 * Expired entries are dropped on every issue() — this file never needs its
 * own cleanup job.
 */
final class ReportTokenStore
{
    private const int TTL_SECONDS = 3600;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Mints a fresh token for this site + extraction, valid for one hour.
     * $issuedBy is the signed-in analyst's display name at the moment they
     * clicked "Report data key" — captured here because report.json itself
     * is fetched later, cold, with no session to read it from (that's the
     * whole point of this token). Empty when minted via the shared
     * reports.api_key path, which has no analyst identity attached to it.
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

    /**
     * The analyst's display name captured when $token was minted, or '' if
     * the token is missing/expired or carries none (the shared
     * reports.api_key path). Call only after verify() already confirmed the
     * token is valid for this site + extraction — this does no such check
     * itself, it just reads whatever is on file for the token.
     */
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
