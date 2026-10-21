<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use SatelliteWP\Manager\Http\ExtractionBundle;
use SatelliteWP\Manager\Http\ReportContract;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Http\Session;
use SatelliteWP\Manager\Probe\BlogVaultProbe;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Support\HostGuard;
use SatelliteWP\Manager\Web\DefaultObservations;
use SatelliteWP\Manager\Web\ObservationsCsv;

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

        // The pre-flights only matter while the analyst decides whether to run (live calls, so not on every queued-page refresh).
        $awaiting       = (string) ($row['status'] ?? '') === Index::STATUS_PENDING;
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
            'wpVersions'          => $this->app->wordPressVersions()->all(),
            'ignoredVulnerabilities' => $this->app->ignoredVulnerabilities(),
            'csrf'                => $this->csrfToken(),
            'blogVault'           => $awaiting ? $this->blogVaultPreflight($payload) : null,
            'httpAuth'            => $awaiting ? $this->httpAuthPreflight($siteId, $payload) : null,
            'reportAssets'        => true,
            'observations'        => DefaultObservations::resolve(
                DefaultObservations::items($store->readObservations($siteId, $extractionId), ReportContract::load($this->app)),
                $this->app->translator($this->locale())
            ),
            'observationSections' => ReportContract::observationSections(ReportContract::load($this->app)),
            'canEditObservations' => $this->currentUserCan('extraction_observations_edit'),
            'observationsImport'  => $this->takeImportFlash("{$siteId}/{$extractionId}"),
            'observationErrors'   => $this->takeObservationErrors("{$siteId}/{$extractionId}"),
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
     * GET …/observations.csv — the extraction's observations (defaults
     * included, in the page's language) in the import format, so they can be
     * edited in a spreadsheet or carried to another extraction.
     *
     * @param array<string, string> $params
     */
    public function exportObservations(array $params): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        $store = $this->app->dataStore();
        if ($store->readExtractionPayload($siteId, $extractionId) === null) {
            $this->notFound();

            return;
        }

        $items = DefaultObservations::resolve(
            DefaultObservations::items($store->readObservations($siteId, $extractionId), ReportContract::load($this->app)),
            $this->app->translator($this->locale())
        );

        $this->response->header('Content-Type: text/csv; charset=utf-8');
        $this->response->header('Content-Disposition: attachment; filename="observations-' . $extractionId . '.csv"');
        $this->response->header('X-Content-Type-Options: nosniff');
        $this->response->write(ObservationsCsv::export($items));
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

        if (!$this->app->index()->transitionStatus($siteId, $extractionId, [Index::STATUS_PENDING, Index::STATUS_ERROR], Index::STATUS_QUEUED)) {
            $this->response->text(409, "The extraction's status changed meanwhile: reload the page.");

            return;
        }
        $this->redirect(Router::safeReturn($_POST['return'] ?? null, "/site/{$siteId}/extraction/{$extractionId}"));
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

        if (!$this->app->index()->transitionStatus($siteId, $extractionId, [Index::STATUS_PENDING, Index::STATUS_QUEUED], Index::STATUS_ABORTED)) {
            $this->response->text(409, "The extraction's status changed meanwhile: reload the page.");

            return;
        }
        // Kept on disk too, so index:rebuild does not bring it back as pending.
        $this->app->dataStore()->updateMeta($siteId, $extractionId, ['aborted' => true]);
        $this->redirect(Router::safeReturn($_POST['return'] ?? null, "/site/{$siteId}/extraction/{$extractionId}"));
    }

    /**
     * POST …/rerun — config `debugging_tools` only (a dev escape hatch):
     * re-runs the chosen probes synchronously on a done or failed extraction,
     * the one deliberate bypass of the frozen-snapshot rule. Absent (404)
     * when the flag is off.
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
        // Pending or queued belongs to the worker: a partial run would mark it done.
        if (!$this->requireCapability('extraction_run')
            || !$this->requireExtractionStatus($siteId, $extractionId, [Index::STATUS_DONE, Index::STATUS_ERROR])) {
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
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_view_technical')
            || !$this->requireExtractionStatus($siteId, $extractionId, [Index::STATUS_DONE])
        ) {
            return;
        }
        $token = $this->app->reportTokenStore()->issue($siteId, $extractionId, $this->currentUserDisplayName());

        // No &lang=: report.json reads the run's language itself (ReportController::reportLocale()).
        $this->response->json(['url' => $this->baseUrl()
            . "/site/{$siteId}/extraction/{$extractionId}/report.json?token=" . rawurlencode($token)]);
    }

    /**
     * POST …/bundle-command — mints a one-hour token and answers the shell
     * command that downloads the extraction bundle into the current
     * directory (JSON: the button's fetch() copies it).
     *
     * @param array<string, string> $params
     */
    public function bundleCommand(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_view_technical')
            || !$this->requireExtractionStatus($siteId, $extractionId, [Index::STATUS_DONE])
        ) {
            return;
        }
        $token = $this->app->reportTokenStore()->issue($siteId, $extractionId, $this->currentUserDisplayName());

        $this->response->json(['command' => ExtractionBundle::command($this->baseUrl()
            . "/site/{$siteId}/extraction/{$extractionId}/bundle.tar.gz?token=" . rawurlencode($token))]);
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

        $action      = (string) ($_POST['action'] ?? '');
        $id          = (string) ($_POST['id'] ?? '');
        $section     = (string) ($_POST['section'] ?? '');
        $color       = (string) ($_POST['color'] ?? '');
        $title       = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $page        = "/site/{$siteId}/extraction/{$extractionId}#observations";

        if (in_array($action, ['add', 'edit'], true)) {
            $problems = ObservationsCsv::problems(
                $section,
                $color,
                $title,
                $description,
                ReportContract::observationSections(ReportContract::load($this->app))
            );
            if ($problems !== []) {
                Session::start();
                $_SESSION['observation_errors'] = ['extraction' => "{$siteId}/{$extractionId}", 'errors' => $problems];
                $this->redirect($page);

                return;
            }
        }

        $record = static fn (string $recordId): array => [
            'id'          => $recordId,
            'section'     => $section,
            'color'       => $color,
            'title'       => $title,
            'description' => $description,
            'include'     => isset($_POST['include']),
        ];

        // The page may have shown the line in either language.
        $translators = [$this->app->translator('fr'), $this->app->translator('en')];

        // Read-modify-write under the extraction's lock: concurrent saves must not drop an edit.
        $this->app->dataStore()->mutateObservations($siteId, $extractionId, static function (array $items) use ($action, $id, $record, $translators): array {
            if ($action === 'add') {
                $items[] = $record(bin2hex(random_bytes(6)));
            } elseif ($action === 'edit') {
                foreach ($items as $k => $item) {
                    if (is_array($item) && ($item['id'] ?? null) === $id) {
                        $new = $record($id);
                        // Text left as shown keeps following the report's language.
                        foreach ($translators as $t) {
                            $shown = DefaultObservations::resolve([$item], $t)[0];
                            if (isset($item['template']) && is_array($shown)
                                && $new['title'] === $shown['title'] && $new['description'] === $shown['description']
                            ) {
                                $new = ['title' => '', 'description' => '', 'template' => $item['template']] + $new;
                                break;
                            }
                        }
                        $items[$k] = $new;
                    }
                }
            } elseif ($action === 'remove') {
                $items = array_filter($items, static fn (mixed $i): bool => !is_array($i) || ($i['id'] ?? null) !== $id);
            }

            return array_values($items);
        }, DefaultObservations::defaults(ReportContract::load($this->app)));

        $this->redirect($page);
    }

    /**
     * POST …/observations-import — appends every observation of an uploaded
     * CSV, or none when a row is invalid. The outcome is flashed to the page.
     *
     * @param array<string, string> $params
     */
    public function importObservations(array $params): void
    {
        [$siteId, $extractionId] = [$params['site_id'], $params['extraction_id']];
        if (!$this->requireCapability('extraction_observations_edit')
            || !$this->requireExtractionStatus($siteId, $extractionId, null)
        ) {
            return;
        }

        $file   = $_FILES['csv'] ?? null;
        $result = match (true) {
            !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                => ['items' => [], 'errors' => ['Choose a CSV file to import.']],
            in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                => ['items' => [], 'errors' => ['The file is larger than 1 MB.']],
            $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])
                => ['items' => [], 'errors' => ['The upload failed; try again.']],
            default => ObservationsCsv::parse(
                (string) file_get_contents((string) $file['tmp_name'], false, null, 0, ObservationsCsv::MAX_BYTES + 1),
                ReportContract::observationSections(ReportContract::load($this->app))
            ),
        };

        if ($result['errors'] === []) {
            $items = $result['items'];
            $this->app->dataStore()->mutateObservations($siteId, $extractionId, static function (array $existing) use ($items): array {
                foreach ($items as $item) {
                    $existing[] = ['id' => bin2hex(random_bytes(6))] + $item;
                }

                return $existing;
            }, DefaultObservations::defaults(ReportContract::load($this->app)));
        }

        Session::start();
        $_SESSION['observations_import'] = [
            'extraction' => "{$siteId}/{$extractionId}",
            'imported'   => count($result['items']),
            'errors'     => $result['errors'],
        ];
        $this->redirect("/site/{$siteId}/extraction/{$extractionId}#observations");
    }

    /**
     * Why the last add/edit was refused, shown once and only on its own extraction.
     *
     * @return list<string>
     */
    private function takeObservationErrors(string $extraction): array
    {
        Session::start();
        $flash = $_SESSION['observation_errors'] ?? null;
        if (!is_array($flash) || ($flash['extraction'] ?? null) !== $extraction) {
            return [];
        }
        unset($_SESSION['observation_errors']);

        return array_values(array_map('strval', (array) ($flash['errors'] ?? [])));
    }

    /**
     * The last CSV import outcome, shown once and only on its own extraction.
     *
     * @return array{imported: int, errors: list<string>}|null
     */
    private function takeImportFlash(string $extraction): ?array
    {
        Session::start();
        $flash = $_SESSION['observations_import'] ?? null;
        if (!is_array($flash) || ($flash['extraction'] ?? null) !== $extraction) {
            return null;
        }
        unset($_SESSION['observations_import']);

        return ['imported' => (int) ($flash['imported'] ?? 0), 'errors' => array_values(array_map('strval', (array) ($flash['errors'] ?? [])))];
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

        $this->redirect(Router::safeReturn($_POST['return'] ?? null, "/site/{$siteId}/extraction/{$extractionId}"));
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
