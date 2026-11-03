<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersAssetTest extends TestCase
{
    public function testAppendsTheFileModificationTime(): void
    {
        $mtime = filemtime(__DIR__ . '/../../public/admin/assets/style.css');

        $this->assertSame('/assets/style.css?ver=' . $mtime, \asset('/assets/style.css'));
    }

    public function testAMissingFileKeepsItsPlainPath(): void
    {
        $this->assertSame('/assets/missing.css', \asset('/assets/missing.css'));
    }
}
