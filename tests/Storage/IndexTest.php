<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Storage;

use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Tests\TestCase;

final class IndexTest extends TestCase
{
    private const string SITE_A = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string SITE_B = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private Index $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = new Index($this->tmpDir . '/index.sqlite');
    }

    /** @param array<string, mixed> $extra */
    private function seedExtraction(string $siteId, string $id, array $extra = [], string $status = 'pending'): void
    {
        $this->index->upsertSite($siteId, ['site_url' => "https://{$siteId}.test", 'site_title' => 'T'], '2026-07-22T10:00:00Z');
        $this->index->insertExtraction($siteId, $id, $extra['received_at'] ?? '2026-07-22T10:00:00Z', [
            'schema_version' => '1.0',
            'wp_version'     => $extra['wp'] ?? '6.8.1',
            'php'            => ['version' => $extra['php'] ?? '8.3.11'],
        ], $status);
    }

    public function testInsertAndGetExtraction(): void
    {
        $this->seedExtraction(self::SITE_A, '20260722T100000Z');

        $row = $this->index->getExtraction(self::SITE_A, '20260722T100000Z');

        $this->assertSame('6.8.1', $row['wp_version']);
        $this->assertSame('8.3.11', $row['php_version']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($this->index->getExtraction(self::SITE_A, 'nope'));
    }

    public function testListSitesReportsCountAndLastStatus(): void
    {
        $this->seedExtraction(self::SITE_A, '20260722T100000Z', [], 'done');
        $this->seedExtraction(self::SITE_A, '20260723T100000Z', ['received_at' => '2026-07-23T10:00:00Z'], 'pending');
        $this->seedExtraction(self::SITE_B, '20260722T110000Z', [], 'error');

        $sites = array_column($this->index->listSites(), null, 'site_id');

        $this->assertSame(2, (int) $sites[self::SITE_A]['extraction_count']);
        // Last by received_at is the pending 2026-07-23 one.
        $this->assertSame('pending', $sites[self::SITE_A]['last_extraction_status']);
        $this->assertSame('20260723T100000Z', $sites[self::SITE_A]['last_extraction_id']);
    }

    public function testListSitesSearchFilters(): void
    {
        $this->seedExtraction(self::SITE_A, '20260722T100000Z');
        $this->seedExtraction(self::SITE_B, '20260722T110000Z');

        $found = $this->index->listSites(self::SITE_B);
        $this->assertCount(1, $found);
        $this->assertSame(self::SITE_B, $found[0]['site_id']);

        $this->assertCount(0, $this->index->listSites('no-such-site'));
    }

    public function testPendingExtractionsOrderedOldestFirst(): void
    {
        $this->seedExtraction(self::SITE_A, '20260723T100000Z', ['received_at' => '2026-07-23T10:00:00Z']);
        $this->seedExtraction(self::SITE_A, '20260722T100000Z', ['received_at' => '2026-07-22T10:00:00Z']);
        $this->seedExtraction(self::SITE_B, '20260722T110000Z', ['received_at' => '2026-07-22T11:00:00Z'], 'done');

        $pending = $this->index->pendingExtractions();

        $this->assertCount(2, $pending, 'the done one is excluded');
        $this->assertSame('20260722T100000Z', $pending[0]['id'], 'oldest first');
    }

    public function testSetStatusAndProbeRuns(): void
    {
        $this->seedExtraction(self::SITE_A, '20260722T100000Z');
        $this->index->setExtractionStatus(self::SITE_A, '20260722T100000Z', Index::STATUS_DONE);
        $this->index->upsertProbeRun(self::SITE_A, '20260722T100000Z', 'tls', 'ok', '2026-07-22T10:01:00Z', 412);
        $this->index->upsertProbeRun(self::SITE_A, '20260722T100000Z', 'tls', 'warn', '2026-07-22T10:02:00Z', 500);

        $row = $this->index->getExtraction(self::SITE_A, '20260722T100000Z');
        $this->assertSame('done', $row['status']);
        $this->assertNotNull($row['processed_at']);

        $runs = $this->index->listProbeRuns(self::SITE_A, '20260722T100000Z');
        $this->assertCount(1, $runs, 'upsert replaces the same probe row');
        $this->assertSame('warn', $runs[0]['status']);
    }

    public function testRequeueStaleOnlyTouchesOldRunning(): void
    {
        // A running extraction whose processed_at is 40 min ago.
        $this->seedExtraction(self::SITE_A, '20260722T100000Z');
        $this->index->setExtractionStatus(self::SITE_A, '20260722T100000Z', Index::STATUS_RUNNING);
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 60);
        $this->index->pdo()->prepare('UPDATE extractions SET processed_at = :t WHERE id = :id')
            ->execute(['t' => $old, 'id' => '20260722T100000Z']);

        // A freshly-running one must not be touched.
        $this->seedExtraction(self::SITE_B, '20260722T110000Z');
        $this->index->setExtractionStatus(self::SITE_B, '20260722T110000Z', Index::STATUS_RUNNING);

        $requeued = $this->index->requeueStale(30);

        $this->assertSame(1, $requeued);
        $this->assertSame('pending', $this->index->getExtraction(self::SITE_A, '20260722T100000Z')['status']);
        $this->assertSame('running', $this->index->getExtraction(self::SITE_B, '20260722T110000Z')['status']);
    }

    public function testRebuildFromDataStore(): void
    {
        $store = new DataStore($this->tmpDir);
        $id    = $store->storeExtraction(self::SITE_A, $this->fixture('extraction-valid.json'), [
            'received_at' => '2026-07-22T14:30:00Z',
        ]);
        $store->writeProbeResult(self::SITE_A, $id, 'dns', ['status' => 'ok', 'ran_at' => '2026-07-22T14:31:00Z', 'duration_ms' => 10]);

        // Start from an index that knows nothing.
        $count = $this->index->rebuildFrom($store);

        $this->assertSame(1, $count);
        $row = $this->index->getExtraction(self::SITE_A, $id);
        $this->assertSame('6.8.1', $row['wp_version']);
        $this->assertSame('done', $row['status'], 'has a probe result -> done');
        $this->assertCount(1, $this->index->listProbeRuns(self::SITE_A, $id));
    }
}
