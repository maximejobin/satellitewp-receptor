<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Read-only view of one extraction for rule evaluation: the plugin payload plus
 * every probe result. Rules address data with dot paths:
 *
 *   payload.php.memory_limit
 *   probe.http.redirects.forces_https   (resolves inside the probe's "data")
 *
 * No network access happens here — evaluation is pure.
 */
final readonly class Context
{
    /**
     * @param array<string, mixed> $payload extraction payload
     * @param array<string, array<string, mixed>> $probes probe name => envelope
     */
    public function __construct(
        private array $payload,
        private array $probes = [],
    ) {
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $root     = array_shift($segments);

        $value = match ($root) {
            'payload' => $this->payload,
            'probe'   => $this->probeData(array_shift($segments) ?? ''),
            default   => null,
        };

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /** The "data" section of a probe, or null when the probe did not run. */
    public function probeData(string $name): ?array
    {
        $envelope = $this->probes[$name] ?? null;

        return is_array($envelope) ? (array) ($envelope['data'] ?? []) : null;
    }

    public function probeRan(string $name): bool
    {
        return isset($this->probes[$name])
            && ($this->probes[$name]['status'] ?? null) !== 'error';
    }

    public function bool(string $path): ?bool
    {
        $value = $this->get($path);

        return $value === null ? null : (bool) $value;
    }

    public function number(string $path): ?float
    {
        $value = $this->get($path);

        return is_numeric($value) ? (float) $value : null;
    }

    public function string(string $path): ?string
    {
        $value = $this->get($path);

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return array<int|string, mixed> */
    public function list(string $path): array
    {
        $value = $this->get($path);

        return is_array($value) ? $value : [];
    }

    public function count(string $path): ?int
    {
        $value = $this->get($path);

        return is_array($value) ? count($value) : null;
    }

    /**
     * PHP ini shorthand ("256M", "64K", "1G", "-1") to bytes.
     * Returns INF for unlimited (-1), null when unparseable.
     */
    public function bytes(string $path): ?float
    {
        $raw = $this->get($path);
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = trim((string) $raw);
        if ($raw === '-1') {
            return INF;
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?)B?$/i', $raw, $m)) {
            return null;
        }

        return (float) $m[1] * match (strtoupper($m[2])) {
            'K'     => 1024,
            'M'     => 1024 ** 2,
            'G'     => 1024 ** 3,
            'T'     => 1024 ** 4,
            default => 1,
        };
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
