<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Probe;

use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Support\HostGuard;

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
        return '1.2';
    }

    protected function collect(SiteContext $site): array
    {
        $host = $site->host;
        if ($host === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No host in site context']];
        }

        // Resolve once and connect to that vetted address (host kept as the
        // TLS peer name), so a second lookup can't be answered differently.
        $ip = HostGuard::publicIpFor($host);
        if ($ip === null) {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['Host does not resolve to a public address — refusing to connect (SSRF guard)']];
        }

        // Chain verification only: the hostname is checked separately against
        // the certificate (hostname_covered), so a name mismatch is never
        // misreported as a broken chain.
        [$cert, $chainValid, $handshakeError] = $this->fetchCertificate($host, $ip, verify: true);

        if ($cert === null) {
            [$cert, , $handshakeError2] = $this->fetchCertificate($host, $ip, verify: false);
            $chainValid = self::chainVerdictFromHandshakeError($handshakeError);

            if ($cert === null) {
                return [
                    'status' => ProbeResult::STATUS_ERROR,
                    'errors' => array_values(array_filter([$handshakeError, $handshakeError2])),
                ];
            }
        }

        $parsed = openssl_x509_parse($cert);
        $data   = self::parseCertificate(is_array($parsed) ? $parsed : [], $host, $chainValid);

        $data['protocols'] = $this->probeProtocols($host, $ip);

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
    public static function parseCertificate(array $parsed, string $host, ?bool $chainValid): array
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
     * Pure. The verified handshake failing proves a broken chain only when
     * OpenSSL rejected the certificate; a timeout or reset proves nothing (null).
     */
    public static function chainVerdictFromHandshakeError(?string $error): ?bool
    {
        return $error !== null && preg_match('/certificate verify failed/i', $error) === 1 ? false : null;
    }

    /**
     * @return array{0: \OpenSSLCertificate|null, 1: bool, 2: string|null} error carries OpenSSL's own messages
     */
    private function fetchCertificate(string $host, string $ip, bool $verify): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => $verify,
            'verify_peer_name'  => false,
            'allow_self_signed' => !$verify,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        // OpenSSL's reason ("certificate verify failed") only reaches the warnings, not $errstr.
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $client = stream_socket_client(
                self::socketTarget($ip),
                $errno,
                $errstr,
                $this->connectTimeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } finally {
            restore_error_handler();
        }

        if ($client === false) {
            $reason = $errstr !== '' ? $errstr : "Connection failed (errno {$errno})";

            return [null, false, implode(' | ', array_merge($warnings, [$reason]))];
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        return [$cert instanceof \OpenSSLCertificate ? $cert : null, $verify, null];
    }

    /**
     * Which TLS versions the server accepts, one pinned handshake each.
     *
     * Security level 0: at the system default (SECLEVEL=2) OpenSSL itself
     * refuses TLS 1.0/1.1, so the server would always read as "not accepting
     * them" whatever it really does.
     *
     * @return array<string, bool|null> null = could not be tested
     */
    private function probeProtocols(string $host, string $ip): array
    {
        $support = [];

        foreach (self::PROTOCOLS as $label => $method) {
            $context = stream_context_create(['ssl' => [
                'crypto_method'     => $method,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
                'security_level'    => 0,
                'ciphers'           => 'DEFAULT@SECLEVEL=0',
            ]]);

            $client = @stream_socket_client(
                self::socketTarget($ip),
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

        return self::interpretProtocolResults($support, self::legacyTlsTestable());
    }

    /**
     * Pure. A refused handshake only means "not accepted" when a modern one
     * went through (the server was reachable) and, for 1.0/1.1, when the
     * local OpenSSL can speak them at all.
     *
     * @param array<string, bool> $handshakes label => handshake succeeded
     * @return array<string, bool|null>
     */
    public static function interpretProtocolResults(array $handshakes, bool $legacyTestable): array
    {
        $reachable = ($handshakes['tls1_2'] ?? false) || ($handshakes['tls1_3'] ?? false);

        $result = [];
        foreach ($handshakes as $label => $ok) {
            $legacy         = in_array($label, ['tls1_0', 'tls1_1'], true);
            $result[$label] = match (true) {
                $ok                        => true,
                !$reachable                => null,
                $legacy && !$legacyTestable => null,
                default                    => false,
            };
        }

        return $result;
    }

    /** security_level needs OpenSSL >= 1.1.0; without it TLS 1.0/1.1 cannot be negotiated here. */
    private static function legacyTlsTestable(): bool
    {
        return OPENSSL_VERSION_NUMBER >= 0x10100000;
    }

    /** Pure: the stream_socket_client() target for a vetted IP — IPv6 in brackets. */
    public static function socketTarget(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? "ssl://[{$ip}]:443"
            : "ssl://{$ip}:443";
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
