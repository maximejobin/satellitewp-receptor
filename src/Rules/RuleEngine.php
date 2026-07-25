<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

use Throwable;

/**
 * Evaluates the rule catalogue against one extraction. Makes no network calls
 * and produces language-neutral findings — no sentences are rendered here.
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
     * @return array<string, mixed> the findings.json payload (language-neutral)
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
            // The failure detail is neutral data, not prose.
            $result = new CheckResult(Status::Unknown, null, ['error' => $e->getMessage()]);
        }

        return new Finding(
            id: $rule->id,
            category: $rule->category,
            source: $rule->source,
            severity: $result->severity ?? $rule->severity,
            status: $result->status,
            observed: $result->observed,
            threshold: $rule->threshold,
            data: $result->data,
        );
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, mixed>
     */
    private function counts(array $findings): array
    {
        $counts = [
            'total'   => count($findings),
            'pass'    => 0,
            'fail'    => 0,
            'na'      => 0,
            'unknown' => 0,
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
