<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Rules;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SatelliteWP\Xtractor\Rules\Check;
use SatelliteWP\Xtractor\Rules\Context;
use SatelliteWP\Xtractor\Rules\Rule;
use SatelliteWP\Xtractor\Rules\RuleEngine;
use SatelliteWP\Xtractor\Rules\Severity;
use SatelliteWP\Xtractor\Rules\Status;

final class RuleEngineTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function rule(string $id, callable $check, array $overrides = []): Rule
    {
        // array_replace, not "+": with the union operator the LEFT side wins and
        // the overrides would be silently ignored.
        return Rule::fromArray(array_replace([
            'id'       => $id,
            'category' => 'Test',
            'source'   => 'DATA',
            'severity' => Severity::Moyenne,
            'title'    => "Règle {$id}",
            'message'  => "Observé {observed}, seuil {threshold}.",
            'check'    => $check,
        ], $overrides));
    }

    public function testEvaluateProducesCountsAndMessages(): void
    {
        $engine = new RuleEngine([
            $this->rule('R1', static fn () => Check::fail(42), ['threshold' => 10]),
            $this->rule('R2', static fn () => Check::pass(1)),
            $this->rule('R3', static fn () => Check::na('rien à vérifier')),
            $this->rule('R4', static fn () => Check::unknown('donnée absente')),
        ]);

        $result = $engine->evaluate(new Context([]));

        $this->assertSame(4, $result['counts']['total']);
        $this->assertSame(1, $result['counts']['fail']);
        $this->assertSame(1, $result['counts']['pass']);
        $this->assertSame(1, $result['counts']['na']);
        $this->assertSame(1, $result['counts']['unknown']);
        $this->assertSame(1, $result['counts']['by_severity']['M']);

        // Only failures carry a rendered action message.
        $byId = array_column($result['findings'], null, 'id');
        $this->assertSame('Observé 42, seuil 10.', $byId['R1']['message']);
        $this->assertNull($byId['R2']['message']);
        $this->assertSame('rien à vérifier', $byId['R3']['detail']);
    }

    public function testFailuresSortFirstThenBySeverity(): void
    {
        $engine = new RuleEngine([
            $this->rule('B', static fn () => Check::pass()),
            $this->rule('C', static fn () => Check::fail(1), ['severity' => Severity::Info]),
            $this->rule('A', static fn () => Check::fail(1), ['severity' => Severity::Critique]),
            $this->rule('D', static fn () => Check::fail(1), ['severity' => Severity::Critique]),
        ]);

        $ids = array_column($engine->evaluate(new Context([]))['findings'], 'id');

        $this->assertSame(['A', 'D', 'C', 'B'], $ids);
    }

    public function testThrowingRuleBecomesUnknownAndDoesNotBreakTheRun(): void
    {
        $engine = new RuleEngine([
            $this->rule('BOOM', static fn () => throw new RuntimeException('kaboom')),
            $this->rule('OK', static fn () => Check::pass()),
        ]);

        $result = $engine->evaluate(new Context([]));
        $byId   = array_column($result['findings'], null, 'id');

        $this->assertSame(Status::Unknown->value, $byId['BOOM']['status']);
        $this->assertStringContainsString('kaboom', $byId['BOOM']['detail']);
        $this->assertSame(Status::Pass->value, $byId['OK']['status']);
    }

    public function testCheckSeverityOverrideWins(): void
    {
        $engine = new RuleEngine([
            $this->rule('G', static fn () => Check::fail(3, null, Severity::Critique)),
        ]);

        $finding = $engine->evaluate(new Context([]))['findings'][0];

        $this->assertSame('C', $finding['severity']);
        $this->assertSame('red', $finding['badge']);
    }

    public function testGradedThresholdsPickTheFirstMatchingLevel(): void
    {
        $levels = [[15.0, Severity::Elevee], [30.0, Severity::Moyenne]];

        $this->assertSame(Severity::Elevee, Check::graded(10.0, $levels)->severity);
        $this->assertSame(Severity::Moyenne, Check::graded(20.0, $levels)->severity);
        $this->assertSame(Status::Pass, Check::graded(45.0, $levels)->status);
        $this->assertSame(Status::Unknown, Check::graded(null, $levels)->status);
    }
}
