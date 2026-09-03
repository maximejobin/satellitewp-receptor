<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Storage;

use SatelliteWP\Xtractor\Storage\KeyStore;
use SatelliteWP\Xtractor\Tests\TestCase;

/**
 * KeyStore::getHttpAuth()/setHttpAuth() — the per-site HTTP Basic Auth
 * credentials HttpProbe sends when a site is paired behind Basic Auth
 * (2026-08-30, user: "ça doit être paramétrable au niveau du site").
 */
final class KeyStoreTest extends TestCase
{
    private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

    private function store(): KeyStore
    {
        return new KeyStore($this->tmpDir . '/keys.json');
    }

    public function testNoHttpAuthByDefault(): void
    {
        $keys = $this->store();
        $keys->addKey(self::SITE_ID, 'secret');

        $this->assertNull($keys->getHttpAuth(self::SITE_ID));
    }

    public function testSetHttpAuthIsReadBack(): void
    {
        $keys = $this->store();
        $keys->addKey(self::SITE_ID, 'secret');

        $ok = $keys->setHttpAuth(self::SITE_ID, 'staging-user', 'staging-pass');

        $this->assertTrue($ok);
        $this->assertSame(['username' => 'staging-user', 'password' => 'staging-pass'], $keys->getHttpAuth(self::SITE_ID));
    }

    public function testSetHttpAuthFailsForAnUnknownSite(): void
    {
        $keys = $this->store();

        $this->assertFalse($keys->setHttpAuth(self::SITE_ID, 'user', 'pass'));
    }

    public function testClearingHttpAuthWithAnEmptyUsernameRemovesIt(): void
    {
        $keys = $this->store();
        $keys->addKey(self::SITE_ID, 'secret');
        $keys->setHttpAuth(self::SITE_ID, 'staging-user', 'staging-pass');

        $keys->setHttpAuth(self::SITE_ID, '', null);

        $this->assertNull($keys->getHttpAuth(self::SITE_ID));
    }

}
