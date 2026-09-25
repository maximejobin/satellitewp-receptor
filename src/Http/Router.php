<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use GuzzleHttp\Client;
use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Crm\ClientsRepository;
use SatelliteWP\Xtractor\Probe\BlogVaultProbe;
use SatelliteWP\Xtractor\Web\ReportBuilder;
use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Rules\Translator;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Storage\UserStore;
use SatelliteWP\Xtractor\Support\HostGuard;
use SatelliteWP\Xtractor\Support\SiteDisplay;

/**
 * Read-only web UI. Lists come from SQLite, detail pages from JSON files.
 *
 * Routes:
 *   /                                        sites list
 *   /site/{site_id}                          site detail (history, events, trends)
 *   /site/{site_id}/extraction/{id}          extraction detail (findings + probes + payload)
 *   /site/{site_id}/extraction/{id}/raw/{f}  serve a JSON file (basename-confined)
 *   /clients                                 external CRM: clients list
 *   /clients/{id}                            client detail (subscriptions + linked websites)
 *   /websites                                external CRM: websites list (filter by tag/client)
 *   /websites/{id}                           website detail (subscriptions + items)
 *   /products                                external CRM: products list (filter by type)
 *   /items                                   external CRM: cross-site item search
 *
 * Clients/websites/products/items are flat siblings, not nested under each
 * other — see the note on Router::withCrmRepository().
 */
final class Router
{
    public function __construct(private readonly App $app)
    {
    }

    public function dispatch(string $path): void
    {
        // The sign-in dance itself must not require being signed in.
        if (str_starts_with($path, '/auth/')) {
            $this->authRoute($path);

            return;
        }

        $match = self::matchRoute($path);

        // A script (e.g. the Google Docs report template's Apps Script) has
        // no browser session to hold a Google sign-in cookie, so this one
        // route is gated by its own bearer token instead of authenticate()
        // — same reasoning as /auth/ above, a different door for a caller
        // that structurally cannot use the normal one.
        if ($match['route'] === 'extraction_report_json') {
            $this->extractionReportJson($match['params']['site_id'], $match['params']['extraction_id']);

            return;
        }

        if (!$this->authenticate()) {
            return;
        }

        $params = $match['params'];

        match ($match['route']) {
            'home'                     => $this->homePage(),
            'sites'                    => $this->sitesPage(),
            'status'                   => $this->statusPage(),
            'catalog'                  => $this->catalogPage(),
            'catalog_search'           => $this->catalogSearch(),
            'users'                    => $this->usersPage(),
            'profile'                  => $this->profilePage(),
            'styleguide'               => $this->styleguidePage(),
            'site'                     => $this->sitePage($params['site_id']),
            'extraction'               => $this->extractionPage($params['site_id'], $params['extraction_id']),
            'raw'                      => $this->rawFile($params['site_id'], $params['extraction_id'], $params['file']),
            'data_wp_versions'         => $this->dataWpVersionsPage(),
            'data_databases'           => $this->dataDatabasesPage(),
            'data_php_versions'        => $this->dataPhpVersionsPage(),
            'data_vulnerabilities'     => $this->dataVulnerabilitiesPage(),
            'data_vulnerabilities_search' => $this->dataVulnerabilitiesSearch(),
            'crm_clients'              => $this->crmClientsPage(),
            'crm_clients_search'       => $this->crmClientsSearch(),
            'crm_client'               => $this->crmClientPage((int) $params['id']),
            'crm_websites'             => $this->crmWebsitesPage(),
            'crm_websites_search'      => $this->crmWebsitesSearch(),
            'crm_tags_search'          => $this->crmTagsSearch(),
            'crm_website'              => $this->crmWebsitePage((int) $params['id']),
            'crm_products'             => $this->crmProductsPage(),
            'crm_items'                => $this->crmItemsPage(),
            'crm_items_search'         => $this->crmItemsSearch(),
            default                    => $this->notFound(),
        };
    }

    /**
     * Pure route resolution — no side effects, so it is unit-testable. Returns
     * the matched route name and its validated params, or 'not_found'. All
     * identifiers are validated here (UUID, extraction id), which is the first
     * line of defence for the read-only web surface.
     *
     * @return array{route: string, params: array<string, string>}
     */
    public static function matchRoute(string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));

        $isSite       = static fn (int $i): bool => PayloadValidator::isUuid($segments[$i] ?? '');
        $isExtraction = static fn (int $i): bool => self::isExtractionId($segments[$i] ?? '');
        $isId         = static fn (int $i): bool => ctype_digit($segments[$i] ?? '');

        return match (true) {
            $segments === []
                => ['route' => 'home', 'params' => []],

            $segments === ['extractions']
                => ['route' => 'sites', 'params' => []],

            $segments === ['status']
                => ['route' => 'status', 'params' => []],

            $segments === ['catalog']
                => ['route' => 'catalog', 'params' => []],

            $segments === ['catalog', 'search']
                => ['route' => 'catalog_search', 'params' => []],

            $segments === ['clients']
                => ['route' => 'crm_clients', 'params' => []],

            // select2 AJAX sources (see subscription_website_form() /
            // crm-websites.php) — literal 'search'/'tags' segments, checked
            // before the numeric-id patterns below so they never get
            // mis-read as an id (they aren't digits either way, but this
            // keeps the two concerns visibly separate).
            $segments === ['clients', 'search']
                => ['route' => 'crm_clients_search', 'params' => []],

            count($segments) === 2 && $segments[0] === 'clients' && $isId(1)
                => ['route' => 'crm_client', 'params' => ['id' => $segments[1]]],

            $segments === ['websites']
                => ['route' => 'crm_websites', 'params' => []],

            $segments === ['websites', 'search']
                => ['route' => 'crm_websites_search', 'params' => []],

            $segments === ['websites', 'tags', 'search']
                => ['route' => 'crm_tags_search', 'params' => []],

            count($segments) === 2 && $segments[0] === 'websites' && $isId(1)
                => ['route' => 'crm_website', 'params' => ['id' => $segments[1]]],

            $segments === ['products']
                => ['route' => 'crm_products', 'params' => []],

            $segments === ['items']
                => ['route' => 'crm_items', 'params' => []],

            $segments === ['items', 'search']
                => ['route' => 'crm_items_search', 'params' => []],

            $segments === ['users']
                => ['route' => 'users', 'params' => []],

            $segments === ['profile']
                => ['route' => 'profile', 'params' => []],

            $segments === ['styleguide']
                => ['route' => 'styleguide', 'params' => []],

            $segments === ['data', 'wp-versions']
                => ['route' => 'data_wp_versions', 'params' => []],

            $segments === ['data', 'databases']
                => ['route' => 'data_databases', 'params' => []],

            $segments === ['data', 'php-versions']
                => ['route' => 'data_php_versions', 'params' => []],

            $segments === ['data', 'vulnerabilities']
                => ['route' => 'data_vulnerabilities', 'params' => []],

            $segments === ['data', 'vulnerabilities', 'search']
                => ['route' => 'data_vulnerabilities_search', 'params' => []],

            count($segments) === 2 && $segments[0] === 'site' && $isSite(1)
                => ['route' => 'site', 'params' => ['site_id' => $segments[1]]],

            count($segments) === 4 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && $isSite(1) && $isExtraction(3)
                => ['route' => 'extraction', 'params' => ['site_id' => $segments[1], 'extraction_id' => $segments[3]]],

            count($segments) === 5 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && $segments[4] === 'report.json' && $isSite(1) && $isExtraction(3)
                => ['route' => 'extraction_report_json', 'params' => ['site_id' => $segments[1], 'extraction_id' => $segments[3]]],

            count($segments) === 6 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && $segments[4] === 'raw' && $isSite(1) && $isExtraction(3)
                => ['route' => 'raw', 'params' => [
                    'site_id'       => $segments[1],
                    'extraction_id' => $segments[3],
                    'file'          => $segments[5],
                ]],

            default => ['route' => 'not_found', 'params' => []],
        };
    }

    /**
     * One entry for the status page's "External sync" list — a source name,
     * when it last synced, the threshold it's judged against, and whether
     * it's currently stale. Shared by every source type (a CRM table's
     * MAX(date_sync), or one of the app's own reference caches) so they can
     * all land in the same list and the same total/up-to-date/error counts.
     *
     * @return array{synced_at: string|null, threshold_seconds: int, stale: bool}
     */
    private function syncEntry(?string $syncedAt, int $thresholdSeconds): array
    {
        $ageSeconds = $syncedAt !== null ? time() - (int) strtotime($syncedAt) : null;

        return [
            'synced_at'         => $syncedAt,
            'threshold_seconds' => $thresholdSeconds,
            'stale'             => $syncedAt === null || $ageSeconds > $thresholdSeconds,
        ];
    }

    private function statusPage(): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $extractionCounts = $this->app->index()->statusCounts();

        $dataFreshness = (int) $this->app->config->get('data_sync_freshness.endoflife_seconds', 2 * 3600);
        $eol           = $this->app->endOfLife();
        $syncSources   = [
            'endoflife: wordpress' => $this->syncEntry($eol->refreshedAt('wordpress'), $dataFreshness),
            'endoflife: php'       => $this->syncEntry($eol->refreshedAt('php'), $dataFreshness),
            'endoflife: mysql'     => $this->syncEntry($eol->refreshedAt('mysql'), $dataFreshness),
            'endoflife: mariadb'   => $this->syncEntry($eol->refreshedAt('mariadb'), $dataFreshness),
            'wordpress.org versions' => $this->syncEntry(
                $this->app->wordPressVersions()->refreshedAt(),
                (int) $this->app->config->get('data_sync_freshness.wordpress_versions_seconds', 2 * 3600)
            ),
            'wordfence intelligence' => $this->syncEntry(
                $this->app->wordfenceIndex()->refreshedAt(),
                (int) $this->app->config->get('data_sync_freshness.wordfence_seconds', 36 * 3600)
            ),
        ];

        $crmRepo = $this->app->crmRepository();
        $crm     = null;
        if ($crmRepo !== null) {
            try {
                $defaultFreshness = (int) $this->app->config->get('crm_sync_freshness.default_seconds', 86400);
                $overrides        = (array) $this->app->config->get('crm_sync_freshness.overrides', []);

                foreach ($crmRepo->lastSyncByTable() as $table => $syncedAt) {
                    $threshold             = (int) ($overrides[$table] ?? $defaultFreshness);
                    $syncSources[$table]   = $this->syncEntry($syncedAt, $threshold);
                }

                $crm = [
                    'unpaidClients'              => $crmRepo->clientsWithUnpaidSubscriptions(),
                    'emptyCompanyClients'        => $crmRepo->clientsWithEmptyCompany(),
                    'activeEmptyCompanyClients'  => $crmRepo->clientsActiveWithEmptyCompany(),
                    'activeNoHubspotClients'     => $crmRepo->clientsActiveWithoutHubspotId(),
                    'activeNoTeamworkClients'    => $crmRepo->clientsActiveWithoutTeamworkId(),
                    'orphanSubscriptions'        => $crmRepo->countOrphanSubscriptions(),
                ];
            } catch (\PDOException $e) {
                $ref = $this->app->errorLog()->recordThrowable('crm_db', $e);
                $crm = ['error' => $ref];
            }
        }

        $staleCount = count(array_filter($syncSources, static fn (array $s): bool => $s['stale']));

        $this->render('status', [
            'title'            => 'Status',
            'nav'              => 'status',
            'tooltip'          => true,
            'extractionCounts' => $extractionCounts,
            'syncSources'      => $syncSources,
            'syncTotals'       => [
                'total'      => count($syncSources),
                'upToDate'   => count($syncSources) - $staleCount,
                'stale'      => $staleCount,
            ],
            'crmConfigured'    => $crmRepo !== null,
            'crm'              => $crm,
        ]);
    }

    private function homePage(): void
    {
        $this->render('home', [
            'title' => 'SatelliteWP Xtractor',
            'nav'   => '',
        ]);
    }

    private function sitesPage(): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $this->render('sites', [
            'title'      => 'Extractions',
            'nav'        => 'sites',
            'sites'      => $this->app->index()->listSites($_GET['q'] ?? null),
            'search'     => (string) ($_GET['q'] ?? ''),
            'notice'     => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    private function catalogPage(): void
    {
        if (!$this->requireCapability('catalog_view')) {
            return;
        }

        $this->render('catalog', [
            'title'            => 'Software catalogue',
            'nav'              => 'catalog',
            'dataTables'       => true,
            'needsOnly'        => !empty($_GET['needs']),
            'unclassifiedOnly' => !empty($_GET['unclassified']),
        ]);
    }

    /**
     * JSON endpoint consumed by Datatables' server-side mode on /catalog
     * (SoftwareCatalog::search()) — the catalogue is expected to grow into
     * the thousands of entries, too many to render into the page at once.
     */
    private function catalogSearch(): void
    {
        if (!$this->requireCapability('catalog_view')) {
            return;
        }

        // Unlike dataVulnerabilitiesSearch()/crmItemsSearch() below, this
        // endpoint's rows carry real markup (license_select()'s <form>), not
        // plain scalars — helpers.php isn't loaded on this code path
        // otherwise (only render() -> layout.php pulls it in).
        require_once dirname(__DIR__) . '/Web/helpers.php';

        $draw   = (int) ($_GET['draw'] ?? 0);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = (int) ($_GET['length'] ?? 50);
        $length = $length > 0 ? min($length, 200) : 50;
        $query  = (string) ($_GET['search']['value'] ?? '');

        $result = $this->app->softwareCatalog()->search(
            null,
            ($_GET['needs'] ?? '') === 'true',
            ($_GET['unclassified'] ?? '') === 'true',
            $query,
            $start,
            $length
        );

        $csrf = $this->csrfToken();

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => array_map(static fn (array $e): array => [
                $e['type'],
                $e['slug'],
                $e['name'],
                license_select(
                    (string) $e['type'],
                    (string) $e['slug'],
                    (string) ($e['license'] ?? 'unknown'),
                    $csrf,
                    '/catalog',
                    $e['suggested'] ?? null
                ),
            ], $result['rows']),
        ]);
    }

    /**
     * @param callable(ClientsRepository): void $render
     */
    private function withCrmRepository(string $title, string $nav, callable $render): void
    {
        // One check here covers all six CRM pages (list + detail for each of
        // the four entities) that route through this helper.
        if (!$this->requireCapability('crm_view')) {
            return;
        }

        $repo = $this->app->crmRepository();
        if ($repo === null) {
            $this->render('crm-unavailable', [
                'title' => $title, 'nav' => $nav, 'reason' => 'unconfigured', 'ref' => null,
            ]);

            return;
        }

        try {
            $render($repo);
        } catch (\PDOException $e) {
            $ref = $this->app->errorLog()->recordThrowable('crm_db', $e);
            $this->render('crm-unavailable', [
                'title' => $title, 'nav' => $nav, 'reason' => 'error', 'ref' => $ref,
            ]);
        }
    }

    private function crmClientsPage(): void
    {
        $this->withCrmRepository('Clients', 'crm-clients', function (ClientsRepository $repo): void {
            $status        = (string) ($_GET['status'] ?? 'active');
            $search        = trim((string) ($_GET['q'] ?? ''));
            $subscriptions = (string) ($_GET['subscriptions'] ?? 'all');

            $this->render('crm-clients', [
                'title'          => 'Clients',
                'nav'            => 'crm-clients',
                'dataTables'     => true,
                'tooltip'        => true,
                'clients'        => $repo->listClients(
                    $status !== 'all' ? $status : null,
                    $search !== '' ? $search : null,
                    $subscriptions !== 'all' ? $subscriptions : null
                ),
                'selectedStatus'        => $status,
                'search'                => $search,
                'selectedSubscriptions' => $subscriptions,
                'lastSyncedAt'   => $repo->clientsLastSyncedAt(),
                // Checked unconditionally, independent of the filters above,
                // so the warning still appears even while looking at a
                // filtered subset that happens to hide every orphan.
                'orphanCount'    => $repo->countOrphanSubscriptions(),
            ]);
        });
    }

    private function crmClientPage(int $id): void
    {
        $this->withCrmRepository('Client', 'crm-clients', function (ClientsRepository $repo) use ($id): void {
            $client = $repo->getClient($id);
            if ($client === null) {
                $this->notFound();

                return;
            }

            $this->render('crm-client', [
                'title'         => ClientsRepository::clientLabel($client),
                'nav'           => 'crm-clients',
                'select2'       => true,
                'tooltip'       => true,
                'client'        => $client,
                'subscriptions' => $repo->subscriptionsForClient($id),
                'csrf'          => $this->csrfToken(),
                'notice'        => (string) ($_GET['notice'] ?? ''),
                'links'         => $this->app->config->get('external_links', []),
            ]);
        });
    }

    /** select2 AJAX source for the /websites client filter. */
    private function crmClientsSearch(): void
    {
        $this->crmJsonSearch(static fn (ClientsRepository $repo, string $q): array => array_map(
            static fn (array $c): array => ['id' => $c['id'], 'text' => $c['label']],
            $repo->searchClients($q)
        ));
    }

    private function crmWebsitesPage(): void
    {
        $this->withCrmRepository('Websites', 'crm-websites', function (ClientsRepository $repo): void {
            $tagsRaw    = $_GET['tag'] ?? [];
            $tags       = is_array($tagsRaw)
                ? array_values(array_filter(array_map('strval', $tagsRaw), static fn (string $t): bool => $t !== ''))
                : [];
            // A cleared multi-value filter submits no key at all in the query
            // string — indistinguishable from "this filter was never
            // touched" by presence alone. exclude_tag_present is a hidden
            // field the tag-filter widget always sends once the form is
            // submitted at all (see layout.php's initTagFilter()), so its
            // absence is the one reliable signal for "first visit, apply the
            // default" and its presence means "trust excludeTag[] exactly,
            // even if now empty."
            $excludeTags = isset($_GET['exclude_tag_present'])
                ? (is_array($_GET['excludeTag'] ?? null)
                    ? array_values(array_filter(array_map('strval', $_GET['excludeTag']), static fn (string $t): bool => $t !== ''))
                    : [])
                : [ClientsRepository::TAG_EXCLUDED_FROM_ASSIGNMENT];
            $search     = trim((string) ($_GET['q'] ?? ''));
            $connection = trim((string) ($_GET['connection'] ?? ''));

            $this->render('crm-websites', [
                'title'        => 'Websites',
                'nav'          => 'crm-websites',
                'dataTables'   => true,
                'websites'     => $repo->listWebsites(
                    $tags !== [] ? $tags : null,
                    null,
                    $search !== '' ? $search : null,
                    $connection !== '' ? $connection : null,
                    $excludeTags !== [] ? $excludeTags : null
                ),
                'selectedTags' => $tags,
                'selectedExcludeTags' => $excludeTags,
                'selectedConnection'  => $connection,
                'search'       => $search,
                'allTags'      => $repo->searchTags('', 500),
                'links'        => $this->app->config->get('external_links', []),
            ]);
        });
    }

    /** select2 AJAX source for the /websites tag filter. */
    private function crmTagsSearch(): void
    {
        $this->crmJsonSearch(static fn (ClientsRepository $repo, string $q): array => array_map(
            static fn (string $tag): array => ['id' => $tag, 'text' => $tag],
            $repo->searchTags($q)
        ));
    }

    /** select2 AJAX source for subscription_website_form()'s "linked website" control. */
    private function crmWebsitesSearch(): void
    {
        $this->crmJsonSearch(static fn (ClientsRepository $repo, string $q): array => array_map(
            static fn (array $w): array => ['id' => (int) $w['id'], 'text' => SiteDisplay::of($w['url'])],
            $repo->searchAssignableWebsites($q)
        ));
    }

    /**
     * Shared body for every select2 AJAX endpoint above: same "not
     * configured" / "query failed" handling as withCrmRepository(), but
     * returning JSON matching select2's default response shape
     * (`{results: [{id, text}, ...], pagination: {more: false}}` — no
     * pagination needed at this data volume, so `more` is always false)
     * instead of rendering a page.
     *
     * @param callable(ClientsRepository, string): list<array{id: int|string, text: string}> $search
     */
    private function crmJsonSearch(callable $search): void
    {
        if (!$this->requireCapability('crm_view')) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $repo = $this->app->crmRepository();
        if ($repo === null) {
            echo json_encode(['results' => [], 'pagination' => ['more' => false]]);

            return;
        }

        try {
            $results = $search($repo, trim((string) ($_GET['q'] ?? '')));
        } catch (\PDOException $e) {
            $this->app->errorLog()->recordThrowable('crm_db', $e);
            http_response_code(500);
            echo json_encode(['results' => [], 'pagination' => ['more' => false]]);

            return;
        }

        echo json_encode(['results' => $results, 'pagination' => ['more' => false]]);
    }

    private function crmWebsitePage(int $id): void
    {
        $this->withCrmRepository('Website', 'crm-websites', function (ClientsRepository $repo) use ($id): void {
            $website = $repo->getWebsite($id);
            if ($website === null) {
                $this->notFound();

                return;
            }

            $this->render('crm-website', [
                'title'         => SiteDisplay::of($website['url']),
                'nav'           => 'crm-websites',
                'select2'       => true,
                'website'       => $website,
                'clients'       => $repo->clientsForWebsite($id),
                'subscriptions' => $repo->subscriptionsForWebsite($id),
                'items'         => $repo->itemsForWebsite($id),
                'csrf'          => $this->csrfToken(),
                'notice'        => (string) ($_GET['notice'] ?? ''),
                'links'         => $this->app->config->get('external_links', []),
                'eol'           => $this->app->endOfLife(),
            ]);
        });
    }

    private function crmProductsPage(): void
    {
        $this->withCrmRepository('Products', 'crm-products', function (ClientsRepository $repo): void {
            $type = (string) ($_GET['type'] ?? '');

            $this->render('crm-products', [
                'title'        => 'Products',
                'nav'          => 'crm-products',
                'dataTables'   => true,
                'products'     => $repo->listProducts($type !== '' ? $type : null),
                'selectedType' => $type,
                'lastSyncedAt' => $repo->productsLastSyncedAt(),
            ]);
        });
    }

    /**
     * "Which sites have which plugins": server-side (see crmItemsSearch())
     * because, unlike the other three CRM lists, item count scales with
     * sites × plugins/themes per site, not with portfolio size — the same
     * reasoning as /data/vulnerabilities.
     */
    private function crmItemsPage(): void
    {
        $this->withCrmRepository('Items', 'crm-items', function (ClientsRepository $repo): void {
            $this->render('crm-items', [
                'title'   => 'Items',
                'nav'     => 'crm-items',
                'dataTables' => true,
                'types'   => $repo->distinctItemTypes(),
            ]);
        });
    }

    /** JSON endpoint consumed by Datatables' server-side mode on /items. */
    private function crmItemsSearch(): void
    {
        if (!$this->requireCapability('crm_view')) {
            return;
        }

        $repo = $this->app->crmRepository();
        if ($repo === null) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'crm_db not configured']);

            return;
        }

        $draw   = (int) ($_GET['draw'] ?? 0);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = (int) ($_GET['length'] ?? 25);
        $length = $length > 0 ? min($length, 100) : 25;

        $filters = [
            'q'               => (string) ($_GET['search']['value'] ?? ''),
            'type'            => (string) ($_GET['type'] ?? ''),
            'vulnerable'      => !empty($_GET['vulnerable']),
            'updateAvailable' => !empty($_GET['updateAvailable']),
        ];

        try {
            $result = $repo->searchItems($filters, $start, $length);
        } catch (\PDOException $e) {
            $ref = $this->app->errorLog()->recordThrowable('crm_db', $e);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "crm_db query failed (ref {$ref})"]);

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => array_map(static fn (array $row): array => [
                strtoupper((string) $row['type']),
                (string) $row['name'],
                (string) $row['slug'],
                (string) $row['version'],
                $row['is_update_available'] ? ((string) ($row['new_version'] ?? '?')) : '—',
                $row['is_vulnerable'] ? 'Vulnerable' : '—',
                $row['is_active'] ? 'Yes' : 'No',
                SiteDisplay::of($row['website_url']),
                (int) $row['website_id'],
            ], $result['rows']),
        ]);
    }

    /**
     * Every explicit WordPress version wordpress.org's own "stable check"
     * service knows about (`WordPressVersions`, refreshed by
     * `reference:refresh`), each carrying wordpress.org's own verdict —
     * collapsed to the 3-state badge this project shows: unsecure / uptodate /
     * outdated. Cross-referenced with the endoflife.date branch cycle
     * (`EndOfLife::cycleFor()`) purely for the branch's own release date; the
     * status itself never comes from endoflife.date. Small, static list
     * (~900 rows): Datatables runs entirely client-side here, no AJAX.
     */
    private function dataWpVersionsPage(): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $eol      = $this->app->endOfLife();
        $versions = $this->app->wordPressVersions()->all();

        $rows = [];
        foreach ($versions as $version => $rawStatus) {
            $branch = EndOfLife::branch((string) $version);
            $rows[] = [
                'version'       => (string) $version,
                'branch'        => $branch,
                'status'        => WordPressVersions::status((string) $rawStatus),
                'branchReleased' => $eol->cycleFor('wordpress', (string) $version)['releaseDate'] ?? null,
            ];
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => version_compare($b['version'], $a['version'])
        );

        $this->render('data-wp-versions', [
            'title'          => 'WordPress versions',
            'nav'            => 'data-wp-versions',
            'dataTables'     => true,
            'rows'           => $rows,
            'refreshedAt'    => $this->app->wordPressVersions()->refreshedAt(),
            'eolRefreshedAt' => $eol->refreshedAt('wordpress'),
        ]);
    }

    /** Same source and shape as dataDatabasesPage(): the PHP release cycle instead of MySQL/MariaDB. */
    private function dataPhpVersionsPage(): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $eol    = $this->app->endOfLife();
        $cycles = $eol->cycles('php');
        usort(
            $cycles,
            static fn (array $a, array $b): int => version_compare((string) ($b['cycle'] ?? '0'), (string) ($a['cycle'] ?? '0'))
        );

        $this->render('data-php-versions', [
            'title'       => 'PHP versions',
            'nav'         => 'data-php-versions',
            'dataTables'  => true,
            'cycles'      => $cycles,
            'eol'         => $eol,
            'refreshedAt' => $eol->refreshedAt('php'),
        ]);
    }

    /** Same source as dataWpVersionsPage(): MySQL and MariaDB release cycles instead of WordPress. */
    private function dataDatabasesPage(): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $eol    = $this->app->endOfLife();
        $cycles = [];
        foreach (['mysql', 'mariadb'] as $engine) {
            foreach ($eol->cycles($engine) as $cycle) {
                $cycle['engine'] = $engine;
                $cycles[]        = $cycle;
            }
        }
        usort($cycles, static function (array $a, array $b): int {
            $engineCmp = strcmp((string) $a['engine'], (string) $b['engine']);

            return $engineCmp !== 0
                ? $engineCmp
                : version_compare((string) ($b['cycle'] ?? '0'), (string) ($a['cycle'] ?? '0'));
        });

        $this->render('data-databases', [
            'title'          => 'Databases',
            'nav'            => 'data-databases',
            'dataTables'     => true,
            'cycles'         => $cycles,
            'eol'            => $eol,
            'mysqlRefreshedAt'   => $eol->refreshedAt('mysql'),
            'mariadbRefreshedAt' => $eol->refreshedAt('mariadb'),
        ]);
    }

    /**
     * The full Wordfence Intelligence catalogue (~84 000 vulnerabilities) is
     * far too large for a client-side table: the page itself only renders the
     * empty table shell, and dataVulnerabilitiesSearch() below serves it via
     * Datatables' server-side AJAX mode.
     */
    private function dataVulnerabilitiesPage(): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $this->render('data-vulnerabilities', [
            'title'       => 'Vulnerabilities (Wordfence Intelligence)',
            'nav'         => 'data-vulnerabilities',
            'dataTables'  => true,
            'tooltip'     => true,
            'available'   => $this->app->wordfenceIndex()->isAvailable(),
            'refreshedAt' => $this->app->wordfenceIndex()->refreshedAt(),
        ]);
    }

    /**
     * JSON endpoint consumed by Datatables' server-side mode on
     * /data/vulnerabilities, backed by CatalogIndex's SQLite table (rebuilt
     * from the Wordfence cache by wordfence:refresh/catalog:reindex) instead
     * of streaming the cache file on every request — this is what makes
     * column sorting possible here (a plain file scan would have to buffer
     * the whole filtered set to sort it, defeating the point of streaming).
     */
    private function dataVulnerabilitiesSearch(): void
    {
        if (!$this->requireCapability('data_view')) {
            return;
        }

        $draw   = (int) ($_GET['draw'] ?? 0);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = (int) ($_GET['length'] ?? 25);
        $length = $length > 0 ? min($length, 100) : 25;
        $query  = (string) ($_GET['search']['value'] ?? '');

        // Column index -> SQL column, matching data-vulnerabilities.php's
        // <thead> order. Columns with no direct backing column (Patched
        // version, the hidden raw-row/icon columns) simply fall back to the
        // default sort inside searchVulnerabilities().
        $sortColumns  = [0 => 'name', 2 => 'type', 3 => 'cve_id', 4 => 'title', 5 => 'published_at', 6 => 'cvss_score'];
        $orderColumn  = (int) ($_GET['order'][0]['column'] ?? 5);
        $orderColumn  = $sortColumns[$orderColumn] ?? 'published_at';
        $orderDir     = (string) ($_GET['order'][0]['dir'] ?? 'desc');

        $result = $this->app->catalogIndex()->searchVulnerabilities($query, $start, $length, $orderColumn, $orderDir);

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => array_map(static fn (array $row): array => [
                $row['name'] ?? $row['slug'],
                $row['slug'],
                (string) $row['type'],
                $row['cve_id'] ?? '—',
                $row['title'] ?? '',
                $row['published_at'] ?? null,
                $row['cvss_score'],
                $row['cvss_rating'] ?? null,
                !empty($row['patched']) ? implode(', ', $row['patched_versions']) : '—',
                $row,
                null,
            ], $result['rows']),
        ]);
    }

    private function sitePage(string $siteId): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $store  = $this->app->dataStore();
        $site   = $store->readSiteInfo($siteId);
        $keyRow = $this->app->keyStore()->all()[$siteId] ?? null;

        // site.json is written by the first extraction — a freshly paired
        // site (key created, nothing sent yet) legitimately has none. Render
        // an "awaiting first push" placeholder rather than 404, so the API
        // key card below has somewhere to live right after pairing.
        if ($site === null && $keyRow === null) {
            $this->notFound();

            return;
        }
        $site ??= [];

        self::startSession();
        $created = $_SESSION['flash_key'] ?? null;
        if (($created['site_id'] ?? null) === $siteId) {
            unset($_SESSION['flash_key']);
        } else {
            $created = null;
        }

        $extractions = $this->app->index()->listExtractions($siteId);

        $this->render('site', [
            'title'       => $site['site_url'] ?? $keyRow['origin'] ?? $siteId,
            'nav'         => 'sites',
            'site'        => $site,
            'siteId'      => $siteId,
            'extractions' => $extractions,
            'events'      => $this->recentEvents($siteId, 20),
            'keyRow'      => $keyRow,
            'createdKey'  => $created,
            'csrf'        => $this->csrfToken(),
        ]);
    }

    private function extractionPage(string $siteId, string $extractionId): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $store   = $this->app->dataStore();
        $payload = $store->readExtractionPayload($siteId, $extractionId);

        if ($payload === null) {
            $this->notFound();

            return;
        }

        $site = $store->readSiteInfo($siteId) ?? [];

        $row = $this->app->index()->getExtraction($siteId, $extractionId);

        // The BlogVault pre-flight is one cheap call and only matters while the
        // analyst is still deciding whether to run, so it is skipped once the
        // extraction has been analysed — a finished report must not pay for it
        // on every refresh.
        $awaiting  = in_array((string) ($row['status'] ?? ''), [Index::STATUS_PENDING, Index::STATUS_QUEUED], true);
        $blogVault = $awaiting ? $this->blogVaultPreflight($payload) : null;
        $httpAuth  = $awaiting ? $this->httpAuthPreflight($siteId, $payload) : null;

        $this->render('extraction', [
            'title'        => 'Extraction ' . $extractionId,
            'nav'          => 'sites',
            'siteId'       => $siteId,
            'extractionId' => $extractionId,
            'site'         => $site,
            'payload'      => $payload,
            'meta'         => $store->readMeta($siteId, $extractionId) ?? [],
            'findings'     => $store->readFindings($siteId, $extractionId),
            'probes'       => $store->readAllProbeResults($siteId, $extractionId),
            'row'          => $row,
            'eol'          => $this->app->endOfLife(),
            'csrf'         => $this->csrfToken(),
            'blogVault'    => $blogVault,
            'httpAuth'     => $httpAuth,
            'reportAssets' => true,
            'observations'        => (array) ($store->readObservations($siteId, $extractionId)['items'] ?? []),
            'observationSections' => $this->observationSectionNames(),
            'canEditObservations' => $this->currentUserCan('extraction_observations_edit'),
            'licenseStatuses'        => $store->readLicenses($siteId, $extractionId) ?? [],
        ]);
    }

    /**
     * Two independent ways in, checked before dispatch() would otherwise
     * require a Google sign-in session a script cannot hold:
     *   - the shared `reports.api_key` (config) as a Bearer header — a
     *     standing credential, for whoever sets it up once and reuses it
     *   - a one-hour, single-extraction token (?token=, minted by the "Report
     *     data key" button — see issueReportToken()) — the one an analyst
     *     actually pastes, scoped so a leaked link only ever opens the one
     *     report it was made for, not every report forever
     */
    private function reportAccessGranted(string $siteId, string $extractionId): bool
    {
        $apiKey = (string) $this->app->config->get('reports.api_key', '');
        if ($apiKey !== '') {
            $header = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] // some Apache/CGI setups only pass it through under this name
                ?? '';
            $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
            if ($given !== '' && hash_equals($apiKey, $given)) {
                return true;
            }
        }

        $token = (string) ($_GET['token'] ?? '');

        return $token !== '' && $this->app->reportTokenStore()->verify($token, $siteId, $extractionId);
    }

    /**
     * Script-friendly export of one extraction's report — feeds the Google
     * Docs report template's Apps Script, which cannot hold a browser
     * session.
     *
     * This method only fetches data and hands it off: what a report
     * actually contains — which value, table, or category maps to which
     * {{variable}} — lives entirely in a contract file under
     * config/reports/ (config `reports.bilan_de_sante`), resolved by
     * Web\ReportBuilder. Having a piece of data and deciding it belongs in
     * THIS report are different facts on purpose — neither this method nor
     * the rules engine needs to change for a report's layout to change, and
     * a second report type is a second contract file, not a second copy of
     * this method.
     */
    private function extractionReportJson(string $siteId, string $extractionId): void
    {
        if (!$this->reportAccessGranted($siteId, $extractionId)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Invalid or missing credentials']);

            return;
        }

        $store   = $this->app->dataStore();
        $payload = $store->readExtractionPayload($siteId, $extractionId);
        if ($payload === null) {
            $this->notFound();

            return;
        }

        $t     = $this->app->translator($this->locale());
        $probe = array_map(
            static fn (array $p): array => (array) ($p['data'] ?? []),
            $store->readAllProbeResults($siteId, $extractionId)
        );

        // The analyst's name at report.json fetch time: read from the token
        // itself (captured when they clicked "Report data key" — see
        // ReportTokenStore::issue()), never from a session, since this route
        // runs with none (that's the whole point of the token). Empty on the
        // shared reports.api_key path, which carries no analyst identity.
        $token    = (string) ($_GET['token'] ?? '');
        $reportBy = $token !== '' ? $this->app->reportTokenStore()->issuedBy($token) : '';

        $context = [
            'payload'       => $payload,
            'site'          => $store->readSiteInfo($siteId) ?? [],
            'meta'          => $store->readMeta($siteId, $extractionId) ?? [],
            'probe'         => $probe,
            'host'          => (string) (parse_url((string) ($payload['home_url'] ?? $payload['site_url'] ?? ''), PHP_URL_HOST) ?? ''),
            'extraction_id' => $extractionId,
            'report_by'     => $reportBy,
            // Today, not the extraction's own date — {{date}} is "when this
            // report was filled", separate from {{extraction_date}}.
            'today'         => gmdate('Y-m-d'),
            // Xtractor's own independently-refreshed reference data — never
            // something the extraction itself claims (payload.* is the
            // site's own self-report, which can be stale/blocked).
            'reference'     => $this->reportReferenceContext($payload, $probe),
            // Per-extraction licence-key status (DataStore::readLicenses(),
            // this extraction's own licenses.json) — "plugin:<slug>"/
            // "theme:<slug>" => active/missing/to_validate/n_a, read by
            // ReportBuilder's plugins/themes table builders for the Status
            // icon. Never SoftwareCatalog: that's the cross-site free/premium
            // classification, a different question entirely.
            'licenses'      => $store->readLicenses($siteId, $extractionId) ?? [],
        ];

        $findings = $this->translatedFindings($store->readFindings($siteId, $extractionId) ?? [], $t);

        $observations = (array) ($store->readObservations($siteId, $extractionId)['items'] ?? []);

        $contract = $this->loadReportContract();
        $iconBase = (self::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/assets/report-icons';
        $report   = (new ReportBuilder($t, $iconBase))->build($contract, $context, $findings, $observations);

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'site'          => $report['fields']['site']['value'] ?? $siteId,
            'extraction_id' => $extractionId,
            'generated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            // One map, keyed by the exact {{variable}} name the report
            // template uses. Every entry carries its own 'type'
            // ('value'/'table'/'observations') — that's what lets the
            // Apps Script stay one generic dispatcher instead of a
            // hardcoded loop per kind, and lets a value or a table cell
            // carry a 'color' the same way an observation always could.
            'fields'   => $report['fields'],
            // Every translated finding, raw and ungrouped — the source of
            // truth if a future report (or a pastille tally) needs
            // something this contract didn't group into an
            // 'observations' field.
            'findings' => $findings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Facts Xtractor itself works out — never the extraction's own claim
     * about itself — for the report contract's 'reference.*' fields:
     * wordpress.org's own latest version, and whether the reported PHP/
     * database version is past end of life (EndOfLife::eolStatus(), the
     * same true/false/null rules F3/H1 already read) and which HTTP
     * version the probe's own request actually observed (a single
     * request only ever sees one — this is "what was used just now", not
     * a full protocol-support survey).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function reportReferenceContext(array $payload, array $probe): array
    {
        $eol = $this->app->endOfLife();

        $phpVersion = (string) ($payload['php']['version'] ?? '');
        $phpEol     = $phpVersion !== '' ? $eol->eolStatus('php', $phpVersion) : null;

        $dbType    = (string) ($payload['database_type'] ?? '');
        $dbVersion = (string) ($payload['database_version'] ?? '');
        $dbEol     = ($dbType !== '' && $dbVersion !== '') ? $eol->eolStatus($dbType, $dbVersion) : null;

        $httpVersion = (string) ($probe['http']['http_version'] ?? '');

        return [
            'wordpress_latest_version' => $this->app->wordPressVersions()->latestVersion() ?? '',
            'php_eol'                  => $phpEol[0] ?? null,
            'database_eol'             => $dbEol[0] ?? null,
            // The branch's own release/EOL date (same figure /data/databases
            // already reads from this cache), folded into {{database_status}}'s
            // sentence — php_status wasn't asked to grow one too.
            'database_eol_date'        => $dbEol[1] ?? '',
            'http1'                    => $httpVersion !== '' ? str_starts_with($httpVersion, '1') : null,
            'http2'                    => $httpVersion !== '' ? $httpVersion === '2' : null,
            'http3'                    => $httpVersion !== '' ? $httpVersion === '3' : null,
        ];
    }

    /** @return array<string, mixed> the active report contract (config/reports/*.php) */
    private function loadReportContract(): array
    {
        $contractFile = (string) $this->app->config->get(
            'reports.bilan_de_sante',
            dirname(__DIR__, 2) . '/config/reports/bilan-de-sante.php'
        );

        return (array) require $contractFile;
    }

    /**
     * Every {{variable}} in the active report contract whose type is
     * 'observations' — the choices for the "Section" dropdown when
     * authoring a manual observation. Reads the same contract file
     * Web\ReportBuilder does, so a new report type or a renamed field
     * changes what's selectable here for free, never a second list to keep
     * in sync by hand.
     *
     * @return list<string>
     */
    private function observationSectionNames(): array
    {
        $names = [];
        foreach ((array) ($this->loadReportContract()['fields'] ?? []) as $name => $spec) {
            if (is_array($spec) && ($spec['type'] ?? null) === 'observations') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Every finding translated once, with both the stable category code
     * (SSL, DOMAIN, … — for a report contract or a script to group by,
     * never changes with ?lang=) and its translated label (display only).
     *
     * @param array<string, mixed> $findingsData one extraction's findings.json
     * @return list<array<string, mixed>>
     */
    private function translatedFindings(array $findingsData, Translator $t): array
    {
        $findings = [];
        foreach ((array) ($findingsData['findings'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $message = $t->message($f);
            if ($message === null) {
                continue; // no phrase for this status (e.g. no "pass" template) — same as the web UI's own fallback
            }
            $findings[] = [
                'id'            => (string) ($f['id'] ?? ''),
                'category_code' => (string) ($f['category'] ?? ''),
                'category'      => $t->category((string) ($f['category'] ?? '')),
                'pastille'      => (string) ($f['pastille'] ?? 'grey'),
                // Translated only, same as title/message/category — the raw
                // single-letter code (C/E/M/I) never reaches here because
                // nothing downstream groups or filters by it (that job is
                // already 'pastille's, since two severities share a colour).
                'severity'      => $t->severity((string) ($f['severity'] ?? '')),
                'title'         => $t->title((string) ($f['id'] ?? '')),
                'message'       => $message,
            ];
        }

        return $findings;
    }

    private function rawFile(string $siteId, string $extractionId, string $name): void
    {
        if (!$this->requireCapability('extraction_view_technical')) {
            return;
        }

        $relative = self::resolveRawFile($name);
        if ($relative === null) {
            $this->notFound();

            return;
        }

        $file = $this->app->dataStore()->extractionDir($siteId, $extractionId) . '/' . $relative;

        if (!is_file($file)) {
            $this->notFound();

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    /**
     * Map a requested raw-file name to its path relative to the extraction dir,
     * or null when it is not a plain file stem. Pure and path-traversal-safe:
     * the name is basename-stripped and pattern-checked, so a request like
     * "../../keys" or "payload/../meta" can never escape.
     */
    public static function resolveRawFile(string $name): ?string
    {
        $name = basename($name, '.json');

        // The name comes from the URL, so it is untrusted. basename() above
        // already strips every path component, which confines the result to the
        // one extraction directory; this pattern additionally rejects anything
        // that is not a plain file stem (no dotfiles, no empty name).
        //
        // There is deliberately no allowlist of probe names: everything in an
        // extraction directory is already rendered on the page, so a list would
        // guard nothing, while silently 404-ing every probe someone forgot to
        // add to it — which is exactly how blogvault.json and wordfence.json
        // ended up as dead links. A file that does not exist 404s on its own.
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
            return null;
        }

        return in_array($name, ['payload', 'meta', 'findings', 'observations', 'licenses'], true)
            ? "{$name}.json"
            : "probes/{$name}.json";
    }

    /** @return list<array<string, mixed>> newest first */
    private function recentEvents(string $siteId, int $limit): array
    {
        $files = glob($this->app->dataStore()->siteDir($siteId) . '/events/*.jsonl') ?: [];
        rsort($files);

        $events = [];
        foreach ($files as $file) {
            $lines = array_reverse(array_filter(explode("\n", (string) file_get_contents($file))));
            foreach ($lines as $line) {
                $batch = json_decode($line, true);
                if (!is_array($batch)) {
                    continue;
                }
                foreach (array_reverse((array) ($batch['events'] ?? [])) as $event) {
                    $events[] = (array) $event + ['received_at' => $batch['received_at'] ?? null];
                    if (count($events) >= $limit) {
                        return $events;
                    }
                }
            }
        }

        return $events;
    }

    private function usersPage(): void
    {
        if (!$this->requireCapability('user_view')) {
            return;
        }

        $users = $this->app->userStore();
        $me    = $this->currentUser();

        $this->render('users', [
            'title'   => 'Users',
            'nav'     => 'users',
            'users'   => $users->all(),
            'roles'   => $this->app->roleCapabilities()->roles(),
            'me'      => $me,
            // Same rule as the POST /users handler: a real signed-in identity
            // whose role holds that exact capability — so a control is shown
            // exactly when submitting it would be accepted.
            'can'     => $this->userManagementCapabilities($me),
            'csrf'    => $this->csrfToken(),
            'notice'  => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    /** @return array{add: bool, edit: bool, suspend: bool, remove: bool} */
    private function userManagementCapabilities(?string $me): array
    {
        $role = $me !== null ? $this->app->userStore()->roleOf($me) : null;
        $can  = fn (string $capability): bool => $role !== null && $this->app->roleCapabilities()->can($role, $capability);

        return ['add' => $can('user_add'), 'edit' => $can('user_edit'), 'suspend' => $can('user_suspend'), 'remove' => $can('user_remove')];
    }

    private function profilePage(): void
    {
        $me = $this->currentUser();
        if ($me === null) {
            $this->notFound();

            return;
        }

        $user = $this->app->userStore()->get($me);
        if ($user === null) {
            $this->notFound();

            return;
        }

        $this->render('profile', [
            'title'  => 'My profile',
            'nav'    => 'profile',
            'user'   => $user,
            'csrf'   => $this->csrfToken(),
            'notice' => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    private function styleguidePage(): void
    {
        $this->render('styleguide', [
            'title'      => 'Style guide',
            'nav'        => 'styleguide',
            'dataTables' => true,
            'select2'    => true,
            'tooltip'    => true,
            'csrf'       => $this->csrfToken(),
        ]);
    }

    /**
     * Session-backed identity. Re-checks the allowlist on every request, so
     * removing someone from the users file logs them out on their next click
     * rather than whenever their session happens to expire.
     */
    private function currentUser(): ?string
    {
        self::startSession();

        $email = $_SESSION['user_email'] ?? null;
        if (!is_string($email) || $email === '') {
            return null;
        }

        if (!$this->app->userStore()->isAllowed($email)) {
            unset($_SESSION['user_email']);

            return null;
        }

        return $email;
    }

    /**
     * "First Last" for the signed-in analyst, for {{report_by}} — falls back
     * to the email when no name is on file (Basic auth/dev, or a blank
     * profile) rather than leaving the report field empty.
     */
    private function currentUserDisplayName(): string
    {
        $email = $this->currentUser();
        if ($email === null) {
            return '';
        }

        $profile = $this->app->userStore()->get($email);
        $name    = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));

        return $name !== '' ? $name : $email;
    }

    /**
     * Whether the *browser's* connection is HTTPS. Behind a TLS-terminating
     * proxy PHP sees plain HTTP, so X-Forwarded-Proto has to be honoured — get
     * this wrong and the OAuth redirect_uri comes out as http://, which Google
     * rejects as redirect_uri_mismatch on the very first sign-in.
     *
     * Spoofing the header can only make us stricter (a secure-flagged cookie,
     * an https redirect_uri Google will not recognise): it cannot downgrade
     * anything, so trusting it is safe even when no proxy is in front.
     */
    private static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }

        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        // The header may carry a list when several proxies are chained.
        return str_starts_with($forwarded, 'https');
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',   // the OAuth callback is a top-level GET redirect
            'secure'   => self::isHttps(),
            'path'     => '/',
        ]);
        session_name('swp_session');
        session_start();
    }

    /** /auth/login · /auth/callback · /auth/logout */
    private function authRoute(string $path): void
    {
        $google = $this->app->googleAuth();
        if (!$google->isConfigured()) {
            $this->notFound();

            return;
        }

        self::startSession();

        match (trim($path, '/')) {
            'auth/login'    => $this->authLogin(),
            'auth/callback' => $this->authCallback(),
            // Sign-out is a POST (handlePost(), CSRF-checked) — a GET here
            // would let any third-party page log an analyst out.
            default         => $this->notFound(),
        };
    }

    private function authLogin(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $this->redirect($this->app->googleAuth()->authorizationUrl($state, $this->redirectUri()));
    }

    private function authCallback(): void
    {
        $expected = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);   // single use, whatever happens next

        // Mismatched state means the callback was not started by this browser.
        if (!is_string($expected) || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
            $this->loginPage('Session expired or invalid request. Try again.');

            return;
        }

        $email = $this->app->googleAuth()->emailFromCode((string) ($_GET['code'] ?? ''), $this->redirectUri());
        if ($email === null) {
            $this->loginPage('Google authentication refused.');

            return;
        }

        $users = $this->app->userStore();

        // Deliberately NO "first sign-in becomes admin" bootstrap: this UI sits
        // on a publicly reachable host (the receptor has to be), so whoever hit
        // the URL first would claim the account. The list is seeded out of band
        // with `bin/xtractor users:add`.
        if ($users->isEmpty()) {
            $this->loginPage('No user registered yet. Seed the list with: bin/xtractor users:add <email>');

            return;
        }

        if (!$users->isAllowed($email)) {
            $this->loginPage("{$email} is not allowed to access this interface.");

            return;
        }

        session_regenerate_id(true);       // no session fixation across the login boundary
        $_SESSION['user_email'] = $email;

        $this->redirect('/');
    }

    private function authLogout(): void
    {
        $_SESSION = [];

        // Expire the cookie as well: session_destroy() only drops the data,
        // leaving the browser to keep presenting a dead session id.
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'] ?: '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);

        session_destroy();
        $this->loginPage('Signed out.');
    }

    /**
     * Configured value wins; otherwise derive it from the request so a plain
     * vhost needs no extra setting. Must match what is registered on the Google
     * client, character for character.
     */
    private function redirectUri(): string
    {
        $configured = (string) $this->app->config->get('auth.google.redirect_uri', '');
        if ($configured !== '') {
            return $configured;
        }

        return (self::isHttps() ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/callback';
    }

    private function loginPage(string $message = ''): void
    {
        http_response_code($message === '' ? 401 : 403);
        $this->render('login', [
            'title'    => 'Connexion',
            'nav'      => '',
            'bare'     => true,
            'message'  => $message,
            'firstRun' => $this->app->userStore()->isEmpty(),
        ]);
    }

    /**
     * Does the site answer 401 to an anonymous request? Every external check —
     * headers, exposure, robots/sitemap, PageSpeed — hits the same wall and
     * comes back empty, so a run against a Basic-Auth-protected site with no
     * credentials stored produces a report that says nothing about the site.
     * Settled before the analyst spends the run, not after.
     *
     * Credentials already stored for the site are sent, so a site that is
     * configured correctly reads as "ok" rather than "locked".
     *
     * @param array<string, mixed> $payload
     * @return array{checked: bool, required: bool, configured: bool, status: int|null, error?: string}
     */
    private function httpAuthPreflight(string $siteId, array $payload): array
    {
        $credentials = $this->app->keyStore()->getHttpAuth($siteId);
        $configured  = $credentials !== null;
        $url         = (string) ($payload['home_url'] ?? $payload['site_url'] ?? '');
        $host        = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        $unchecked = ['checked' => false, 'required' => false, 'configured' => $configured, 'status' => null];

        if ($url === '' || $host === '') {
            return $unchecked;
        }
        // Same SSRF guard the probes apply: the URL comes from the payload, so
        // a compromised site could point it at an internal address. Every hop
        // is re-vetted and the connection pinned to the vetted IP
        // (CURLOPT_RESOLVE), so DNS can't answer differently to curl.
        $siteHost = strtolower($host);
        $status   = null;
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
                    'headers'         => ['User-Agent' => (string) $this->app->config->get('probes.user_agent', 'SatelliteWP-Xtractor/1.0')],
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
                $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));
            }
        } catch (\Throwable $e) {
            return $unchecked + ['error' => $e->getMessage()];
        }

        return [
            'checked'    => true,
            'required'   => $status === 401,
            'configured' => $configured,
            'status'     => $status,
        ];
    }

    /**
     * Is this site managed in BlogVault? A single "url:contains" lookup, matched
     * on exact host. Absence is a business signal in its own right — the site is
     * not on a maintenance plan — so it is reported, never treated as an error.
     *
     * @param array<string, mixed> $payload
     * @return array{configured: bool, found: bool, host: string, name?: string, id?: string, error?: string}
     */
    private function blogVaultPreflight(array $payload): array
    {
        $host = (string) (parse_url(
            (string) ($payload['home_url'] ?? $payload['site_url'] ?? ''),
            PHP_URL_HOST
        ) ?? '');

        $configured = (string) $this->app->config->get('blogvault.base_url', '') !== ''
            && (string) $this->app->config->get('blogvault.api_key', '') !== '';

        if (!$configured || $host === '') {
            return ['configured' => $configured, 'found' => false, 'host' => $host];
        }

        try {
            $listed = $this->app->blogVault()->get('sites', ['filters' => ['url:contains' => $host]]);
            $match  = BlogVaultProbe::matchSite($listed, $host);
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

    /**
     * Gate one action/page behind a named capability (config/roles.php) —
     * writes a 403 and returns false when it isn't held, so a call site can
     * just do `if (!$this->requireCapability('catalog_edit')) { return; }`.
     *
     * With Google sign-in configured, authenticate() already guarantees
     * currentUser() is non-null by the time this runs, so this checks that
     * identity's role. Without Google configured (Basic auth / the open dev
     * fallback) there is no per-user role to check anything against — this
     * permits, same as every capability-gated action's behaviour before
     * capabilities existed. /users mutations are the one exception: Router
     * checks a real identity there directly, before capability, and stays
     * blocked (not promoted to full access) under Basic auth/open — see
     * config/roles.php's docblock.
     */
    private function requireCapability(string $capability): bool
    {
        if ($this->currentUserCan($capability)) {
            return true;
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'You do not have permission to do this.';

        return false;
    }

    /**
     * A POST against an extraction must name one that exists (writing under a
     * well-formed but unknown id would create a phantom directory under
     * data/sites/) and, when $allowed is given, be in one of those statuses.
     * Writes the 404/409 itself and returns false, same contract as
     * requireCapability().
     *
     * @param list<string>|null $allowed
     */
    private function requireExtractionStatus(string $siteId, string $extractionId, ?array $allowed): bool
    {
        $row = $this->app->index()->getExtraction($siteId, $extractionId);
        if ($row === null || $this->app->dataStore()->readExtractionPayload($siteId, $extractionId) === null) {
            $this->notFound();

            return false;
        }
        if ($allowed !== null && !in_array((string) ($row['status'] ?? ''), $allowed, true)) {
            http_response_code(409);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This action is not available for an extraction in status "' . (string) ($row['status'] ?? '?') . '".';

            return false;
        }

        return true;
    }

    /**
     * The read-only version of requireCapability() — same rule, no 403
     * side effect — for gating whether a template even shows a control
     * that would 403 on submit (e.g. the manual-observations edit form
     * on an extraction page) rather than relying only on the POST route's
     * own check.
     */
    private function currentUserCan(string $capability): bool
    {
        if (!$this->app->googleAuth()->isConfigured()) {
            return true;
        }

        $me   = $this->currentUser();
        $role = $me !== null ? $this->app->userStore()->roleOf($me) : null;

        return $role !== null && $this->app->roleCapabilities()->can($role, $capability);
    }

    /**
     * Google sign-in when configured, Basic auth as a dev fallback, open when
     * neither is set (rely on server-level protection then).
     */
    private function authenticate(): bool
    {
        if ($this->app->googleAuth()->isConfigured()) {
            if ($this->currentUser() !== null) {
                return true;
            }

            $this->loginPage();

            return false;
        }

        $user     = $this->app->config->get('web.user');
        $passHash = $this->app->config->get('web.pass_hash');

        if ($user === null || $passHash === null) {
            return true; // auth not configured (dev) — rely on server-level protection in prod
        }

        $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $lockout = $this->app->loginLockout();

        if ($ip !== '' && $lockout->isLocked($ip)) {
            header('Retry-After: ' . $lockout->retryAfter($ip));
            http_response_code(429);
            echo 'Too many failed attempts. Try again later.';

            return false;
        }

        $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
        $givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';

        if (hash_equals((string) $user, $givenUser) && password_verify($givenPass, (string) $passHash)) {
            if ($ip !== '') {
                $lockout->recordSuccess($ip);
            }

            return true;
        }

        if ($ip !== '') {
            $lockout->recordFailure($ip);
        }

        header('WWW-Authenticate: Basic realm="SatelliteWP Xtractor"');
        http_response_code(401);
        echo 'Authentication required.';

        return false;
    }

    public static function isExtractionId(string $value): bool
    {
        return (bool) preg_match('/^\d{8}T\d{6}Z(-\d+)?$/', $value);
    }

    /**
     * Handle a web form POST (the only mutation the UI allows: setting a
     * plugin/theme licence). Protected by Basic auth + a double-submit CSRF
     * token, then follows the POST/redirect/GET pattern.
     */
    public function handlePost(string $path): void
    {
        if (!$this->authenticate()) {
            return;
        }

        if (!isset($_COOKIE['swp_csrf'], $_POST['_csrf'])) {
            http_response_code(400);
            echo 'Invalid CSRF token';

            return;
        }

        $cookieToken = (string) $_COOKIE['swp_csrf'];
        $postedToken = (string) $_POST['_csrf'];

        if ($cookieToken === '' || !hash_equals($cookieToken, $postedToken)) {
            http_response_code(400);
            echo 'Invalid CSRF token';

            return;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));

        if ($segments === ['auth', 'logout'] && $this->app->googleAuth()->isConfigured()) {
            $this->authLogout();

            return;
        }

        // Queue an extraction for analysis. The web request only flips a status;
        // the cron worker does the slow part, so nothing here can time out.
        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'run'
        ) {
            if (!$this->requireCapability('extraction_run')) {
                return;
            }
            // A done extraction is a frozen snapshot; re-queuing it would re-run
            // every probe (quota) and overwrite it. Only pending and error runs.
            if (!$this->requireExtractionStatus($segments[1], $segments[3], [Index::STATUS_PENDING, Index::STATUS_ERROR])) {
                return;
            }

            // Only the first "Run analysis" (the pending-state form) carries
            // this field — "Retry analysis" (the error-state form) doesn't,
            // so a retry leaves whatever language was already chosen alone
            // instead of silently resetting it back to the French default.
            if (isset($_POST['language'])) {
                $language = (string) $_POST['language'];
                if (!in_array($language, ['fr', 'en'], true)) {
                    $language = 'fr';
                }
                $this->app->dataStore()->updateMeta($segments[1], $segments[3], ['language' => $language]);
            }

            $this->app->index()->setExtractionStatus($segments[1], $segments[3], Index::STATUS_QUEUED);
            $this->redirect(self::safeReturn(
                $_POST['return'] ?? "/site/{$segments[1]}/extraction/{$segments[3]}"
            ));

            return;
        }

        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'abort'
        ) {
            if (!$this->requireCapability('extraction_run')) {
                return;
            }
            if (!$this->requireExtractionStatus($segments[1], $segments[3], [Index::STATUS_PENDING, Index::STATUS_QUEUED])) {
                return;
            }

            $this->app->index()->setExtractionStatus($segments[1], $segments[3], Index::STATUS_ABORTED);
            $this->redirect(self::safeReturn(
                $_POST['return'] ?? "/site/{$segments[1]}/extraction/{$segments[3]}"
            ));

            return;
        }

        // "Report data key" button on the extraction page — mints a
        // one-hour, single-extraction token (see reportAccessGranted()) and
        // hands back the exact URL to paste into the Google Docs report
        // template, rather than the shared reports.api_key. JSON in, JSON
        // out (no redirect): the button's own fetch() needs the URL back to
        // copy it, so this is one of the few POST handlers that isn't
        // POST/redirect/GET.
        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'report-token'
        ) {
            if (!$this->requireCapability('extraction_view_technical')) {
                return;
            }

            $token = $this->app->reportTokenStore()->issue($segments[1], $segments[3], $this->currentUserDisplayName());
            // Defaults to the language chosen on the extraction page before
            // "Run analysis" (meta.json's 'language' — see Pipeline::run()),
            // falling back to French for an extraction queued before that
            // choice existed. &lang= stays a plain overridable query param
            // (same one every other page already honours), not baked into
            // the token itself, so pasting &lang=en still switches it by hand.
            $meta = $this->app->dataStore()->readMeta($segments[1], $segments[3]) ?? [];
            $lang = in_array($meta['language'] ?? null, ['fr', 'en'], true) ? $meta['language'] : 'fr';
            $url  = (self::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')
                . "/site/{$segments[1]}/extraction/{$segments[3]}/report.json?token=" . rawurlencode($token)
                . '&lang=' . $lang;

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['url' => $url]);

            return;
        }

        // Manual observations — the one piece of report content that
        // isn't derived from a probe or the rules engine, an analyst's own
        // words for a section the report contract already defines. Stored
        // per extraction (DataStore::mutateObservations()), same
        // "snapshot in time" rule as everything else there: a new
        // extraction starts with none, on purpose.
        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'observations'
        ) {
            if (!$this->requireCapability('extraction_observations_edit')) {
                return;
            }
            if (!$this->requireExtractionStatus($segments[1], $segments[3], null)) {
                return;
            }

            [$siteId, $extractionId] = [$segments[1], $segments[3]];
            $action = (string) ($_POST['action'] ?? '');

            $validSections = $this->observationSectionNames();
            $validColors   = ['green', 'orange', 'red', 'blue', 'grey'];
            $section       = (string) ($_POST['section'] ?? '');
            $color         = (string) ($_POST['color'] ?? '');
            $id            = (string) ($_POST['id'] ?? '');
            $valid         = in_array($section, $validSections, true) && in_array($color, $validColors, true);
            $record        = static fn (string $recordId): array => [
                'id'          => $recordId,
                'section'     => $section,
                'color'       => $color,
                'title'       => trim((string) ($_POST['title'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'include'     => isset($_POST['include']),
            ];

            // Read-modify-write under the extraction's lock — two analysts
            // saving at the same time must not drop each other's edit.
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

            return;
        }

        if ($segments === ['users']) {
            $users  = $this->app->userStore();
            $me     = $this->currentUser();
            $action = (string) ($_POST['action'] ?? '');

            // A real Google-signed-in identity is required outright, before
            // any capability check — unchanged from before capabilities
            // existed. Under Basic auth/the open dev fallback there is no
            // per-user roster to check a capability against, and this list
            // is sensitive enough that it stays blocked there rather than
            // being promoted to full access (see config/roles.php).
            if ($me === null) {
                http_response_code(403);
                echo 'Only a signed-in administrator can manage users.';

                return;
            }

            $capability = match ($action) {
                'add'                  => 'user_add',
                'edit'                 => 'user_edit',
                'suspend', 'reactivate' => 'user_suspend',
                'remove'               => 'user_remove',
                default                => null,
            };
            $role = $users->roleOf($me);
            if ($capability === null || $role === null || !$this->app->roleCapabilities()->can($role, $capability)) {
                http_response_code(403);
                echo 'You do not have permission to do this.';

                return;
            }

            $notice = match ($action) {
                'add'    => $users->add(
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['role'] ?? UserStore::DEFAULT_ROLE),
                    (string) ($_POST['first_name'] ?? ''),
                    (string) ($_POST['last_name'] ?? '')
                ) ? 'added' : 'add-failed',
                'edit'   => $users->updateUser(
                    (string) ($_POST['email'] ?? ''),
                    (string) ($_POST['new_email'] ?? ''),
                    (string) ($_POST['first_name'] ?? ''),
                    (string) ($_POST['last_name'] ?? ''),
                    (string) ($_POST['role'] ?? UserStore::DEFAULT_ROLE)
                ) ? 'edited' : 'edit-failed',
                'suspend'    => $users->setStatus((string) ($_POST['email'] ?? ''), UserStore::STATUS_SUSPENDED)
                    ? 'suspended' : 'suspend-failed',
                'reactivate' => $users->setStatus((string) ($_POST['email'] ?? ''), UserStore::STATUS_ACTIVE)
                    ? 'reactivated' : 'reactivate-failed',
                'remove' => $users->remove((string) ($_POST['email'] ?? ''))
                    ? 'removed' : 'remove-failed',
                // No default: $capability being non-null above already
                // proved $action is one of exactly these five values.
            };

            $this->redirect('/users?notice=' . $notice);

            return;
        }

        // Self-service profile save — always the current session's own
        // record (no email/id in the request), never gated by capability:
        // any signed-in user may edit their own data/runcloud_api_key/
        // public_ssh_key. Under Basic auth/open dev there is no session
        // identity to save against at all.
        if ($segments === ['profile']) {
            $me = $this->currentUser();
            if ($me === null) {
                http_response_code(403);
                echo 'You must be signed in to edit a profile.';

                return;
            }

            $rawData = trim((string) ($_POST['data'] ?? ''));
            $data    = null;
            if ($rawData !== '') {
                $decoded = json_decode($rawData, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $this->redirect('/profile?notice=invalid-json');

                    return;
                }
                $data = $decoded;
            }

            $this->app->userStore()->updateProfile(
                $me,
                $data,
                (string) ($_POST['runcloud_api_key'] ?? ''),
                (string) ($_POST['public_ssh_key'] ?? '')
            );

            $this->redirect('/profile?notice=saved');

            return;
        }

        // Key management (create/revoke/rebind) lives on the site's own page
        // now, not a separate table — pairing a brand-new site is a form on
        // the sites list (its /site/{id} page does not exist yet), everything
        // else is a form on /site/{id} itself. Both post here and both are
        // sent back to /site/{id} either way.
        if ($segments === ['keys']) {
            $siteId = (string) ($_POST['site_id'] ?? '');
            $action = (string) ($_POST['action'] ?? '');

            if (!PayloadValidator::isUuid($siteId)) {
                $this->redirect('/?notice=invalid-uuid');

                return;
            }

            // An unrecognized action falls through uncapability-checked to
            // the elseif chain below, which matches nothing and just
            // redirects — the same harmless no-op as before capabilities
            // existed, not a bare blocked response for something that was
            // never going to do anything anyway.
            $capability = match ($action) {
                'add'                            => 'site_key_add',
                'revoke'                         => 'site_key_revoke',
                'rebind'                         => 'site_key_rebind',
                'http_auth', 'http_auth_clear'   => 'site_http_auth_edit',
                default                          => null,
            };
            if ($capability !== null && !$this->requireCapability($capability)) {
                return;
            }

            if ($action === 'add') {
                $origin = trim((string) ($_POST['origin'] ?? ''));
                $origin = $origin !== '' ? PayloadValidator::normalizeOrigin($origin) : null;
                $key    = $this->app->keyStore()->addKey($siteId, null, $origin);

                self::startSession();
                // Shown exactly once, on the /site/{id} page right after —
                // same one-time-display rule as the CLI, which only ever
                // prints it at creation time.
                $_SESSION['flash_key'] = ['site_id' => $siteId, 'key' => $key, 'origin' => $origin];
            } elseif ($action === 'revoke') {
                $this->app->keyStore()->revokeKey($siteId);
            } elseif ($action === 'rebind') {
                $url = trim((string) ($_POST['url'] ?? ''));
                if ($url !== '') {
                    $this->app->keyStore()->setOrigin($siteId, PayloadValidator::normalizeOrigin($url));
                }
            } elseif ($action === 'http_auth') {
                // Basic Auth this server should send when probing the site
                // directly — needed for a site paired behind Basic Auth (a
                // staging environment, an IP-restriction bypass, …), or
                // every passive exposure check would 401 and read as a false
                // "clean" instead of "couldn't check". Per-site, not global:
                // different client sites use different logins.
                $username = trim((string) ($_POST['http_auth_username'] ?? ''));
                $password = (string) ($_POST['http_auth_password'] ?? '');
                if ($username !== '' && $password !== '') {
                    $this->app->keyStore()->setHttpAuth($siteId, $username, $password);
                }
            } elseif ($action === 'http_auth_clear') {
                $this->app->keyStore()->setHttpAuth($siteId, null, null);
            }

            $this->redirect('/site/' . $siteId);

            return;
        }

        if ($segments === ['catalog']) {
            if (!$this->requireCapability('catalog_edit')) {
                return;
            }

            $type = (string) ($_POST['type'] ?? '');
            $slug = (string) ($_POST['slug'] ?? '');
            $saved = in_array($type, ['plugin', 'theme'], true)
                && $this->app->softwareCatalog()->setLicense($type, $slug, (string) ($_POST['license'] ?? ''));

            // Keep the SQLite cross-reference index current immediately,
            // rather than waiting for the next wordfence:refresh-triggered
            // rebuild — a single-row upsert, not a full reindex.
            if ($saved) {
                $entry = $this->app->softwareCatalog()->get($type, $slug);
                if ($entry !== null) {
                    $this->app->catalogIndex()->upsertCatalogEntry($entry);
                }
            }

            // A rejected save (missing/invalid field, unknown slug) must not
            // 303 like a successful one: the licence dropdown's fetch() call
            // in layout.php reads any redirect as "saved" (redirect: 'manual'
            // surfaces it as an opaque redirect, not a followable response),
            // so silently redirecting here hid a real failure as a green
            // flash with nothing actually written.
            if (!$saved) {
                http_response_code(400);
                echo 'Could not save the licence.';

                return;
            }

            $this->redirect(self::safeReturn($_POST['return'] ?? '/catalog'));

            return;
        }

        // Per-extraction licence-key status for one plugin/theme
        // (license_status_select() on an extraction report) — a different
        // question from /catalog above (is this free/premium at all,
        // cross-site): is THIS install's licence, as of THIS extraction,
        // active, missing, or needs checking — stored in that extraction's
        // own directory (licenses.json), same "snapshot in time" rule as
        // findings.json/observations.json, re-set on each new
        // extraction rather than carried forward. Same auto-save-via-
        // fetch() contract as /catalog: a rejected save must not 303, or
        // the dropdown's fetch() call reads it as saved.
        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'licenses'
        ) {
            if (!$this->requireCapability('catalog_edit')) {
                return;
            }
            if (!$this->requireExtractionStatus($segments[1], $segments[3], null)) {
                return;
            }

            $type   = (string) ($_POST['type'] ?? '');
            $slug   = (string) ($_POST['slug'] ?? '');
            $status = (string) ($_POST['status'] ?? '');
            $saved  = in_array($type, ['plugin', 'theme'], true) && $slug !== ''
                && $this->app->dataStore()->setLicenseStatus($segments[1], $segments[3], $type, $slug, $status);

            if (!$saved) {
                http_response_code(400);
                echo 'Could not save the licence status.';

                return;
            }

            $this->redirect(self::safeReturn($_POST['return'] ?? "/site/{$segments[1]}/extraction/{$segments[3]}"));

            return;
        }

        // Which website a subscription is linked to — the one write this app
        // makes against the external CRM database, and always an explicit,
        // deliberate action (a real form submit + full page reload, not the
        // auto-submit-on-change pattern /catalog uses above): this changes a
        // real billing/service relationship, not a low-stakes local
        // classification.
        if ($segments === ['subscriptions']) {
            if (!$this->requireCapability('crm_subscription_edit')) {
                return;
            }

            $repo = $this->app->crmRepository();
            $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
            $websiteIdRaw   = trim((string) ($_POST['website_id'] ?? ''));
            $websiteId      = $websiteIdRaw !== '' && ctype_digit($websiteIdRaw) ? (int) $websiteIdRaw : null;

            $notice = 'website-update-failed';
            if ($repo !== null && $subscriptionId > 0) {
                try {
                    $notice = $repo->setSubscriptionWebsite($subscriptionId, $websiteId)
                        ? 'website-updated' : 'website-update-failed';
                } catch (\PDOException $e) {
                    $this->app->errorLog()->recordThrowable('crm_db', $e);
                    $notice = 'website-update-failed';
                }
            }

            $this->redirect(self::withQueryParam(self::safeReturn($_POST['return'] ?? '/clients', '/clients'), 'notice', $notice));

            return;
        }

        $this->notFound();
    }

    /**
     * Only same-site relative paths are allowed as a redirect target. A target
     * must start with a single '/' followed by neither '/' nor '\': browsers
     * normalise a backslash to a slash in the Location authority, so '/\evil.com'
     * would resolve to '//evil.com' — an off-site open redirect.
     */
    public static function safeReturn(mixed $target, string $fallback = '/catalog'): string
    {
        $target = is_string($target) ? $target : '';

        return (str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_starts_with($target, '/\\'))
            ? $target
            : $fallback;
    }

    /**
     * Adds (or replaces) one query parameter on a local URL, keeping any
     * existing query and #fragment — "/clients?service=all" + notice must not
     * become "/clients?service=all?notice=…".
     */
    public static function withQueryParam(string $url, string $key, string $value): string
    {
        [$beforeFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query]              = array_pad(explode('?', $beforeFragment, 2), 2, '');

        parse_str((string) $query, $params);
        $params[$key] = $value;

        return $path . '?' . http_build_query($params) . ($fragment !== null ? '#' . $fragment : '');
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }

    /** Double-submit CSRF token, stored in a cookie and echoed into forms. */
    private function csrfToken(): string
    {
        $token = (string) ($_COOKIE['swp_csrf'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            setcookie('swp_csrf', $token, [
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => self::isHttps(),
                'path'     => '/',
            ]);
        }

        return $token;
    }

    /** Locale for this request: ?lang=fr|en, else the configured default. */
    private function locale(): string
    {
        $requested = strtolower((string) ($_GET['lang'] ?? ''));

        return in_array($requested, ['en', 'fr'], true)
            ? $requested
            : (string) $this->app->config->get('lang.default', 'en');
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $vars['t']          = $this->app->translator($this->locale());
        $vars['lang']       = $vars['t']->locale;
        $vars['csrf']       = $vars['csrf'] ?? $this->csrfToken();
        $vars['appVersion'] = (string) $this->app->config->get('app.version', '');
        // Drives the account block in the sidebar; null under Basic auth, where
        // there is no identity to show.
        $vars['currentUser'] = $vars['currentUser']
            ?? ($this->app->googleAuth()->isConfigured() ? $this->currentUser() : null);
        extract($vars, EXTR_SKIP);
        $templateFile = dirname(__DIR__) . '/Web/templates/' . $template . '.php';

        require dirname(__DIR__) . '/Web/templates/layout.php';
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
    }
}
