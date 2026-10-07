<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use SatelliteWP\Manager\Support\JsonFileStore;

final class LoginLockout
{
    private const int MAX_ATTEMPTS   = 5;
    private const int WINDOW_SECONDS = 300;
    private const int LOCK_SECONDS   = 300;

    private readonly JsonFileStore $json;

    public function __construct(string $file)
    {
        $this->json = new JsonFileStore($file, 0600);
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
        $this->json->mutate(function (array $raw) use ($key): array {
            $all   = self::clean($raw);
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

            return [null, $this->prune($all)];
        });
    }

    /** A successful login means past failures no longer matter. */
    public function recordSuccess(string $key): void
    {
        $this->json->mutate(static function (array $raw) use ($key): array {
            $all = self::clean($raw);
            if (!isset($all[$key])) {
                return [null, null];
            }
            unset($all[$key]);

            return [null, $all];
        });
    }

    /** @return array<string, array{first_failure: int, count: int, locked_until: int}> */
    private function load(): array
    {
        return self::clean($this->json->read());
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, array{first_failure: int, count: int, locked_until: int}>
     */
    private static function clean(array $raw): array
    {
        $all = [];
        foreach ($raw as $key => $state) {
            if (is_array($state)) {
                $all[(string) $key] = [
                    'first_failure' => (int) ($state['first_failure'] ?? 0),
                    'count'         => (int) ($state['count'] ?? 0),
                    'locked_until'  => (int) ($state['locked_until'] ?? 0),
                ];
            }
        }

        return $all;
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
}
