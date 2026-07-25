<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

use RuntimeException;

/**
 * Renders language-neutral findings into sentences, in a chosen locale, from
 * the translation catalogue (config/lang/<locale>.php). This is the ONLY place
 * language enters the rules pipeline: source data and findings.json stay
 * neutral, and any consumer (English UI, French report) renders with the
 * locale it wants.
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

    public function status(string $status): string
    {
        return (string) ($this->catalog['status'][$status] ?? $status);
    }

    public function severity(string $code): string
    {
        return (string) ($this->catalog['severity'][$code] ?? $code);
    }

    public function category(string $code): string
    {
        return (string) ($this->catalog['categories'][$code] ?? $code);
    }

    /** The short title of a rule. */
    public function title(string $ruleId): string
    {
        return (string) ($this->catalog['rules'][$ruleId]['title'] ?? $ruleId);
    }

    /**
     * The rendered sentence for a finding: the pass/fail template for its rule,
     * interpolated with {observed}, {threshold} and any named data values.
     * Returns null when no template exists for that status (e.g. a rule with no
     * "pass" phrase — the UI then shows the status label instead).
     *
     * @param array<string, mixed> $finding one entry of findings.json
     */
    public function message(array $finding): ?string
    {
        $id     = (string) ($finding['id'] ?? '');
        $status = (string) ($finding['status'] ?? '');
        $key    = match ($status) {
            'fail'  => 'fail',
            'pass'  => 'pass',
            default => $status,
        };

        $template = $this->catalog['rules'][$id][$key] ?? null;
        if (!is_string($template)) {
            return null;
        }

        return $this->interpolate($template, $finding);
    }

    /** @param array<string, mixed> $finding */
    private function interpolate(string $template, array $finding): string
    {
        $values = [
            'observed'  => $this->scalar($finding['observed'] ?? null),
            'threshold' => $this->scalar($finding['threshold'] ?? null),
        ];
        foreach ((array) ($finding['data'] ?? []) as $name => $value) {
            $values[(string) $name] = $this->scalar($value);
        }

        return preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => $values[$m[1]] ?? $m[0],
            $template
        ) ?? $template;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null  => '?',
            is_bool($value)  => $value ? '1' : '0',
            is_float($value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
            is_array($value) => (string) count($value),
            default          => (string) $value,
        };
    }
}
