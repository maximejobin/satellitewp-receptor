<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Config;
use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Tests\Crm\ClientsRepositoryTest;
use SatelliteWP\Manager\Tests\Crm\PortableSqlPdo;
use SatelliteWP\Manager\Tests\TestCase;

/** The UI offers a role only the pages and controls its capabilities let it use. */
final class RoleUiTest extends TestCase
{
    private const string SITE = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = $_POST = $_COOKIE = $_SESSION = [];

        $pdo = new PortableSqlPdo();
        $pdo->exec(ClientsRepositoryTest::SCHEMA);
        $pdo->exec("INSERT INTO swp_clients (id, email, company, date_sync) VALUES (1, 'a@example.com', 'Acme Inc', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO swp_products (auto_id, id, name, date_sync) VALUES (30, 300, 'Care plan', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO swp_subscriptions (id, client_id, product_id, subscription_status, creation_date, last_payment_date, date_sync) VALUES
            (3000, 1, 300, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $defaults  = require dirname(__DIR__, 2) . '/config/config.php';
        $this->app = new App(new Config(array_replace_recursive($defaults, [
            'data_dir' => $this->tmpDir,
            'auth'     => ['users_file' => $this->tmpDir . '/users.json', 'google' => ['client_id' => 'id', 'client_secret' => 'secret']],
            'crm_db'   => ['host' => 'crm.example.com', 'database' => 'crm'],
        ])));
        // crm_db only marks the CRM configured; the repository runs on the seeded SQLite.
        $services = new ReflectionProperty(App::class, 'services');
        $services->setValue($this->app, [ClientsRepository::class => new ClientsRepository($pdo)] + $services->getValue($this->app));

        foreach (['admin', 'maintenance', 'coordinator', 'sale'] as $role) {
            $this->app->userStore()->add($role . '@example.com', $role);
        }
        $this->app->keyStore()->addKey(self::SITE, str_repeat('ab', 32), 'https://www.example.com');
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_COOKIE = $_SESSION = [];
        parent::tearDown();
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> */
    public static function menus(): iterable
    {
        yield 'admin' => ['admin', ['/status', '/clients', '/extractions', '/catalog', '/data/', '/users', '/styleguide'], []];
        yield 'maintenance' => ['maintenance', ['/status', '/clients', '/extractions', '/catalog', '/data/'], ['href="/users"', '/styleguide']];
        yield 'coordinator' => ['coordinator', ['/status', '/clients', '/extractions', '/catalog', '/data/'], ['href="/users"', '/styleguide']];
        yield 'sale' => ['sale', ['/clients', '/websites', '/items', '/products', '/catalog'], ['/status', '/extractions', '/data/', 'href="/users"', '/styleguide']];
    }

    /**
     * @param list<string> $shown
     * @param list<string> $hidden
     */
    #[Test]
    #[DataProvider('menus')]
    public function theMenuListsOnlyThePagesTheRoleCanOpen(string $role, array $shown, array $hidden): void
    {
        $menu = $this->menuOf($this->get($role, '/profile'));

        foreach ($shown as $link) {
            $this->assertStringContainsString($link, $menu, "{$role} sees {$link}");
        }
        foreach ($hidden as $link) {
            $this->assertStringNotContainsString($link, $menu, "{$role} does not see {$link}");
        }
    }

    #[Test]
    public function theStyleGuideIsForAdminsOnly(): void
    {
        $this->assertSame(200, $this->statusOf('admin', '/styleguide'));
        $this->assertSame(403, $this->statusOf('maintenance', '/styleguide'));
    }

    #[Test]
    public function everyRoleCanLinkASubscriptionButOnlyEditorsManageExternalWebsites(): void
    {
        foreach (['admin', 'maintenance', 'coordinator', 'sale'] as $role) {
            $this->assertStringContainsString('wf-edit-btn', $this->get($role, '/clients/1'), $role);
        }

        $this->assertStringNotContainsString('Add external website', $this->get('sale', '/websites'));
        $this->assertStringContainsString('Add external website', $this->get('coordinator', '/websites'));
    }

    #[Test]
    public function theCoordinatorSeesTheKeyStatusButNoKeyNorAnyKeyAction(): void
    {
        $page = $this->get('coordinator', '/site/' . self::SITE);
        $this->assertStringContainsString('badge-ok">active', $page);
        $this->assertStringContainsString('not configured', $page);
        foreach (['ababab…', 'Rebind', 'Revoke', 'Create new key', 'http_auth_password'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $page, $hidden);
        }
        $this->assertStringNotContainsString('Pair a new site', $this->get('coordinator', '/extractions'));

        $page = $this->get('maintenance', '/site/' . self::SITE);
        foreach (['ababab…', 'Rebind', 'Revoke', 'Create new key', 'http_auth_password'] as $shown) {
            $this->assertStringContainsString($shown, $page, $shown);
        }
        $this->assertStringContainsString('Pair a new site', $this->get('maintenance', '/extractions'));
    }

    #[Test]
    public function onlyARoleThatRunsAnalysesSeesRetry(): void
    {
        $path = '/site/' . self::SITE . '/extraction/' . $this->storeExtraction('error');

        $this->assertStringNotContainsString('Retry analysis', $this->get('coordinator', $path));
        $this->assertStringContainsString('Retry analysis', $this->get('maintenance', $path));
    }

    #[Test]
    public function aRoleThatCannotEditLicencesReadsThemAsText(): void
    {
        $path = '/site/' . self::SITE . '/extraction/' . $this->storeExtraction('done');

        $this->assertStringNotContainsString('/licenses" class="lic-form"', $this->get('coordinator', $path));
        $this->assertStringContainsString('lic-value lic-n_a', $this->get('coordinator', $path));
        $this->assertStringContainsString('/licenses" class="lic-form"', $this->get('maintenance', $path));
    }

    private function storeExtraction(string $status): string
    {
        $id = $this->app->dataStore()->storeExtraction(self::SITE, $this->fixture('extraction-valid.json'), [
            'received_at' => '2026-07-22T14:30:00Z',
        ]);
        $this->app->index()->insertExtraction(self::SITE, $id, '2026-07-22T14:30:00Z', [], $status);

        return $id;
    }

    private function menuOf(string $page): string
    {
        $start = strpos($page, '<aside class="side"');
        $this->assertNotFalse($start);

        return substr($page, $start, (int) strpos($page, '</aside>', $start) - $start);
    }

    private function statusOf(string $role, string $path): int
    {
        $_SESSION['user_email'] = $role . '@example.com';
        $response = new RecordingResponse();
        ob_start();
        (new Router($this->app, $response))->dispatch($path);
        ob_end_clean();

        return $response->status;
    }

    private function get(string $role, string $path): string
    {
        $_SESSION['user_email'] = $role . '@example.com';
        $response = new RecordingResponse();
        ob_start();
        (new Router($this->app, $response))->dispatch($path);

        return $response->body . (string) ob_get_clean();
    }
}
