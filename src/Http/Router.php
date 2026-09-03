<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Crm\ClientsRepository;
use SatelliteWP\Xtractor\Probe\BlogVaultProbe;
use SatelliteWP\Xtractor\Reference\EndOfLife;
use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Storage\UserStore;
use SatelliteWP\Xtractor\Support\SiteDisplay;

/**
 * Read-only web UI. Lists come from SQLite, detail pages from JSON files.
 *
 * Routes:
 *   /                                        sites list
 *   /site/{site_id}                          site detail (history, events, trends)
 *   /site/{site_id}/extraction/{id}          extraction detail (findings + probes + payload)
 *   /site/{site_id}/extraction/{id}/raw/{f}  serve a JSON file (allowlisted)
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

        if (!$this->authenticate()) {
            return;
        }

        $match  = self::matchRoute($path);
        $params = $match['params'];

        match ($match['route']) {
            'sites'                    => $this->sitesPage(),
            'catalog'                  => $this->catalogPage(),
            'catalog_search'           => $this->catalogSearch(),
            'users'                    => $this->usersPage(),
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
                => ['route' => 'sites', 'params' => []],

            $segments === ['catalog']
                => ['route' => 'catalog', 'params' => []],

            $segments === ['catalog', 'search']
                => ['route' => 'catalog_search', 'params' => []],

            // CRM: clients, websites, products and items are siblings, none
            // nested under another — the business identifies a website first
            // and finds its client after, never the reverse (user, 2026-09-02:
            // "rien ne devrait être sous client"), so the URLs are flat
            // (/clients, /websites, /products, /items) rather than
            // /clients/websites, even though every list page still links to
            // the related entities it references.
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

    private function sitesPage(): void
    {
        $this->render('sites', [
            'title'      => 'Sites',
            'nav'        => 'sites',
            'sites'      => $this->app->index()->listSites($_GET['q'] ?? null),
            'search'     => (string) ($_GET['q'] ?? ''),
            'notice'     => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    private function catalogPage(): void
    {
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
            !empty($_GET['needs']),
            !empty($_GET['unclassified']),
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
     * Clients/websites/products/items are four sibling entities from the same
     * external CRM database — none owned by another (2026-09-02, user: "notre
     * business fonctionne par site web et non par client... rien ne devrait
     * être sous client. Tout est une entité de même hiérarchie"), hence flat
     * URLs (/clients, /websites, /products, /items) rather than nesting the
     * other three under /clients/. This helper is just shared plumbing: every
     * one of the four pages needs the same "not connected yet"
     * (App::crmRepository() is null until crm_db is configured) and
     * "connection dropped mid-request" handling (a configured but temporarily
     * unreachable MySQL server must not 500 the whole admin UI with a raw
     * PDOException).
     *
     * @param callable(ClientsRepository): void $render
     */
    private function withCrmRepository(string $title, string $nav, callable $render): void
    {
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
            // Absent from the URL at all -> default to "active" (2026-09-02,
            // user: "par défaut, on affiche les clients actifs"); explicitly
            // ?status=all is how the filter bar asks for everyone, so the
            // three real states (active / inactive / all) are distinguishable
            // from "no filter chosen yet" without a fourth empty-string case.
            $status        = (string) ($_GET['status'] ?? 'active');
            $search        = trim((string) ($_GET['q'] ?? ''));
            $subscriptions = (string) ($_GET['subscriptions'] ?? 'all');

            $this->render('crm-clients', [
                'title'          => 'Clients',
                'nav'            => 'crm-clients',
                'dataTables'     => true,
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
            $clientId   = ctype_digit((string) ($_GET['client_id'] ?? '')) ? (int) $_GET['client_id'] : null;
            $search     = trim((string) ($_GET['q'] ?? ''));
            $connection = trim((string) ($_GET['connection'] ?? ''));

            // Only the *currently selected* client needs a label looked up —
            // select2's AJAX source supplies every other option on demand,
            // so the full 315-client list this used to preload never has to
            // reach the page at all.
            $selectedClient = $clientId !== null ? $repo->getClient($clientId) : null;

            $this->render('crm-websites', [
                'title'        => 'Websites',
                'nav'          => 'crm-websites',
                'dataTables'   => true,
                'select2'      => true,
                'websites'     => $repo->listWebsites(
                    $tags !== [] ? $tags : null,
                    $clientId,
                    $search !== '' ? $search : null,
                    $connection !== '' ? $connection : null
                ),
                'selectedTags' => $tags,
                'selectedClientId'    => $clientId,
                'selectedClientLabel' => $selectedClient !== null ? ClientsRepository::clientLabel($selectedClient) : null,
                'selectedConnection'  => $connection,
                'search'       => $search,
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
            // Plain scalars only, no server-built HTML (same convention as
            // dataVulnerabilitiesSearch() below) — this endpoint never loads
            // helpers.php's e(), and DataTables does not escape a column's
            // content for you if you hand it markup instead of text.
            'data'            => array_map(static fn (array $row): array => [
                strtoupper((string) $row['type']),
                (string) $row['name'],
                (string) $row['slug'],
                (string) $row['version'],
                $row['is_update_available'] ? ((string) ($row['new_version'] ?? '?')) : '—',
                $row['is_vulnerable'] ? 'Yes (' . ((int) ($row['vulnerability_count'] ?? 0)) . ')' : 'No',
                $row['is_active'] ? 'Yes' : 'No',
                SiteDisplay::of($row['website_url']),
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
        $this->render('data-vulnerabilities', [
            'title'       => 'Vulnerabilities (Wordfence Intelligence)',
            'nav'         => 'data-vulnerabilities',
            'dataTables'  => true,
            'available'   => $this->app->wordfenceIndex()->isAvailable(),
            'refreshedAt' => $this->app->wordfenceIndex()->refreshedAt(),
        ]);
    }

    /**
     * JSON endpoint consumed by Datatables' server-side mode on
     * /data/vulnerabilities. Streams the cache (WordfenceIndex::search()) so
     * a request never has to hold the full nested cache in memory — see the
     * OOM this exact pattern already caused once for a per-site scan,
     * documented on WordfenceIndex::preload(). search() itself still sorts
     * the (flat, filtered) result set by version descending before this
     * slices out the requested page.
     */
    private function dataVulnerabilitiesSearch(): void
    {
        $draw   = (int) ($_GET['draw'] ?? 0);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = (int) ($_GET['length'] ?? 25);
        $length = $length > 0 ? min($length, 100) : 25;
        $query  = (string) ($_GET['search']['value'] ?? '');

        $result = $this->app->wordfenceIndex()->search($query, $start, $length);

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => array_map(static fn (array $row): array => [
                $row['slug'],
                strtoupper((string) $row['type']),
                $row['cve_id'] ?? '—',
                $row['title'] ?? '',
                $row['cvss_score'] !== null ? $row['cvss_score'] . ' (' . ($row['cvss_rating'] ?? '?') . ')' : '—',
                !empty($row['patched']) ? implode(', ', $row['patched_versions']) : '—',
            ], $result['rows']),
        ]);
    }

    private function sitePage(string $siteId): void
    {
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
            'title'       => $site['name'] ?? $site['site_url'] ?? $siteId,
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
            'catalog'      => $this->app->softwareCatalog(),
            'csrf'         => $this->csrfToken(),
            'blogVault'    => $blogVault,
            'reportAssets' => true,
        ]);
    }

    private function rawFile(string $siteId, string $extractionId, string $name): void
    {
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
     * or null when the name is not allowlisted. Pure and path-traversal-safe:
     * the name is basename-stripped and matched against a fixed allowlist, so a
     * request like "../../keys" or "payload/../meta" can never escape.
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

        return in_array($name, ['payload', 'meta', 'findings'], true)
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
        $users = $this->app->userStore();
        $me    = $this->currentUser();

        $this->render('users', [
            'title'   => 'Users',
            'nav'     => 'users',
            'users'   => $users->all(),
            'roles'   => $this->app->roleCapabilities()->roles(),
            'me'      => $me,
            'isAdmin' => $me !== null && $users->isAdmin($me),
            'csrf'    => $this->csrfToken(),
            'notice'  => (string) ($_GET['notice'] ?? ''),
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
            'auth/logout'   => $this->authLogout(),
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
            'title'   => 'Connexion',
            'nav'     => '',
            'message' => $message,
            'firstRun' => $this->app->userStore()->isEmpty(),
        ]);
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

        // Basic Auth has no session to key a limit on before the request is
        // verified, so this is by IP — no attempt limit existed at all
        // before 2026-08-31.
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

        // A double-submit CSRF check has exactly one job: prove the request
        // carries the same secret the cookie carries. That only holds if
        // "the cookie is missing" and "the field is missing" are rejected as
        // their own cases, not folded into '' via a '??' default and then
        // compared as strings — '' == '' is true, so two different kinds of
        // "not there" would silently agree with each other. Fixed 2026-08-30
        // (a '?? "_"' sentinel on the posted side used to paper over exactly
        // this, which is what let an absent cookie slip past unnoticed).
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

        // Queue an extraction for analysis. The web request only flips a status;
        // the cron worker does the slow part, so nothing here can time out.
        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'run'
        ) {
            $this->app->index()->setExtractionStatus($segments[1], $segments[3], Index::STATUS_QUEUED);
            $this->redirect(self::safeReturn(
                $_POST['return'] ?? "/site/{$segments[1]}/extraction/{$segments[3]}"
            ));

            return;
        }

        if ($segments === ['users']) {
            $users = $this->app->userStore();
            $me    = $this->currentUser();

            // Only the admin may change the list, and only when sign-in is on:
            // with Basic auth there is no identity to check against.
            if ($me === null || !$users->isAdmin($me)) {
                http_response_code(403);
                echo 'Only the administrator can manage users.';

                return;
            }

            $notice = match ((string) ($_POST['action'] ?? '')) {
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
                default  => '',
            };

            $this->redirect('/users' . ($notice !== '' ? '?notice=' . $notice : ''));

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
            $type = (string) ($_POST['type'] ?? '');
            $saved = in_array($type, ['plugin', 'theme'], true)
                && $this->app->softwareCatalog()->setLicense(
                    $type,
                    (string) ($_POST['slug'] ?? ''),
                    (string) ($_POST['license'] ?? '')
                );

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

        // Which website a subscription is linked to — the one write this app
        // makes against the external CRM database, and always an explicit,
        // deliberate action (a real form submit + full page reload, not the
        // auto-submit-on-change pattern /catalog uses above): this changes a
        // real billing/service relationship, not a low-stakes local
        // classification.
        if ($segments === ['subscriptions']) {
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

            $this->redirect(self::safeReturn($_POST['return'] ?? '/clients') . '?notice=' . $notice);

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
    public static function safeReturn(mixed $target): string
    {
        $target = is_string($target) ? $target : '';

        return (str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_starts_with($target, '/\\'))
            ? $target
            : '/catalog';
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
