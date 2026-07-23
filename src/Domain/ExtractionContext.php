<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Domain;

/**
 * A specific extraction of a site: its context plus where it lives on disk.
 */
final readonly class ExtractionContext
{
    public function __construct(
        public SiteContext $site,
        public string $extractionId,
        public string $dir,
    ) {
    }
}
