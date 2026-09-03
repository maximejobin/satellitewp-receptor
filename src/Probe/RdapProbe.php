<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;

/**
 * Domain registration data. RDAP first (structured JSON, via rdap.org
 * bootstrap), classic WHOIS over port 43 as fallback.
 */
final class RdapProbe extends AbstractProbe
{
    public function __construct(
        private readonly string $rdapBaseUrl,
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly string $userAgent,
    ) {
    }

    public function name(): string
    {
        return 'rdap';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        $domain = $site->registrableDomain;
        if ($domain === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No domain in site context']];
        }

        $errors = [];

        $data = $this->fetchRdap($domain, $errors);

        if ($data === null) {
            $data = $this->fetchWhois($domain, $errors);
        }

        if ($data === null) {
            return ['target' => $domain, 'status' => ProbeResult::STATUS_ERROR, 'errors' => $errors];
        }

        $days = $data['days_to_expiry'];
        $warn = ($days !== null && $days < 30) || $data['expires_at'] === null;

        return [
            'target' => $domain,
            'data'   => $data,
            'status' => $warn ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private function fetchRdap(string $domain, array &$errors): ?array
    {
        $client = new Client([
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'headers'         => ['User-Agent' => $this->userAgent, 'Accept' => 'application/rdap+json'],
            'http_errors'     => false,
        ]);

        try {
            $response = $client->get($this->rdapBaseUrl . '/domain/' . rawurlencode($domain));
        } catch (GuzzleException $e) {
            $errors[] = "RDAP: {$e->getMessage()}";

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $errors[] = 'RDAP: HTTP ' . $response->getStatusCode();

            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            $errors[] = 'RDAP: invalid JSON response';

            return null;
        }

        return self::parseRdapResponse($decoded);
    }

    /**
     * Pure transformation of an RDAP domain object — unit-testable.
     *
     * @param array<string, mixed> $rdap
     * @return array<string, mixed>
     */
    public static function parseRdapResponse(array $rdap): array
    {
        $createdAt = null;
        $expiresAt = null;
        $updatedAt = null;
        foreach ((array) ($rdap['events'] ?? []) as $event) {
            $action = $event['eventAction'] ?? '';
            $date   = $event['eventDate'] ?? null;
            if ($action === 'registration') {
                $createdAt = $date;
            } elseif ($action === 'expiration') {
                $expiresAt = $date;
            } elseif ($action === 'last changed') {
                // Domain-level "last changed" — distinct from "last update of
                // RDAP database", which is about the registry's database, not
                // this domain, and must not be mistaken for it.
                $updatedAt = $date;
            }
        }

        $registrar = null;
        foreach ((array) ($rdap['entities'] ?? []) as $entity) {
            if (in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                // vCard: ["vcard", [["version",…], ["fn", {}, "text", "Registrar Name"], …]]
                foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
                    if (($field[0] ?? '') === 'fn') {
                        $registrar = $field[3] ?? null;
                        break;
                    }
                }
                break;
            }
        }

        $nameservers = [];
        foreach ((array) ($rdap['nameservers'] ?? []) as $ns) {
            if (isset($ns['ldhName'])) {
                $nameservers[] = strtolower((string) $ns['ldhName']);
            }
        }

        return [
            'source'         => 'rdap',
            'registrar'      => $registrar,
            'created_at'     => self::toIso($createdAt),
            'expires_at'     => self::toIso($expiresAt),
            'updated_at'     => self::toIso($updatedAt),
            'days_to_expiry' => self::daysUntil($expiresAt),
            'statuses'       => array_values((array) ($rdap['status'] ?? [])),
            'nameservers'    => $nameservers,
        ];
    }

    /**
     * WHOIS fallback: IANA referral then TLD server, minimal line parsing.
     *
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private function fetchWhois(string $domain, array &$errors): ?array
    {
        $referral = $this->whoisQuery('whois.iana.org', $domain, $errors);
        if ($referral === null) {
            return null;
        }

        $server = null;
        if (preg_match('/^\s*(?:whois|refer):\s*(\S+)/mi', $referral, $m)) {
            $server = strtolower($m[1]);
        }
        if ($server === null) {
            $errors[] = 'WHOIS: no referral server found for TLD';

            return null;
        }

        $raw = $this->whoisQuery($server, $domain, $errors);
        if ($raw === null) {
            return null;
        }

        return self::parseWhoisText($raw);
    }

    /**
     * Pure parsing of a WHOIS text blob — unit-testable.
     *
     * @return array<string, mixed>
     */
    public static function parseWhoisText(string $raw): array
    {
        $registrar = null;
        if (preg_match('/^\s*Registrar:\s*(.+)$/mi', $raw, $m)) {
            $registrar = trim($m[1]);
        }

        $createdAt = null;
        if (preg_match('/^\s*Creation Date:\s*(\S+)/mi', $raw, $m)) {
            $createdAt = $m[1];
        }

        $expiresAt = null;
        if (preg_match('/^\s*(?:Registry Expiry Date|Expiry Date|Expiration Date):\s*(\S+)/mi', $raw, $m)) {
            $expiresAt = $m[1];
        }

        $updatedAt = null;
        if (preg_match('/^\s*(?:Updated Date|Last Updated On|Last Modified):\s*(\S+)/mi', $raw, $m)) {
            $updatedAt = $m[1];
        }

        $statuses = [];
        if (preg_match_all('/^\s*Domain Status:\s*(\S+)/mi', $raw, $m)) {
            $statuses = array_values(array_unique($m[1]));
        }

        $nameservers = [];
        if (preg_match_all('/^\s*Name Server:\s*(\S+)/mi', $raw, $m)) {
            $nameservers = array_values(array_unique(array_map('strtolower', $m[1])));
        }

        return [
            'source'         => 'whois',
            'registrar'      => $registrar,
            'created_at'     => self::toIso($createdAt),
            'expires_at'     => self::toIso($expiresAt),
            'updated_at'     => self::toIso($updatedAt),
            'days_to_expiry' => self::daysUntil($expiresAt),
            'statuses'       => $statuses,
            'nameservers'    => $nameservers,
        ];
    }

    /** @param list<string> $errors */
    private function whoisQuery(string $server, string $query, array &$errors): ?string
    {
        $socket = @stream_socket_client(
            "tcp://{$server}:43",
            $errno,
            $errstr,
            $this->connectTimeout
        );

        if ($socket === false) {
            $errors[] = "WHOIS {$server}: {$errstr}";

            return null;
        }

        stream_set_timeout($socket, $this->timeout);
        fwrite($socket, $query . "\r\n");

        $response = stream_get_contents($socket, 65536);
        fclose($socket);

        if ($response === false || $response === '') {
            $errors[] = "WHOIS {$server}: empty response";

            return null;
        }

        return $response;
    }

    private static function toIso(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $ts = strtotime($date);

        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private static function daysUntil(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        $ts = strtotime($date);

        return $ts === false ? null : (int) floor(($ts - time()) / 86400);
    }
}
