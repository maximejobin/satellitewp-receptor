<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Probe\DnsProbe;

final class DnsProbeTest extends TestCase
{
    public function testParseRecordsExtractsEverything(): void
    {
        $data = DnsProbe::parseRecords([
            'ns'   => [['target' => 'ns1.example.net'], ['target' => 'ns2.example.net']],
            'a'    => [['ip' => '192.0.2.10']],
            'aaaa' => [['ipv6' => '2001:db8::1']],
            'mx'   => [['target' => 'mail.example.com', 'pri' => 10]],
            'txt'  => [
                ['txt' => 'v=spf1 include:_spf.example.com ~all'],
                ['txt' => 'google-site-verification=abc'],
            ],
            'dmarc' => [['txt' => 'v=DMARC1; p=quarantine; rua=mailto:d@example.com']],
            'caa'   => [['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']],
        ]);

        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $data['nameservers']);
        $this->assertSame(['192.0.2.10'], $data['a']);
        $this->assertSame(['2001:db8::1'], $data['aaaa']);
        $this->assertSame([['host' => 'mail.example.com', 'priority' => 10]], $data['mx']);
        $this->assertTrue($data['spf']['present']);
        $this->assertSame('v=spf1 include:_spf.example.com ~all', $data['spf']['record']);
        $this->assertTrue($data['dmarc']['present']);
        $this->assertSame('quarantine', $data['dmarc']['policy']);
        $this->assertSame('letsencrypt.org', $data['caa'][0]['value']);
        $this->assertNull($data['dnssec']);
    }

    public function testParseRecordsWithoutSpfOrDmarc(): void
    {
        $data = DnsProbe::parseRecords([
            'a'   => [['ip' => '192.0.2.10']],
            'txt' => [['txt' => 'not-spf']],
        ]);

        $this->assertFalse($data['spf']['present']);
        $this->assertFalse($data['dmarc']['present']);
        $this->assertNull($data['dmarc']['policy']);
        $this->assertSame([], $data['mx']);
    }

    public function testAFailedLookupIsNullNeverAnEmptyFinding(): void
    {
        $data = DnsProbe::parseRecords([
            'a'     => [['ip' => '192.0.2.10']],
            'txt'   => null,
            'dmarc' => null,
            'mx'    => null,
            'caa'   => null,
        ]);

        $this->assertNull($data['txt']);
        $this->assertNull($data['spf']);
        $this->assertNull($data['dmarc']);
        $this->assertNull($data['mx']);
        $this->assertNull($data['caa']);
        $this->assertSame(['192.0.2.10'], $data['a']);
    }

    public function testAnEmptyAnswerIsAFindingNotAFailure(): void
    {
        $data = DnsProbe::parseRecords(['txt' => [], 'dmarc' => [], 'mx' => []]);

        $this->assertSame(['present' => false, 'record' => null], $data['spf']);
        $this->assertFalse($data['dmarc']['present']);
        $this->assertSame([], $data['mx']);
    }
}
