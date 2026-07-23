<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

use RuntimeException;

/**
 * Loads config/rules.php into Rule objects, applying per-id threshold
 * overrides from configuration (rules.thresholds.<id>).
 */
final class RuleCatalog
{
    /**
     * @param array<string, mixed> $thresholdOverrides id => threshold
     * @return list<Rule>
     */
    public static function load(string $file, array $thresholdOverrides = []): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("Rule catalogue not found: {$file}");
        }

        $definitions = require $file;
        if (!is_array($definitions)) {
            throw new RuntimeException("Rule catalogue must return an array: {$file}");
        }

        $rules = [];
        $seen  = [];

        foreach ($definitions as $definition) {
            $rule = Rule::fromArray(
                (array) $definition,
                $thresholdOverrides[$definition['id'] ?? ''] ?? null
            );

            if (isset($seen[$rule->id])) {
                throw new RuntimeException("Duplicate rule id \"{$rule->id}\" in the catalogue");
            }
            $seen[$rule->id] = true;

            $rules[] = $rule;
        }

        return $rules;
    }
}
