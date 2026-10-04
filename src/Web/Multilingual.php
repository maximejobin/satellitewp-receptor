<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

/**
 * The multilingual plugin a payload reports, if any. The plugin's connectors
 * (WPML, Polylang, TranslatePress) all share one shape, so consumers read a
 * single normalized record.
 */
final class Multilingual
{
    /** Connector key => plugin name, in detection order. */
    public const PLUGINS = [
        'wpml'           => 'WPML',
        'polylang'       => 'Polylang',
        'translatepress' => 'TranslatePress',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array{plugin: string, label: string, version: string, default_language: string, current_language: string, active_languages: list<string>}|null
     */
    public static function fromPayload(array $payload): ?array
    {
        foreach (self::PLUGINS as $key => $label) {
            $data = $payload['connectors'][$key] ?? null;
            if (!is_array($data)) {
                continue;
            }

            return [
                'plugin'           => $key,
                'label'            => $label,
                'version'          => (string) ($data['version'] ?? ''),
                'default_language' => (string) ($data['default_language'] ?? ''),
                'current_language' => (string) ($data['current_language'] ?? ''),
                'active_languages' => array_values(array_map('strval', (array) ($data['active_languages'] ?? []))),
            ];
        }

        return null;
    }
}
