<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Crm;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Crm\ClientsDb;

final class ClientsDbTest extends TestCase
{
    public function testUnconfiguredByDefault(): void
    {
        $db = ClientsDb::fromConfig([]);

        $this->assertFalse($db->isConfigured());
    }

    public function testMissingDatabaseIsStillUnconfigured(): void
    {
        $db = ClientsDb::fromConfig(['host' => 'db.example.com']);

        $this->assertFalse($db->isConfigured());
    }

    public function testMissingHostIsStillUnconfigured(): void
    {
        $db = ClientsDb::fromConfig(['database' => 'clients']);

        $this->assertFalse($db->isConfigured());
    }

    public function testConfiguredWhenHostAndDatabaseAreSet(): void
    {
        $db = ClientsDb::fromConfig(['host' => 'db.example.com', 'database' => 'clients']);

        $this->assertTrue($db->isConfigured());
    }

    public function testDsnBuildsFromConfigWithDefaults(): void
    {
        $db = ClientsDb::fromConfig(['host' => 'db.example.com', 'database' => 'clients']);

        $this->assertSame('mysql:host=db.example.com;port=3306;dbname=clients;charset=utf8mb4', $db->dsn());
    }

    public function testDsnHonoursPortAndCharsetOverrides(): void
    {
        $db = ClientsDb::fromConfig([
            'host' => 'db.example.com', 'database' => 'clients', 'port' => 3307, 'charset' => 'utf8',
        ]);

        $this->assertSame('mysql:host=db.example.com;port=3307;dbname=clients;charset=utf8', $db->dsn());
    }

    public function testDsnThrowsWhenUnconfigured(): void
    {
        $db = ClientsDb::fromConfig([]);

        $this->expectException(\RuntimeException::class);
        $db->dsn();
    }

    public function testEmptyStringHostOrDatabaseCountsAsUnset(): void
    {
        $db = ClientsDb::fromConfig(['host' => '', 'database' => '']);

        $this->assertFalse($db->isConfigured());
    }
}
