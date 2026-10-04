<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use SatelliteWP\Manager\Http\ReportContract;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Probe\BlogVaultProbe;
use SatelliteWP\Manager\Rules\Pastille;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Support\HostGuard;

/** One extraction: its report page, raw files and every action on it. */
final class ExtractionController extends Controller
{
    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        $store   = $this->app->dataStore();
        $payload = $store->readExtractionPayload($siteId, $extractionId);
        if ($payload === null) {
            $this->notFound();

            return;
        }

        $row = $this->app->index()->getExtraction($siteId, $extractionId);

        // The pre-flights only matter while the analyst decides whether to run.
        $awaiting       = in_array((string) ($row['status'] ?? ''), [Index::STATUS_PENDING, Index::STATUS_QUEUED], true);
        $debuggingTools = (bool) $this->app->config->get('debugging_tools', false) && $this->currentUserCan('extraction_run');

        $this->render('extraction', [
            'title'               => 'Extraction ' . $extractionId,
            'nav'                 => 'sites',
            'siteId'              => $siteId,
            'extractionId'        => $extractionId,
            'site'                => $store->readSiteInfo($siteId) ?? [],
            'payload'             => $payload,
            'meta'                => $store->readMeta($siteId, $extractionId) ?? [],
            'findings'            => $store->readFindings($siteId, $extractionId),
            'probes'              => $store->readAllProbeResults($siteId, $extractionId),
            'row'                 => $row,
            'eol'                 => $this->app->endOfLife(),
            'ignoredVulnerabilities' => $this->app->ignoredVulnerabilities(),
            'csrf'                => $this->csrfToken(),
            'blogVault'           => $awaiting ? $this->blogVaultPreflight($payload) : null,
            'httpAuth'            => $awaiting ? $this->httpAuthPreflight($siteId, $payload) : null,
            'reportAssets'        => true,
            'observations'        => (array) ($store->readObservations($siteId, $extractionId)['items'] ?? []),
            'observationSections' => ReportContract::observationSections(ReportContract::load($this->app)),
            'canEditObservations' => $this->currentUserCan('extraction_observations_edit'),
            'licenseStatuses'     => $store->readLicenses($siteId, $extractionId) ?? [],
            'debuggingTools'      => $debuggingTools,
            'rerunProbes'         => $debuggingTools
                ? array_map(static fn ($probe): string => $probe->name(), $this->app->probeRegistry()->enabled())
                : [],
            'notice'              => (string) ($_GET['notice'] ?? ''),
            'noticeRef'           => (string) ($_GET['ref'] ?? ''),
        ]);
    }

    /** @param array<string, string> $params */
    public function raw(array $params): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $relative = Router::resolveRawFile($params['file']);
        $file     = $relative !== null
            ? $this->app->dataStore()->extractionDir($params['site_id'], $params['extraction_id']) . '/' . $relative
            : null;

        if ($file === null || !is_file($file)) {
            $this->notFound();

            return;
        }

        $this->response->header('Content-Type: application/json; charset=utf-8');
        $this->response->header('X-Content-Type-Options: nosniff');
        $this->response->write((string) file_get_contents($file));
    }

    /**
     * POST …/run — queue for the cron worker; the web request only flips a
     * status, so nothing here can time out. A done extraction is a frozen
     * snapshot: only pending and error runs can be (re)queued.
     *
     * @param array<string, string> $params
     */
    public function run(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_run')
            || !$this->requireExtractionStatus($siteId, $extractionId, [Index::STATUS_PENDING, Index::STATUS_ERROR])
        ) {
            return;
        }

        // Only the first "Run analysis" form carries a language; a retry keeps the one already chosen.
        if (isset($_POST['language'])) {
            $language = in_array($_POST['language'], ['fr', 'en'], true) ? (string) $_POST['language'] : 'fr';
            $this->app->dataStore()->updateMeta($siteId, $extractionId, ['language' => $language]);
        }

        $this->app->index()->setExtractionStatus($siteId, $extractionId, Index::STATUS_QUEUED);
        $this->redirect(Router::safeReturn($_POST['return'] ?? "/site/{$siteId}/extraction/{$extractionId}"));
    }

    /** @param array<string, string> $params */
    public function abort(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_run')
            || !$this->requireExtractionStatus($siteId, $extractionId, [Index::STATUS_PENDING, Index::STATUS_QUEUED])
        ) {
            return;
        }

        $this->app->index()->setExtractionStatus($siteId, $extractionId, Index::STATUS_ABORTED);
        $this->redirect(Router::safeReturn($_POST['return'] ?? "/site/{$siteId}/extraction/{$extractionId}"));
    }

    /**
     * POST …/rerun — config `debugging_tools` only (a dev escape hatch):
     * re-runs the chosen probes synchronously, whatever the status, the one
     * deliberate bypass of the frozen-snapshot rule. Absent (404) when the
     * flag is off.
     *
     * @param array<string, string> $params
     */
    public function rerun(array $params): void
    {
        if (!$this->app->config->get('debugging_tools', false)) {
            $this->notFound();

            return;
        }

        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_run') || !$this->requireExtractionStatus($siteId, $extractionId, null)) {
            return;
        }

        $registry = $this->app->probeRegistry();
        $probes   = array_values(array_filter(
            array_map('strval', is_array($_POST['probes'] ?? null) ? $_POST['probes'] : []),
            static fn (string $name): bool => $registry->isEnabled($name)
        ));

        $page = "/site/{$siteId}/extraction/{$extractionId}";
        if ($probes === []) {
            $this->redirect(Router::withQueryParam($page, 'notice', 'rerun-none'));

            return;
        }

        try {
            $this->app->pipeline()->run($siteId, $extractionId, $probes);
        } catch (\Throwable $e) {
            $ref = $this->app->errorLog()->recordThrowable('rerun', $e);
            $this->redirect(Router::withQueryParam(Router::withQueryParam($page, 'notice', 'rerun-failed'), 'ref', $ref));

            return;
        }

        $this->redirect(Router::withQueryParam($page, 'notice', 'rerun-done'));
    }

    /**
     * POST …/report-token — mints a one-hour token for this extraction and
     * answers the ready-to-paste report.json URL (JSON, no redirect: the
     * button's fetch() copies it).
     *
     * @param array<string, string> $params
     */
    public function reportToken(array $params): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        $token = $this->app->reportTokenStore()->issue($siteId, $extractionId, $this->currentUserDisplayName());

        // The report follows the language chosen before the run; &lang= stays overridable by hand.
        $meta = $this->app->dataStore()->readMeta($siteId, $extractionId) ?? [];
        $lang = in_array($meta['language'] ?? null, ['fr', 'en'], true) ? (string) $meta['language'] : 'fr';

        $this->response->json(['url' => $this->baseUrl()
            . "/site/{$siteId}/extraction/{$extractionId}/report.json?token=" . rawurlencode($token)
            . '&lang=' . $lang]);
    }

    /**
     * POST …/observations — an analyst's own lines for a report section,
     * stored with the extraction (a new extraction starts with none).
     *
     * @param array<string, string> $params
     */
    public function observations(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_observations_edit')
            || !$this->requireExtractionStatus($siteId, $extractionId, null)
        ) {
            return;
        }

        $action  = (string) ($_POST['action'] ?? '');
        $id      = (string) ($_POST['id'] ?? '');
        $section = (string) ($_POST['section'] ?? '');
        $color   = (string) ($_POST['color'] ?? '');
        $valid   = in_array($section, ReportContract::observationSections(ReportContract::load($this->app)), true)
            && Pastille::isValid($color);
        $record  = static fn (string $recordId): array => [
            'id'          => $recordId,
            'section'     => $section,
            'color'       => $color,
            'title'       => trim((string) ($_POST['title'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'include'     => isset($_POST['include']),
        ];

        // Read-modify-write under the extraction's lock: concurrent saves must not drop an edit.
        $this->app->dataStore()->mutateObservations($siteId, $extractionId, static function (array $items) use ($action, $valid, $id, $record): array {
            if ($action === 'add' && $valid) {
                $items[] = $record(bin2hex(random_bytes(6)));
            } elseif ($action === 'edit' && $valid) {
                foreach ($items as $k => $item) {
                    if (is_array($item) && ($item['id'] ?? null) === $id) {
                        $items[$k] = $record($id);
                    }
                }
            } elseif ($action === 'remove') {
                $items = array_filter($items, static fn (mixed $i): bool => !is_array($i) || ($i['id'] ?? null) !== $id);
            }

            return array_values($items);
        });

        $this->redirect("/site/{$siteId}/extraction/{$extractionId}#observations");
    }

    /**
     * POST …/licenses — this install's licence-key status for one
     * plugin/theme, as of this extraction. Same auto-save contract as
     * /catalog: a rejected save answers 400, never a redirect.
     *
     * @param array<string, string> $params
     */
    public function licenses(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('catalog_edit') || !$this->requireExtractionStatus($siteId, $extractionId, null)) {
            return;
        }

        $type  = (string) ($_POST['type'] ?? '');
        $slug  = (string) ($_POST['slug'] ?? '');
        $saved = in_array($type, ['plugin', 'theme'], true) && $slug !== ''
            && $this->app->dataStore()->setLicenseStatus($siteId, $extractionId, $type, $slug, (string) ($_POST['status'] ?? ''));

        if (!$saved) {
            $this->response->text(400, 'Could not save the licence status.');

            return;
        }

        $this->redirect(Router::safeReturn($_POST['return'] ?? "/site/{$siteId}/extraction/{$extractionId}"));
    }

    /**
     * Does the site answer 401 to an anonymous request? Every external check
     * would then come back empty, so this is settled before the run. Stored
     * credentials are sent, so a correctly configured site reads as "ok".
     * Same SSRF guard as the probes: every hop vetted and pinned to its IP.
     *
     * @param array<string, mixed> $payload
     * @return array{checked: bool, required: bool, configured: bool, status: int|null, error?: string}
     */
    private function httpAuthPreflight(string $siteId, array $payload): array
    {
        $credentials = $this->app->keyStore()->getHttpAuth($siteId);
        $url         = (string) ($payload['home_url'] ?? $payload['site_url'] ?? '');
        $siteHost    = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $unchecked   = ['checked' => false, 'required' => false, 'configured' => $credentials !== null, 'status' => null];

        if ($siteHost === '') {
            return $unchecked;
        }

        $status = null;
        try {
            for ($hop = 0; $hop <= 5; $hop++) {
                $hopHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
                $scheme  = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
                $ip      = $hopHost !== '' ? HostGuard::publicIpFor($hopHost) : null;
                if ($ip === null || !in_array($scheme, ['http', 'https'], true)) {
                    return $unchecked + ['error' => 'Host does not resolve to a public address'];
                }
                $port = (int) (parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));

                $options = [
                    'connect_timeout' => 5,
                    'timeout'         => 10,
                    'http_errors'     => false,
                    'allow_redirects' => false,
                    'headers'         => ['User-Agent' => (string) $this->app->config->get('probes.user_agent', 'SatelliteWP-Manager/1.0')],
                    'curl'            => [\CURLOPT_RESOLVE => [HostGuard::curlResolveEntry($hopHost, $port, $ip)]],
                ];
                // Basic credentials only over https, and only to the site itself.
                if ($credentials !== null && $scheme === 'https' && $hopHost === $siteHost) {
                    $options['auth'] = [$credentials['username'], $credentials['password']];
                }

                $response = (new Client($options))->get($url);
                $status   = $response->getStatusCode();
                $location = $response->getHeaderLine('Location');
                if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '' || $hop === 5) {
                    break;
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            }
        } catch (\Throwable $e) {
            return $unchecked + ['error' => $e->getMessage()];
        }

        return ['checked' => true, 'required' => $status === 401, 'configured' => $credentials !== null, 'status' => $status];
    }

    /**
     * Is the site managed in BlogVault (exact host match)? Absence is a
     * business signal — no maintenance plan — not an error.
     *
     * @param array<string, mixed> $payload
     * @return array{configured: bool, found: bool, host: string, name?: string, id?: string, error?: string}
     */
    private function blogVaultPreflight(array $payload): array
    {
        $host       = (string) (parse_url((string) ($payload['home_url'] ?? $payload['site_url'] ?? ''), PHP_URL_HOST) ?? '');
        $configured = (string) $this->app->config->get('blogvault.base_url', '') !== ''
            && (string) $this->app->config->get('blogvault.api_key', '') !== '';

        if (!$configured || $host === '') {
            return ['configured' => $configured, 'found' => false, 'host' => $host];
        }

        try {
            $match = BlogVaultProbe::matchSite(
                $this->app->blogVault()->get('sites', ['filters' => ['url:contains' => $host]]),
                $host
            );
        } catch (\Throwable $e) {
            return ['configured' => true, 'found' => false, 'host' => $host, 'error' => $e->getMessage()];
        }

        if ($match === null) {
            return ['configured' => true, 'found' => false, 'host' => $host];
        }

        return [
            'configured' => true,
            'found'      => true,
            'host'       => $host,
            'name'       => (string) ($match['title'] ?? $match['url'] ?? $host),
            'id'         => (string) ($match['id'] ?? ''),
        ];
    }
}
