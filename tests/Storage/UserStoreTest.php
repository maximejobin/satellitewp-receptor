<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Storage;

use SatelliteWP\Xtractor\Storage\UserStore;
use SatelliteWP\Xtractor\Tests\TestCase;

final class UserStoreTest extends TestCase
{
    private const array ROLES = ['admin', 'maintenance', 'coordinator', 'sale'];

    private function store(?array $seed = null): UserStore
    {
        $file = $this->tmpDir . '/users.json';
        if ($seed !== null) {
            file_put_contents($file, (string) json_encode($seed));
        }

        return new UserStore($file, self::ROLES);
    }

    public function testMissingFileIsEmptyNotAnError(): void
    {
        $store = $this->store();

        $this->assertTrue($store->isEmpty());
        $this->assertSame([], $store->all());
        $this->assertFalse($store->isAllowed('anyone@example.com'));
    }

    public function testLegacyFlatListFirstEntryBecomesAdminRestBecomeMaintenance(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertTrue($store->isAdmin('boss@example.com'));
        $this->assertFalse($store->isAdmin('dev@example.com'));
        $this->assertSame('admin', $store->roleOf('boss@example.com'));
        $this->assertSame('maintenance', $store->roleOf('dev@example.com'), 'kept the same access it already had');
        $this->assertTrue($store->isAllowed('dev@example.com'), 'upgraded record defaults to active');
    }

    public function testLegacyFileIsRewrittenInTheNewShapeOnNextSave(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);
        $store->add('new@example.com', 'sale');

        $raw = json_decode((string) file_get_contents($this->tmpDir . '/users.json'), true);
        $this->assertSame(
            ['email' => 'boss@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'active'],
            $raw[0]
        );
    }

    public function testTwoFieldFormatIsUpgradedWithDefaults(): void
    {
        $store = $this->store([
            ['email' => 'boss@example.com', 'role' => 'admin'],
            ['email' => 'rep@example.com', 'role' => 'sale'],
        ]);

        $this->assertSame('sale', $store->roleOf('rep@example.com'));
        $this->assertFalse($store->isAdmin('rep@example.com'));
        $this->assertTrue($store->isAllowed('rep@example.com'), 'a record with no status field defaults to active');
    }

    public function testCurrentFormatRoundTripsNameAndStatus(): void
    {
        $store = $this->store([
            ['email' => 'boss@example.com', 'role' => 'admin', 'first_name' => 'Ann', 'last_name' => 'Admin', 'status' => 'active'],
            ['email' => 'rep@example.com', 'role' => 'sale', 'first_name' => 'Rita', 'last_name' => 'Rep', 'status' => 'suspended'],
        ]);

        $rep = $store->all()[1];
        $this->assertSame('Rita', $rep['first_name']);
        $this->assertSame('Rep', $rep['last_name']);
        $this->assertSame('suspended', $rep['status']);
        $this->assertFalse($store->isAllowed('rep@example.com'));
        $this->assertTrue($store->exists('rep@example.com'), 'still a real record, just not allowed to sign in');
    }

    public function testMatchingIsCaseAndWhitespaceInsensitive(): void
    {
        $store = $this->store(['  Boss@Example.COM ']);

        $this->assertTrue($store->isAllowed('boss@example.com'));
        $this->assertTrue($store->isAllowed('BOSS@EXAMPLE.COM'));
        $this->assertTrue($store->isAdmin(' boss@example.com '));
    }

    public function testAddPersistsRejectsGarbageAndUnknownRoles(): void
    {
        $store = $this->store(['boss@example.com']);

        $this->assertTrue($store->add('New.Dev@example.com', 'maintenance', 'New', 'Dev'));
        $this->assertFalse($store->add('new.dev@example.com', 'maintenance'), 'duplicate');
        $this->assertFalse($store->add('not-an-email', 'maintenance'));
        $this->assertFalse($store->add(''));
        $this->assertFalse($store->add('third@example.com', 'wizard'), 'unknown role');

        // Re-read from disk: the write must have landed, lowercased.
        $reloaded = new UserStore($this->tmpDir . '/users.json', self::ROLES);
        $this->assertSame('maintenance', $reloaded->roleOf('new.dev@example.com'));
        $this->assertSame('New', $reloaded->all()[1]['first_name']);
    }

    public function testAddDefaultsToMaintenance(): void
    {
        $store = $this->store(['boss@example.com']);
        $store->add('dev@example.com');

        $this->assertSame('maintenance', $store->roleOf('dev@example.com'));
    }

    public function testLastActiveAdminCannotBeRemoved(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertFalse($store->remove('boss@example.com'), 'last admin is protected');
        $this->assertTrue($store->remove('dev@example.com'));
        $this->assertFalse($store->remove('dev@example.com'), 'already gone');
    }

    public function testAnAdminCanBeRemovedWhenAnotherActiveAdminRemains(): void
    {
        $store = $this->store([
            ['email' => 'a@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'active'],
            ['email' => 'b@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'active'],
        ]);

        $this->assertTrue($store->remove('a@example.com'));
        $this->assertTrue($store->isAdmin('b@example.com'));
    }

    public function testCannotRemoveTheLastAdminEvenWhenAnotherAdminRecordExistsButIsSuspended(): void
    {
        $store = $this->store([
            ['email' => 'a@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'active'],
            ['email' => 'b@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'suspended'],
        ]);

        $this->assertFalse($store->remove('a@example.com'), 'b exists but cannot sign in, so a is the only reachable admin');
    }

    public function testUpdateUserChangesNameRoleAndEmail(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertTrue($store->updateUser('dev@example.com', 'developer@example.com', 'Dev', 'Oper', 'coordinator'));

        $this->assertFalse($store->exists('dev@example.com'));
        $updated = $store->all()[1];
        $this->assertSame('developer@example.com', $updated['email']);
        $this->assertSame('Dev', $updated['first_name']);
        $this->assertSame('Oper', $updated['last_name']);
        $this->assertSame('coordinator', $updated['role']);
    }

    public function testUpdateUserRejectsAnEmailAlreadyTakenByAnotherUser(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertFalse($store->updateUser('dev@example.com', 'boss@example.com', '', '', 'maintenance'));
        $this->assertTrue($store->exists('dev@example.com'), 'the rejected rename left the original untouched');
    }

    public function testUpdateUserAllowsKeepingTheSameEmail(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertTrue($store->updateUser('dev@example.com', 'dev@example.com', 'D', 'E', 'sale'));
        $this->assertSame('sale', $store->roleOf('dev@example.com'));
    }

    public function testUpdateUserProtectsTheLastActiveAdmin(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertFalse($store->updateUser('boss@example.com', 'boss@example.com', '', '', 'maintenance'), 'would leave no admin');
        $this->assertFalse($store->updateUser('boss@example.com', 'boss@example.com', '', '', 'wizard'), 'unknown role');
        $this->assertFalse($store->updateUser('nobody@example.com', 'x@example.com', '', '', 'admin'), 'unknown address');
    }

    public function testSetStatusSuspendsAndReactivates(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertTrue($store->setStatus('dev@example.com', UserStore::STATUS_SUSPENDED));
        $this->assertFalse($store->isAllowed('dev@example.com'));
        $this->assertTrue($store->exists('dev@example.com'), 'suspended, not deleted');
        $this->assertSame('maintenance', $store->roleOf('dev@example.com'), 'role is untouched by suspension');

        $this->assertTrue($store->setStatus('dev@example.com', UserStore::STATUS_ACTIVE));
        $this->assertTrue($store->isAllowed('dev@example.com'));
    }

    public function testSetStatusRejectsUnknownEmailOrStatus(): void
    {
        $store = $this->store(['boss@example.com']);

        $this->assertFalse($store->setStatus('nobody@example.com', UserStore::STATUS_SUSPENDED));
        $this->assertFalse($store->setStatus('boss@example.com', 'on-vacation'));
    }

    public function testCannotSuspendTheLastActiveAdmin(): void
    {
        $store = $this->store(['boss@example.com', 'dev@example.com']);

        $this->assertFalse($store->setStatus('boss@example.com', UserStore::STATUS_SUSPENDED));
        $this->assertTrue($store->isAllowed('boss@example.com'));
    }

    public function testCanSuspendAnAdminWhenAnotherActiveAdminRemains(): void
    {
        $store = $this->store([
            ['email' => 'a@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'active'],
            ['email' => 'b@example.com', 'role' => 'admin', 'first_name' => '', 'last_name' => '', 'status' => 'active'],
        ]);

        $this->assertTrue($store->setStatus('a@example.com', UserStore::STATUS_SUSPENDED));
        $this->assertTrue($store->isAllowed('b@example.com'));
    }

    public function testUnknownRolesAreAcceptedWhenNoKnownRolesListIsGiven(): void
    {
        $store = new UserStore($this->tmpDir . '/users.json');

        $this->assertTrue($store->add('a@example.com', 'anything-goes'));
        $this->assertSame('anything-goes', $store->roleOf('a@example.com'));
    }

    public function testCorruptFileDoesNotLockOrCrash(): void
    {
        file_put_contents($this->tmpDir . '/users.json', 'not json at all');

        $this->assertSame([], (new UserStore($this->tmpDir . '/users.json'))->all());
    }
}
