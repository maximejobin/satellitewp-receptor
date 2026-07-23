<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Probe\TlsProbe;

final class TlsProbeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function parsedCertFixture(): array
    {
        return [
            'subject' => ['CN' => 'www.example.com'],
            'issuer'  => ['C' => 'US', 'O' => "Let's Encrypt", 'CN' => 'R11'],
            'extensions' => [
                'subjectAltName' => 'DNS:www.example.com, DNS:example.com',
            ],
            'validFrom_time_t' => strtotime('-30 days'),
            'validTo_time_t'   => strtotime('+60 days'),
        ];
    }

    public function testParseCertificate(): void
    {
        $data = TlsProbe::parseCertificate($this->parsedCertFixture(), 'www.example.com', true);

        $this->assertSame('www.example.com', $data['subject_cn']);
        $this->assertSame('R11', $data['issuer']);
        $this->assertSame(['www.example.com', 'example.com'], $data['san']);
        $this->assertGreaterThanOrEqual(59, $data['days_to_expiry']);
        $this->assertFalse($data['self_signed']);
        $this->assertTrue($data['chain_valid']);
        $this->assertTrue($data['hostname_covered']);
    }

    public function testSelfSignedDetection(): void
    {
        $cert            = $this->parsedCertFixture();
        $cert['issuer']  = $cert['subject'];

        $data = TlsProbe::parseCertificate($cert, 'www.example.com', false);

        $this->assertTrue($data['self_signed']);
        $this->assertFalse($data['chain_valid']);
    }

    public function testHostnameNotCovered(): void
    {
        $data = TlsProbe::parseCertificate($this->parsedCertFixture(), 'other.example.org', true);

        $this->assertFalse($data['hostname_covered']);
    }

    public function testWildcardCoversOneLabel(): void
    {
        $this->assertTrue(TlsProbe::hostnameCovered('shop.example.com', ['*.example.com'], null));
        $this->assertFalse(TlsProbe::hostnameCovered('a.b.example.com', ['*.example.com'], null));
        $this->assertFalse(TlsProbe::hostnameCovered('example.com', ['*.example.com'], null));
    }
}
