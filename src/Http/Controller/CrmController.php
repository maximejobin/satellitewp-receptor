<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http\Controller;

use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Crm\ExternalWebsites;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Support\SiteDisplay;
use SatelliteWP\Manager\Web\StatusChecks;

/**
 * The external CRM browser: clients, websites, products and items as flat
 * sibling routes (a technician starts from a website, not a client), plus
 * the one CRM write — re-linking a subscription to a website — and the
 * Manager-side non-WordPress websites (ExternalWebsites) it can also link to.
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
            $externalLinks = $this->app->externalWebsites()->links();

            $this->render('crm-clients', [
                'title'                 => 'Clients',
                'nav'                   => 'crm-clients',
                'dataTables'            => true,
                'tooltip'               => true,
                'clients'               => $repo->listClients(
                    $status !== 'all' ? $status : null,
                    $search !== '' ? $search : null,
                    $subscriptions !== 'all' ? $subscriptions : null,
                    $externalLinks
                ),
                'selectedStatus'        => $status,
                'search'                => $search,
                'selectedSubscriptions' => $subscriptions,
                'lastSyncedAt'          => $repo->clientsLastSyncedAt(),
                // Independent of the filters, so the warning survives a subset that hides every orphan.
                'orphanCount'           => $repo->countOrphanSubscriptions(array_keys($externalLinks)),
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
                'subscriptions' => $this->withExternalWebsites($repo->subscriptionsForClient($id)),
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
            $source     = in_array($_GET['source'] ?? '', ['wordpress', 'external'], true) ? (string) $_GET['source'] : '';

            $websites = $source === 'external' ? [] : $repo->listWebsites(
                $tags !== [] ? $tags : null,
                null,
                $search !== '' ? $search : null,
                $connection !== '' ? $connection : null,
                $excludeTags !== [] ? $excludeTags : null
            );
            $components    = $repo->componentsByWebsiteIds(array_map(static fn (array $w): int => (int) $w['id'], $websites));
            $vulnerability = $this->app->websiteVulnerability();
            $vulnerability->preload(array_merge([], ...array_values($components)));
            foreach ($websites as &$website) {
                $website['vulnerable_reasons'] = $vulnerability->reasons($website, $components[(int) $website['id']] ?? []);
            }
            unset($website);

            // External websites have no tags or connection: a filter on either leaves them out.
            if ($source !== 'wordpress' && $tags === [] && $connection === '') {
                $websites = array_merge($websites, $this->externalWebsiteRows($repo, $search));
                usort($websites, static fn (array $a, array $b): int => strcmp(SiteDisplay::of($a['url']), SiteDisplay::of($b['url'])));
            }

            $this->render('crm-websites', [
                'title'               => 'Websites',
                'nav'                 => 'crm-websites',
                'dataTables'          => true,
                'websites'            => $websites,
                'selectedTags'        => $tags,
                'selectedExcludeTags' => $excludeTags,
                'selectedConnection'  => $connection,
                'selectedSource'      => $source,
                'search'              => $search,
                'canEdit'             => $this->currentUserCan('crm_subscription_edit'),
                'csrf'                => $this->csrfToken(),
                'notice'              => (string) ($_GET['notice'] ?? ''),
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
        $external = $this->app->externalWebsites()->all();
        $this->jsonSearch(static function (ClientsRepository $repo, string $q) use ($external): array {
            $results = array_map(
                static fn (array $w): array => ['id' => (int) $w['id'], 'text' => SiteDisplay::of($w['url'])],
                $repo->searchAssignableWebsites($q)
            );
            foreach ($external as $w) {
                if ($q === '' || stripos($w['url'], $q) !== false) {
                    $results[] = ['id' => ExternalWebsites::publicId($w['id']), 'text' => SiteDisplay::of($w['url']) . ' (External)'];
                }
            }
            usort($results, static fn (array $a, array $b): int => strcmp($a['text'], $b['text']));

            return array_slice($results, 0, 50);
        });
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

            $items         = $repo->itemsForWebsite($id);
            $vulnerability = $this->app->websiteVulnerability();
            $vulnerability->preload(array_merge($items['plugin'], $items['theme']));

            $this->render('crm-website', [
                'title'         => SiteDisplay::of($website['url']),
                'nav'           => 'crm-websites',
                'select2'       => true,
                'website'       => $website,
                'clients'       => $repo->clientsForWebsite($id),
                'subscriptions' => $repo->subscriptionsForWebsite($id),
                'items'         => $items,
                'csrf'          => $this->csrfToken(),
                'notice'        => (string) ($_GET['notice'] ?? ''),
                'links'         => $this->app->config->get('external_links', []),
                'vulnerability' => $vulnerability,
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function externalWebsite(array $params): void
    {
        $website = $this->app->externalWebsites()->get((int) $params['id']);
        $this->withRepository('Website', 'crm-websites', function (ClientsRepository $repo) use ($website): void {
            if ($website === null) {
                $this->notFound();

                return;
            }

            $subscriptions = $repo->subscriptionsByIds($website['subscription_ids']);
            $this->render('crm-external-website', [
                'title'         => SiteDisplay::of($website['url']),
                'nav'           => 'crm-websites',
                'select2'       => true,
                'website'       => $website,
                'clients'       => self::clientsOf($subscriptions),
                'subscriptions' => $subscriptions,
                'canEdit'       => $this->currentUserCan('crm_subscription_edit'),
                'csrf'          => $this->csrfToken(),
                'notice'        => (string) ($_GET['notice'] ?? ''),
            ]);
        });
    }

    /**
     * POST /websites/external — a non-WordPress website subscriptions can be linked to.
     *
     * @param array<string, string> $params
     */
    public function addExternalWebsite(array $params): void
    {
        if (!$this->requireCapability('crm_subscription_edit')) {
            return;
        }

        $this->withCrmWrite('/websites', function (ClientsRepository $repo): string {
            $id = $this->app->externalWebsites()->add((string) ($_POST['url'] ?? ''), $repo->allWebsiteUrls(), (string) $this->currentUser());

            return '/websites/' . ExternalWebsites::publicId($id) . '?notice=external-added';
        });
    }

    /** @param array<string, string> $params */
    public function updateExternalWebsite(array $params): void
    {
        if (!$this->requireCapability('crm_subscription_edit')) {
            return;
        }

        $id   = (int) $params['id'];
        $page = '/websites/' . ExternalWebsites::publicId($id);
        $this->withCrmWrite($page, function (ClientsRepository $repo) use ($id, $page): string {
            if (!$this->app->externalWebsites()->updateUrl($id, (string) ($_POST['url'] ?? ''), $repo->allWebsiteUrls())) {
                return '/websites?notice=external-not-found';
            }

            return $page . '?notice=external-updated';
        });
    }

    /** @param array<string, string> $params */
    public function deleteExternalWebsite(array $params): void
    {
        if (!$this->requireCapability('crm_subscription_edit')) {
            return;
        }

        $id   = (int) $params['id'];
        $page = '/websites/' . ExternalWebsites::publicId($id);
        $this->withCrmWrite($page, function (ClientsRepository $repo) use ($id, $page): string {
            $website = $this->app->externalWebsites()->get($id);
            if ($website === null) {
                return '/websites?notice=external-not-found';
            }
            $existing = array_map('intval', array_column($repo->subscriptionsByIds($website['subscription_ids']), 'id'));

            return $this->app->externalWebsites()->delete($id, $existing)
                ? '/websites?notice=external-deleted'
                : $page . '?notice=external-linked';
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
                'title'       => 'Items',
                'nav'         => 'crm-items',
                'dataTables'  => true,
                'types'       => $repo->distinctItemTypes(),
                'allTags'     => $repo->searchTags('', 500),
                'excludeTags' => [ClientsRepository::TAG_EXCLUDED_FROM_ASSIGNMENT],
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
                'tags'            => self::stringList($_GET['tag'] ?? []),
                // An empty list is not sent at all: exclude_tag_present tells "cleared" from "not sent".
                'excludeTags'     => isset($_GET['exclude_tag_present'])
                    ? self::stringList($_GET['excludeTag'] ?? [])
                    : [ClientsRepository::TAG_EXCLUDED_FROM_ASSIGNMENT],
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
            'data'            => self::itemRows($result['rows']),
        ]);
    }

    /**
     * DataTables inserts each cell as HTML and CRM item names come from
     * plugin headers: text is escaped here, markup built around it.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<int|string>>
     */
    public static function itemRows(array $rows): array
    {
        require_once dirname(__DIR__, 2) . '/Web/helpers.php';

        return array_map(static fn (array $row): array => [
            e(strtoupper((string) ($row['type'] ?? ''))),
            e($row['name'] ?? ''),
            '<span class="mono">' . e($row['slug'] ?? '') . '</span>',
            e($row['version'] ?? ''),
            !empty($row['is_update_available']) ? e($row['new_version'] ?? '?') : '',
            !empty($row['is_vulnerable']) ? '<span class="val-error">Vulnerable</span>' : '',
            !empty($row['is_active']) ? 'Yes' : 'No',
            '<a href="/websites/' . (int) ($row['website_id'] ?? 0) . '">' . e(SiteDisplay::of($row['website_url'] ?? '')) . '</a>',
            (int) ($row['website_id'] ?? 0),
        ], $rows);
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
        $external       = $this->app->externalWebsites();
        $subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
        $websiteIdRaw   = trim((string) ($_POST['website_id'] ?? ''));
        $externalId     = ExternalWebsites::parseId($websiteIdRaw);
        $websiteId      = $websiteIdRaw !== '' && ctype_digit($websiteIdRaw) ? (int) $websiteIdRaw : null;
        $valid          = $websiteIdRaw === '' || $websiteId !== null
            || ($externalId !== null && $external->get($externalId) !== null);

        $notice = 'website-update-failed';
        if ($repo !== null && $subscriptionId > 0 && $valid) {
            try {
                // The CRM row first: it also proves the subscription exists before the file names it.
                if ($repo->setSubscriptionWebsite($subscriptionId, $websiteId) && $external->link($subscriptionId, $externalId)) {
                    $notice = 'website-updated';
                    (new StatusChecks($this->app))->forgetAlert();
                }
            } catch (\PDOException $e) {
                $this->app->errorLog()->recordThrowable('crm_db', $e);
            }
        }

        $this->redirect(Router::withQueryParam(Router::safeReturn($_POST['return'] ?? '/clients', '/clients'), 'notice', $notice));
    }

    /**
     * An external-website write that compares against the CRM's URLs: an
     * invalid or duplicate URL and an unreachable CRM go back to $back with
     * a notice, as does success to the URL $write returns.
     *
     * @param callable(ClientsRepository): string $write
     */
    private function withCrmWrite(string $back, callable $write): void
    {
        $repo = $this->app->crmRepository();
        if ($repo === null) {
            $this->redirect(Router::withQueryParam($back, 'notice', 'external-failed'));

            return;
        }

        try {
            $this->redirect($write($repo));
        } catch (\InvalidArgumentException $e) {
            $this->redirect(Router::withQueryParam($back, 'notice', 'external-' . $e->getMessage()));
        } catch (\PDOException $e) {
            $this->app->errorLog()->recordThrowable('crm_db', $e);
            $this->redirect(Router::withQueryParam($back, 'notice', 'external-failed'));
        }
    }

    /**
     * Client subscriptions with no CRM website get their external one, as
     * website_id "x3" and website_url.
     *
     * @param list<array<string, mixed>> $subscriptions
     * @return list<array<string, mixed>>
     */
    private function withExternalWebsites(array $subscriptions): array
    {
        $links = $this->app->externalWebsites()->links();
        $urls  = array_column($this->app->externalWebsites()->all(), 'url', 'id');
        foreach ($subscriptions as &$s) {
            $externalId = $links[(int) $s['id']] ?? null;
            if (empty($s['website_id']) && $externalId !== null) {
                $s['website_id']  = ExternalWebsites::publicId($externalId);
                $s['website_url'] = $urls[$externalId] ?? '';
            }
        }
        unset($s);

        return $subscriptions;
    }

    /**
     * External websites as /websites rows, matched like listWebsites() on
     * the URL or the client's company.
     *
     * @return list<array<string, mixed>>
     */
    private function externalWebsiteRows(ClientsRepository $repo, string $search): array
    {
        $rows = [];
        foreach ($this->app->externalWebsites()->all() as $w) {
            $clients = self::clientsOf($repo->subscriptionsByIds($w['subscription_ids']));
            $matches = $search === '' || stripos($w['url'], $search) !== false
                || array_filter($clients, static fn (array $c): bool => stripos((string) ($c['company'] ?? ''), $search) !== false) !== [];
            if ($matches) {
                $rows[] = [
                    'id'                 => ExternalWebsites::publicId($w['id']),
                    'url'                => $w['url'],
                    'external'           => true,
                    'clients'            => $clients,
                    'tags'               => [],
                    'vulnerable_reasons' => [],
                ];
            }
        }

        return $rows;
    }

    /**
     * The distinct clients of subscriptionsByIds() rows, as swp_clients-shaped rows.
     *
     * @param list<array<string, mixed>> $subscriptions
     * @return list<array<string, mixed>>
     */
    private static function clientsOf(array $subscriptions): array
    {
        $clients = [];
        foreach ($subscriptions as $s) {
            $clients[(int) $s['client_id']] = [
                'id'         => (int) $s['client_id'],
                'email'      => $s['client_email'] ?? null,
                'first_name' => $s['first_name'] ?? null,
                'last_name'  => $s['last_name'] ?? null,
                'company'    => $s['company'] ?? null,
            ];
        }
        $clients = array_values($clients);
        usort($clients, static fn (array $a, array $b): int => strcmp(ClientsRepository::clientLabel($a), ClientsRepository::clientLabel($b)));

        return $clients;
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
