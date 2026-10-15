<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

/**
 * payload.constants as display rows — shared by the extraction page and the
 * client report so both show and flag exactly the same thing.
 */
final class HardeningConstants
{
    /** "N/A" is the plugin's sentinel for a constant that is not defined. */
    private const string UNDEFINED = 'N/A';

    /**
     * @param array<array-key, mixed> $constants
     * @return list<array{name: string, display: string, flagged: bool}>
     */
    public static function rows(array $constants): array
    {
        // Core defines WP_DEBUG_DISPLAY as true by default: it only shows errors once WP_DEBUG is on.
        $debug = ($constants['WP_DEBUG'] ?? null) === true;
        $rows  = [];
        foreach ($constants as $name => $value) {
            $rows[] = [
                'name'    => (string) $name,
                // The literal value WordPress reports, never a yes/no humanization; undefined is empty.
                'display' => is_bool($value) ? ($value ? 'true' : 'false') : ($value === null || $value === self::UNDEFINED ? '' : (string) $value),
                'flagged' => $debug && $value === true && in_array((string) $name, ['WP_DEBUG', 'WP_DEBUG_DISPLAY'], true),
            ];
        }

        return $rows;
    }
}
