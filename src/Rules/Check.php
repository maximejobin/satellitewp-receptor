<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Factories used inside rule definitions. Everything here is language-neutral:
 * checks return an outcome plus raw values ($observed and named $data), never
 * a translated sentence.
 */
final class Check
{
    /** @param array<string, scalar|null> $data */
    public static function pass(mixed $observed = null, array $data = []): CheckResult
    {
        return new CheckResult(Status::Pass, $observed, $data);
    }

    /**
     * $severity overrides the rule's default — used by graded thresholds.
     *
     * @param array<string, scalar|null> $data
     */
    public static function fail(mixed $observed = null, array $data = [], ?Severity $severity = null): CheckResult
    {
        return new CheckResult(Status::Fail, $observed, $data, $severity);
    }

    /** The rule does not apply to this site (nothing to check, not a problem). */
    public static function na(): CheckResult
    {
        return new CheckResult(Status::NotApplicable);
    }

    /** The data needed is missing — probe failed, field absent, etc. */
    public static function unknown(): CheckResult
    {
        return new CheckResult(Status::Unknown);
    }

    /** Passes when the value is strictly true. */
    public static function isTrue(?bool $value): CheckResult
    {
        return match ($value) {
            true  => self::pass(true),
            false => self::fail(false),
            null  => self::unknown(),
        };
    }

    /** Passes when the value is strictly false (e.g. "debug must be off"). */
    public static function isFalse(?bool $value): CheckResult
    {
        return match ($value) {
            false => self::pass(false),
            true  => self::fail(true),
            null  => self::unknown(),
        };
    }

    /** Passes when $value <= $max. */
    public static function atMost(?float $value, float $max): CheckResult
    {
        if ($value === null) {
            return self::unknown();
        }

        return $value <= $max ? self::pass($value) : self::fail($value);
    }

    /** Passes when $value >= $min. */
    public static function atLeast(?float $value, float $min): CheckResult
    {
        if ($value === null) {
            return self::unknown();
        }

        return $value >= $min ? self::pass($value) : self::fail($value);
    }

    /**
     * Graded thresholds: the first matching level wins. Levels are
     * [maximum, Severity] pairs, most severe first.
     *
     * @param list<array{0: float, 1: Severity}> $levels
     */
    public static function graded(?float $value, array $levels): CheckResult
    {
        if ($value === null) {
            return self::unknown();
        }

        foreach ($levels as [$max, $severity]) {
            if ($value < $max) {
                return self::fail($value, [], $severity);
            }
        }

        return self::pass($value);
    }
}
