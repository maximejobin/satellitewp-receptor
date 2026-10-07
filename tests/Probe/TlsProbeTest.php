<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Probe\TlsProbe;

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

    public function testOnlyACertificateVerificationFailureMeansABrokenChain(): void
    {
        $this->assertFalse(TlsProbe::chainVerdictFromHandshakeError('stream_socket_client(): SSL operations failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed | Unknown error'));
        $this->assertNull(TlsProbe::chainVerdictFromHandshakeError('Connection timed out'));
        $this->assertNull(TlsProbe::chainVerdictFromHandshakeError('stream_socket_client(): Unable to connect to ssl://203.0.113.5:443 (Connection reset by peer)'));
        $this->assertNull(TlsProbe::chainVerdictFromHandshakeError(null));
    }

    public function testAnUntestedChainIsNotABrokenOne(): void
    {
        $this->assertNull(TlsProbe::parseCertificate([], 'example.com', null)['chain_valid']);
    }

    public function testSocketTargetBracketsIpv6(): void
    {
        $this->assertSame('ssl://93.184.216.34:443', TlsProbe::socketTarget('93.184.216.34'));
        $this->assertSame('ssl://[2606:2800:220:1::1]:443', TlsProbe::socketTarget('2606:2800:220:1::1'));
    }

    public function testALegacyProtocolTheServerAcceptsIsReportedTrue(): void
    {
        $result = TlsProbe::interpretProtocolResults(['tls1_0' => true, 'tls1_1' => false, 'tls1_2' => true, 'tls1_3' => true], true);

        $this->assertSame(['tls1_0' => true, 'tls1_1' => false, 'tls1_2' => true, 'tls1_3' => true], $result);
    }

    public function testLegacyProtocolsAreUnknownWhenTheLocalStackCannotSpeakThem(): void
    {
        $result = TlsProbe::interpretProtocolResults(['tls1_0' => false, 'tls1_1' => false, 'tls1_2' => true, 'tls1_3' => false], false);

        $this->assertNull($result['tls1_0']);
        $this->assertNull($result['tls1_1']);
        $this->assertTrue($result['tls1_2']);
        $this->assertFalse($result['tls1_3']);
    }

    public function testAnUnreachableServerYieldsUnknownNeverRefused(): void
    {
        $result = TlsProbe::interpretProtocolResults(['tls1_0' => false, 'tls1_1' => false, 'tls1_2' => false, 'tls1_3' => false], true);

        $this->assertSame(['tls1_0' => null, 'tls1_1' => null, 'tls1_2' => null, 'tls1_3' => null], $result);
    }
}
