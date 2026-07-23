<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;

/**
 * Timing + envelope boilerplate shared by all probes. Concrete probes
 * implement collect() and return [data, status, errors, target?].
 */
abstract class AbstractProbe implements ProbeInterface
{
    public function run(SiteContext $site): ProbeResult
    {
        $start = hrtime(true);
        $ranAt = gmdate('Y-m-d\TH:i:s\Z');

        $collected = $this->collect($site);

        return new ProbeResult(
            probe: $this->name(),
            probeVersion: $this->version(),
            siteId: $site->siteId,
            target: (string) ($collected['target'] ?? $site->host),
            ranAt: $ranAt,
            durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
            status: (string) ($collected['status'] ?? ProbeResult::STATUS_OK),
            data: (array) ($collected['data'] ?? []),
            errors: array_values((array) ($collected['errors'] ?? [])),
        );
    }

    /**
     * @return array{data?: array<string, mixed>, status?: string, errors?: list<string>, target?: string}
     */
    abstract protected function collect(SiteContext $site): array;
}
