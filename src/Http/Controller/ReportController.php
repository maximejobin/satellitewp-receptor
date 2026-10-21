<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use RuntimeException;
use SatelliteWP\Manager\Http\ExtractionBundle;
use SatelliteWP\Manager\Http\ReportDocument;
use SatelliteWP\Manager\Storage\Index;

/**
 * GET /site/{id}/extraction/{id}/report.json — the Google Docs report
 * script's data feed — and report-script.json, the rendering engine the Doc's
 * loader runs. The script holds no browser session, so both routes are gated
 * by the same report credentials instead of the sign-in.
 */
final class ReportController extends Controller
{
    public const string ENGINE_FILE = 'resources/apps-script/report-engine.gs';

    /**
     * ?lang= when given, else the language chosen before the run. The key
     * carries no lang: loaders already pasted in Docs accept nothing after the token.
     *
     * @param array<string, mixed> $meta
     */
    public static function reportLocale(mixed $requested, array $meta): string
    {
        foreach ([is_string($requested) ? strtolower($requested) : null, $meta['language'] ?? null] as $lang) {
            if (in_array($lang, ['fr', 'en'], true)) {
                return $lang;
            }
        }

        return 'fr';
    }

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
        $row     = $this->app->index()->getExtraction($siteId, $extractionId);
        if ($payload === null || $row === null) {
            $this->response->json(['error' => 'Extraction not found'], 404);

            return;
        }

        // Before "done" the findings and probes are partial: a report built from them would read as complete.
        if (($row['status'] ?? null) !== Index::STATUS_DONE) {
            $this->response->json(['error' => 'The analysis of this extraction is not done'], 409);

            return;
        }

        $meta  = $store->readMeta($siteId, $extractionId) ?? [];
        $t     = $this->app->translator(self::reportLocale($_GET['lang'] ?? null, $meta));
        $token = (string) ($_GET['token'] ?? '');

        $document = ReportDocument::build(
            $this->app,
            $t,
            $this->baseUrl() . '/assets/report-icons',
            $extractionId,
            // The analyst captured on the token when it was minted; the shared API key carries none.
            $token !== '' ? $this->app->reportTokenStore()->issuedBy($token) : '',
            $payload,
            $store->readSiteInfo($siteId) ?? [],
            $meta,
            $store->readAllProbeResults($siteId, $extractionId),
            $store->readLicenses($siteId, $extractionId) ?? [],
            $store->readFindings($siteId, $extractionId) ?? [],
            $store->readObservations($siteId, $extractionId)
        );

        $this->response->json($document, 200, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET …/bundle.tar.gz — the extraction's raw and compiled data, personal
     * data masked, for an AI working in a local copy of the site
     * (Http\ExtractionBundle). Same credentials as report.json.
     *
     * @param array<string, string> $params
     */
    public function bundle(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];

        if (!$this->accessGranted($siteId, $extractionId)) {
            $this->response->text(401, 'Invalid or missing credentials');

            return;
        }
        $row = $this->app->index()->getExtraction($siteId, $extractionId);
        if ($row === null || ($row['status'] ?? null) !== Index::STATUS_DONE) {
            $this->response->text($row === null ? 404 : 409, $row === null ? 'Extraction not found' : 'The analysis of this extraction is not done');

            return;
        }

        $archive = (new ExtractionBundle($this->app, $this->baseUrl()))->archive($siteId, $extractionId, (string) $row['status']);
        if ($archive === null) {
            $this->response->text(404, 'Extraction not found');

            return;
        }

        $this->response->status(200);
        $this->response->header('Content-Type: application/gzip');
        $this->response->header('Content-Disposition: attachment; filename="swp-extraction-' . $extractionId . '.tar.gz"');
        $this->response->header('Cache-Control: no-store');
        $this->response->header('X-Content-Type-Options: nosniff');
        $this->response->write($archive);
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
