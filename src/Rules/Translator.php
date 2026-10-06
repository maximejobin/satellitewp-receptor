<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Rules;

use RuntimeException;

/**
 * Renders language-neutral findings into sentences from config/lang/<locale>.php —
 * the only place language enters the rules pipeline.
 */
final class Translator
{
    /** @var array<string, mixed> */
    private array $catalog;

    public function __construct(
        public readonly string $locale,
        string $langDir,
        private readonly string $fallbackLocale = 'en',
    ) {
        $file = $langDir . '/' . preg_replace('/[^a-z-]/i', '', $locale) . '.php';
        if (!is_file($file)) {
            $file = $langDir . '/' . $this->fallbackLocale . '.php';
        }
        if (!is_file($file)) {
            throw new RuntimeException("No language catalogue found in {$langDir}");
        }

        $this->catalog = (array) require $file;
    }

    /** A UI chrome string by dotted key, e.g. ui('nav.sites'). */
    public function ui(string $key, string $default = ''): string
    {
        return (string) ($this->catalog['ui'][$key] ?? ($default !== '' ? $default : $key));
    }

    /** A label from the client report's own 'report' block (follows the report locale, unlike 'ui'). */
    public function report(string $key, string $default = ''): string
    {
        return (string) ($this->catalog['report'][$key] ?? ($default !== '' ? $default : $key));
    }

    public function status(string $status): string
    {
        return (string) ($this->catalog['status'][$status] ?? $status);
    }

    public function severity(string $code): string
    {
        return (string) ($this->catalog['severity'][$code] ?? $code);
    }

    /** Label for a pastille colour. */
    public function pastille(string $color): string
    {
        return (string) ($this->catalog['pastille'][$color] ?? $color);
    }

    /** The role WordPress gives a drop-in file, or null for a file it does not know. */
    public function dropin(string $file): ?string
    {
        $role = $this->catalog['dropins'][$file] ?? null;

        return is_string($role) ? $role : null;
    }

    public function category(string $code): string
    {
        return (string) ($this->catalog['categories'][$code] ?? $code);
    }

    /**
     * A rule's title — the verdict-specific 'title_success'/'title_failure'
     * for a pass/fail status, else the neutral 'title'.
     */
    public function title(string $ruleId, ?string $status = null): string
    {
        $rule = (array) ($this->catalog['rules'][$ruleId] ?? []);
        $key  = match ($status) {
            'pass'  => 'title_success',
            'fail'  => 'title_failure',
            default => null,
        };
        if ($key !== null && is_string($rule[$key] ?? null) && $rule[$key] !== '') {
            return $rule[$key];
        }

        return (string) ($rule['title'] ?? $ruleId);
    }

    /**
     * The rendered sentence for a finding, or null when the rule has no
     * template for its status. A finding whose data carries a 'variant'
     * uses "<status>_<variant>" when that template exists.
     *
     * @param array<string, mixed> $finding one entry of findings.json
     */
    public function message(array $finding): ?string
    {
        $template = $this->template($finding);

        return $template === null ? null : $this->interpolate($template, $finding);
    }

    /**
     * The raw template message() would render, placeholders untouched.
     *
     * @param array<string, mixed> $finding
     */
    public function template(array $finding): ?string
    {
        $rule    = (array) ($this->catalog['rules'][(string) ($finding['id'] ?? '')] ?? []);
        $status  = (string) ($finding['status'] ?? '');
        $variant = $finding['data']['variant'] ?? null;

        if (is_string($variant) && $variant !== '' && is_string($rule["{$status}_{$variant}"] ?? null)) {
            return $rule["{$status}_{$variant}"];
        }

        return is_string($rule[$status] ?? null) ? $rule[$status] : null;
    }

    /** @param array<string, mixed> $finding */
    private function interpolate(string $template, array $finding): string
    {
        $values = [
            'observed'  => $this->scalar($finding['observed'] ?? null),
            'threshold' => $this->scalar($finding['threshold'] ?? null),
        ];
        $raw = ['observed' => $finding['observed'] ?? null, 'threshold' => $finding['threshold'] ?? null];
        foreach ((array) ($finding['data'] ?? []) as $name => $value) {
            $values[(string) $name] = $this->scalar($value);
            $raw[(string) $name]    = $value;
        }

        // {name|singular|plural} first: its text contains no braces, so the plain pass below can't touch it.
        $template = preg_replace_callback(
            '/\{(\w+)\|([^{}|]*)\|([^{}|]*)\}/u',
            fn (array $m): string => $this->isSingular($raw[$m[1]] ?? null) ? $m[2] : $m[3],
            $template
        ) ?? $template;

        return preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => $values[$m[1]] ?? $m[0],
            $template
        ) ?? $template;
    }

    /** French treats 0 and 1 as singular; English only 1. Anything non-numeric reads as plural. */
    private function isSingular(mixed $value): bool
    {
        if (is_array($value)) {
            $value = count($value);
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return false;
        }
        $n = abs((float) $value);

        return str_starts_with($this->locale, 'fr') ? $n < 2 : $n == 1.0;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null  => '?',
            is_bool($value)  => $value ? '1' : '0',
            is_int($value), is_float($value) => $this->number($value),
            is_array($value) => (string) count($value),
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => $this->date($value),
            default          => (string) $value,
        };
    }

    /** "2026-07-06" as "6 juillet 2026" / "July 6, 2026". */
    private function date(string $iso): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $iso));
        if (!checkdate($m, $d, $y)) {
            return $iso;
        }
        if (str_starts_with($this->locale, 'fr')) {
            $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

            return ($d === 1 ? '1er' : (string) $d) . ' ' . $months[$m - 1] . ' ' . $y;
        }
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        return $months[$m - 1] . " {$d}, {$y}";
    }

    /** Up to 2 decimals, locale separators; thousands grouped only from 10 000 so years stay intact. */
    private function number(int|float $value): string
    {
        [$decimal, $thousands] = str_starts_with($this->locale, 'fr') ? [',', "\u{202F}"] : ['.', ','];
        $decimals = is_float($value) && floor($value) !== $value ? 2 : 0;
        $text     = number_format($value, $decimals, $decimal, abs($value) >= 10000 ? $thousands : '');

        return $decimals > 0 ? rtrim(rtrim($text, '0'), $decimal) : $text;
    }
}
