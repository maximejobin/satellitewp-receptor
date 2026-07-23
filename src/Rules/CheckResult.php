<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * What a rule's check closure returns. Build these with the Check factories.
 */
final readonly class CheckResult
{
    public function __construct(
        public Status $status,
        public mixed $observed = null,
        public ?string $detail = null,
        public ?Severity $severity = null,
    ) {
    }
}
