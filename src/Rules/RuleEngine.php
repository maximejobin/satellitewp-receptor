<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

use Throwable;

/**
 * Evaluates the rule catalogue against one extraction. Makes no network calls:
 * everything it needs is already in the payload and the probe results.
 *
 * A rule that throws never breaks the run — it becomes an "unknown" finding,
 * the same isolation principle the probe pipeline uses.
 */
final class RuleEngine
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, mixed> the findings.json payload
     */
    public function evaluate(Context $context): array
    {
        $findings = [];
        foreach ($this->rules as $rule) {
            $findings[] = $this->evaluateRule($rule, $context);
        }

        // Failures first, most severe first, then by id for stable output.
        usort($findings, static function (Finding $a, Finding $b): int {
            return [$b->isFailure(), $b->severity->weight(), $a->id]
                <=> [$a->isFailure(), $a->severity->weight(), $b->id];
        });

        return [
            'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'counts'       => $this->counts($findings),
            'findings'     => array_map(static fn (Finding $f): array => $f->toArray(), $findings),
        ];
    }

    private function evaluateRule(Rule $rule, Context $context): Finding
    {
        try {
            $result = ($rule->check)($context, $rule);
        } catch (Throwable $e) {
            $result = Check::unknown('Erreur d\'évaluation : ' . $e->getMessage());
        }

        return new Finding(
            id: $rule->id,
            category: $rule->category,
            source: $rule->source,
            severity: $result->severity ?? $rule->severity,
            status: $result->status,
            title: $rule->title,
            message: $result->status === Status::Fail
                ? $this->renderMessage($rule, $result)
                : null,
            observed: $result->observed,
            threshold: $rule->threshold,
            detail: $result->detail,
        );
    }

    /** Substitutes {observed} and {threshold} in the rule's action message. */
    private function renderMessage(Rule $rule, CheckResult $result): string
    {
        return strtr($rule->message, [
            '{observed}'  => $this->scalar($result->observed),
            '{threshold}' => $this->scalar($rule->threshold),
        ]);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null  => '?',
            is_bool($value)  => $value ? 'oui' : 'non',
            is_array($value) => (string) count($value),
            is_float($value) => rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ','),
            default          => (string) $value,
        };
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, mixed>
     */
    private function counts(array $findings): array
    {
        $counts = [
            'total'     => count($findings),
            'pass'      => 0,
            'fail'      => 0,
            'na'        => 0,
            'unknown'   => 0,
            'by_severity' => ['C' => 0, 'E' => 0, 'M' => 0, 'I' => 0],
        ];

        foreach ($findings as $finding) {
            $counts[$finding->status->value]++;

            if ($finding->isFailure()) {
                $counts['by_severity'][$finding->severity->value]++;
            }
        }

        return $counts;
    }
}
