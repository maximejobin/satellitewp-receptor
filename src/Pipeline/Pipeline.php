<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Pipeline;

use RuntimeException;
use SatelliteWP\Xtractor\Domain\ExtractionContext;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Probe\ProbeInterface;
use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Probe\ProbeRegistry;
use SatelliteWP\Xtractor\Rules\Context as RuleContext;
use SatelliteWP\Xtractor\Rules\RuleEngine;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Storage\KeyStore;
use Throwable;

/**
 * Runs probes for one extraction. A failing probe never stops the run:
 * its throw becomes a synthetic error result and the pipeline moves on.
 */
final class Pipeline
{
    /** @param array<string, mixed> $referenceData */
    public function __construct(
        private readonly ProbeRegistry $registry,
        private readonly DataStore $store,
        private readonly Index $index,
        private readonly ?RuleEngine $ruleEngine = null,
        private readonly array $referenceData = [],
        private readonly ?SoftwareCatalog $catalog = null,
        private readonly ?KeyStore $keyStore = null,
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
            SiteContext::fromExtractionPayload($siteId, $payload, $this->keyStore?->getHttpAuth($siteId)),
            $extractionId,
            $this->store->extractionDir($siteId, $extractionId)
        );

        $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_RUNNING);

        // Feed the cross-site plugin/theme catalogue with any newly seen slugs.
        $this->catalog?->recordExtraction($payload);

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

        // Findings always reflect every probe file on disk (including probes
        // run in a previous pass), not just this run.
        $allProbes = $this->store->readAllProbeResults($siteId, $extractionId);
        $this->evaluateRules($siteId, $extractionId, $payload, $allProbes);

        $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_DONE);

        return $results;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array<string, mixed>> $probes
     */
    private function evaluateRules(string $siteId, string $extractionId, array $payload, array $probes): void
    {
        if ($this->ruleEngine === null) {
            return;
        }

        $findings = $this->ruleEngine->evaluate(new RuleContext($payload, $probes, $this->referenceData));
        $findings['site_id']       = $siteId;
        $findings['extraction_id'] = $extractionId;

        $this->store->writeFindings($siteId, $extractionId, $findings);
    }

    public function runSingleProbe(string $siteId, string $extractionId, string $probeName): ProbeResult
    {
        $results = $this->run($siteId, $extractionId, [$probeName]);

        return $results[$probeName];
    }

    /**
     * @param list<string>|null $onlyProbes
     * @return list<ProbeInterface>
     */
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
