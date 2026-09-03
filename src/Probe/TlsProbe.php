<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Support\HostGuard;

/**
 * TLS certificate and protocol support: issuer, SAN coverage, validity,
 * chain trust, and which TLS versions the server accepts.
 */
final class TlsProbe extends AbstractProbe
{
    private const array PROTOCOLS = [
        'tls1_0' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
        'tls1_1' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
        'tls1_2' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        'tls1_3' => STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
    ];

    public function __construct(private readonly int $connectTimeout)
    {
    }

    public function name(): string
    {
        return 'tls';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        $host = $site->host;
        if ($host === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No host in site context']];
        }

        if (!HostGuard::isPubliclyRoutable($host)) {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['Host does not resolve to a public address — refusing to connect (SSRF guard)']];
        }

        // Full handshake with verification, capturing the certificate.
        [$cert, $chainValid, $handshakeError] = $this->fetchCertificate($host, verify: true);

        if ($cert === null) {
            // Retry without verification: cert may be self-signed or chain broken.
            [$cert, , $handshakeError2] = $this->fetchCertificate($host, verify: false);
            $chainValid = false;

            if ($cert === null) {
                return [
                    'status' => ProbeResult::STATUS_ERROR,
                    'errors' => array_values(array_filter([$handshakeError, $handshakeError2])),
                ];
            }
        }

        $parsed = openssl_x509_parse($cert);
        $data   = self::parseCertificate(is_array($parsed) ? $parsed : [], $host, (bool) $chainValid);

        $data['protocols'] = $this->probeProtocols($host);

        $errors = [];
        $status = $this->assess($data);

        return ['data' => $data, 'status' => $status, 'errors' => $errors];
    }

    /**
     * Pure transformation of an openssl_x509_parse() array — unit-testable.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public static function parseCertificate(array $parsed, string $host, bool $chainValid): array
    {
        $subject = (array) ($parsed['subject'] ?? []);
        $issuer  = (array) ($parsed['issuer'] ?? []);

        $san = [];
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $san[] = substr($entry, 4);
            }
        }

        $notBefore = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $notAfter  = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;

        $subjectCn = $subject['CN'] ?? null;
        $issuerCn  = $issuer['CN'] ?? $issuer['O'] ?? null;

        return [
            'subject_cn'       => $subjectCn,
            'issuer'           => $issuerCn,
            'san'              => $san,
            'not_before'       => $notBefore !== null ? gmdate('Y-m-d\TH:i:s\Z', $notBefore) : null,
            'not_after'        => $notAfter !== null ? gmdate('Y-m-d\TH:i:s\Z', $notAfter) : null,
            'days_to_expiry'   => $notAfter !== null ? (int) floor(($notAfter - time()) / 86400) : null,
            'self_signed'      => $subject !== [] && $subject == $issuer,
            'chain_valid'      => $chainValid,
            'hostname_covered' => self::hostnameCovered($host, $san, is_string($subjectCn) ? $subjectCn : null),
        ];
    }

    /** @param list<string> $san */
    public static function hostnameCovered(string $host, array $san, ?string $subjectCn): bool
    {
        $names = $san;
        if ($subjectCn !== null) {
            $names[] = $subjectCn;
        }

        foreach ($names as $name) {
            if (strcasecmp($name, $host) === 0) {
                return true;
            }
            // Wildcard covers exactly one label.
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 2);
                $hostParts = explode('.', $host, 2);
                if (count($hostParts) === 2 && strcasecmp($hostParts[1], $suffix) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: \OpenSSLCertificate|null, 1: bool, 2: string|null}
     */
    private function fetchCertificate(string $host, bool $verify): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled'       => true,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            return [null, false, $errstr !== '' ? $errstr : "Connection failed (errno {$errno})"];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        return [$cert instanceof \OpenSSLCertificate ? $cert : null, $verify, null];
    }

    /** @return array<string, bool|null> */
    private function probeProtocols(string $host): array
    {
        $support = [];

        foreach (self::PROTOCOLS as $label => $method) {
            $context = stream_context_create(['ssl' => [
                'crypto_method'     => $method,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'SNI_enabled'       => true,
            ]]);

            $client = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                $this->connectTimeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            $support[$label] = $client !== false;
            if ($client !== false) {
                fclose($client);
            }
        }

        return $support;
    }

    /** @param array<string, mixed> $data */
    private function assess(array $data): string
    {
        $days = $data['days_to_expiry'];

        if (($days !== null && $days < 0) || $data['chain_valid'] === false
            || $data['hostname_covered'] === false || $data['self_signed'] === true) {
            return ProbeResult::STATUS_ERROR;
        }

        $warn =
            ($days !== null && $days < 30)
            || ($data['protocols']['tls1_0'] ?? false)
            || ($data['protocols']['tls1_1'] ?? false);

        return $warn ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK;
    }
}
