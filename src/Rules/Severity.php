<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Severity levels from .github/validations-techniques.txt:
 * (C)ritique, (É)levée, (M)oyenne, (I)nfo.
 */
enum Severity: string
{
    case Critique = 'C';
    case Elevee   = 'E';
    case Moyenne  = 'M';
    case Info     = 'I';

    public function label(): string
    {
        return match ($this) {
            self::Critique => 'Critique',
            self::Elevee   => 'Élevée',
            self::Moyenne  => 'Moyenne',
            self::Info     => 'Info',
        };
    }

    /** Sort weight — higher is more urgent. */
    public function weight(): int
    {
        return match ($this) {
            self::Critique => 4,
            self::Elevee   => 3,
            self::Moyenne  => 2,
            self::Info     => 1,
        };
    }

    /**
     * Report badge from .github/bilan-de-sante.txt:
     * red = à corriger, yellow = attention, blue = à vérifier avec le client.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Critique, self::Elevee => 'red',
            self::Moyenne                => 'yellow',
            self::Info                   => 'blue',
        };
    }
}
