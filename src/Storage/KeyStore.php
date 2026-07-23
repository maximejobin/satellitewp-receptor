<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

use RuntimeException;

/**
 * Per-site API keys used to verify X-SWP-Signature. Stored in data/keys.json.
 */
final class KeyStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function getKey(string $siteId): ?string
    {
        $entry = $this->all()[$siteId] ?? null;

        if ($entry === null || !empty($entry['revoked'])) {
            return null;
        }

        return $entry['api_key'] ?? null;
    }

    public function addKey(string $siteId, ?string $apiKey = null, ?string $label = null): string
    {
        $apiKey ??= bin2hex(random_bytes(32));

        $keys          = $this->all();
        $keys[$siteId] = [
            'api_key'    => $apiKey,
            'label'      => $label,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'revoked'    => false,
        ];
        $this->save($keys);

        return $apiKey;
    }

    public function revokeKey(string $siteId): bool
    {
        $keys = $this->all();
        if (!isset($keys[$siteId])) {
            return false;
        }

        $keys[$siteId]['revoked']    = true;
        $keys[$siteId]['revoked_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->save($keys);

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string, mixed>> $keys */
    private function save(array $keys): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp  = $this->file . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to write {$this->file}");
        }
        @chmod($this->file, 0600);
    }
}
