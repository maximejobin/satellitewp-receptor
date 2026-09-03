<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * requirement_cell(): a plugin's declared minimum WP/PHP version is only
 * worth flagging on a per-site report when the site's actual running
 * version doesn't meet it — plain muted text told an analyst nothing beyond
 * a bare number that isn't a fact about this site.
 */
final class HelpersRequirementCellTest extends TestCase
{
    public function testRequirementMetRendersPlainNotError(): void
    {
        $cell = \requirement_cell('6.0', '7.4', '6.8.1', '8.3.11');

        $this->assertStringNotContainsString('val-error', $cell);
        $this->assertStringContainsString('WP 6.0', $cell);
        $this->assertStringContainsString('PHP 7.4', $cell);
    }

    public function testUnmetPhpRequirementIsFlagged(): void
    {
        $cell = \requirement_cell(null, '8.1', null, '7.4.0');

        $this->assertStringContainsString('val-error', $cell);
        $this->assertStringContainsString('PHP 8.1', $cell);
    }

    public function testUnmetWpRequirementIsFlaggedIndependentlyOfPhp(): void
    {
        $cell = \requirement_cell('6.5', '7.4', '6.4.0', '8.3.0');

        $this->assertStringContainsString('val-error', $cell);
        $this->assertStringContainsString('WP 6.5', $cell);
    }

    public function testNoRequirementsDeclaredRendersDash(): void
    {
        $this->assertSame('—', \requirement_cell(null, null, '6.8.1', '8.3.11'));
    }

    public function testUnknownInstalledVersionIsNeverFlagged(): void
    {
        // Nothing to compare against — must not guess "unmet".
        $cell = \requirement_cell('6.5', '8.1', null, null);

        $this->assertStringNotContainsString('val-error', $cell);
    }
}
