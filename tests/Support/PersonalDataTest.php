<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Support\PersonalData;

final class PersonalDataTest extends TestCase
{
    public function testEmailsLoginsAndClientNamesAreMaskedEverywhere(): void
    {
        $payload = [
            'website_administrator_email' => 'jane.doe@example.com',
            'administrators' => [
                ['id' => 1, 'login' => 'JaneDoe', 'email' => 'jane.doe@example.com'],
                ['id' => 2, 'login' => 'admin', 'email' => 'ops@example.org'],
                // A login equal to a role name must not rename the role elsewhere.
                ['id' => 3, 'login' => 'administrator', 'email' => 'x@example.net'],
            ],
            'users_count' => ['avail_roles' => ['administrator' => 3]],
            'registration' => ['default_role' => 'administrator'],
        ];
        $probes = [
            'blogvault' => ['data' => ['users' => ['items' => [['username' => 'janedoe', 'role' => 'administrator']]]]],
            'crm'       => ['data' => ['clients' => [['id' => 7, 'label' => 'Jane Doe', 'first_name' => 'Jane', 'last_name' => 'Doe', 'company' => '']]]],
        ];

        $mask    = new PersonalData($payload, $probes);
        $payload = $mask->payload($payload);
        $probes  = $mask->probes($probes);

        $this->assertSame('redacted@example.com', $payload['website_administrator_email']);
        $this->assertSame(['user-1', 'admin', 'user-2'], array_column($payload['administrators'], 'login'));
        $this->assertSame('user-1', $probes['blogvault']['data']['users']['items'][0]['username'], 'same person, same alias');
        $this->assertSame('administrator', $payload['registration']['default_role']);
        $this->assertSame('administrator', $probes['blogvault']['data']['users']['items'][0]['role']);
        $this->assertSame(
            ['id' => 7, 'label' => PersonalData::MASK, 'first_name' => PersonalData::MASK, 'last_name' => PersonalData::MASK, 'company' => ''],
            $probes['crm']['data']['clients'][0]
        );
        $this->assertSame('Write to redacted@example.org today.', $mask->text('Write to ops@example.org today.'));
    }
}
