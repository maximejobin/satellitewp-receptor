<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Storage;

use SatelliteWP\Xtractor\Storage\UserStore;
use SatelliteWP\Xtractor\Tests\TestCase;

final class UserStoreTest extends TestCase
{
    private function store(?array $seed = null): UserStore
    {
        $file = $this->tmpDir . '/users.json';
        if ($seed !== null) {
            file_put_contents($file, (string) json_encode($seed));
        }

        return new UserStore($file);
    }

    public function testMissingFileIsEmptyNotAnError(): void
    {
        $store = $this->store();

        $this->assertTrue($store->isEmpty());
        $this->assertSame([], $store->all());
        $this->assertNull($store->admin());
        $this->assertFalse($store->isAllowed('anyone@example.com'));
    }

    public function testFirstEntryIsTheAdmin(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertSame('boss@example.com', $store->admin());
        $this->assertTrue($store->isAdmin('boss@example.com'));
        $this->assertFalse($store->isAdmin('dev@example.com'));
    }

    public function testMatchingIsCaseAndWhitespaceInsensitive(): void
    {
        $store = $this->store(['  Boss@Example.COM ']);

        $this->assertTrue($store->isAllowed('boss@example.com'));
        $this->assertTrue($store->isAllowed('BOSS@EXAMPLE.COM'));
        $this->assertTrue($store->isAdmin(' boss@example.com '));
    }

    public function testAddPersistsAndRejectsGarbage(): void
    {
        $store = $this->store(['boss@example.com']);

        $this->assertTrue($store->add('New.Dev@example.com'));
        $this->assertFalse($store->add('new.dev@example.com'), 'duplicate');
        $this->assertFalse($store->add('not-an-email'));
        $this->assertFalse($store->add(''));

        // Re-read from disk: the write must have landed, lowercased.
        $reloaded = new UserStore($this->tmpDir . '/users.json');
        $this->assertSame(['boss@example.com', 'new.dev@example.com'], $reloaded->all());
    }

    public function testAdminCannotBeRemoved(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertFalse($store->remove('boss@example.com'), 'admin is protected');
        $this->assertTrue($store->remove('dev@example.com'));
        $this->assertFalse($store->remove('dev@example.com'), 'already gone');
        $this->assertSame(['boss@example.com'], $store->all());
    }

    public function testAdminStaysFirstAfterChurn(): void
    {
        $store = $this->store(['boss@example.com']);
        $store->add('a@example.com');
        $store->add('b@example.com');
        $store->remove('a@example.com');

        $this->assertSame('boss@example.com', $store->admin());
        $this->assertSame(['boss@example.com', 'b@example.com'], $store->all());
    }

    public function testCorruptFileDoesNotLockOrCrash(): void
    {
        file_put_contents($this->tmpDir . '/users.json', 'not json at all');

        $this->assertSame([], (new UserStore($this->tmpDir . '/users.json'))->all());
    }
}
