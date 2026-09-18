<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersFmtLinesTest extends TestCase
{
    public function testJoinsItemsWithLineBreaksNotCommas(): void
    {
        $html = \fmt_lines(['alice', 'bob']);

        $this->assertSame('alice<br>bob', $html);
    }

    public function testEmptyListRendersADash(): void
    {
        $this->assertSame('—', \fmt_lines([]));
    }

    public function testEscapesEachItem(): void
    {
        $html = \fmt_lines(['<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testOverflowsPastMax(): void
    {
        $html = \fmt_lines(['a', 'b', 'c'], 2);

        $this->assertStringContainsString('a<br>b', $html);
        $this->assertStringContainsString('+1', $html);
        $this->assertStringNotContainsString('>c<', $html);
    }
}
