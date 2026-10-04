<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use RuntimeException;
use SatelliteWP\Manager\Http\ReportContext;
use SatelliteWP\Manager\Http\ReportContract;
use SatelliteWP\Manager\Web\ReportBuilder;

/**
 * GET /site/{id}/extraction/{id}/report.json — the Google Docs report
 * script's data feed — and report-script.json, the rendering engine the Doc's
 * loader runs. The script holds no browser session, so both routes are gated
 * by the same report credentials instead of the sign-in.
 */
final class ReportController extends Controller
{
    public const string ENGINE_FILE = 'resources/apps-script/report-engine.gs';

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

    /** @param array<string, string> $params */
    public function script(array $params): void
    {
        if (!$this->accessGranted($params['site_id'], $params['extraction_id'])) {
            $this->response->json(['error' => 'Invalid or missing credentials'], 401);

            return;
        }

        $engine = self::engineScript(dirname(__DIR__, 3) . '/' . self::ENGINE_FILE);
        if ($engine === null) {
            throw new RuntimeException('Report engine script missing or without a VERSION line: ' . self::ENGINE_FILE);
        }

        $this->response->json($engine, 200, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The engine's code and its `var VERSION = <n>;` — the number the loader
     * compares with the copy stored in the Doc. Null when unreadable or unversioned.
     *
     * @return array{version: int, code: string}|null
     */
    public static function engineScript(string $file): ?array
    {
        $code = is_file($file) ? file_get_contents($file) : false;
        if ($code === false || preg_match('/^var VERSION = (\d+);$/m', $code, $m) !== 1) {
            return null;
        }

        return ['version' => (int) $m[1], 'code' => $code];
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
