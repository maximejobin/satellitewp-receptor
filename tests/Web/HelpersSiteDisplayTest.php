<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * Full behaviour is covered by tests/Support/SiteDisplayTest.php — this only
 * confirms the template-facing wrapper actually delegates to it.
 */
final class HelpersSiteDisplayTest extends TestCase
{
    public function testDelegatesToSiteDisplay(): void
    {
        $this->assertSame('rds.ca', \site_display('https://www.rds.ca'));
    }
}
