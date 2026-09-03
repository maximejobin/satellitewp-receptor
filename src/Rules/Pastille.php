<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * The coloured "pastille" SatelliteWP analysts read at a glance, from the health
 * report legend:
 *   green  — validated, follows best practices (bravo)
 *   orange — not a best practice, worth attention, not critical
 *   red    — critical to the site, fix without delay
 *   blue   — informational, no qualitative judgement
 *   grey   — not applicable / undetermined
 *
 * It is DERIVED from a finding's status + severity (severity stays the neutral
 * underlying data; the pastille is what the UI shows instead of a severity
 * label). Being a colour code, not prose, it is language-neutral.
 */
enum Pastille: string
{
    case Green = 'green';
    case Orange = 'orange';
    case Red = 'red';
    case Blue = 'blue';
    case Grey = 'grey';

    public static function for(Status $status, Severity $severity): self
    {
        return match (true) {
            // No result at all is grey — not a coloured judgement.
            $status === Status::NotApplicable, $status === Status::Unknown => self::Grey,
            // Evaluated Info-severity rules are informational — always blue.
            $severity === Severity::Info => self::Blue,
            $status === Status::Pass     => self::Green,
            default                      => in_array($severity, [Severity::Critical, Severity::High], true)
                ? self::Red   // fail, critical/high
                : self::Orange, // fail, medium
        };
    }
}
