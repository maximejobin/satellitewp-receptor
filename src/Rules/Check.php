<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Factories used inside rule definitions, plus the handful of comparison
 * helpers that cover most rules. Keeping these terse is what lets the
 * catalogue read like the source document.
 */
final class Check
{
    public static function pass(mixed $observed = null, ?string $detail = null): CheckResult
    {
        return new CheckResult(Status::Pass, $observed, $detail);
    }

    /** $severity overrides the rule's default — used by graded thresholds. */
    public static function fail(
        mixed $observed = null,
        ?string $detail = null,
        ?Severity $severity = null
    ): CheckResult {
        return new CheckResult(Status::Fail, $observed, $detail, $severity);
    }

    /** The rule does not apply to this site (nothing to check, not a problem). */
    public static function na(string $reason): CheckResult
    {
        return new CheckResult(Status::NotApplicable, null, $reason);
    }

    /** The data needed is missing — probe failed, field absent, etc. */
    public static function unknown(?string $detail = null): CheckResult
    {
        return new CheckResult(Status::Unknown, null, $detail);
    }

    /** Passes when the value is strictly true. */
    public static function isTrue(?bool $value, ?string $failDetail = null): CheckResult
    {
        return match ($value) {
            true  => self::pass(true),
            false => self::fail(false, $failDetail),
            null  => self::unknown('Donnée absente'),
        };
    }

    /** Passes when the value is strictly false (e.g. "debug must be off"). */
    public static function isFalse(?bool $value, ?string $failDetail = null): CheckResult
    {
        return match ($value) {
            false => self::pass(false),
            true  => self::fail(true, $failDetail),
            null  => self::unknown('Donnée absente'),
        };
    }

    /** Passes when $value <= $max. */
    public static function atMost(?float $value, float $max, ?string $failDetail = null): CheckResult
    {
        if ($value === null) {
            return self::unknown('Donnée absente');
        }

        return $value <= $max ? self::pass($value) : self::fail($value, $failDetail);
    }

    /** Passes when $value >= $min. */
    public static function atLeast(?float $value, float $min, ?string $failDetail = null): CheckResult
    {
        if ($value === null) {
            return self::unknown('Donnée absente');
        }

        return $value >= $min ? self::pass($value) : self::fail($value, $failDetail);
    }

    /**
     * Graded thresholds: the first matching level wins.
     * Levels are [maximum, Severity] pairs, ordered most severe first —
     * e.g. TLS expiry: [[15, Elevee], [30, Moyenne]].
     *
     * @param list<array{0: float, 1: Severity}> $levels
     */
    public static function graded(?float $value, array $levels): CheckResult
    {
        if ($value === null) {
            return self::unknown('Donnée absente');
        }

        foreach ($levels as [$max, $severity]) {
            if ($value < $max) {
                return self::fail($value, null, $severity);
            }
        }

        return self::pass($value);
    }
}
