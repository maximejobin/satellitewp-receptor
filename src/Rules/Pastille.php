<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Rules;

/**
 * The coloured signal shown instead of a severity label:
 *   green  — passes, best practice followed
 *   orange — fails, worth attention, not critical
 *   red    — fails, critical to the site
 *   purple — a standing request for the client's own input (rule flag
 *            client_action), independent of severity
 *   blue   — informational, no qualitative judgement
 *   grey   — not applicable / undetermined
 *
 * Derived from a finding's status + severity + client_action; this enum is
 * the single list of colours every UI, report and CLI consumer iterates.
 */
enum Pastille: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Purple = 'purple';
    case Blue = 'blue';
    case Green = 'green';
    case Grey = 'grey';

    public static function for(Status $status, Severity $severity, bool $clientAction = false): self
    {
        return match (true) {
            $status === Status::NotApplicable, $status === Status::Unknown => self::Grey,
            $clientAction                => self::Purple,
            $severity === Severity::Info => self::Blue,
            $status === Status::Pass     => self::Green,
            default                      => in_array($severity, [Severity::Critical, Severity::High], true)
                ? self::Red
                : self::Orange,
        };
    }

    /**
     * Every colour value, most urgent first (declaration order).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /** Something a reader must act on — fails and client-action requests, never info/pass/n-a. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Red, self::Orange, self::Purple], true);
    }
}
