<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use ReflectionProperty;
use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Config;
use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Tests\Crm\ClientsRepositoryTest;
use SatelliteWP\Manager\Tests\Crm\PortableSqlPdo;
use SatelliteWP\Manager\Tests\TestCase;

final class ExternalWebsiteRouteTest extends TestCase
{
    private const string CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private PortableSqlPdo $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE  = ['swp_csrf' => self::CSRF];
        $_POST    = ['_csrf' => self::CSRF];
        $_GET     = [];
        $_SESSION = [];

        $this->pdo = new PortableSqlPdo();
        $this->pdo->exec(ClientsRepositoryTest::SCHEMA);
        $this->pdo->exec("INSERT INTO swp_clients (id, email, company, date_sync) VALUES (1, 'a@example.com', 'Acme Inc', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_products (auto_id, id, name, date_sync) VALUES (30, 300, 'Shop hosting', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_websites (id, url, url_standard, blogvault_site_id, created, updated, date_sync) VALUES
            (1, 'https://www.example.com', 'example.com', 'bv-1', '2026-01-01', '2026-01-01', '2026-01-01')");
        $this->pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO swp_subscriptions_websites (subscription_id, website_id, date_added, date_updated) VALUES
            (3000, 1, '2026-01-01', '2026-01-01')");
    }

    protected function tearDown(): void
    {
        $_COOKIE = $_POST = $_GET = $_SESSION = [];
        parent::tearDown();
    }

    public function testLinkingToAnExternalWebsiteMovesTheSubscriptionOutOfTheCrmAndBack(): void
    {
        $app = $this->app();
        $id  = $app->externalWebsites()->add('https://shop.example.org', [], '');

        $response = $this->post($app, '/subscriptions', ['subscription_id' => '3000', 'website_id' => 'x' . $id, 'return' => '/clients/1']);

        $this->assertSame('/clients/1?notice=website-updated', $response->location());
        $this->assertSame([3000 => $id], $app->externalWebsites()->links());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM swp_subscriptions_websites')->fetchColumn());
        $this->assertSame(0, $app->crmRepository()?->countOrphanSubscriptions(array_keys($app->externalWebsites()->links())));

        $this->post($app, '/subscriptions', ['subscription_id' => '3000', 'website_id' => '1', 'return' => '/clients/1']);

        $this->assertSame([], $app->externalWebsites()->links());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM swp_subscriptions_websites')->fetchColumn());
    }

    public function testAnUnknownExternalWebsiteLeavesTheCrmLinkAlone(): void
    {
        $app      = $this->app();
        $response = $this->post($app, '/subscriptions', ['subscription_id' => '3000', 'website_id' => 'x9', 'return' => '/clients/1']);

        $this->assertSame('/clients/1?notice=website-update-failed', $response->location());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM swp_subscriptions_websites')->fetchColumn());
    }

    public function testAddingRefusesAUrlTheCrmAlreadyHas(): void
    {
        $app = $this->app();

        $response = $this->post($app, '/websites/external', ['url' => 'http://example.com/']);
        $this->assertSame('/websites?notice=external-duplicate', $response->location());

        $response = $this->post($app, '/websites/external', ['url' => 'https://shop.example.org']);
        $this->assertSame('/websites/x1?notice=external-added', $response->location());
    }

    public function testALinkedExternalWebsiteIsNotDeleted(): void
    {
        $app = $this->app();
        $id  = $app->externalWebsites()->add('https://shop.example.org', [], '');
        $app->externalWebsites()->link(3000, $id);

        $response = $this->post($app, '/websites/x' . $id . '/delete', []);

        $this->assertSame('/websites/x' . $id . '?notice=external-linked', $response->location());
        $this->assertNotNull($app->externalWebsites()->get($id));
    }

    public function testTheCoordinatorManagesExternalWebsitesAndTheSaleRoleDoesNot(): void
    {
        $app = $this->app(['auth' => ['open_mode' => false, 'google' => ['client_id' => 'id', 'client_secret' => 'secret']]]);
        $app->userStore()->add('admin@example.com', 'admin');
        $app->userStore()->add('coordinator@example.com', 'coordinator');
        $app->userStore()->add('sale@example.com', 'sale');

        $_SESSION['user_email'] = 'sale@example.com';
        $this->assertSame(403, $this->post($app, '/websites/external', ['url' => 'https://shop.example.org'])->status);
        $this->assertSame(403, $this->post($app, '/subscriptions', ['subscription_id' => '3000', 'website_id' => ''])->status);

        $_SESSION['user_email'] = 'coordinator@example.com';
        $this->assertSame(303, $this->post($app, '/websites/external', ['url' => 'https://shop.example.org'])->status);
        $this->assertSame('coordinator@example.com', $app->externalWebsites()->get(1)['created_by'] ?? null);
    }

    public function testTheWebsitesListAndTheExternalPageShowTheExternalWebsite(): void
    {
        $app = $this->app();
        $id  = $app->externalWebsites()->add('https://shop.example.org', [], '');
        $app->externalWebsites()->link(3000, $id);

        $list = $this->get($app, '/websites');
        $this->assertStringContainsString('<a href="/websites/x1">shop.example.org</a>', $list);
        $this->assertStringContainsString('<span class="badge badge-muted">External</span>', $list);

        $_GET = ['source' => 'wordpress'];
        $this->assertStringNotContainsString('shop.example.org', $this->get($app, '/websites'));
        $_GET = [];

        $page = $this->get($app, '/websites/x1');
        $this->assertStringContainsString('Non-WordPress', $page);
        $this->assertStringContainsString('Acme Inc', $page);
        $this->assertStringContainsString('Shop hosting', $page);
    }

    /** @param array<string, string> $fields */
    private function post(App $app, string $path, array $fields): RecordingResponse
    {
        $_POST    = ['_csrf' => self::CSRF] + $fields;
        $response = new RecordingResponse();

        ob_start();
        (new Router($app, $response))->handlePost($path);
        ob_end_clean();

        return $response;
    }

    private function get(App $app, string $path): string
    {
        $response = new RecordingResponse();
        ob_start();
        (new Router($app, $response))->dispatch($path);

        return $response->body . (string) ob_get_clean();
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        $defaults = require dirname(__DIR__, 2) . '/config/config.php';
        $app      = new App(new Config(array_replace_recursive($defaults, [
            'data_dir' => $this->tmpDir,
            'auth'     => ['users_file' => $this->tmpDir . '/users.json', 'open_mode' => true],
            'crm_db'   => ['host' => 'crm.example.com', 'database' => 'crm'],
        ], $overrides)));

        // crm_db only marks the CRM configured; the repository runs on the seeded SQLite.
        $services = new ReflectionProperty(App::class, 'services');
        $services->setValue($app, [ClientsRepository::class => new ClientsRepository($this->pdo)] + $services->getValue($app));

        return $app;
    }
}
