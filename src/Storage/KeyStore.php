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

    public function addKey(
        string $siteId,
        ?string $apiKey = null,
        ?string $label = null,
        ?string $origin = null,
    ): string {
        $apiKey ??= bin2hex(random_bytes(32));

        $keys          = $this->all();
        $keys[$siteId] = [
            'api_key'    => $apiKey,
            'label'      => $label,
            'origin'     => $origin,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'revoked'    => false,
        ];
        $this->save($keys);

        return $apiKey;
    }

    /**
     * The address this site is bound to, or null while it is still unbound.
     *
     * Binding is what stops a site restored from a backup — same id, same key —
     * from reporting over the original's history. The plugin refuses to send in
     * that situation too, but that check runs on the client and a client can be
     * modified; this one cannot.
     */
    public function getOrigin(string $siteId): ?string
    {
        $origin = $this->all()[$siteId]['origin'] ?? null;

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * Binds the site to an address. Used on the first extraction received, and by
     * `keys:rebind` after a site legitimately moves.
     */
    public function setOrigin(string $siteId, string $origin): bool
    {
        $keys = $this->all();
        if (!isset($keys[$siteId])) {
            return false;
        }

        $keys[$siteId]['origin']      = $origin;
        $keys[$siteId]['rebound_at']  = gmdate('Y-m-d\TH:i:s\Z');
        $this->save($keys);

        return true;
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
