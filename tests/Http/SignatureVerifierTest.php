<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use SatelliteWP\Xtractor\Http\SignatureException;
use SatelliteWP\Xtractor\Http\SignatureVerifier;
use SatelliteWP\Xtractor\Storage\KeyStore;
use SatelliteWP\Xtractor\Tests\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string API_KEY = 'secret-key-for-tests';

    private function verifier(bool $withKey = true, bool $allowUnsigned = false): SignatureVerifier
    {
        $keys = new KeyStore($this->tmpDir . '/keys.json');
        if ($withKey) {
            $keys->addKey(self::SITE_ID, self::API_KEY);
        }

        return new SignatureVerifier($keys, 300, $allowUnsigned);
    }

    private function sign(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, self::API_KEY);
    }

    public function testValidSignatureIsAccepted(): void
    {
        $ts   = (string) time();
        $body = '{"site_id":"x"}';

        $result = $this->verifier()->verify(self::SITE_ID, $ts, $this->sign($ts, $body), $body);

        $this->assertSame(SignatureVerifier::RESULT_VALID, $result);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Invalid X-SWP-Signature');

        $ts = (string) time();
        $this->verifier()->verify(self::SITE_ID, $ts, 'bad-signature', '{}');
    }

    public function testMissingSignatureWithKeyIsRejected(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Missing X-SWP-Signature');

        $this->verifier()->verify(self::SITE_ID, (string) time(), null, '{}');
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('window');

        $ts   = (string) (time() - 3600);
        $body = '{}';
        $this->verifier()->verify(self::SITE_ID, $ts, $this->sign($ts, $body), $body);
    }

    public function testMalformedTimestampIsRejected(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('X-SWP-Timestamp');

        $this->verifier()->verify(self::SITE_ID, 'not-a-number', null, '{}');
    }

    public function testUnsignedRejectedByDefault(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Unsigned');

        $this->verifier(withKey: false)->verify(self::SITE_ID, (string) time(), null, '{}');
    }

    public function testUnsignedAcceptedInDevMode(): void
    {
        $result = $this->verifier(withKey: false, allowUnsigned: true)
            ->verify(self::SITE_ID, (string) time(), null, '{}');

        $this->assertSame(SignatureVerifier::RESULT_UNSIGNED, $result);
    }

    public function testSignedPayloadWithoutRegisteredKeyIsRejected(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('No API key registered');

        $this->verifier(withKey: false, allowUnsigned: true)
            ->verify(self::SITE_ID, (string) time(), 'some-signature', '{}');
    }

    public function testRevokedKeyBehavesLikeNoKey(): void
    {
        $keys = new KeyStore($this->tmpDir . '/keys.json');
        $keys->addKey(self::SITE_ID, self::API_KEY);
        $keys->revokeKey(self::SITE_ID);

        $verifier = new SignatureVerifier($keys, 300, false);

        $this->expectException(SignatureException::class);

        $ts   = (string) time();
        $body = '{}';
        $verifier->verify(self::SITE_ID, $ts, $this->sign($ts, $body), $body);
    }
}
