<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * cvss_badge() bands by the score itself (same thresholds as
 * /data/vulnerabilities), not the rating string — the rating still shows,
 * in the badge's tooltip.
 */
final class HelpersCvssBadgeTest extends TestCase
{
    public function testNineAndAboveIsCritical(): void
    {
        $this->assertStringContainsString('badge-critical', \cvss_badge(9.8, 'Critical'));
        $this->assertStringContainsString('badge-critical', \cvss_badge(9.0, 'Critical'));
    }

    public function testEightPointOneToEightPointNineIsError(): void
    {
        $badge = \cvss_badge(8.8, 'High');
        $this->assertStringContainsString('badge-error', $badge);
        $this->assertStringNotContainsString('badge-critical', $badge);
    }

    public function testSixPointOneToEightIsWarn(): void
    {
        $this->assertStringContainsString('badge-warn', \cvss_badge(7.5, 'High'));
        $this->assertStringContainsString('badge-warn', \cvss_badge(6.1, 'Medium'));
    }

    public function testUpToSixIsOk(): void
    {
        $this->assertStringContainsString('badge-ok', \cvss_badge(6.0, 'Medium'));
        $this->assertStringContainsString('badge-ok', \cvss_badge(2.0, 'Low'));
    }

    public function testTitleCarriesTheScoreAndRating(): void
    {
        $badge = \cvss_badge(9.1, 'Critical');
        $this->assertStringContainsString('CVSS 9.1', $badge);
        $this->assertStringContainsString('Critical', $badge);
    }

    public function testNullScoreRendersADashNotABadge(): void
    {
        $this->assertSame('—', \cvss_badge(null, null));
    }
}
