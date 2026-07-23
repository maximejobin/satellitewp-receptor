<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Domain;

/**
 * Common result envelope written as probes/<name>.json for every probe.
 */
final readonly class ProbeResult
{
    public const string STATUS_OK    = 'ok';
    public const string STATUS_WARN  = 'warn';
    public const string STATUS_ERROR = 'error';

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    public function __construct(
        public string $probe,
        public string $probeVersion,
        public string $siteId,
        public string $target,
        public string $ranAt,
        public int $durationMs,
        public string $status,
        public array $data = [],
        public array $errors = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'probe'         => $this->probe,
            'probe_version' => $this->probeVersion,
            'site_id'       => $this->siteId,
            'target'        => $this->target,
            'ran_at'        => $this->ranAt,
            'duration_ms'   => $this->durationMs,
            'status'        => $this->status,
            'data'          => $this->data,
            'errors'        => $this->errors,
        ];
    }
}
