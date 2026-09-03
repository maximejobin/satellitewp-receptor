<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Severity levels from .github/validations-techniques.txt:
 * (C)ritique, (É)levée, (M)oyenne, (I)nfo.
 *
 * Case names are language-neutral codes (like Status and Pastille), never
 * displayed as-is — display strings live in config/lang/*.php, rendered via
 * Rules\Translator.
 */
enum Severity: string
{
    case Critical = 'C';
    case High     = 'E';
    case Medium   = 'M';
    case Info     = 'I';

    /** Sort weight — higher is more urgent. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High     => 3,
            self::Medium   => 2,
            self::Info     => 1,
        };
    }
}
