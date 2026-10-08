<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Web\StatusChecks;

final class StatusChecksTest extends TestCase
{
    /** @param array<string, mixed>|null $crm */
    private static function collected(bool $stale = false, ?array $crm = null): array
    {
        return ['syncSources' => ['wordfence intelligence' => ['synced_at' => null, 'threshold_seconds' => 1, 'stale' => $stale]], 'crm' => $crm];
    }

    public function testAllClearRaisesNoAlert(): void
    {
        $this->assertFalse(StatusChecks::needsAttention(self::collected()));
        $this->assertFalse(StatusChecks::needsAttention(self::collected(crm: [
            'activeEmptyCompanyClients' => [], 'activeNoHubspotClients' => [], 'activeNoTeamworkClients' => [], 'orphanSubscriptions' => 0,
        ])));
    }

    public function testUnpaidOrInactiveClientsAreNotAnAlert(): void
    {
        $this->assertFalse(StatusChecks::needsAttention(self::collected(crm: [
            'unpaidClients' => [['id' => 1]], 'emptyCompanyClients' => [['id' => 2]], 'orphanSubscriptions' => 0,
        ])));
    }

    public function testEachWatchedProblemRaisesTheAlert(): void
    {
        $this->assertTrue(StatusChecks::needsAttention(self::collected(stale: true)));
        $this->assertTrue(StatusChecks::needsAttention(self::collected(crm: ['error' => 'abc123'])));
        $this->assertTrue(StatusChecks::needsAttention(self::collected(crm: ['activeEmptyCompanyClients' => [['id' => 1]]])));
        $this->assertTrue(StatusChecks::needsAttention(self::collected(crm: ['activeNoHubspotClients' => [['id' => 1]]])));
        $this->assertTrue(StatusChecks::needsAttention(self::collected(crm: ['activeNoTeamworkClients' => [['id' => 1]]])));
        $this->assertTrue(StatusChecks::needsAttention(self::collected(crm: ['orphanSubscriptions' => 2])));
    }
}
