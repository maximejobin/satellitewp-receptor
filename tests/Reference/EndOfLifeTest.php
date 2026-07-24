<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Reference;

use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Tests\TestCase;

final class EndOfLifeTest extends TestCase
{
    private function seed(string $product, array $cycles): EndOfLife
    {
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents(
            $this->tmpDir . '/reference/' . $product . '.json',
            (string) json_encode($cycles)
        );

        return new EndOfLife($this->tmpDir . '/reference');
    }

    public function testBranchExtraction(): void
    {
        $this->assertSame('8.3', EndOfLife::branch('8.3.11'));
        $this->assertSame('6.8', EndOfLife::branch('6.8'));
        $this->assertSame('7', EndOfLife::branch('7'));
    }

    public function testEolStatusWithPastDateIsEol(): void
    {
        $eol = $this->seed('php', [['cycle' => '8.0', 'eol' => '2023-11-26']]);

        [$isEol, $date] = $eol->eolStatus('php', '8.0.30');

        $this->assertTrue($isEol);
        $this->assertSame('2023-11-26', $date);
    }

    public function testEolStatusWithFutureDateIsSupported(): void
    {
        $eol = $this->seed('php', [['cycle' => '8.4', 'eol' => '2999-12-31']]);

        [$isEol, $date] = $eol->eolStatus('php', '8.4.1');

        $this->assertFalse($isEol);
        $this->assertSame('2999-12-31', $date);
    }

    public function testEolStatusWithBooleanEolField(): void
    {
        // endoflife.date returns eol: false for still-supported WordPress branches.
        $eol = $this->seed('wordpress', [
            ['cycle' => '7.0', 'eol' => false],
            ['cycle' => '6.8', 'eol' => '2025-12-02'],
        ]);

        $this->assertSame([false, null], $eol->eolStatus('wordpress', '7.0.2'));
        $this->assertSame([true, '2025-12-02'], $eol->eolStatus('wordpress', '6.8.1'));
    }

    public function testUnknownBranchReturnsNull(): void
    {
        $eol = $this->seed('php', [['cycle' => '8.3', 'eol' => '2027-12-31']]);

        $this->assertNull($eol->eolStatus('php', '5.6.40'));
    }

    public function testMissingCacheReturnsEmptyAndNull(): void
    {
        $eol = new EndOfLife($this->tmpDir . '/reference'); // nothing seeded

        $this->assertSame([], $eol->cycles('php'));
        $this->assertNull($eol->eolStatus('php', '8.3.11'));
    }
}
