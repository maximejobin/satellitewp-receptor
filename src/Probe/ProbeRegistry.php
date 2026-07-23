<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use InvalidArgumentException;

/**
 * Holds every registered probe; "enabled" (from config) controls what the
 * pipeline runs by default. probe:run can still execute a disabled probe.
 */
final class ProbeRegistry
{
    /** @var array<string, ProbeInterface> */
    private array $probes = [];

    /** @param list<string> $enabled */
    public function __construct(private readonly array $enabled)
    {
    }

    public function register(ProbeInterface $probe): void
    {
        $this->probes[$probe->name()] = $probe;
    }

    public function get(string $name): ProbeInterface
    {
        return $this->probes[$name]
            ?? throw new InvalidArgumentException("Unknown probe \"{$name}\"");
    }

    public function has(string $name): bool
    {
        return isset($this->probes[$name]);
    }

    /** @return list<ProbeInterface> enabled probes, in configured order */
    public function enabled(): array
    {
        $list = [];
        foreach ($this->enabled as $name) {
            if (isset($this->probes[$name])) {
                $list[] = $this->probes[$name];
            }
        }

        return $list;
    }

    /** @return array<string, ProbeInterface> */
    public function all(): array
    {
        return $this->probes;
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabled, true);
    }
}
