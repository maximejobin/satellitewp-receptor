<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Http\Controller\ReportController;
use SatelliteWP\Manager\Rules\Pastille;
use SatelliteWP\Manager\Support\PersonalData;
use SatelliteWP\Manager\Support\TarGz;
use SatelliteWP\Manager\Web\DefaultObservations;

/**
 * One extraction as a `.swp-extraction/` directory for an AI working in a
 * local copy of the site: raw data (payload, probes), Manager's analysis
 * (findings, report data) and the static instructions.md that explains them.
 * Personal data is left out of every file (Support\PersonalData).
 */
final class ExtractionBundle
{
    public const string ROOT = '.swp-extraction';

    public const string INSTRUCTIONS_FILE = 'resources/extraction-bundle/instructions.md';

    public function __construct(private readonly App $app, private readonly string $baseUrl)
    {
    }

    /** The command an analyst pastes in the site copy's root: replaces any previous bundle. */
    public static function command(string $url): string
    {
        $archive = self::ROOT . '.tar.gz';

        return "curl -fsS '" . str_replace("'", "'\\''", $url) . "' -o {$archive}"
            . ' && rm -rf ' . self::ROOT
            . " && tar -xzf {$archive} && rm {$archive}";
    }

    public function archive(string $siteId, string $extractionId, string $status): ?string
    {
        $files = $this->files($siteId, $extractionId, $status);

        return $files === null ? null : TarGz::build($files, time());
    }

    /** @return array<string, string>|null archive path => content */
    public function files(string $siteId, string $extractionId, string $status): ?array
    {
        $store   = $this->app->dataStore();
        $payload = $store->readExtractionPayload($siteId, $extractionId);
        if ($payload === null) {
            return null;
        }
        $site         = $store->readSiteInfo($siteId) ?? [];
        $meta         = $store->readMeta($siteId, $extractionId) ?? [];
        // Business records (client, plan) are not technical data: the CRM snapshot stays in Manager.
        $probes       = array_diff_key($store->readAllProbeResults($siteId, $extractionId), ['crm' => true]);
        $licenses     = $store->readLicenses($siteId, $extractionId);
        $observations = $store->readObservations($siteId, $extractionId);
        $findingsData = $store->readFindings($siteId, $extractionId) ?? [];
        $language     = ReportController::reportLocale(null, $meta);
        $t            = $this->app->translator($language);

        $mask         = new PersonalData();
        $payload      = $mask->payload($payload);
        $probes       = $mask->probes($probes);
        $findingsData = (array) $mask->any($findingsData);
        $observations = $observations === null ? null : (array) $mask->any($observations);
        $licenses     = $licenses === null ? null : (array) $mask->any($licenses);

        $report = ReportDocument::build(
            $this->app,
            $t,
            $this->baseUrl . '/assets/report-icons',
            $extractionId,
            '',
            $payload,
            $site,
            $meta,
            $probes,
            $licenses ?? [],
            $findingsData,
            $observations
        );
        $contract = ReportContract::load($this->app);
        $report['fields'] = array_diff_key($report['fields'], array_flip(self::crmFields($contract)));
        $resolvedObservations = DefaultObservations::resolve(
            DefaultObservations::items($observations, $contract),
            $t
        );

        $root  = self::ROOT;
        $files = [
            "{$root}/instructions.md" => (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::INSTRUCTIONS_FILE),
            "{$root}/meta.json"       => self::json(self::meta($site, $meta, $payload, $extractionId, $status, $language, $this->baseUrl)),
            "{$root}/raw/payload.json" => self::json($payload),
        ];
        ksort($probes);
        foreach ($probes as $name => $envelope) {
            $files["{$root}/raw/probes/" . basename((string) $name) . '.json'] = self::json($envelope);
        }
        $files["{$root}/analysis/findings.json"]           = self::json($findingsData);
        $files["{$root}/analysis/findings.{$language}.md"] = $mask->text(self::findingsMarkdown($report['findings'], $resolvedObservations, $language));
        $files["{$root}/analysis/report.json"]             = self::json($report);
        if ($observations !== null) {
            $files["{$root}/analysis/observations.json"] = self::json($observations);
        }
        if ($licenses !== null) {
            $files["{$root}/analysis/licenses.json"] = self::json($licenses);
        }
        return $files;
    }

    /**
     * Report fields read from the CRM snapshot, left out with it.
     *
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public static function crmFields(array $contract): array
    {
        $names = [];
        foreach ((array) ($contract['fields'] ?? []) as $name => $spec) {
            foreach ((array) (((array) $spec)['from'] ?? []) as $from) {
                if (str_starts_with((string) $from, 'probe.crm.')) {
                    $names[] = (string) $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function meta(array $site, array $meta, array $payload, string $extractionId, string $status, string $language, string $baseUrl): array
    {
        $siteId = (string) ($site['site_id'] ?? $payload['site_id'] ?? '');

        return [
            'bundle_format' => 1,
            'generated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'site'          => [
                'site_id'  => $siteId,
                'site_url' => $site['site_url'] ?? $payload['site_url'] ?? null,
                'home_url' => $site['home_url'] ?? $payload['home_url'] ?? null,
            ],
            'extraction'    => [
                'id'                => $extractionId,
                'received_at'       => $meta['received_at'] ?? null,
                'generated_at'      => $payload['generated_at'] ?? null,
                'status'            => $status,
                'language'          => $language,
                'extractor_version' => $meta['extractor_version'] ?? $payload['extractor_version'] ?? null,
                'schema_version'    => $meta['schema_version'] ?? $payload['schema_version'] ?? null,
                'signature_valid'   => $meta['signature_valid'] ?? null,
                'collector_errors'  => array_values((array) ($payload['collector_errors'] ?? [])),
                'manager_url'       => $baseUrl . "/site/{$siteId}/extraction/{$extractionId}",
            ],
            'personal_data' => 'omitted',
        ];
    }

    /**
     * Every finding with its client-facing text, worst first, then the
     * analyst's observations that go into the report.
     *
     * @param list<array<string, mixed>> $findings translated findings
     * @param list<mixed>                $observations
     */
    public static function findingsMarkdown(array $findings, array $observations, string $language): string
    {
        $out = "# Findings ({$language})\n\nClient-facing texts, as the report shows them. Worst first.\n";
        foreach (Pastille::values() as $colour) {
            $group = array_filter($findings, static fn (array $f): bool => ($f['pastille'] ?? '') === $colour);
            if ($group === []) {
                continue;
            }
            $out .= "\n## {$colour}\n";
            foreach ($group as $f) {
                $out .= sprintf(
                    "\n### %s — %s\n\n- status: %s\n- severity: %s\n- category: %s\n\n%s\n",
                    $f['id'],
                    $f['title'],
                    $f['status'] !== '' ? $f['status'] : 'unknown',
                    $f['severity'],
                    $f['category_code'],
                    $f['message']
                );
            }
        }

        $included = array_filter($observations, static fn (mixed $o): bool => is_array($o) && ($o['include'] ?? true) !== false);
        if ($included !== []) {
            $out .= "\n# Analyst observations\n";
            foreach ($included as $o) {
                $out .= sprintf(
                    "\n### %s\n\n- colour: %s\n- section: %s\n\n%s\n",
                    (string) ($o['title'] ?? ''),
                    (string) ($o['color'] ?? ''),
                    (string) ($o['section'] ?? ''),
                    (string) ($o['description'] ?? '')
                );
            }
        }

        return $out;
    }

    /** @param array<array-key, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
