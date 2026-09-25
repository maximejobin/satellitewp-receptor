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

    public function testAnAlreadyClaimedIdIsNeverReused(): void
    {
        $store = new DataStore($this->tmpDir);
        // Someone else already holds this second's id (e.g. a concurrent push
        // that created the directory between our clock read and our mkdir).
        $taken = gmdate('Ymd\THis\Z');
        mkdir($store->extractionDir(self::SITE_ID, $taken), 0775, true);
        mkdir($store->extractionDir(self::SITE_ID, $taken . '-2'), 0775, true);

        $id = $store->storeExtraction(self::SITE_ID, '{}', []);

        // Either a later second, or the next free suffix — never a claimed dir.
        $this->assertNotContains($id, [$taken, $taken . '-2']);
        $this->assertSame([], $store->readExtractionPayload(self::SITE_ID, $id));
    }

    public function testMutateObservationsIsARealReadModifyWrite(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', []);

        $store->mutateObservations(self::SITE_ID, $id, function (array $items): array {
            $this->assertSame([], $items, 'nothing stored yet -> empty list');
            $items[] = ['id' => 'a'];

            return $items;
        });
        $store->mutateObservations(self::SITE_ID, $id, static function (array $items): array {
            $items[] = ['id' => 'b'];

            return $items;
        });

        $this->assertSame(['a', 'b'], array_column($store->readObservations(self::SITE_ID, $id)['items'], 'id'));
    }

    public function testTheLockFileNeverShowsUpAsDataAndReadsStillWork(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', ['received_at' => 'x']);
        $store->updateMeta(self::SITE_ID, $id, ['language' => 'en']);
        $store->setLicenseStatus(self::SITE_ID, $id, 'plugin', 'akismet', 'active');

        $this->assertFileExists($store->extractionDir(self::SITE_ID, $id) . '/.lock');
        $this->assertSame(['received_at' => 'x', 'language' => 'en'], $store->readMeta(self::SITE_ID, $id));
        $this->assertSame(['plugin:akismet' => 'active'], $store->readLicenses(self::SITE_ID, $id));
        $this->assertSame([$id], $store->listExtractionIds(self::SITE_ID));
        $this->assertSame([], $store->readAllProbeResults(self::SITE_ID, $id));
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

    public function testObservationsRoundTripAndDefaultToNull(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', []);

        $this->assertNull($store->readObservations(self::SITE_ID, $id)); // nothing authored yet

        $store->writeObservations(self::SITE_ID, $id, ['items' => [
            ['id' => 'a1', 'section' => 'domain_observations', 'color' => 'blue', 'title' => 'T', 'description' => 'D', 'include' => true],
        ]]);

        $this->assertSame('a1', $store->readObservations(self::SITE_ID, $id)['items'][0]['id']);
    }

    public function testLicenseStatusIsPerExtractionNotPerSite(): void
    {
        $store = new DataStore($this->tmpDir);
        $a     = $store->storeExtraction(self::SITE_ID, '{}', []);
        $b     = $store->storeExtraction(self::SITE_ID, '{}', []);

        $this->assertNull($store->readLicenses(self::SITE_ID, $a)); // nothing set yet

        $this->assertTrue($store->setLicenseStatus(self::SITE_ID, $a, 'plugin', 'elementor-pro', 'active'));
        $this->assertSame(['plugin:elementor-pro' => 'active'], $store->readLicenses(self::SITE_ID, $a));

        // A second, later extraction of the same site starts with none —
        // this is a snapshot per extraction, not a per-site setting.
        $this->assertNull($store->readLicenses(self::SITE_ID, $b));
    }

    public function testSetLicenseStatusRejectsAnUnknownStatus(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', []);

        $this->assertFalse($store->setLicenseStatus(self::SITE_ID, $id, 'plugin', 'akismet', 'bogus'));
        $this->assertNull($store->readLicenses(self::SITE_ID, $id));
    }

    public function testSetLicenseStatusMergesRatherThanOverwritingOtherSlugs(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', []);

        $store->setLicenseStatus(self::SITE_ID, $id, 'plugin', 'akismet', 'missing');
        $store->setLicenseStatus(self::SITE_ID, $id, 'theme', 'astra', 'to_validate');

        $this->assertSame(
            ['plugin:akismet' => 'missing', 'theme:astra' => 'to_validate'],
            $store->readLicenses(self::SITE_ID, $id)
        );
    }

    public function testUpdateMetaMergesIntoWhatIngestAlreadyWrote(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_ID, '{}', ['received_at' => '2026-09-25T00:00:00Z']);

        $store->updateMeta(self::SITE_ID, $id, ['language' => 'en']);

        $meta = $store->readMeta(self::SITE_ID, $id);
        $this->assertSame('2026-09-25T00:00:00Z', $meta['received_at']); // untouched
        $this->assertSame('en', $meta['language']); // merged in
    }

    public function testSiteInfoIsOverwrittenByTheLatestPayload(): void
    {
        $store = new DataStore($this->tmpDir);

        $store->updateSiteInfo(self::SITE_ID, ['site_url' => 'https://a.test']);
        $store->updateSiteInfo(self::SITE_ID, ['site_url' => 'https://b.test']);

        $info = $store->readSiteInfo(self::SITE_ID);
        $this->assertSame('https://b.test', $info['site_url']);
    }
}
