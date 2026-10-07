<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Storage;

use SatelliteWP\Manager\Storage\KeyStore;
use SatelliteWP\Manager\Tests\TestCase;

/**
 * KeyStore::getHttpAuth()/setHttpAuth() — the per-site HTTP Basic Auth
 * credentials HttpProbe sends when a site is paired behind Basic Auth.
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

    public function testRotatingAKeyKeepsOriginAndHttpAuth(): void
    {
        $keys = $this->store();
        $keys->addKey(self::SITE_ID, 'old', 'example.com');
        $keys->setHttpAuth(self::SITE_ID, 'u', 'p');

        $keys->addKey(self::SITE_ID, 'new');

        $this->assertSame('new', $keys->getKey(self::SITE_ID));
        $this->assertSame('example.com', $keys->getOrigin(self::SITE_ID));
        $this->assertSame(['username' => 'u', 'password' => 'p'], $keys->getHttpAuth(self::SITE_ID));
    }

    public function testRotatingWithAnExplicitOriginReplacesIt(): void
    {
        $keys = $this->store();
        $keys->addKey(self::SITE_ID, 'old', 'example.com');

        $keys->addKey(self::SITE_ID, 'new', 'moved.example');

        $this->assertSame('moved.example', $keys->getOrigin(self::SITE_ID));
    }

    public function testAnUnencodableWriteKeepsEveryExistingKey(): void
    {
        $file = $this->tmpDir . '/keys.json';
        $keys = $this->store();
        $keys->addKey(self::SITE_ID, 'secret', 'https://example.com');
        $before = (string) file_get_contents($file);

        try {
            $keys->setHttpAuth(self::SITE_ID, "user\xB1", 'pass');
            $this->fail('an invalid UTF-8 value must not be written');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, file_get_contents($file));
        $this->assertSame('secret', $keys->getKey(self::SITE_ID));
    }

    public function testKeysFileIsOwnerOnlyAndWritersReReadIt(): void
    {
        $file = $this->tmpDir . '/keys.json';
        $a    = new KeyStore($file);
        $b    = new KeyStore($file);

        $a->addKey(self::SITE_ID, 'one');
        $b->addKey('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'two');
        $a->revokeKey('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

        $this->assertSame('600', substr(sprintf('%o', fileperms($file)), -3));
        $this->assertSame([], glob($file . '.tmp*') ?: []);
        $this->assertSame('one', $b->getKey(self::SITE_ID));
        $this->assertNull($b->getKey('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
    }
}
