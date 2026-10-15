<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersFmtStatusTallyTest extends TestCase
{
    public function testRendersTheCountPerStatusTheMapCarries(): void
    {
        $this->assertSame(
            'all <span class="val-muted">3</span> · archived <span class="val-muted">1</span>',
            \fmt_status_tally(['all' => 3, 'archived' => 1])
        );
        $this->assertSame('', \fmt_status_tally([]));
        $this->assertSame('', \fmt_status_tally(null));
    }
}
