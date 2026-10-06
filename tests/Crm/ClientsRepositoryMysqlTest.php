<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Crm;

use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The whole ClientsRepository suite on a real MySQL, which parses literals
 * and GROUP BY more strictly than SQLite. Needs a disposable database:
 * SWPMGR_TEST_MYSQL_DSN (e.g. mysql:host=127.0.0.1;port=3306;dbname=crm_test),
 * SWPMGR_TEST_MYSQL_USER, SWPMGR_TEST_MYSQL_PASSWORD. Its tables are dropped.
 */
#[Group('mysql')]
final class ClientsRepositoryMysqlTest extends ClientsRepositoryTest
{
    private const array TABLES = [
        'swp_clients', 'swp_products', 'swp_licenses', 'swp_maintenance_plans', 'swp_subscriptions',
        'swp_subscriptions_websites', 'swp_websites', 'swp_website_items', 'swp_website_tags',
    ];

    protected function connect(): PDO
    {
        $dsn = (string) getenv('SWPMGR_TEST_MYSQL_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('SWPMGR_TEST_MYSQL_DSN is not set.');
        }
        $pdo = new PDO($dsn, (string) getenv('SWPMGR_TEST_MYSQL_USER'), (string) getenv('SWPMGR_TEST_MYSQL_PASSWORD'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('DROP TABLE IF EXISTS ' . implode(', ', self::TABLES));

        return $pdo;
    }
}
