<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use RuntimeException;

/**
 * Remembers every signature accepted within its replay window, so the exact
 * same signed request cannot be replayed a second time while it is still
 * fresh (2026-08-31 — `SignatureVerifier` used to check the timestamp window
 * alone, which lets a request captured in transit be resent as-is for as
 * long as that window stays open).
 *
 * Stored at `data/replay-cache.json` — same shape and same atomic
 * write-then-rename pattern as `KeyStore`. Entries expire and are pruned on
 * every read, so the file never grows past what one replay window's worth
 * of traffic actually needs.
 *
 * Deliberately simple: keyed by the signature alone (an HMAC-SHA256 output
 * is unique enough per request on its own — two distinct legitimate requests
 * producing the same signature is not a real-world case to defend against),
 * and the read-modify-write below is not lock-protected against a genuine
 * concurrent race. For the traffic one site actually produces (at most a
 * handful of pushes a day), that is not worth the extra complexity — see
 * "Keep it simple" in CLAUDE.md.
 */
final class ReplayCache
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * True when $signature has already been accepted and its window has not
     * elapsed yet (a replay — reject it). False the first time, which also
     * records it as seen until $timestamp + $windowSeconds.
     */
    public function seenBefore(string $signature, int $timestamp, int $windowSeconds): bool
    {
        $cache = $this->prune($this->load());

        if (isset($cache[$signature])) {
            $this->save($cache);

            return true;
        }

        $cache[$signature] = $timestamp + $windowSeconds;
        $this->save($cache);

        return false;
    }

    /** @return array<string, int> signature => expires-at (unix timestamp) */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, int> $cache */
    /**
     * @param array<string, int> $cache
     * @return array<string, int>
     */
    private function prune(array $cache): array
    {
        $now = time();

        return array_filter($cache, static fn (int $expiresAt): bool => $expiresAt >= $now);
    }

    /** @param array<string, int> $cache */
    private function save(array $cache): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory {$dir}");
        }

        $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp  = $this->file . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to write {$this->file}");
        }
        @chmod($this->file, 0600);
    }
}
