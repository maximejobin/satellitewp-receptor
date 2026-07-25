<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

use Closure;
use InvalidArgumentException;

/**
 * One validation from the catalogue. Language-neutral: it holds the id,
 * category, source, default severity, configurable threshold and the check
 * closure. Titles and messages (per language) live in the translation
 * catalogue keyed by this id — never here.
 */
final readonly class Rule
{
    public function __construct(
        public string $id,
        public string $category,
        public string $source,
        public Severity $severity,
        public Closure $check,
        public mixed $threshold = null,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     * @param mixed $thresholdOverride from config, replaces the catalogue default
     */
    public static function fromArray(array $definition, mixed $thresholdOverride = null): self
    {
        foreach (['id', 'category', 'source', 'severity', 'check'] as $key) {
            if (!isset($definition[$key])) {
                $id = $definition['id'] ?? '?';

                throw new InvalidArgumentException("Rule {$id} is missing \"{$key}\"");
            }
        }

        if (!Category::isValid((string) $definition['category'])) {
            throw new InvalidArgumentException(
                "Rule {$definition['id']} has unknown category \"{$definition['category']}\""
            );
        }

        return new self(
            id: (string) $definition['id'],
            category: (string) $definition['category'],
            source: (string) $definition['source'],
            severity: $definition['severity'] instanceof Severity
                ? $definition['severity']
                : Severity::from((string) $definition['severity']),
            check: $definition['check'],
            threshold: $thresholdOverride ?? ($definition['threshold'] ?? null),
        );
    }
}
