<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Pipeline;

use RuntimeException;
use SatelliteWP\Manager\Domain\ExtractionContext;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Probe\ProbeInterface;
use SatelliteWP\Manager\Catalog\SoftwareCatalog;
use SatelliteWP\Manager\Probe\ProbeRegistry;
use SatelliteWP\Manager\Rules\Context as RuleContext;
use SatelliteWP\Manager\Rules\RuleEngine;
use SatelliteWP\Manager\Storage\DataStore;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Storage\KeyStore;
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
     * An explicit run (debug re-run, pipeline:run, probe:run), whatever the
     * extraction's status.
     *
     * @param list<string>|null $onlyProbes limit the run to these probe names
     * @return array<string, ProbeResult> probe name => result
     */
    public function run(string $siteId, string $extractionId, ?array $onlyProbes = null): array
    {
        $payload = $this->readPayload($siteId, $extractionId);
        $this->index->markManualRun($siteId, $extractionId);

        return $this->process($siteId, $extractionId, $payload, $onlyProbes);
    }

    /**
     * The worker's run: only if the extraction is still queued when claimed,
     * so an abort pressed after the worker listed it is honoured.
     *
     * @return array<string, ProbeResult>|null null when it was no longer queued
     */
    public function runQueued(string $siteId, string $extractionId): ?array
    {
        if (!$this->index->claimQueued($siteId, $extractionId)) {
            return null;
        }

        try {
            $payload = $this->readPayload($siteId, $extractionId);
        } catch (Throwable $e) {
            $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_ERROR);

            throw $e;
        }

        return $this->process($siteId, $extractionId, $payload);
    }

    /** @return array<string, mixed> */
    private function readPayload(string $siteId, string $extractionId): array
    {
        return $this->store->readExtractionPayload($siteId, $extractionId)
            ?? throw new RuntimeException("Extraction {$siteId}/{$extractionId} not found");
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $onlyProbes
     * @return array<string, ProbeResult>
     */
    private function process(string $siteId, string $extractionId, array $payload, ?array $onlyProbes = null): array
    {
        // Anything that escapes (unknown probe, storage failure) must not leave
        // the extraction stuck in "running" — probe throws are already isolated.
        try {
            $meta   = $this->store->readMeta($siteId, $extractionId) ?? [];
            $locale = is_string($meta['language'] ?? null) && $meta['language'] !== '' ? $meta['language'] : null;

            $context = new ExtractionContext(
                SiteContext::fromExtractionPayload($siteId, $payload, $this->keyStore?->getHttpAuth($siteId), $locale),
                $extractionId,
                $this->store->extractionDir($siteId, $extractionId)
            );

            $this->catalog?->recordExtraction($payload);

            $results = [];
            foreach ($this->selectProbes($onlyProbes) as $probe) {
                $site = $probe->name() === 'crm'
                    ? $this->withBlogvault($context->site, $siteId, $extractionId, $results)
                    : $context->site;
                $result = $this->runProbe($probe, $site);

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

            // Findings reflect every probe file on disk, including earlier passes.
            $allProbes = $this->store->readAllProbeResults($siteId, $extractionId);
            $this->evaluateRules($siteId, $extractionId, $payload, $allProbes);
        } catch (Throwable $e) {
            $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_ERROR);

            throw $e;
        }

        $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_DONE);

        return $results;
    }

    /**
     * The BlogVault site id from this run's blogvault result, else from the one
     * already stored (a lone `probe:run crm`). A failed BlogVault lookup is
     * flagged rather than read as "no id", so the CRM link is not reported missing.
     *
     * @param array<string, ProbeResult> $results
     */
    private function withBlogvault(SiteContext $site, string $siteId, string $extractionId, array $results): SiteContext
    {
        if (isset($results['blogvault'])) {
            $status = $results['blogvault']->status;
            $data   = $results['blogvault']->data;
        } else {
            $stored = $this->store->readProbeResult($siteId, $extractionId, 'blogvault') ?? [];
            $status = $stored['status'] ?? null;
            $data   = (array) ($stored['data'] ?? []);
        }
        if ($status === ProbeResult::STATUS_ERROR) {
            return $site->withBlogvaultSiteId(null, true);
        }

        $id = $data['site']['id'] ?? null;

        return $site->withBlogvaultSiteId(($data['linked'] ?? false) === true && is_string($id) && $id !== '' ? $id : null);
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
