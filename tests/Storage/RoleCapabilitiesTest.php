<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Storage;

use SatelliteWP\Xtractor\Storage\RoleCapabilities;
use SatelliteWP\Xtractor\Tests\TestCase;

final class RoleCapabilitiesTest extends TestCase
{
    private function roles(array $map): RoleCapabilities
    {
        $file = $this->tmpDir . '/roles.php';
        file_put_contents($file, '<?php return ' . var_export($map, true) . ';');

        return RoleCapabilities::load($file);
    }

    public function testWildcardGrantsEveryCapability(): void
    {
        $roles = $this->roles(['admin' => ['*']]);

        $this->assertTrue($roles->can('admin', 'manage_users'));
        $this->assertTrue($roles->can('admin', 'anything_not_even_a_real_capability'));
    }

    public function testExplicitCapabilitiesOnly(): void
    {
        $roles = $this->roles(['sale' => ['view_catalog']]);

        $this->assertTrue($roles->can('sale', 'view_catalog'));
        $this->assertFalse($roles->can('sale', 'view_technical'));
        $this->assertFalse($roles->can('sale', 'manage_users'));
    }

    public function testUnknownRoleHasNoCapabilities(): void
    {
        $roles = $this->roles(['admin' => ['*']]);

        $this->assertFalse($roles->can('nobody', 'view_catalog'));
        $this->assertFalse($roles->knowsRole('nobody'));
        $this->assertTrue($roles->knowsRole('admin'));
    }

    public function testRolesListsKnownRoleNames(): void
    {
        $roles = $this->roles(['admin' => ['*'], 'sale' => ['view_catalog']]);

        $this->assertSame(['admin', 'sale'], $roles->roles());
    }

    public function testMissingFileYieldsNoRoles(): void
    {
        $roles = RoleCapabilities::load($this->tmpDir . '/nope.php');

        $this->assertSame([], $roles->roles());
        $this->assertFalse($roles->can('admin', 'anything'));
    }

    public function testTheRealConfigFileLoadsAndMatchesTheDesignedShape(): void
    {
        $roles = RoleCapabilities::load(dirname(__DIR__, 2) . '/config/roles.php');

        $this->assertSame(['admin', 'maintenance', 'coordinator', 'sale'], $roles->roles());
        $this->assertTrue($roles->can('admin', 'manage_users'));
        $this->assertTrue($roles->can('maintenance', 'view_technical'));
        $this->assertFalse($roles->can('maintenance', 'manage_users'));
        $this->assertTrue($roles->can('coordinator', 'view_technical'));
        $this->assertFalse($roles->can('coordinator', 'run_analysis'));
        $this->assertTrue($roles->can('sale', 'view_catalog'));
        $this->assertFalse($roles->can('sale', 'view_technical'));
    }
}
