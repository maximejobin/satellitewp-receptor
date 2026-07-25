<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * The result of one rule against one extraction — language-neutral. It carries
 * the id, category, severity, status and the raw values only. The human
 * sentence (title + message, FR or EN) is produced at display time by the
 * Translator; nothing here is localized, which is what lets findings.json be
 * used to render a report in any language.
 */
final readonly class Finding
{
    /** @param array<string, scalar|null> $data named interpolation values */
    public function __construct(
        public string $id,
        public string $category,
        public string $source,
        public Severity $severity,
        public Status $status,
        public mixed $observed = null,
        public mixed $threshold = null,
        public array $data = [],
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
            'badge'     => $this->severity->badge(),
            'status'    => $this->status->value,
            'observed'  => $this->observed,
            'threshold' => $this->threshold,
            'data'      => $this->data,
        ];
    }
}
