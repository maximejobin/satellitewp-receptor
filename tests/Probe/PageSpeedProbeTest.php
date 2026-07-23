<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Probe;

use SatelliteWP\Xtractor\Probe\PageSpeedProbe;
use SatelliteWP\Xtractor\Tests\TestCase;

final class PageSpeedProbeTest extends TestCase
{
    public function testParseResponse(): void
    {
        $data = PageSpeedProbe::parseResponse($this->fixtureArray('psi-mobile.json'));

        $this->assertSame('mobile', $data['strategy']);
        $this->assertSame('fr', $data['locale']);
        $this->assertSame(74, $data['performance_score']);
        $this->assertSame('11.0.0', $data['lighthouse_version']);

        // Every requested category is kept, with its localized title.
        $this->assertSame(
            ['performance' => 74, 'accessibility' => 96, 'best-practices' => 92, 'seo' => 80],
            $data['scores']
        );
        $this->assertSame('Bonnes pratiques', $data['categories']['best-practices']['title']);
        $this->assertSame(80, $data['categories']['seo']['score']);

        // Lab metrics kept with value + display.
        $this->assertSame(3120.0, $data['lab']['lcp']['value']);
        $this->assertSame('3.1 s', $data['lab']['lcp']['display']);
        $this->assertSame(0.043, $data['lab']['cls']['value']);
        $this->assertSame(320.0, $data['lab']['ttfb']['value']);

        // Field (CrUX) page-level data.
        $this->assertTrue($data['field']['available']);
        $this->assertSame('AVERAGE', $data['field']['overall_category']);
        $this->assertSame(2890, $data['field']['metrics']['lcp']['percentile']);
        $this->assertSame('FAST', $data['field']['metrics']['cls']['category']);
        $this->assertSame(180, $data['field']['metrics']['inp']['percentile']);

        // Origin-level field data.
        $this->assertSame('SLOW', $data['origin_field']['overall_category']);
    }

    public function testParseResponseWithoutFieldData(): void
    {
        $data = PageSpeedProbe::parseResponse([
            'lighthouseResult' => [
                'configSettings' => ['formFactor' => 'desktop'],
                'categories'     => ['performance' => ['score' => 0.98]],
                'audits'         => [],
            ],
        ]);

        $this->assertSame('desktop', $data['strategy']);
        $this->assertSame(98, $data['performance_score']);
        $this->assertSame(['performance' => 98], $data['scores']);
        $this->assertFalse($data['field']['available']);
        $this->assertFalse($data['origin_field']['available']);
        $this->assertNull($data['lab']['lcp']['value']);
    }

    public function testParseResponseWithMissingScore(): void
    {
        $data = PageSpeedProbe::parseResponse(['lighthouseResult' => ['audits' => []]]);

        $this->assertNull($data['performance_score']);
    }
}
