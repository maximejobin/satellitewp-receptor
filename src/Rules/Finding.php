<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * The result of one rule against one extraction — the unit the health report
 * and the analyst console consume.
 */
final readonly class Finding
{
    public function __construct(
        public string $id,
        public string $category,
        public string $source,
        public Severity $severity,
        public Status $status,
        public string $title,
        public ?string $message,
        public mixed $observed = null,
        public mixed $threshold = null,
        public ?string $detail = null,
    ) {
    }

    public function isFailure(): bool
    {
        return $this->status === Status::Fail;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'category'  => $this->category,
            'source'    => $this->source,
            'severity'  => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'badge'     => $this->severity->badge(),
            'status'    => $this->status->value,
            'title'     => $this->title,
            'message'   => $this->message,
            'observed'  => $this->observed,
            'threshold' => $this->threshold,
            'detail'    => $this->detail,
        ];
    }
}
