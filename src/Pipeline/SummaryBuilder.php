<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Pipeline;

/**
 * Builds summary.json: a flat digest of the extraction payload + probe results.
 * This is the first file report generators and list views should read.
 */
final class SummaryBuilder
{
    /**
     * @param array<string, mixed> $payload extraction payload
     * @param array<string, array<string, mixed>> $probes probe name => envelope
     * @param array<string, mixed> $meta extraction meta.json
     * @return array<string, mixed>
     */
    public function build(array $payload, array $probes, array $meta): array
    {
        $summary = [
            'generated_at'    => gmdate('Y-m-d\TH:i:s\Z'),
            'site_id'         => $payload['site_id'] ?? null,
            'received_at'     => $meta['received_at'] ?? null,
            'schema_version'  => $payload['schema_version'] ?? null,
            'site_url'        => $payload['site_url'] ?? null,
            'site_title'      => $payload['site_title'] ?? null,

            // WordPress
            'wp_version'      => $payload['wp_version'] ?? null,
            'core_update'     => $payload['core_update']['status'] ?? null,
            'is_multisite'    => $payload['is_multisite'] ?? null,
            'active_theme'    => $payload['active_theme'] ?? null,

            // Server
            'php_version'     => $payload['php']['version'] ?? null,
            'web_server'      => $payload['web_server'] ?? null,
            'database_type'   => $payload['database_type'] ?? null,
            'database_version' => $payload['database_version'] ?? null,
            'db_total_bytes'  => $payload['database']['total_bytes'] ?? null,
            'disk_free_bytes' => $payload['filesystem']['disk_free_bytes'] ?? null,
            'disk_total_bytes' => $payload['filesystem']['disk_total_bytes'] ?? null,
            'autoload_bytes'  => $payload['autoload']['total_bytes'] ?? null,

            // Inventory
            'plugins_total'   => is_array($payload['plugins'] ?? null) ? count($payload['plugins']) : null,
            'plugins_active'  => is_array($payload['active_plugins'] ?? null) ? count($payload['active_plugins']) : null,
            'plugins_with_update' => $this->countPluginUpdates($payload),
            'admins_count'    => is_array($payload['administrators'] ?? null) ? count($payload['administrators']) : null,
            'users_count'     => $payload['users_count'] ?? null,

            'extraction_errors' => array_keys((array) ($payload['_errors'] ?? [])),
        ];

        // Probe digests: status of every probe + a few headline values.
        $summary['probes'] = [];
        foreach ($probes as $name => $envelope) {
            $summary['probes'][$name] = [
                'status'      => $envelope['status'] ?? null,
                'ran_at'      => $envelope['ran_at'] ?? null,
                'duration_ms' => $envelope['duration_ms'] ?? null,
            ];
        }

        $summary += $this->probeHighlights($probes);

        return $summary;
    }

    /** @param array<string, mixed> $payload */
    private function countPluginUpdates(array $payload): ?int
    {
        if (!is_array($payload['plugins'] ?? null)) {
            return null;
        }

        return count(array_filter(
            $payload['plugins'],
            static fn (array $p): bool => !empty($p['new_version'])
        ));
    }

    /**
     * @param array<string, array<string, mixed>> $probes
     * @return array<string, mixed>
     */
    private function probeHighlights(array $probes): array
    {
        $highlights = [];

        $tls = $probes['tls']['data'] ?? [];
        if ($tls !== []) {
            $highlights['ssl_days_to_expiry'] = $tls['days_to_expiry'] ?? null;
            $highlights['ssl_issuer']         = $tls['issuer'] ?? null;
        }

        $rdap = $probes['rdap']['data'] ?? [];
        if ($rdap !== []) {
            $highlights['domain_expires_at']      = $rdap['expires_at'] ?? null;
            $highlights['domain_days_to_expiry']  = $rdap['days_to_expiry'] ?? null;
            $highlights['domain_registrar']       = $rdap['registrar'] ?? null;
        }

        $http = $probes['http']['data'] ?? [];
        if ($http !== []) {
            $highlights['http_version']     = $http['http_version'] ?? null;
            $highlights['content_encoding'] = $http['content_encoding'] ?? null;
            $highlights['forces_https']     = $http['redirects']['forces_https'] ?? null;
            $highlights['ttfb_ms']          = $http['ttfb_ms'] ?? null;
            $highlights['asset_encoding']   = $http['asset']['content_encoding'] ?? null;
        }

        $pagespeed = $probes['pagespeed']['data'] ?? [];
        if ($pagespeed !== []) {
            // All category scores, per strategy: {mobile: {performance: 74, seo: 80, …}, desktop: {…}}
            $byStrategy = [];
            foreach ($pagespeed as $name => $result) {
                if (is_array($result)) {
                    $byStrategy[$name] = $result['scores'] ?? [];
                }
            }
            $highlights['pagespeed_scores'] = $byStrategy;

            // Headline figures come from mobile when available (what Google ranks on).
            $headline = $pagespeed['mobile'] ?? reset($pagespeed);
            if (is_array($headline)) {
                $highlights['pagespeed_score']    = $headline['performance_score'] ?? null;
                $highlights['pagespeed_strategy'] = $headline['strategy'] ?? null;
                $highlights['field_data']         = $headline['field']['overall_category'] ?? null;
                $highlights['lcp_ms']             = $headline['lab']['lcp']['value'] ?? null;
                $highlights['cls']                = $headline['lab']['cls']['value'] ?? null;
            }
        }

        return $highlights;
    }
}
