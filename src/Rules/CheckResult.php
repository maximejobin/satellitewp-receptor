<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * What a rule's check closure returns. Language-neutral: it carries the outcome
 * and the raw values a message might interpolate ({observed}, plus named
 * $data placeholders), never any prose. The sentence is produced at display
 * time by the Translator.
 */
final readonly class CheckResult
{
    /** @param array<string, scalar|null> $data named interpolation values */
    public function __construct(
        public Status $status,
        public mixed $observed = null,
        public array $data = [],
        public ?Severity $severity = null,
    ) {
    }
}
