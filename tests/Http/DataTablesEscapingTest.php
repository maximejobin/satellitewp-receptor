<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use SatelliteWP\Manager\Http\Controller\CatalogController;
use SatelliteWP\Manager\Http\Controller\CrmController;
use SatelliteWP\Manager\Http\Controller\DataController;
use SatelliteWP\Manager\Tests\TestCase;

/** DataTables writes server-side cells as HTML: plugin-supplied text must arrive escaped. */
final class DataTablesEscapingTest extends TestCase
{
    private const string XSS = '<img src=x onerror=alert(1)>';

    #[Test]
    public function catalogRowsEscapeSlugAndName(): void
    {
        $rows = CatalogController::tableRows([[
            'type'      => 'plugin',
            'slug'      => 'evil"><script>alert(1)</script>',
            'name'      => self::XSS,
            'license'   => 'unknown',
            'suggested' => ['not', 'a', 'string'],
        ]], str_repeat('a', 32));

        $this->assertSame('plugin', $rows[0][0]);
        $this->assertSame('evil&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $rows[0][1]);
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $rows[0][2]);
        $this->assertStringNotContainsString('<script>', $rows[0][3]);
    }

    #[Test]
    public function crmItemRowsEscapeEveryTextCell(): void
    {
        $rows = CrmController::itemRows([[
            'type'                => 'plugin',
            'name'                => self::XSS,
            'slug'                => self::XSS,
            'version'             => self::XSS,
            'is_update_available' => 1,
            'new_version'         => self::XSS,
            'is_vulnerable'       => 1,
            'is_active'           => 0,
            'website_url'         => 'https://example.com/' . self::XSS,
            'website_id'          => '42',
        ]]);

        foreach ([1, 2, 3, 4, 7] as $i) {
            $this->assertStringNotContainsString('<img', (string) $rows[0][$i], "column {$i}");
        }
        $this->assertSame('<span class="mono">&lt;img src=x onerror=alert(1)&gt;</span>', $rows[0][2]);
        $this->assertStringStartsWith('<a href="/websites/42">', (string) $rows[0][7]);
        $this->assertSame(42, $rows[0][8]);
    }

    #[Test]
    public function vulnerabilityRowsEscapeWordfenceText(): void
    {
        $rows = DataController::vulnerabilityRows([[
            'name'             => self::XSS,
            'slug'             => 'example-plugin',
            'type'             => '"><b>',
            'cve_id'           => self::XSS,
            'title'            => self::XSS,
            'published_at'     => '2026-01-02T00:00:00Z',
            'cvss_score'       => 9.8,
            'cvss_rating'      => 'Critical',
            'patched'          => true,
            'patched_versions' => [self::XSS],
            'ignored'          => true,
        ]]);

        foreach ([0, 2, 3, 4, 8] as $i) {
            $this->assertStringNotContainsString('<img', (string) $rows[0][$i], "column {$i}");
            $this->assertStringNotContainsString('"><b>', (string) $rows[0][$i], "column {$i}");
        }
        $this->assertStringContainsString('Ignored', (string) $rows[0][0]);
        $this->assertSame('2026-01-02', $rows[0][5]);
        $this->assertStringContainsString('badge-critical', (string) $rows[0][6]);
        $this->assertSame('example-plugin', $rows[0][9]['slug']);
    }
}
