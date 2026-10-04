<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use PDO;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Probe\CrmProbe;

final class CrmProbeTest extends TestCase
{
    private const string BV_ID = '31a5938dccb4db96c34f13abd9c07b62';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE swp_clients (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, company TEXT);
            CREATE TABLE swp_products (auto_id INTEGER PRIMARY KEY, id INTEGER, name TEXT, category TEXT);
            CREATE TABLE swp_licenses (auto_id INTEGER PRIMARY KEY, slug TEXT);
            CREATE TABLE swp_maintenance_plans (auto_id INTEGER PRIMARY KEY);
            CREATE TABLE swp_subscriptions (
                id INTEGER PRIMARY KEY, client_id INTEGER, product_id INTEGER, subscription_status TEXT,
                creation_date TEXT, next_renewal_date TEXT
            );
            CREATE TABLE swp_subscriptions_websites (subscription_id INTEGER, website_id INTEGER);
            CREATE TABLE swp_websites (id INTEGER PRIMARY KEY, url TEXT, blogvault_site_id TEXT, date_sync TEXT);
            INSERT INTO swp_clients VALUES (1, 'a@example.com', 'Ann', 'Admin', 'Acme Inc');
            INSERT INTO swp_products VALUES (10, 100, 'Care Gold', 'plan'), (11, 101, 'Care Silver', 'plan'), (12, 102, 'Pro License', 'plugin');
            INSERT INTO swp_licenses VALUES (12, 'pro');
            INSERT INTO swp_maintenance_plans VALUES (10), (11);
            INSERT INTO swp_websites VALUES (1, 'https://example.com', '31A5938DCCB4DB96C34F13ABD9C07B62', '2026-01-01 00:00:00');
            SQL);
    }

    private function link(int $subscription, int $product, string $status): void
    {
        $this->pdo->exec("INSERT INTO swp_subscriptions VALUES ({$subscription}, 1, {$product}, '{$status}', '2026-01-01', '2027-01-01')");
        $this->pdo->exec("INSERT INTO swp_subscriptions_websites VALUES ({$subscription}, 1)");
    }

    private function runProbe(?string $blogvaultId): ProbeResult
    {
        $probe = new CrmProbe(fn (): ClientsRepository => new ClientsRepository($this->pdo));
        $site  = (new SiteContext('s', 'https://example.com', 'https://example.com', 'example.com', 'example.com'))
            ->withBlogvaultSiteId($blogvaultId);

        return $probe->run($site);
    }

    public function testLinksBlogvaultIdToClientAndActiveMaintenancePlan(): void
    {
        $this->link(1000, 100, 'active');
        $this->link(1001, 102, 'active');

        $result = $this->runProbe(self::BV_ID);

        self::assertSame(ProbeResult::STATUS_OK, $result->status);
        self::assertTrue($result->data['linked']);
        self::assertSame('Acme Inc', $result->data['clients'][0]['label']);
        self::assertSame(['subscription_id' => 1000, 'name' => 'Care Gold', 'next_renewal' => '2027-01-01'], $result->data['maintenance_plan']);
        self::assertSame(['maintenance_plan', 'license'], array_column($result->data['subscriptions'], 'kind'));
        self::assertArrayNotHasKey('email', $result->data['clients'][0]);
    }

    public function testInactiveOrAmbiguousPlanIsNotGuessed(): void
    {
        $this->link(1000, 100, 'on-hold');
        self::assertNull($this->runProbe(self::BV_ID)->data['maintenance_plan']);

        $this->link(1001, 100, 'active');
        $this->link(1002, 101, 'active');
        $data = $this->runProbe(self::BV_ID)->data;
        self::assertNull($data['maintenance_plan']);
        self::assertCount(3, $data['subscriptions']);
    }

    public function testUnlinkedReasons(): void
    {
        self::assertSame('no_blogvault_id', $this->runProbe(null)->data['reason']);
        self::assertSame('website_not_found', $this->runProbe('ffffffffffffffffffffffffffffffff')->data['reason']);

        $data = $this->runProbe(self::BV_ID)->data;
        self::assertFalse($data['linked']);
        self::assertSame('no_client', $data['reason']);
        self::assertSame('https://example.com', $data['website']['url']);
    }

    public function testTwoWebsitesForOneIdAreNotGuessed(): void
    {
        $this->pdo->exec("INSERT INTO swp_websites VALUES (2, 'https://other.example.com', '31a5938dccb4db96c34f13abd9c07b62', NULL)");

        self::assertSame('website_ambiguous', $this->runProbe(self::BV_ID)->data['reason']);
    }

    public function testUnconfiguredCrmIsAnErrorNotAnUnlinkedSite(): void
    {
        $site   = (new SiteContext('s', 'https://example.com', 'https://example.com', 'example.com', 'example.com'))->withBlogvaultSiteId(self::BV_ID);
        $result = (new CrmProbe(fn () => null))->run($site);

        self::assertSame(ProbeResult::STATUS_ERROR, $result->status);
    }

    public function testDatabaseFailureStoresNoDriverMessage(): void
    {
        $this->pdo->exec('DROP TABLE swp_websites');
        $result = $this->runProbe(self::BV_ID);

        self::assertSame(ProbeResult::STATUS_ERROR, $result->status);
        self::assertStringStartsWith('CRM database query failed (SQLSTATE', $result->errors[0]);
        self::assertStringNotContainsString('swp_websites', $result->errors[0]);
    }
}
