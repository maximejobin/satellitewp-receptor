<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use SatelliteWP\Xtractor\Http\ReportContext;
use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Rules\Translator;
use SatelliteWP\Xtractor\Tests\TestCase;

final class ReportContextTest extends TestCase
{
    public function testBuildUnwrapsProbeEnvelopesAndDerivesTheHostFromHomeUrl(): void
    {
        $context = ReportContext::build(
            ['home_url' => 'https://www.example.com/blog', 'site_url' => 'https://core.example.com'],
            ['site_url' => 'https://www.example.com'],
            ['received_at' => '2026-07-22T14:30:00Z'],
            ['http' => ['status' => 'ok', 'data' => ['status_code' => 200]], 'dns' => ['status' => 'error']],
            ['plugin:akismet' => 'active'],
            ['wordpress_status' => 'latest'],
            '20260722T143000Z',
            'Ann Analyst',
            '2026-09-29'
        );

        $this->assertSame(['http' => ['status_code' => 200], 'dns' => []], $context['probe']);
        $this->assertSame('www.example.com', $context['host']);
        $this->assertSame('20260722T143000Z', $context['extraction_id']);
        $this->assertSame('Ann Analyst', $context['report_by']);
        $this->assertSame('2026-09-29', $context['today']);
        $this->assertSame(['plugin:akismet' => 'active'], $context['licenses']);
        $this->assertSame(['wordpress_status' => 'latest'], $context['reference']);
    }

    public function testReferenceUsesXtractorsOwnCachesNotThePayloadsClaims(): void
    {
        mkdir($this->tmpDir . '/reference', 0775, true);
        file_put_contents($this->tmpDir . '/reference/wordpress-versions.json', (string) json_encode(['6.8.1' => 'latest', '6.7.2' => 'insecure']));
        file_put_contents($this->tmpDir . '/reference/php.json', (string) json_encode([
            ['cycle' => '8.3', 'eol' => '2027-12-31'],
            ['cycle' => '7.4', 'eol' => '2022-11-28'],
        ]));
        $eol        = new EndOfLife($this->tmpDir . '/reference');
        $wpVersions = new WordPressVersions($this->tmpDir . '/reference/wordpress-versions.json');

        $current = ReportContext::reference(['wp_version' => '6.8.1', 'php' => ['version' => '8.3.11']], $eol, $wpVersions);
        $this->assertSame('latest', $current['wordpress_status']);
        $this->assertSame('6.8.1', $current['wordpress_latest_version']);
        $this->assertFalse($current['php_eol']);

        $old = ReportContext::reference(['wp_version' => '6.7.2', 'php' => ['version' => '7.4.33']], $eol, $wpVersions);
        $this->assertSame('insecure', $old['wordpress_status']);
        $this->assertTrue($old['php_eol']);

        $unknown = ReportContext::reference(['wp_version' => '5.0.0'], $eol, $wpVersions);
        $this->assertNull($unknown['wordpress_status'], 'a version the cache does not list is unknown, never guessed');
        $this->assertNull($unknown['php_eol']);
        $this->assertNull($unknown['database_eol']);
        $this->assertSame('', $unknown['database_eol_date']);
    }

    public function testTranslatedFindingsFollowTheOutcomeAndSkipFindingsWithoutAPhrase(): void
    {
        $t = new Translator('en', dirname(__DIR__, 2) . '/config/lang', 'en');

        $findings = ReportContext::translatedFindings(['findings' => [
            ['id' => 'A1', 'category' => 'SSL', 'severity' => 'C', 'status' => 'fail', 'pastille' => 'red'],
            ['id' => 'A1', 'category' => 'SSL', 'severity' => 'C', 'status' => 'pass', 'pastille' => 'green'],
            ['id' => 'A1', 'category' => 'SSL', 'severity' => 'C', 'status' => 'unknown', 'pastille' => 'grey'],
            'not-an-array',
        ]], $t);

        $this->assertCount(2, $findings, 'no phrase for "unknown", and non-arrays are skipped');
        $this->assertSame('SSL', $findings[0]['category_code']);
        $this->assertSame('fail', $findings[0]['status']);
        $this->assertSame($t->title('A1', 'fail'), $findings[0]['title']);
        $this->assertSame($t->title('A1', 'pass'), $findings[1]['title']);
        $this->assertNotSame($findings[0]['title'], $findings[1]['title'], 'the title follows the outcome');
    }
}
