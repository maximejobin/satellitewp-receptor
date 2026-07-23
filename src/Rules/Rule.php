<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

use Closure;
use InvalidArgumentException;

/**
 * One validation from the catalogue. Carries exactly what
 * .github/validations-techniques.txt asks for: id, category, source,
 * configurable threshold, severity, action message.
 */
final readonly class Rule
{
    public function __construct(
        public string $id,
        public string $category,
        public string $source,
        public Severity $severity,
        public string $title,
        public string $message,
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
        foreach (['id', 'category', 'source', 'severity', 'title', 'message', 'check'] as $key) {
            if (!isset($definition[$key])) {
                $id = $definition['id'] ?? '?';

                throw new InvalidArgumentException("Rule {$id} is missing \"{$key}\"");
            }
        }

        return new self(
            id: (string) $definition['id'],
            category: (string) $definition['category'],
            source: (string) $definition['source'],
            severity: $definition['severity'] instanceof Severity
                ? $definition['severity']
                : Severity::from((string) $definition['severity']),
            title: (string) $definition['title'],
            message: (string) $definition['message'],
            check: $definition['check'],
            threshold: $thresholdOverride ?? ($definition['threshold'] ?? null),
        );
    }
}
