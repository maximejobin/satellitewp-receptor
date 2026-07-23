<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Pipeline;

use RuntimeException;
use SatelliteWP\Xtractor\Domain\ExtractionContext;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Probe\ProbeInterface;
use SatelliteWP\Xtractor\Probe\ProbeRegistry;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use Throwable;

/**
 * Runs probes for one extraction. A failing probe never stops the run:
 * its throw becomes a synthetic error result and the pipeline moves on.
 */
final class Pipeline
{
    public function __construct(
        private readonly ProbeRegistry $registry,
        private readonly DataStore $store,
        private readonly Index $index,
        private readonly SummaryBuilder $summaryBuilder,
    ) {
    }

    /**
     * @param list<string>|null $onlyProbes limit the run to these probe names
     * @return array<string, ProbeResult> probe name => result
     */
    public function run(string $siteId, string $extractionId, ?array $onlyProbes = null): array
    {
        $payload = $this->store->readExtractionPayload($siteId, $extractionId)
            ?? throw new RuntimeException("Extraction {$siteId}/{$extractionId} not found");

        $context = new ExtractionContext(
            SiteContext::fromExtractionPayload($siteId, $payload),
            $extractionId,
            $this->store->extractionDir($siteId, $extractionId)
        );

        $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_RUNNING);

        $results = [];
        foreach ($this->selectProbes($onlyProbes) as $probe) {
            $result = $this->runProbe($probe, $context->site);

            $this->store->writeProbeResult($siteId, $extractionId, $probe->name(), $result->toArray());
            $this->index->upsertProbeRun(
                $siteId,
                $extractionId,
                $probe->name(),
                $result->status,
                $result->ranAt,
                $result->durationMs
            );

            $results[$probe->name()] = $result;
        }

        // Summary always reflects every probe file on disk (including previous runs).
        $summary = $this->summaryBuilder->build(
            $payload,
            $this->store->readAllProbeResults($siteId, $extractionId),
            $this->store->readMeta($siteId, $extractionId) ?? []
        );
        $this->store->writeSummary($siteId, $extractionId, $summary);

        $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_DONE);

        return $results;
    }

    public function runSingleProbe(string $siteId, string $extractionId, string $probeName): ProbeResult
    {
        $results = $this->run($siteId, $extractionId, [$probeName]);

        return $results[$probeName];
    }

    /** @return list<ProbeInterface> */
    private function selectProbes(?array $onlyProbes): array
    {
        if ($onlyProbes === null) {
            return $this->registry->enabled();
        }

        return array_map($this->registry->get(...), $onlyProbes);
    }

    private function runProbe(ProbeInterface $probe, SiteContext $site): ProbeResult
    {
        try {
            return $probe->run($site);
        } catch (Throwable $e) {
            return new ProbeResult(
                probe: $probe->name(),
                probeVersion: $probe->version(),
                siteId: $site->siteId,
                target: $site->host,
                ranAt: gmdate('Y-m-d\TH:i:s\Z'),
                durationMs: 0,
                status: ProbeResult::STATUS_ERROR,
                errors: [get_class($e) . ': ' . $e->getMessage()],
            );
        }
    }
}
