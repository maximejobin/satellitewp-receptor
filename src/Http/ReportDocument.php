<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Rules\Translator;
use SatelliteWP\Manager\Web\DefaultObservations;
use SatelliteWP\Manager\Web\ReportBuilder;

/**
 * The report.json document of one extraction, from its stored files: the
 * Google Doc's feed and the extraction bundle's analysis/report.json.
 */
final class ReportDocument
{
    /**
     * @param array<string, mixed>                $payload
     * @param array<string, mixed>                $site
     * @param array<string, mixed>                $meta
     * @param array<string, array<string, mixed>> $probes        probe name => stored envelope
     * @param array<string, mixed>                $licenses
     * @param array<string, mixed>                $findingsData  findings.json
     * @param array<string, mixed>|null           $observations  observations.json
     * @return array<string, mixed>
     */
    public static function build(
        App $app,
        Translator $t,
        string $iconBaseUrl,
        string $extractionId,
        string $reportBy,
        array $payload,
        array $site,
        array $meta,
        array $probes,
        array $licenses,
        array $findingsData,
        ?array $observations,
    ): array {
        $context = ReportContext::build(
            $payload,
            $site,
            $meta,
            $probes,
            $licenses,
            ReportContext::reference($payload, $app->endOfLife(), $app->wordPressVersions()),
            $extractionId,
            $reportBy,
            gmdate('Y-m-d')
        );

        $findings = ReportContext::translatedFindings($findingsData, $t);
        $contract = ReportContract::load($app);
        $resolved = DefaultObservations::resolve(DefaultObservations::items($observations, $contract), $t);

        $report = (new ReportBuilder($t, $iconBaseUrl, $app->ignoredVulnerabilities()))
            ->build($contract, $context, $findings, $resolved);

        return [
            'site'          => $report['fields']['site']['value'] ?? ($site['site_id'] ?? ''),
            'extraction_id' => $extractionId,
            'generated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            // Keyed by the template's exact {{variable}} names, each carrying its own 'type'.
            'fields'        => $report['fields'],
            'findings'      => $findings,
        ];
    }
}
