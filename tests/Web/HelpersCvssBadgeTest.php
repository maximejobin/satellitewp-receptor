<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/** cvss_badge(): critical and high must read as visually distinct, not just both "red". */
final class HelpersCvssBadgeTest extends TestCase
{
    public function testCriticalUsesTheDedicatedDarkRedClass(): void
    {
        $this->assertStringContainsString('badge-critical', \cvss_badge(9.8, 'Critical'));
    }

    public function testHighUsesTheOrdinaryErrorClassNotCritical(): void
    {
        $badge = \cvss_badge(7.5, 'High');
        $this->assertStringContainsString('badge-error', $badge);
        $this->assertStringNotContainsString('badge-critical', $badge);
    }

    public function testMediumIsWarnOrange(): void
    {
        $this->assertStringContainsString('badge-warn', \cvss_badge(5.0, 'Medium'));
    }

    public function testLowIsMuted(): void
    {
        $this->assertStringContainsString('badge-muted', \cvss_badge(2.0, 'Low'));
    }

    public function testRatingIsCaseInsensitive(): void
    {
        $this->assertStringContainsString('badge-critical', \cvss_badge(9.8, 'CRITICAL'));
    }

    public function testNullScoreRendersADashNotABadge(): void
    {
        $this->assertSame('—', \cvss_badge(null, null));
    }
}
