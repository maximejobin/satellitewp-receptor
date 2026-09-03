<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use RuntimeException;

/**
 * Locks out an address after too many failed Basic Auth attempts on the
 * admin UI (2026-08-31 — `Router::authenticate()` had no attempt limit at
 * all). Keyed by the caller's IP (`$_SERVER['REMOTE_ADDR']`) since Basic
 * Auth has no session to key on before a request is verified.
 *
 * Same file-backed, atomic-write pattern as `KeyStore`/`ReplayCache`, stored
 * at `data/login-lockout.json`. Only relevant when Basic Auth is actually
 * configured (`web.user`/`web.pass_hash`) — Google sign-in has its own
 * protections (OAuth, an allowlist) and is not gated by this.
 */
final class LoginLockout
{
    private const int MAX_ATTEMPTS   = 5;
    private const int WINDOW_SECONDS = 300;
    private const int LOCK_SECONDS   = 300;

    public function __construct(private readonly string $file)
    {
    }

    public function isLocked(string $key): bool
    {
        $state = $this->load()[$key] ?? null;

        return $state !== null && $state['locked_until'] > time();
    }

    /** Seconds remaining on the lock, or 0 when not locked — for a `Retry-After` header. */
    public function retryAfter(string $key): int
    {
        $state = $this->load()[$key] ?? null;
        $until = $state['locked_until'] ?? 0;

        return max(0, $until - time());
    }

    public function recordFailure(string $key): void
    {
        $all   = $this->load();
        $now   = time();
        $state = $all[$key] ?? ['first_failure' => $now, 'count' => 0, 'locked_until' => 0];

        // A stale, expired window (no active lock, and the last failure was
        // long enough ago) starts counting from zero rather than accumulating
        // forever — only a *burst* of failures should trip the lock.
        if ($state['locked_until'] <= $now && ($now - $state['first_failure']) > self::WINDOW_SECONDS) {
            $state = ['first_failure' => $now, 'count' => 0, 'locked_until' => 0];
        }

        $state['count']++;
        if ($state['count'] >= self::MAX_ATTEMPTS) {
            $state['locked_until'] = $now + self::LOCK_SECONDS;
        }

        $all[$key] = $state;
        $this->save($this->prune($all));
    }

    /** A successful login means past failures no longer matter. */
    public function recordSuccess(string $key): void
    {
        $all = $this->load();
        unset($all[$key]);
        $this->save($all);
    }

    /** @return array<string, array{first_failure: int, count: int, locked_until: int}> */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array{first_failure: int, count: int, locked_until: int}> $all
     * @return array<string, array{first_failure: int, count: int, locked_until: int}>
     */
    private function prune(array $all): array
    {
        $now = time();

        return array_filter(
            $all,
            static fn (array $s): bool => $s['locked_until'] > $now || ($now - $s['first_failure']) <= self::WINDOW_SECONDS
        );
    }

    /** @param array<string, array{first_failure: int, count: int, locked_until: int}> $all */
    private function save(array $all): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory {$dir}");
        }

        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp  = $this->file . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to write {$this->file}");
        }
        @chmod($this->file, 0600);
    }
}
