<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersFmtRelativeTimeTest extends TestCase
{
    public function testNullIsAnEmDash(): void
    {
        $this->assertSame('<span class="muted">—</span>', \fmt_relative_time(null));
    }

    public function testJustNow(): void
    {
        $this->assertStringContainsString('just now', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z')));
    }

    public function testMinutesAgoSingularAndPlural(): void
    {
        $this->assertStringContainsString('1 minute ago', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z', time() - 60)));
        $this->assertStringContainsString('50 minutes ago', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z', time() - 50 * 60)));
    }

    public function testHoursDaysAndMonthsAgo(): void
    {
        $this->assertStringContainsString('3 hours ago', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 3600)));
        $this->assertStringContainsString('2 days ago', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 86400)));
        $this->assertStringContainsString('3 months ago', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z', time() - 100 * 86400)));
    }

    public function testFutureDateReadsFromNow(): void
    {
        $this->assertStringContainsString('from now', \fmt_relative_time(gmdate('Y-m-d\TH:i:s\Z', time() + 3600)));
    }

    public function testTooltipCarriesTheExactDate(): void
    {
        $out = \fmt_relative_time('2026-01-01T12:00:00Z');
        $this->assertStringContainsString('title="2026-01-01T12:00:00Z"', $out);
    }

    public function testUnparsableStringIsEchoedAsIs(): void
    {
        $this->assertSame('not-a-date', \fmt_relative_time('not-a-date'));
    }
}
