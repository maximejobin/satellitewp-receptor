<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Outcome of evaluating a rule against one extraction.
 *
 * The distinction between NotApplicable and Unknown matters for reports:
 * "the site has no CSS asset to compress" is not a problem, while "the probe
 * never ran" means we simply do not know — neither should look like a failure.
 */
enum Status: string
{
    case Pass          = 'pass';
    case Fail          = 'fail';
    case NotApplicable = 'na';
    case Unknown       = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Pass          => 'Conforme',
            self::Fail          => 'À corriger',
            self::NotApplicable => 'Non applicable',
            self::Unknown       => 'Indéterminé',
        };
    }
}
