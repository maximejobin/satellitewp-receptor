<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

use SatelliteWP\Manager\Support\JsonFileStore;

/**
 * Per-site API keys used to verify X-SWP-Signature. Stored in data/keys.json.
 */
final class KeyStore
{
    private readonly JsonFileStore $json;

    public function __construct(string $file)
    {
        $this->json = new JsonFileStore($file, 0600);
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
        ?string $origin = null,
    ): string {
        $apiKey ??= bin2hex(random_bytes(32));

        $this->json->mutate(static function (array $keys) use ($siteId, $apiKey, $origin): array {
            $existing = is_array($keys[$siteId] ?? null) ? $keys[$siteId] : [];
            $entry    = [
                'api_key'    => $apiKey,
                // A rotation (key re-added for a known site) must not unbind it —
                // that would silently disable the restored-backup 409 guard.
                'origin'     => $origin ?? ($existing['origin'] ?? null),
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'revoked'    => false,
            ];
            if (isset($existing['http_auth'])) {
                $entry['http_auth'] = $existing['http_auth'];
            }
            $keys[$siteId] = $entry;

            return [null, $keys];
        });

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
        return $this->updateEntry($siteId, static function (array $entry) use ($origin): array {
            $entry['origin']     = $origin;
            $entry['rebound_at'] = gmdate('Y-m-d\TH:i:s\Z');

            return $entry;
        });
    }

    /**
     * @return array{username: string, password: string}|null
     */
    public function getHttpAuth(string $siteId): ?array
    {
        $auth = $this->all()[$siteId]['http_auth'] ?? null;
        if (!is_array($auth) || !isset($auth['username'], $auth['password'])) {
            return null;
        }

        return ['username' => (string) $auth['username'], 'password' => (string) $auth['password']];
    }

    /** Set this site's Basic Auth credentials for probing, or clear them (empty/null username). */
    public function setHttpAuth(string $siteId, ?string $username, ?string $password): bool
    {
        return $this->updateEntry($siteId, static function (array $entry) use ($username, $password): array {
            if ($username === null || $username === '') {
                unset($entry['http_auth']);
            } else {
                $entry['http_auth'] = ['username' => $username, 'password' => (string) $password];
            }

            return $entry;
        });
    }

    public function revokeKey(string $siteId): bool
    {
        return $this->updateEntry($siteId, static function (array $entry): array {
            $entry['revoked']    = true;
            $entry['revoked_at'] = gmdate('Y-m-d\TH:i:s\Z');

            return $entry;
        });
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->json->read();
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return bool false when the site has no key record
     */
    private function updateEntry(string $siteId, callable $change): bool
    {
        return $this->json->mutate(static function (array $keys) use ($siteId, $change): array {
            if (!is_array($keys[$siteId] ?? null)) {
                return [false, null];
            }
            $keys[$siteId] = $change($keys[$siteId]);

            return [true, $keys];
        });
    }
}
