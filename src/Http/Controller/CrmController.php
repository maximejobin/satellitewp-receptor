<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Support\SiteDisplay;

/**
 * The external CRM browser: clients, websites, products and items as flat
 * sibling routes (a technician starts from a website, not a client), plus
 * the one CRM write — re-linking a subscription to a website.
 */
final class CrmController extends Controller
{
    /** @param array<string, string> $params */
    public function clients(array $params): void
    {
        $this->withRepository('Clients', 'crm-clients', function (ClientsRepository $repo): void {
            $status        = (string) ($_GET['status'] ?? 'active');
            $search        = trim((string) ($_GET['q'] ?? ''));
            $subscriptions = (string) ($_GET['subscriptions'] ?? 'all');

            $this->render('crm-clients', [
                'title'                 => 'Clients',
                'nav'                   => 'crm-clients',
                'dataTables'            => true,
                'tooltip'               => true,
                'clients'               => $repo->listClients(
                    $status !== 'all' ? $status : null,
                    $search !== '' ? $search : null,
                    $subscriptions !== 'all' ? $subscriptions : null
                ),
                'selectedStatus'        => $status,
                'search'                => $search,
                'selectedSubscriptions' => $subscriptions,
                'lastSyncedAt'          => $repo->clientsLastSyncedAt(),
                // Independent of the filters, so the warning survives a subset that hides every orphan.
                'orphanCount'           => $repo->countOrphanSubscriptions(),
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function client(array $params): void
    {
        $id = (int) $params['id'];
        $this->withRepository('Client', 'crm-clients', function (ClientsRepository $repo) use ($id): void {
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

    /**
     * select2 source for the /websites client filter.
     *
     * @param array<string, string> $params
     */
    public function clientsSearch(array $params): void
    {
        $this->jsonSearch(static fn (ClientsRepository $repo, string $q): array => array_map(
            static fn (array $c): array => ['id' => $c['id'], 'text' => $c['label']],
            $repo->searchClients($q)
        ));
    }

    /** @param array<string, string> $params */
    public function websites(array $params): void
    {
        $this->withRepository('Websites', 'crm-websites', function (ClientsRepository $repo): void {
            $tags = self::stringList($_GET['tag'] ?? []);
            // A cleared multi-select submits no key at all; exclude_tag_present
            // (always sent by the filter form) tells "cleared" from "first visit".
            $excludeTags = isset($_GET['exclude_tag_present'])
                ? self::stringList($_GET['excludeTag'] ?? [])
                : [ClientsRepository::TAG_EXCLUDED_FROM_ASSIGNMENT];
            $search     = trim((string) ($_GET['q'] ?? ''));
            $connection = trim((string) ($_GET['connection'] ?? ''));

            $this->render('crm-websites', [
                'title'               => 'Websites',
                'nav'                 => 'crm-websites',
                'dataTables'          => true,
                'websites'            => $repo->listWebsites(
                    $tags !== [] ? $tags : null,
                    null,
                    $search !== '' ? $search : null,
                    $connection !== '' ? $connection : null,
                    $excludeTags !== [] ? $excludeTags : null
                ),
                'selectedTags'        => $tags,
                'selectedExcludeTags' => $excludeTags,
                'selectedConnection'  => $connection,
                'search'              => $search,
                'allTags'             => $repo->searchTags('', 500),
                'links'               => $this->app->config->get('external_links', []),
            ]);
        });
    }

    /**
     * select2 source for the /websites tag filter.
     *
     * @param array<string, string> $params
     */
    public function tagsSearch(array $params): void
    {
        $this->jsonSearch(static fn (ClientsRepository $repo, string $q): array => array_map(
            static fn (string $tag): array => ['id' => $tag, 'text' => $tag],
            $repo->searchTags($q)
        ));
    }

    /**
     * select2 source for a subscription's "linked website" control.
     *
     * @param array<string, string> $params
     */
    public function websitesSearch(array $params): void
    {
        $this->jsonSearch(static fn (ClientsRepository $repo, string $q): array => array_map(
            static fn (array $w): array => ['id' => (int) $w['id'], 'text' => SiteDisplay::of($w['url'])],
            $repo->searchAssignableWebsites($q)
        ));
    }

    /** @param array<string, string> $params */
    public function website(array $params): void
    {
        $id = (int) $params['id'];
        $this->withRepository('Website', 'crm-websites', function (ClientsRepository $repo) use ($id): void {
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

    /** @param array<string, string> $params */
    public function products(array $params): void
    {
        $this->withRepository('Products', 'crm-products', function (ClientsRepository $repo): void {
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
     * Item count scales with sites × plugins, so the table is server-side (itemsSearch()).
     *
     * @param array<string, string> $params
     */
    public function items(array $params): void
    {
        $this->withRepository('Items', 'crm-items', function (ClientsRepository $repo): void {
            $this->render('crm-items', [
                'title'      => 'Items',
                'nav'        => 'crm-items',
                'dataTables' => true,
                'types'      => $repo->distinctItemTypes(),
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function itemsSearch(array $params): void
    {
        if (!$this->requireCapability('crm_view')) {
            return;
        }

        $repo = $this->app->crmRepository();
        if ($repo === null) {
            $this->response->json(['error' => 'crm_db not configured'], 503);

            return;
        }

        $length = (int) ($_GET['length'] ?? 25);
        $length = $length > 0 ? min($length, 100) : 25;

        try {
            $result = $repo->searchItems([
                'q'               => (string) ($_GET['search']['value'] ?? ''),
                'type'            => (string) ($_GET['type'] ?? ''),
                'vulnerable'      => !empty($_GET['vulnerable']),
                'updateAvailable' => !empty($_GET['updateAvailable']),
            ], max(0, (int) ($_GET['start'] ?? 0)), $length);
        } catch (\PDOException $e) {
            $ref = $this->app->errorLog()->recordThrowable('crm_db', $e);
            $this->response->json(['error' => "crm_db query failed (ref {$ref})"], 500);

            return;
        }

        $this->response->json([
            'draw'            => (int) ($_GET['draw'] ?? 0),
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
     * POST /subscriptions — re-link a subscription to a website. A real
     * billing relationship, so an explicit form submit, never auto-save.
     *
     * @param array<string, string> $params
     */
    public function linkSubscription(array $params): void
    {
        if (!$this->requireCapability('crm_subscription_edit')) {
            return;
        }

        $repo           = $this->app->crmRepository();
        $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
        $websiteIdRaw   = trim((string) ($_POST['website_id'] ?? ''));
        $websiteId      = $websiteIdRaw !== '' && ctype_digit($websiteIdRaw) ? (int) $websiteIdRaw : null;

        $notice = 'website-update-failed';
        if ($repo !== null && $subscriptionId > 0) {
            try {
                if ($repo->setSubscriptionWebsite($subscriptionId, $websiteId)) {
                    $notice = 'website-updated';
                }
            } catch (\PDOException $e) {
                $this->app->errorLog()->recordThrowable('crm_db', $e);
            }
        }

        $this->redirect(Router::withQueryParam(Router::safeReturn($_POST['return'] ?? '/clients', '/clients'), 'notice', $notice));
    }

    /** @param callable(ClientsRepository): void $render */
    private function withRepository(string $title, string $nav, callable $render): void
    {
        if (!$this->requireCapability('crm_view')) {
            return;
        }

        $repo = $this->app->crmRepository();
        if ($repo === null) {
            $this->render('crm-unavailable', ['title' => $title, 'nav' => $nav, 'reason' => 'unconfigured', 'ref' => null]);

            return;
        }

        try {
            $render($repo);
        } catch (\PDOException $e) {
            $ref = $this->app->errorLog()->recordThrowable('crm_db', $e);
            $this->render('crm-unavailable', ['title' => $title, 'nav' => $nav, 'reason' => 'error', 'ref' => $ref]);
        }
    }

    /**
     * Shared body of the select2 AJAX endpoints — select2's default
     * `{results, pagination}` shape; no pagination at this data volume.
     *
     * @param callable(ClientsRepository, string): list<array{id: int|string, text: string}> $search
     */
    private function jsonSearch(callable $search): void
    {
        if (!$this->requireCapability('crm_view')) {
            return;
        }

        $empty = ['results' => [], 'pagination' => ['more' => false]];
        $repo  = $this->app->crmRepository();
        if ($repo === null) {
            $this->response->json($empty);

            return;
        }

        try {
            $results = $search($repo, trim((string) ($_GET['q'] ?? '')));
        } catch (\PDOException $e) {
            $this->app->errorLog()->recordThrowable('crm_db', $e);
            $this->response->json($empty, 500);

            return;
        }

        $this->response->json(['results' => $results, 'pagination' => ['more' => false]]);
    }

    /** @return list<string> non-empty strings from a query-string array value */
    private static function stringList(mixed $raw): array
    {
        return is_array($raw)
            ? array_values(array_filter(array_map('strval', $raw), static fn (string $t): bool => $t !== ''))
            : [];
    }
}
