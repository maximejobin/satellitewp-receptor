<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersHealthGradeTest extends TestCase
{
    public function testGradeBands(): void
    {
        $this->assertSame('A', \health_grade(100));
        $this->assertSame('A', \health_grade(90));
        $this->assertSame('B', \health_grade(89));
        $this->assertSame('B', \health_grade(80));
        $this->assertSame('C', \health_grade(79));
        $this->assertSame('C', \health_grade(65));
        $this->assertSame('D', \health_grade(64));
        $this->assertSame('D', \health_grade(50));
        $this->assertSame('F', \health_grade(49));
        $this->assertSame('F', \health_grade(0));
    }

    /** health_score_breakdown() must show the exact arithmetic, not just restate the formula. */
    public function testBreakdownShowsTheRealNumbersForThisExtraction(): void
    {
        $html = \health_score_breakdown(['by_pastille' => ['red' => 12, 'orange' => 19]]);

        $this->assertStringContainsString('12', $html, 'the red count itself');
        $this->assertStringContainsString('19', $html, 'the orange count itself');
        // 100 * 0.9^12 * 0.97^19 rounds to 16, same as health_score() itself.
        $this->assertStringContainsString((string) \health_score(['by_pastille' => ['red' => 12, 'orange' => 19]]), $html);
        $this->assertStringContainsString('F', $html, 'the resulting grade');
    }

    public function testBreakdownWithNoFailuresShowsAPerfectScoreAndGradeA(): void
    {
        $html = \health_score_breakdown(['by_pastille' => ['red' => 0, 'orange' => 0]]);

        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('A', $html);
    }
}
