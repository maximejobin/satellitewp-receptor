<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Pipeline;

use SatelliteWP\Manager\Probe\SeRankingProbe;
use SatelliteWP\Manager\Storage\DataStore;
use SatelliteWP\Manager\Storage\Index;

/**
 * Completes SE Ranking audits the pipeline left `pending`: each due one is
 * polled once and its probes/seranking.json and index row are rewritten.
 */
final class AuditPoller
{
    public function __construct(
        private readonly SeRankingProbe $probe,
        private readonly DataStore $store,
        private readonly Index $index,
    ) {
    }

    /**
     * @param bool $force poll even when the poll interval has not elapsed
     * @return list<string> one line per audit polled: "<site>/<extraction> <status> (<state>)"
     */
    public function pollPending(int $now, bool $force = false): array
    {
        $lines = [];
        foreach ($this->index->probeRunsWithStatus($this->probe->name(), 'pending') as $row) {
            $siteId       = (string) $row['site_id'];
            $extractionId = (string) $row['extraction_id'];
            $envelope     = $this->store->readProbeResult($siteId, $extractionId, $this->probe->name());

            if ($envelope === null || ($envelope['status'] ?? null) !== 'pending') {
                // The file moved on (re-run, hand edit): the index row follows it.
                $this->record($siteId, $extractionId, $envelope ?? ['status' => 'error'], $row);
                continue;
            }
            if (!$force && !$this->probe->isDue($envelope, $now)) {
                continue;
            }

            $updated = $this->probe->poll($envelope, $now);
            $this->store->writeProbeResult($siteId, $extractionId, $this->probe->name(), $updated);
            $this->record($siteId, $extractionId, $updated, $row);

            $lines[] = "{$siteId}/{$extractionId} {$updated['status']} (" . ($updated['data']['state'] ?? '?') . ')';
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $row the index row being replaced
     */
    private function record(string $siteId, string $extractionId, array $envelope, array $row): void
    {
        $this->index->upsertProbeRun(
            $siteId,
            $extractionId,
            $this->probe->name(),
            (string) ($envelope['status'] ?? 'error'),
            (string) ($envelope['ran_at'] ?? $row['ran_at']),
            (int) ($envelope['duration_ms'] ?? 0)
        );
    }
}
