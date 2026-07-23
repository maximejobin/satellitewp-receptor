<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\Storage\KeyStore;

/**
 * Verifies X-SWP-Signature = HMAC-SHA256(timestamp . '.' . raw_body, api_key)
 * and X-SWP-Timestamp freshness (anti-replay window).
 *
 * Mirrors the plugin's RemoteClient signing scheme — do not change independently.
 */
final class SignatureVerifier
{
    public const string RESULT_VALID    = 'valid';
    public const string RESULT_UNSIGNED = 'unsigned';   // accepted without a signature (dev mode)

    public function __construct(
        private readonly KeyStore $keys,
        private readonly int $replayWindowSeconds,
        private readonly bool $allowUnsigned,
    ) {
    }

    /**
     * @return string one of the RESULT_* constants
     * @throws SignatureException when the request must be rejected
     */
    public function verify(string $siteId, string $timestamp, ?string $signature, string $rawBody): string
    {
        if ($timestamp === '' || !ctype_digit($timestamp)) {
            throw new SignatureException('Missing or malformed X-SWP-Timestamp', 400);
        }

        if (abs(time() - (int) $timestamp) > $this->replayWindowSeconds) {
            throw new SignatureException('X-SWP-Timestamp outside the accepted window', 401);
        }

        $apiKey = $this->keys->getKey($siteId);

        if ($apiKey === null) {
            if ($signature !== null && $signature !== '') {
                // Signed payload but no key on file: cannot verify — refuse rather than guess.
                throw new SignatureException('No API key registered for this site', 401);
            }
            if (!$this->allowUnsigned) {
                throw new SignatureException('Unsigned payloads are not accepted', 401);
            }

            return self::RESULT_UNSIGNED;
        }

        if ($signature === null || $signature === '') {
            throw new SignatureException('Missing X-SWP-Signature', 401);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $apiKey);

        if (!hash_equals($expected, $signature)) {
            throw new SignatureException('Invalid X-SWP-Signature', 401);
        }

        return self::RESULT_VALID;
    }
}
