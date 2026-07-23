<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor;

/**
 * Merged configuration: config/config.php defaults + config/config.local.php overrides.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $configDir): self
    {
        $defaults = require $configDir . '/config.php';

        $localFile = $configDir . '/config.local.php';
        $local     = is_file($localFile) ? require $localFile : [];

        return new self(array_replace_recursive($defaults, $local));
    }

    /**
     * Dot-notation getter: get('probes.timeout', 15).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
