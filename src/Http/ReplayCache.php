<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use RuntimeException;

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
