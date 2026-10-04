<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

/**
 * payload.constants as display rows — shared by the extraction page and the
 * client report so both show and flag exactly the same thing.
 */
final class HardeningConstants
{
    /** Constants whose true value exposes errors to visitors. */
    private const array FLAGGED_WHEN_TRUE = ['WP_DEBUG', 'WP_DEBUG_DISPLAY'];

    /**
     * @param array<array-key, mixed> $constants
     * @return list<array{name: string, display: string, flagged: bool}>
     */
    public static function rows(array $constants): array
    {
        $rows = [];
        foreach ($constants as $name => $value) {
            $rows[] = [
                'name'    => (string) $name,
                // The literal value WordPress reports, never a yes/no humanization.
                'display' => is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '—'),
                'flagged' => $value === true && in_array((string) $name, self::FLAGGED_WHEN_TRUE, true),
            ];
        }

        return $rows;
    }
}
