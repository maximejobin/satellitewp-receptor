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
     * @param list<string>|null $onlyProbes limit the run to these probe names
     * @return array<string, ProbeResult> probe name => result
     */
    public function run(string $siteId, string $extractionId, ?array $onlyProbes = null): array
    {
        $payload = $this->store->readExtractionPayload($siteId, $extractionId)
            ?? throw new RuntimeException("Extraction {$siteId}/{$extractionId} not found");

        $meta   = $this->store->readMeta($siteId, $extractionId) ?? [];
        $locale = is_string($meta['language'] ?? null) && $meta['language'] !== '' ? $meta['language'] : null;

        $context = new ExtractionContext(
            SiteContext::fromExtractionPayload($siteId, $payload, $this->keyStore?->getHttpAuth($siteId), $locale),
            $extractionId,
            $this->store->extractionDir($siteId, $extractionId)
        );

        $this->index->setExtractionStatus($siteId, $extractionId, Index::STATUS_RUNNING);

        // Anything that escapes (unknown probe, storage failure) must not leave
        // the extraction stuck in "running" — probe throws are already isolated.
        try {
            $this->catalog?->recordExtraction($payload);

            $results = [];
            foreach ($this->selectProbes($onlyProbes) as $probe) {
                $site = $probe->name() === 'crm'
                    ? $context->site->withBlogvaultSiteId($this->blogvaultSiteId($siteId, $extractionId, $results))
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
     * already stored (a lone `probe:run crm`). Null when BlogVault has no such site.
     *
     * @param array<string, ProbeResult> $results
     */
    private function blogvaultSiteId(string $siteId, string $extractionId, array $results): ?string
    {
        $data = isset($results['blogvault'])
            ? $results['blogvault']->data
            : (array) ($this->store->readProbeResult($siteId, $extractionId, 'blogvault')['data'] ?? []);
        $id = $data['site']['id'] ?? null;

        return ($data['linked'] ?? false) === true && is_string($id) && $id !== '' ? $id : null;
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
