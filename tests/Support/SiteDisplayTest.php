<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Support;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Support\SiteDisplay;

final class SiteDisplayTest extends TestCase
{
    public function testStripsSchemeAndWww(): void
    {
        $this->assertSame('rds.ca', SiteDisplay::of('https://www.rds.ca'));
    }

    public function testStripsSchemeWithoutWww(): void
    {
        $this->assertSame('rds.ca', SiteDisplay::of('https://rds.ca'));
    }

    public function testHttpSchemeToo(): void
    {
        $this->assertSame('rds.ca', SiteDisplay::of('http://www.rds.ca'));
    }

    public function testTrailingSlashRemoved(): void
    {
        $this->assertSame('rds.ca', SiteDisplay::of('https://www.rds.ca/'));
    }

    public function testPathIsKept(): void
    {
        $this->assertSame('rds.ca/some/Path', SiteDisplay::of('https://www.rds.ca/some/Path'));
    }

    public function testNoSchemeOrWwwIsUnchanged(): void
    {
        $this->assertSame('rds.ca', SiteDisplay::of('rds.ca'));
    }

    public function testOnlyALeadingWwwIsStripped(): void
    {
        $this->assertSame('www2.rds.ca', SiteDisplay::of('https://www2.rds.ca'));
    }

    public function testNullOrEmptyIsEmptyString(): void
    {
        $this->assertSame('', SiteDisplay::of(null));
        $this->assertSame('', SiteDisplay::of(''));
    }
}
