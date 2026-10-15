<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Web;

/**
 * The multilingual plugins a payload reports. The plugin's connectors (WPML,
 * Polylang, TranslatePress) all share one shape, so consumers read one
 * normalized record per plugin.
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
     * Every multilingual plugin reported, in detection order. Two at once is
     * itself worth flagging: they compete for the same URLs and content.
     *
     * @param array<string, mixed> $payload
     * @return list<array{plugin: string, label: string, version: string, default_language: string, active_languages: list<string>, locales: array<string, string>, language_urls: array<string, string>, url_mode: string, browser_redirect: ?bool, details: array<string, mixed>, error: string}>
     */
    public static function all(array $payload): array
    {
        $found = [];
        foreach (self::PLUGINS as $key => $name) {
            $data = $payload['connectors'][$key] ?? null;
            if (!is_array($data)) {
                continue;
            }

            $label   = $data['label'] ?? null;
            $found[] = [
                'plugin'           => $key,
                // The connector's label names the edition ("Polylang Pro").
                'label'            => is_string($label) && $label !== '' ? $label : $name,
                'version'          => (string) ($data['version'] ?? ''),
                'default_language' => (string) ($data['default_language'] ?? ''),
                'active_languages' => array_values(array_map('strval', (array) ($data['active_languages'] ?? []))),
                'locales'          => self::stringMap($data['locales'] ?? null),
                'language_urls'    => self::stringMap($data['language_urls'] ?? null),
                'url_mode'         => (string) ($data['url_mode'] ?? ''),
                'browser_redirect' => is_bool($data['browser_redirect'] ?? null) ? $data['browser_redirect'] : null,
                'details'          => is_array($data['details'] ?? null) ? $data['details'] : [],
                'error'            => (string) ($data['error'] ?? ''),
            ];
        }

        return $found;
    }

    /**
     * The first multilingual plugin reported, if any.
     *
     * @param array<string, mixed> $payload
     * @return array{plugin: string, label: string, version: string, default_language: string, active_languages: list<string>, locales: array<string, string>, language_urls: array<string, string>, url_mode: string, browser_redirect: ?bool, details: array<string, mixed>, error: string}|null
     */
    public static function fromPayload(array $payload): ?array
    {
        return self::all($payload)[0] ?? null;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        $map = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_scalar($item)) {
                $map[(string) $key] = (string) $item;
            }
        }

        return $map;
    }
}
