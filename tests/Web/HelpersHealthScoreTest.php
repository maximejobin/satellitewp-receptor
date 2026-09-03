<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * health_score(): was "100 - red*8 - orange*3", floored at 0 — which clips
 * to exactly 0 for any moderately unhealthy site (a real tracked site here
 * scored 0 on every single extraction, 9-12 red findings each time) and
 * gives no way to tell a bad site from a catastrophic one. Now a
 * multiplicative decay (0.9 per red, 0.97 per orange) that approaches but
 * never actually reaches 0 for a realistic finding count.
 */
final class HelpersHealthScoreTest extends TestCase
{
    public function testNoFailuresScoresPerfect(): void
    {
        $this->assertSame(100, \health_score(['by_pastille' => ['red' => 0, 'orange' => 0]]));
    }

    public function testMissingByPastilleScoresPerfect(): void
    {
        $this->assertSame(100, \health_score([]));
    }

    public function testManyRedFindingsNoLongerFloorsToZero(): void
    {
        // The real, previously-broken case: a live site with 9 red / 19
        // orange findings scored exactly 0 under the old formula on every
        // one of its extractions.
        $score = \health_score(['by_pastille' => ['red' => 9, 'orange' => 19]]);

        $this->assertGreaterThan(0, $score);
        $this->assertLessThan(50, $score, 'still reads as unhealthy (red bucket)');
    }

    public function testWorseFindingCountsScoreStrictlyLower(): void
    {
        $mild     = \health_score(['by_pastille' => ['red' => 1, 'orange' => 2]]);
        $moderate = \health_score(['by_pastille' => ['red' => 5, 'orange' => 10]]);
        $severe   = \health_score(['by_pastille' => ['red' => 12, 'orange' => 19]]);

        $this->assertGreaterThan($moderate, $mild, 'fewer failures must score higher');
        $this->assertGreaterThan($severe, $moderate, 'fewer failures must score higher');
    }

    public function testScoreNeverGoesNegativeOrOverflowsBelowZero(): void
    {
        $score = \health_score(['by_pastille' => ['red' => 500, 'orange' => 500]]);

        $this->assertGreaterThanOrEqual(0, $score);
    }
}
