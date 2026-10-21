<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Support\PersonalData;

final class PersonalDataTest extends TestCase
{
    public function testEmailsAndLoginsAreOmittedNotReplaced(): void
    {
        $payload = [
            'website_administrator_email' => 'jane.doe@example.com',
            'administrators' => [
                ['id' => 1, 'login' => 'JaneDoe', 'email' => 'jane.doe@example.com'],
                ['id' => 2, 'login' => 'admin', 'email' => 'ops@example.org'],
            ],
            'mail'         => ['test' => ['recipients' => ['a@example.com', 'b@example.com']]],
            'registration' => ['default_role' => 'administrator'],
        ];
        $probes = [
            'blogvault' => ['data' => ['users' => ['items' => [['username' => 'janedoe', 'role' => 'administrator']]]]],
        ];

        $mask    = new PersonalData();
        $payload = $mask->payload($payload);
        $probes  = $mask->probes($probes);

        $this->assertArrayNotHasKey('website_administrator_email', $payload);
        $this->assertSame([['id' => 1], ['id' => 2, 'login' => 'admin']], $payload['administrators'], '"admin" names no one and is a finding');
        $this->assertSame([], $payload['mail']['test']['recipients']);
        $this->assertSame('administrator', $payload['registration']['default_role']);
        $this->assertSame([['role' => 'administrator']], $probes['blogvault']['data']['users']['items']);
        $this->assertSame('Write to today.', $mask->text('Write to ops@example.org today.'));
        $this->assertSame('No address here.', $mask->text('No address here.'));
    }
}
