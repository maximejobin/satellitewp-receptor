<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Support\SiteDisplay;

final class SiteDisplayTest extends TestCase
{
    public function testStripsSchemeAndWww(): void
    {
        $this->assertSame('example.com', SiteDisplay::of('https://www.example.com'));
    }

    public function testStripsSchemeWithoutWww(): void
    {
        $this->assertSame('example.com', SiteDisplay::of('https://example.com'));
    }

    public function testHttpSchemeToo(): void
    {
        $this->assertSame('example.com', SiteDisplay::of('http://www.example.com'));
    }

    public function testTrailingSlashRemoved(): void
    {
        $this->assertSame('example.com', SiteDisplay::of('https://www.example.com/'));
    }

    public function testPathIsKept(): void
    {
        $this->assertSame('example.com/some/Path', SiteDisplay::of('https://www.example.com/some/Path'));
    }

    public function testNoSchemeOrWwwIsUnchanged(): void
    {
        $this->assertSame('example.com', SiteDisplay::of('example.com'));
    }

    public function testOnlyALeadingWwwIsStripped(): void
    {
        $this->assertSame('www2.example.com', SiteDisplay::of('https://www2.example.com'));
    }

    public function testNullOrEmptyIsEmptyString(): void
    {
        $this->assertSame('', SiteDisplay::of(null));
        $this->assertSame('', SiteDisplay::of(''));
    }
}
