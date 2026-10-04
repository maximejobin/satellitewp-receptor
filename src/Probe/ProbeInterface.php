<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Probe;

use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;

/**
 * Contract every validation service implements. A probe:
 *  - runs standalone (CLI) or inside the pipeline,
 *  - never throws for a failing *check* (that's status warn/error in the result),
 *  - may throw for its own bugs — the pipeline converts that to an error result.
 */
interface ProbeInterface
{
    /** Stable identifier: 'dns', 'tls', 'rdap', 'http', … Used as the output filename. */
    public function name(): string;

    /** Bump when the shape of the data section changes. */
    public function version(): string;

    public function run(SiteContext $site): ProbeResult;
}
