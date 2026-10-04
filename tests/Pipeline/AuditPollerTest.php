<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Pipeline;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SatelliteWP\Manager\Integration\SeRankingClient;
use SatelliteWP\Manager\Pipeline\AuditPoller;
use SatelliteWP\Manager\Pipeline\Pipeline;
use SatelliteWP\Manager\Probe\ProbeRegistry;
use SatelliteWP\Manager\Probe\SeRankingProbe;
use SatelliteWP\Manager\Storage\DataStore;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Tests\TestCase;

final class AuditPollerTest extends TestCase
{
    private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

    private DataStore $store;
    private Index $index;
    private MockHandler $api;
    private SeRankingProbe $probe;
    private string $extractionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DataStore($this->tmpDir);
        $this->index = new Index($this->tmpDir . '/index.sqlite');
        $this->extractionId = $this->store->storeExtraction(self::SITE_ID, $this->fixture('extraction-valid.json'), [
            'received_at' => '2026-07-22T14:30:00Z',
        ]);
        $this->index->insertExtraction(self::SITE_ID, $this->extractionId, '2026-07-22T14:30:00Z', $this->fixtureArray('extraction-valid.json'));

        $this->api   = new MockHandler();
        $client      = new SeRankingClient(new Client(['handler' => HandlerStack::create($this->api), 'http_errors' => false]), 'https://api.seranking.test/v1/project-management', 'key');
        $this->probe = new SeRankingProbe($client, [], 5, 24);
    }

    private function json(mixed $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private function auditStatus(): ?string
    {
        foreach ($this->index->listProbeRuns(self::SITE_ID, $this->extractionId) as $run) {
            if ($run['probe'] === 'seranking') {
                return (string) $run['status'];
            }
        }

        return null;
    }

    public function testThePipelineStartsTheAuditAndThePollerCompletesIt(): void
    {
        $registry = new ProbeRegistry(['seranking']);
        $registry->register($this->probe);
        $this->api->append($this->json(['id' => 42]));
        (new Pipeline($registry, $this->store, $this->index))->run(self::SITE_ID, $this->extractionId);

        // The extraction is done; only the audit waits.
        $this->assertSame('done', $this->index->getExtraction(self::SITE_ID, $this->extractionId)['status']);
        $this->assertSame('pending', $this->auditStatus());

        $poller = new AuditPoller($this->probe, $this->store, $this->index);
        $now    = time();

        $this->api->append($this->json(['status' => 'processing', 'total_pages' => 10]));
        $this->assertSame([self::SITE_ID . "/{$this->extractionId} pending (processing)"], $poller->pollPending($now));

        // Within the poll interval nothing is asked, unless forced.
        $this->assertSame([], $poller->pollPending($now + 60));

        $this->api->append($this->json(['status' => 'finished']), $this->json([
            'is_finished' => true, 'score_percent' => 91, 'total_pages' => 12, 'sections' => [],
        ]));
        $this->assertSame([self::SITE_ID . "/{$this->extractionId} ok (finished)"], $poller->pollPending($now + 60, force: true));

        $this->assertSame('ok', $this->auditStatus());
        $stored = $this->store->readProbeResult(self::SITE_ID, $this->extractionId, 'seranking');
        $this->assertSame(91, $stored['data']['score']);
        $this->assertSame(42, $stored['data']['audit_id']);

        // Finished audits are no longer polled.
        $this->assertSame([], $poller->pollPending($now + 3600));
        $this->assertSame(0, $this->api->count());
    }

    public function testAnIndexRowWhoseFileMovedOnFollowsTheFile(): void
    {
        $this->index->upsertProbeRun(self::SITE_ID, $this->extractionId, 'seranking', 'pending', '2026-07-22T14:31:00Z', 0);
        $this->store->writeProbeResult(self::SITE_ID, $this->extractionId, 'seranking', ['status' => 'ok', 'ran_at' => '2026-07-22T14:31:00Z', 'data' => []]);

        $this->assertSame([], (new AuditPoller($this->probe, $this->store, $this->index))->pollPending(time()));
        $this->assertSame('ok', $this->auditStatus());
    }
}
