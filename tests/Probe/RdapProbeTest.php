<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Probe\RdapProbe;

final class RdapProbeTest extends TestCase
{
    public function testParseRdapResponse(): void
    {
        $data = RdapProbe::parseRdapResponse([
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '2010-05-01T00:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2027-05-01T00:00:00Z'],
            ],
            'entities' => [
                [
                    'roles'      => ['registrar'],
                    'vcardArray' => ['vcard', [
                        ['version', new \stdClass(), 'text', '4.0'],
                        ['fn', new \stdClass(), 'text', 'Example Registrar Inc.'],
                    ]],
                ],
            ],
            'status'      => ['client transfer prohibited'],
            'nameservers' => [
                ['ldhName' => 'NS1.EXAMPLE.NET'],
                ['ldhName' => 'ns2.example.net'],
            ],
        ]);

        $this->assertSame('rdap', $data['source']);
        $this->assertSame('Example Registrar Inc.', $data['registrar']);
        $this->assertSame('2010-05-01T00:00:00Z', $data['created_at']);
        $this->assertSame('2027-05-01T00:00:00Z', $data['expires_at']);
        $this->assertGreaterThan(200, $data['days_to_expiry']);
        $this->assertSame(['client transfer prohibited'], $data['statuses']);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $data['nameservers']);
    }

    public function testParseWhoisText(): void
    {
        $raw = <<<TXT
            Domain Name: EXAMPLE.COM
               Registrar: Example Registrar, LLC
               Creation Date: 2010-05-01T00:00:00Z
               Registry Expiry Date: 2027-05-01T00:00:00Z
               Domain Status: clientTransferProhibited https://icann.org/epp
               Name Server: NS1.EXAMPLE.NET
               Name Server: NS2.EXAMPLE.NET
            TXT;

        $data = RdapProbe::parseWhoisText($raw);

        $this->assertSame('whois', $data['source']);
        $this->assertSame('Example Registrar, LLC', $data['registrar']);
        $this->assertSame('2010-05-01T00:00:00Z', $data['created_at']);
        $this->assertSame('2027-05-01T00:00:00Z', $data['expires_at']);
        $this->assertSame(['clientTransferProhibited'], $data['statuses']);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $data['nameservers']);
    }

    public function testParseWhoisTextWithNothingUseful(): void
    {
        $data = RdapProbe::parseWhoisText('No match for domain "WHATEVER.COM".');

        $this->assertNull($data['registrar']);
        $this->assertNull($data['expires_at']);
        $this->assertNull($data['days_to_expiry']);
        $this->assertSame([], $data['nameservers']);
    }
}
