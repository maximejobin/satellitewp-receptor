<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http\Controller;

use SatelliteWP\Xtractor\Http\ReportContext;
use SatelliteWP\Xtractor\Http\ReportContract;
use SatelliteWP\Xtractor\Web\ReportBuilder;

/**
 * GET /site/{id}/extraction/{id}/report.json — the Google Docs report
 * script's data feed. The script holds no browser session, so this route is
 * gated by its own credentials instead of the sign-in.
 */
final class ReportController extends Controller
{
    /** @param array<string, string> $params */
    public function json(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];

        if (!$this->accessGranted($siteId, $extractionId)) {
            $this->response->json(['error' => 'Invalid or missing credentials'], 401);

            return;
        }

        $store   = $this->app->dataStore();
        $payload = $store->readExtractionPayload($siteId, $extractionId);
        if ($payload === null) {
            $this->notFound();

            return;
        }

        $t     = $this->app->translator($this->locale());
        $token = (string) ($_GET['token'] ?? '');

        $context = ReportContext::build(
            $payload,
            $store->readSiteInfo($siteId) ?? [],
            $store->readMeta($siteId, $extractionId) ?? [],
            $store->readAllProbeResults($siteId, $extractionId),
            $store->readLicenses($siteId, $extractionId) ?? [],
            ReportContext::reference($payload, $this->app->endOfLife(), $this->app->wordPressVersions()),
            $extractionId,
            // The analyst captured on the token when it was minted; the shared API key carries none.
            $token !== '' ? $this->app->reportTokenStore()->issuedBy($token) : '',
            gmdate('Y-m-d')
        );

        $findings     = ReportContext::translatedFindings($store->readFindings($siteId, $extractionId) ?? [], $t);
        $observations = (array) ($store->readObservations($siteId, $extractionId)['items'] ?? []);

        $report = (new ReportBuilder($t, $this->baseUrl() . '/assets/report-icons', $this->app->ignoredVulnerabilities()))
            ->build(ReportContract::load($this->app), $context, $findings, $observations);

        $this->response->json([
            'site'          => $report['fields']['site']['value'] ?? $siteId,
            'extraction_id' => $extractionId,
            'generated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            // Keyed by the template's exact {{variable}} names, each carrying its own 'type'.
            'fields'        => $report['fields'],
            'findings'      => $findings,
        ], 200, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Either the shared `reports.api_key` as a Bearer header, or a one-hour
     * token scoped to this one extraction (the "Report data key" button).
     */
    private function accessGranted(string $siteId, string $extractionId): bool
    {
        $apiKey = (string) $this->app->config->get('reports.api_key', '');
        if ($apiKey !== '') {
            // Some Apache/CGI setups only pass the header through as REDIRECT_*.
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            $given  = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
            if ($given !== '' && hash_equals($apiKey, $given)) {
                return true;
            }
        }

        $token = (string) ($_GET['token'] ?? '');

        return $token !== '' && $this->app->reportTokenStore()->verify($token, $siteId, $extractionId);
    }
}
