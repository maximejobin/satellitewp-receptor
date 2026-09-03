<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Crm;

use PDO;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Crm\ClientsRepository;

/**
 * ClientsRepository is written in portable SQL on purpose (no MySQL-only
 * syntax such as GROUP_CONCAT's SEPARATOR keyword) so it can be exercised
 * here against a SQLite :memory: database instead of a real MySQL server —
 * this project's test suite runs fully offline (phpunit.xml.dist excludes
 * the 'network' group) and a live external CRM database will only exist
 * later. The schema below is a portable-DDL translation of the real MySQL
 * dump this repository was built against (AUTO_INCREMENT/ENGINE/COLLATE
 * stripped, tinyint(1) -> INTEGER) — same columns and relationships.
 */
final class ClientsRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ClientsRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE swp_clients (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL, first_name TEXT, last_name TEXT,
                company TEXT, teamwork_id TEXT, hubspot_id TEXT, blogvault_client_id TEXT,
                date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_products (
                auto_id INTEGER PRIMARY KEY, id INTEGER, parent_id INTEGER, internal_id INTEGER,
                name TEXT NOT NULL, category TEXT, permalink TEXT, date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_licenses (
                auto_id INTEGER PRIMARY KEY, id INTEGER, parent_id INTEGER, internal_id INTEGER,
                type TEXT NOT NULL, slug TEXT, is_manual_update INTEGER NOT NULL, date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_maintenance_plans (
                auto_id INTEGER PRIMARY KEY, id INTEGER NOT NULL, parent_id INTEGER,
                is_licenses_included INTEGER NOT NULL, date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_subscriptions (
                id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL, product_id INTEGER NOT NULL,
                subscription_status TEXT NOT NULL DEFAULT 'unknown', creation_date TEXT NOT NULL,
                last_payment_date TEXT NOT NULL, next_renewal_date TEXT, blogvault_site_id TEXT,
                date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_subscriptions_websites (
                subscription_id INTEGER NOT NULL, website_id INTEGER NOT NULL,
                date_added TEXT NOT NULL, date_updated TEXT NOT NULL,
                PRIMARY KEY (subscription_id, website_id)
            );
            CREATE TABLE swp_websites (
                id INTEGER PRIMARY KEY, url TEXT NOT NULL, url_standard TEXT NOT NULL,
                blogvault_site_id TEXT NOT NULL, created TEXT NOT NULL, updated TEXT NOT NULL,
                connection_status TEXT, host TEXT, php_version TEXT, mysql_version TEXT,
                wp_core_version TEXT, wp_core_is_vulnerable INTEGER, date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_website_items (
                id INTEGER PRIMARY KEY, website_id INTEGER NOT NULL, type TEXT NOT NULL,
                name TEXT NOT NULL, slug TEXT NOT NULL, version TEXT NOT NULL, new_version TEXT,
                is_active INTEGER NOT NULL, network_active INTEGER,
                is_vulnerable INTEGER NOT NULL DEFAULT 0, is_update_available INTEGER NOT NULL DEFAULT 0,
                file_path TEXT, vulnerability_count INTEGER,
                bv_date_sync TEXT NOT NULL, date_sync TEXT NOT NULL
            );
            CREATE TABLE swp_website_tags (
                website_id INTEGER NOT NULL, tag TEXT NOT NULL,
                date_added TEXT NOT NULL, date_sync TEXT NOT NULL,
                PRIMARY KEY (website_id, tag)
            );
            SQL);

        $this->repo = new ClientsRepository($this->pdo);
    }

    /** Two clients, two websites, a license and a maintenance-plan product, one subscription each. */
    private function seedBasicPortfolio(): void
    {
        $this->pdo->exec("INSERT INTO swp_clients (id, email, first_name, last_name, company, date_sync) VALUES
            (1, 'a@example.com', 'Ann', 'Admin', 'Acme Inc', '2026-01-01 00:00:00'),
            (2, 'b@example.com', 'Bob', 'Builder', NULL, '2026-01-01 00:00:00')");

        $this->pdo->exec("INSERT INTO swp_products (auto_id, id, name, category, date_sync) VALUES
            (10, 100, 'WooCommerce Pro License', 'plugin', '2026-01-01 00:00:00'),
            (20, 200, 'Care Plan Gold', 'plan', '2026-01-01 00:00:00'),
            (30, 300, 'Consulting Hour', NULL, '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_licenses (auto_id, id, type, slug, is_manual_update, date_sync) VALUES
            (10, 1, 'plugin', 'woocommerce', 0, '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_maintenance_plans (auto_id, id, is_licenses_included, date_sync) VALUES
            (20, 1, 1, '2026-01-01 00:00:00')");

        $this->pdo->exec("INSERT INTO swp_websites (id, url, url_standard, blogvault_site_id, created, updated, host, wp_core_version, date_sync) VALUES
            (1, 'https://siteone.test', 'siteone.test', 'bv-1', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'host-a', '6.6', '2026-01-01 00:00:00'),
            (2, 'https://sitetwo.test', 'sitetwo.test', 'bv-2', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'host-b', '6.5', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_website_tags (website_id, tag, date_added, date_sync) VALUES
            (1, 'vip', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (1, 'ecommerce', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (2, 'ecommerce', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (1000, 1, 100, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (2000, 2, 200, 'active', '2026-02-01 00:00:00', '2026-02-01 00:00:00', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_subscriptions_websites (subscription_id, website_id, date_added, date_updated) VALUES
            (1000, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (2000, 2, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    }

    // ---- Clients ---------------------------------------------------------

    public function testListClientsIncludesSubscriptionAndWebsiteCounts(): void
    {
        $this->seedBasicPortfolio();

        $clients = array_column($this->repo->listClients(), null, 'id');

        $this->assertSame(1, (int) $clients[1]['subscription_count']);
        $this->assertSame(1, (int) $clients[1]['website_count']);
    }

    public function testListClientsFiltersByServiceStatus(): void
    {
        $this->seedBasicPortfolio();
        // Client 1 has one 'active' subscription (seeded). Give client 2's
        // subscription a non-active status so it becomes "inactive".
        $this->pdo->exec("UPDATE swp_subscriptions SET subscription_status = 'pending' WHERE id = 2000");

        $active   = array_column($this->repo->listClients('active'), 'id');
        $inactive = array_column($this->repo->listClients('inactive'), 'id');

        $this->assertSame([1], $active);
        $this->assertContains(2, $inactive, 'pending-only subscriptions count as inactive');
    }

    public function testListClientsWithNoSubscriptionsAtAllCountsAsInactive(): void
    {
        $this->pdo->exec("INSERT INTO swp_clients (id, email, date_sync) VALUES (9, 'nobody@example.com', '2026-01-01 00:00:00')");

        $inactive = array_column($this->repo->listClients('inactive'), 'id');

        $this->assertContains(9, $inactive);
    }

    public function testListClientsFiltersByOrphanSubscription(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $orphanClients = array_column($this->repo->listClients(subscriptionFilter: 'have_unassigned'), 'id');

        $this->assertSame([1], $orphanClients, 'only client 1 has a subscription with no linked website');
    }

    public function testListClientsFiltersByNoUnassignedSubscription(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $clients = array_column($this->repo->listClients(subscriptionFilter: 'no_unassigned'), 'id');

        $this->assertSame([2], $clients, 'client 1 has an unlinked subscription, client 2 does not');
    }

    public function testListClientsSearchesCompanyContactAndEmail(): void
    {
        $this->seedBasicPortfolio();

        $this->assertSame([1], array_column($this->repo->listClients(search: 'Acme'), 'id'), 'matches company');
        $this->assertSame([2], array_column($this->repo->listClients(search: 'Builder'), 'id'), 'matches last name');
        $this->assertSame([2], array_column($this->repo->listClients(search: 'b@example'), 'id'), 'matches email');
        $this->assertSame([], array_column($this->repo->listClients(search: 'nobody-matches-this'), 'id'));
    }

    public function testCountOrphanSubscriptionsIsUnaffectedByAnyFilter(): void
    {
        $this->seedBasicPortfolio();
        $this->assertSame(0, $this->repo->countOrphanSubscriptions());

        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (3001, 2, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $this->assertSame(2, $this->repo->countOrphanSubscriptions());
    }

    public function testClientsLastSyncedAtIsTheMostRecentDateSync(): void
    {
        $this->assertNull($this->repo->clientsLastSyncedAt());

        $this->seedBasicPortfolio();
        $this->pdo->exec("UPDATE swp_clients SET date_sync = '2026-05-01 00:00:00' WHERE id = 1");
        $this->pdo->exec("UPDATE swp_clients SET date_sync = '2026-06-15 10:00:00' WHERE id = 2");

        $this->assertSame('2026-06-15 10:00:00', $this->repo->clientsLastSyncedAt());
    }

    public function testGetClientFoundAndNotFound(): void
    {
        $this->seedBasicPortfolio();

        $this->assertSame('a@example.com', $this->repo->getClient(1)['email']);
        $this->assertNull($this->repo->getClient(999));
    }

    public function testClientLabelPrefersCompanyThenNameThenEmail(): void
    {
        $this->assertSame('Acme Inc', ClientsRepository::clientLabel(['company' => 'Acme Inc', 'first_name' => 'Ann', 'email' => 'a@x.com']));
        $this->assertSame('Bob Builder', ClientsRepository::clientLabel(['company' => '', 'first_name' => 'Bob', 'last_name' => 'Builder', 'email' => 'b@x.com']));
        $this->assertSame('c@x.com', ClientsRepository::clientLabel(['email' => 'c@x.com']));
    }

    public function testSubscriptionsForClientJoinsProductLicenseAndWebsite(): void
    {
        $this->seedBasicPortfolio();

        $subs = $this->repo->subscriptionsForClient(1);

        $this->assertCount(1, $subs);
        $this->assertSame('WooCommerce Pro License', $subs[0]['product_name']);
        $this->assertSame('woocommerce', $subs[0]['license_slug']);
        $this->assertSame(1, (int) $subs[0]['website_id']);
        $this->assertSame('https://siteone.test', $subs[0]['website_url']);
    }

    public function testSubscriptionsForClientWithNoWebsiteLinkStillReturnsTheSubscription(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $subs = $this->repo->subscriptionsForClient(1);

        $this->assertCount(2, $subs);
        $unlinked = array_values(array_filter($subs, static fn (array $s): bool => $s['product_name'] === 'Consulting Hour'));
        $this->assertNull($unlinked[0]['website_id']);
    }

    // ---- Websites ----------------------------------------------------------

    public function testListWebsitesAttachesTags(): void
    {
        $this->seedBasicPortfolio();

        $websites = array_column($this->repo->listWebsites(), null, 'id');

        $this->assertSame(['ecommerce', 'vip'], $websites[1]['tags']); // ordered by tag
        $this->assertSame(['ecommerce'], $websites[2]['tags']);
    }

    public function testListWebsitesFiltersByTag(): void
    {
        $this->seedBasicPortfolio();

        $vip = $this->repo->listWebsites(tags: ['vip']);
        $this->assertCount(1, $vip);
        $this->assertSame(1, (int) $vip[0]['id']);

        $ecommerce = $this->repo->listWebsites(tags: ['ecommerce']);
        $this->assertCount(2, $ecommerce);
    }

    public function testListWebsitesFiltersByMultipleTagsIsOrNotAnd(): void
    {
        $this->seedBasicPortfolio();

        // site 1 has 'vip', site 2 has only 'ecommerce' -> OR of the two
        // tags matches both, not just a site carrying every tag listed.
        $both = $this->repo->listWebsites(tags: ['vip', 'ecommerce']);

        $this->assertCount(2, $both);
    }

    public function testListWebsitesFiltersByConnectionStatus(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("UPDATE swp_websites SET connection_status = 'CONNECTED' WHERE id = 1");
        $this->pdo->exec("UPDATE swp_websites SET connection_status = 'DISCONNECTED' WHERE id = 2");

        $connected = $this->repo->listWebsites(connectionStatus: 'CONNECTED');
        $this->assertCount(1, $connected);
        $this->assertSame(1, (int) $connected[0]['id']);

        $disconnected = $this->repo->listWebsites(connectionStatus: 'DISCONNECTED');
        $this->assertCount(1, $disconnected);
        $this->assertSame(2, (int) $disconnected[0]['id']);
    }

    public function testListWebsitesFiltersByClient(): void
    {
        $this->seedBasicPortfolio();

        $forClient2 = $this->repo->listWebsites(clientId: 2);

        $this->assertCount(1, $forClient2);
        $this->assertSame(2, (int) $forClient2[0]['id']);
    }

    public function testListWebsitesFiltersBySearch(): void
    {
        $this->seedBasicPortfolio();

        $this->assertCount(1, $this->repo->listWebsites(search: 'siteone'));
        $this->assertCount(0, $this->repo->listWebsites(search: 'nope'));
    }

    public function testListWebsitesReturnsEmptyArrayWithoutQueryingForTagsWhenNoneMatch(): void
    {
        $this->seedBasicPortfolio();

        $this->assertSame([], $this->repo->listWebsites(search: 'nothing-matches-this'));
    }

    public function testGetWebsiteFoundAndNotFound(): void
    {
        $this->seedBasicPortfolio();

        $website = $this->repo->getWebsite(1);
        $this->assertSame('https://siteone.test', $website['url']);
        $this->assertSame(['ecommerce', 'vip'], $website['tags']);
        $this->assertNull($this->repo->getWebsite(999));
    }

    public function testClientsForWebsite(): void
    {
        $this->seedBasicPortfolio();

        $clients = $this->repo->clientsForWebsite(1);

        $this->assertCount(1, $clients);
        $this->assertSame('a@example.com', $clients[0]['email']);
        $this->assertSame([], $this->repo->clientsForWebsite(999), 'a website with no subscriptions has no client');
    }

    public function testSubscriptionsForWebsiteJoinsClientAndProduct(): void
    {
        $this->seedBasicPortfolio();

        $subs = $this->repo->subscriptionsForWebsite(1);

        $this->assertCount(1, $subs);
        $this->assertSame('Acme Inc', $subs[0]['company']);
        $this->assertSame('WooCommerce Pro License', $subs[0]['product_name']);
    }

    public function testItemsForWebsiteGroupsByTypePluginThemeOther(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_website_items
            (id, website_id, type, name, slug, version, is_active, is_vulnerable, is_update_available, bv_date_sync, date_sync) VALUES
            (1, 1, 'plugin', 'WooCommerce', 'woocommerce', '8.0', 1, 0, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (2, 1, 'theme', 'Storefront', 'storefront', '4.0', 1, 0, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (3, 1, 'mu-plugin', 'Custom MU', 'custom-mu', '1.0', 1, 0, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (4, 2, 'plugin', 'Akismet', 'akismet', '5.0', 1, 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $items = $this->repo->itemsForWebsite(1);

        $this->assertCount(1, $items['plugin']);
        $this->assertSame('WooCommerce', $items['plugin'][0]['name']);
        $this->assertCount(1, $items['theme']);
        $this->assertCount(1, $items['other'], 'an unrecognised type is bucketed, not dropped');
        $this->assertSame('Custom MU', $items['other'][0]['name']);
    }

    public function testSearchTagsIsDistinctSortedAndFiltered(): void
    {
        $this->seedBasicPortfolio();

        $this->assertSame(['ecommerce', 'vip'], $this->repo->searchTags(''));
        $this->assertSame(['vip'], $this->repo->searchTags('vi'));
        $this->assertSame([], $this->repo->searchTags('nope'));
    }

    public function testSearchTagsRespectsLimit(): void
    {
        $this->seedBasicPortfolio();

        $this->assertCount(1, $this->repo->searchTags('', 1));
    }

    public function testSearchClientsMatchesCompanyNameOrEmail(): void
    {
        $this->seedBasicPortfolio();

        $byCompany = array_column($this->repo->searchClients('Acme'), 'label');
        $this->assertSame(['Acme Inc'], $byCompany);

        $byName = array_column($this->repo->searchClients('Builder'), 'label');
        $this->assertSame(['Bob Builder'], $byName);

        $byEmail = array_column($this->repo->searchClients('a@example.com'), 'label');
        $this->assertSame(['Acme Inc'], $byEmail);

        $this->assertSame([], $this->repo->searchClients('no-such-client'));
    }

    public function testSearchClientsWithEmptyQueryReturnsEveryone(): void
    {
        $this->seedBasicPortfolio();

        $this->assertCount(2, $this->repo->searchClients(''));
    }

    // ---- Subscription <-> website assignment ------------------------------

    public function testAssignableWebsitesExcludesDevTaggedSites(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_website_tags (website_id, tag, date_added, date_sync) VALUES
            (2, 'DEV', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $ids = array_column($this->repo->assignableWebsites(), 'id');

        $this->assertSame([1], $ids, 'site 2 is tagged DEV, excluded from assignment');
    }

    public function testSearchAssignableWebsitesFiltersByQueryAndExcludesDev(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_website_tags (website_id, tag, date_added, date_sync) VALUES
            (2, 'DEV', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $all = array_column($this->repo->searchAssignableWebsites(''), 'id');
        $this->assertSame([1], $all);

        $this->assertCount(1, $this->repo->searchAssignableWebsites('siteone'));
        $this->assertCount(0, $this->repo->searchAssignableWebsites('sitetwo'), 'sitetwo is site 2, tagged DEV');
        $this->assertCount(0, $this->repo->searchAssignableWebsites('nope'));
    }

    public function testSetSubscriptionWebsiteCreatesLinkForAnOrphanSubscription(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $this->assertTrue($this->repo->setSubscriptionWebsite(3000, 2));

        $subs = array_column($this->repo->subscriptionsForClient(1), null, 'id');
        $this->assertSame(2, (int) $subs[3000]['website_id']);
    }

    public function testSetSubscriptionWebsiteReplacesAnExistingLinkWithANewDateAdded(): void
    {
        $this->seedBasicPortfolio(); // subscription 1000 already linked to website 1

        $this->assertTrue($this->repo->setSubscriptionWebsite(1000, 2));

        $link = $this->pdo->query('SELECT * FROM swp_subscriptions_websites WHERE subscription_id = 1000')->fetch();
        $this->assertSame(2, (int) $link['website_id']);
        $this->assertCount(1, $this->pdo->query('SELECT * FROM swp_subscriptions_websites WHERE subscription_id = 1000')->fetchAll(), 'old link replaced, not duplicated');
    }

    public function testSetSubscriptionWebsiteCanClearTheLink(): void
    {
        $this->seedBasicPortfolio();

        $this->assertTrue($this->repo->setSubscriptionWebsite(1000, null));

        $link = $this->pdo->query('SELECT * FROM swp_subscriptions_websites WHERE subscription_id = 1000')->fetch();
        $this->assertFalse($link);
    }

    public function testSetSubscriptionWebsiteRejectsAnUnknownSubscription(): void
    {
        $this->seedBasicPortfolio();

        $this->assertFalse($this->repo->setSubscriptionWebsite(999999, 1));
    }

    public function testSetSubscriptionWebsiteRejectsAnUnknownWebsite(): void
    {
        $this->seedBasicPortfolio();

        $this->assertFalse($this->repo->setSubscriptionWebsite(1000, 999999));
        // The original link must survive a rejected change.
        $link = $this->pdo->query('SELECT * FROM swp_subscriptions_websites WHERE subscription_id = 1000')->fetch();
        $this->assertSame(1, (int) $link['website_id']);
    }

    public function testSetSubscriptionWebsiteRejectsADevTaggedWebsite(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_website_tags (website_id, tag, date_added, date_sync) VALUES
            (2, 'DEV', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $this->assertFalse($this->repo->setSubscriptionWebsite(1000, 2));
    }

    // ---- Products --------------------------------------------------------

    public function testListProductsDerivesTypeFromChildTableNotCategory(): void
    {
        $this->seedBasicPortfolio();

        $products = array_column($this->repo->listProducts(), null, 'auto_id');

        $this->assertSame('license', $products[10]['product_type']);
        $this->assertSame('woocommerce', $products[10]['license_slug']);
        $this->assertSame('maintenance_plan', $products[20]['product_type']);
        $this->assertSame(1, (int) $products[20]['is_licenses_included']);
        $this->assertSame('other', $products[30]['product_type'], 'no license/plan row -> other, regardless of category being null');
    }

    public function testListProductsFiltersByDerivedType(): void
    {
        $this->seedBasicPortfolio();

        $this->assertCount(1, $this->repo->listProducts(ClientsRepository::PRODUCT_TYPE_LICENSE));
        $this->assertCount(1, $this->repo->listProducts(ClientsRepository::PRODUCT_TYPE_MAINTENANCE_PLAN));
        $this->assertCount(1, $this->repo->listProducts(ClientsRepository::PRODUCT_TYPE_OTHER));
        $this->assertCount(3, $this->repo->listProducts(null));
    }

    // ---- Items search ------------------------------------------------------

    private function seedItemsForSearch(): void
    {
        $this->seedBasicPortfolio();
        $this->pdo->exec("INSERT INTO swp_website_items
            (id, website_id, type, name, slug, version, new_version, is_active, is_vulnerable, is_update_available, vulnerability_count, bv_date_sync, date_sync) VALUES
            (1, 1, 'plugin', 'WooCommerce', 'woocommerce', '8.0', NULL, 1, 0, 0, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (2, 1, 'theme', 'Storefront', 'storefront', '4.0', '4.1', 1, 0, 1, 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (3, 2, 'plugin', 'Akismet', 'akismet', '5.0', NULL, 1, 1, 0, 3, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    }

    public function testDistinctItemTypes(): void
    {
        $this->seedItemsForSearch();

        $this->assertSame(['plugin', 'theme'], $this->repo->distinctItemTypes());
    }

    public function testSearchItemsPaginatesAndReportsTotals(): void
    {
        $this->seedItemsForSearch();

        $page1 = $this->repo->searchItems([], 0, 2);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(3, $page1['filtered']);
        $this->assertCount(2, $page1['rows']);

        $page2 = $this->repo->searchItems([], 2, 2);
        $this->assertCount(1, $page2['rows']);
    }

    public function testSearchItemsFiltersByType(): void
    {
        $this->seedItemsForSearch();

        $result = $this->repo->searchItems(['type' => 'theme'], 0, 50);

        $this->assertSame(3, $result['total'], 'total is unfiltered');
        $this->assertSame(1, $result['filtered']);
        $this->assertSame('Storefront', $result['rows'][0]['name']);
    }

    public function testSearchItemsFiltersByVulnerableAndUpdateAvailable(): void
    {
        $this->seedItemsForSearch();

        $vulnerable = $this->repo->searchItems(['vulnerable' => true], 0, 50);
        $this->assertSame(1, $vulnerable['filtered']);
        $this->assertSame('Akismet', $vulnerable['rows'][0]['name']);

        $updateAvailable = $this->repo->searchItems(['updateAvailable' => true], 0, 50);
        $this->assertSame(1, $updateAvailable['filtered']);
        $this->assertSame('Storefront', $updateAvailable['rows'][0]['name']);
    }

    public function testSearchItemsFreeTextMatchesNameSlugOrWebsiteUrl(): void
    {
        $this->seedItemsForSearch();

        $this->assertSame(1, $this->repo->searchItems(['q' => 'akismet'], 0, 50)['filtered']);
        $this->assertSame(1, $this->repo->searchItems(['q' => 'storefront'], 0, 50)['filtered']);
        $this->assertSame(2, $this->repo->searchItems(['q' => 'siteone'], 0, 50)['filtered'], 'matches by website url too');
        $this->assertSame(0, $this->repo->searchItems(['q' => 'nothing-here'], 0, 50)['filtered']);
    }

    public function testSearchItemsIncludesWebsiteUrlOnEachRow(): void
    {
        $this->seedItemsForSearch();

        $result = $this->repo->searchItems(['type' => 'plugin', 'q' => 'akismet'], 0, 50);

        $this->assertSame('https://sitetwo.test', $result['rows'][0]['website_url']);
    }
}
