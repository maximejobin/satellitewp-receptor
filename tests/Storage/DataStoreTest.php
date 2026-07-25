<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Storage;

use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Tests\TestCase;

final class DataStoreTest extends TestCase
{
    private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

    public function testExtractionRoundTrip(): void
    {
        $store = new DataStore($this->tmpDir);
        $body  = $this->fixture('extraction-valid.json');

        $id = $store->storeExtraction(self::SITE_ID, $body, ['received_at' => '2026-07-22T14:30:00Z']);

        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $id);
        $this->assertSame(
            $this->fixtureArray('extraction-valid.json'),
            $store->readExtractionPayload(self::SITE_ID, $id)
        );
        $this->assertSame($id, $store->latestExtractionId(self::SITE_ID));
    }

    public function testCollidingExtractionIdsGetSuffix(): void
    {
        $store = new DataStore($this->tmpDir);

        $a = $store->storeExtraction(self::SITE_ID, '{}', []);
        $b = $store->storeExtraction(self::SITE_ID, '{}', []);

        $this->assertNotSame($a, $b);
        $this->assertCount(2, $store->listExtractionIds(self::SITE_ID));
    }

    public function testProbeResultsAndFindings(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', []);

        $store->writeProbeResult(self::SITE_ID, $id, 'dns', ['probe' => 'dns', 'status' => 'ok']);
        $store->writeProbeResult(self::SITE_ID, $id, 'tls', ['probe' => 'tls', 'status' => 'warn']);
        $store->writeFindings(self::SITE_ID, $id, ['findings' => [['id' => 'A1', 'status' => 'pass']]]);

        $all = $store->readAllProbeResults(self::SITE_ID, $id);
        $this->assertSame(['dns', 'tls'], array_keys($all));
        $this->assertSame('warn', $all['tls']['status']);
        $this->assertSame('A1', $store->readFindings(self::SITE_ID, $id)['findings'][0]['id']);
    }

    public function testSiteInfoPreservesFirstSeen(): void
    {
        $store = new DataStore($this->tmpDir);

        $store->updateSiteInfo(self::SITE_ID, ['site_url' => 'https://a.test'], '2026-01-01T00:00:00Z');
        $store->updateSiteInfo(self::SITE_ID, ['site_url' => 'https://b.test'], '2026-07-22T00:00:00Z');

        $info = $store->readSiteInfo(self::SITE_ID);
        $this->assertSame('2026-01-01T00:00:00Z', $info['first_seen']);
        $this->assertSame('2026-07-22T00:00:00Z', $info['last_seen']);
        $this->assertSame('https://b.test', $info['site_url']);
    }
}
