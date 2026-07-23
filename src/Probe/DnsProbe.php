<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;

/**
 * DNS records of the site: NS, A/AAAA, MX, TXT (SPF), DMARC, CAA.
 * DNSSEC is reported as null in v1 (not reliably detectable in pure PHP).
 */
final class DnsProbe extends AbstractProbe
{
    public function name(): string
    {
        return 'dns';
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

        $records = [
            'ns'    => $this->records($domain, DNS_NS),
            'a'     => $this->records($site->host, DNS_A),
            'aaaa'  => $this->records($site->host, DNS_AAAA),
            'mx'    => $this->records($domain, DNS_MX),
            'txt'   => $this->records($domain, DNS_TXT),
            'caa'   => $this->records($domain, DNS_CAA),
            'dmarc' => $this->records('_dmarc.' . $domain, DNS_TXT),
        ];

        $data = self::parseRecords($records);

        if ($data['a'] === [] && $data['aaaa'] === []) {
            return [
                'target' => $domain,
                'data'   => $data,
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => ['No A or AAAA record — host does not resolve'],
            ];
        }

        $warn = !$data['spf']['present'] || !$data['dmarc']['present'];

        return [
            'target' => $domain,
            'data'   => $data,
            'status' => $warn ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK,
        ];
    }

    /**
     * Pure transformation of raw dns_get_record() output — unit-testable.
     *
     * @param array<string, list<array<string, mixed>>> $records
     * @return array<string, mixed>
     */
    public static function parseRecords(array $records): array
    {
        $txt = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['txt'] ?? ''),
            $records['txt'] ?? []
        )));

        $spfRecord = null;
        foreach ($txt as $value) {
            if (str_starts_with(strtolower($value), 'v=spf1')) {
                $spfRecord = $value;
                break;
            }
        }

        $dmarcRecord = null;
        foreach ($records['dmarc'] ?? [] as $r) {
            $value = (string) ($r['txt'] ?? '');
            if (str_starts_with(strtolower($value), 'v=dmarc1')) {
                $dmarcRecord = $value;
                break;
            }
        }

        $dmarcPolicy = null;
        if ($dmarcRecord !== null && preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $dmarcRecord, $m)) {
            $dmarcPolicy = strtolower($m[1]);
        }

        return [
            'nameservers' => array_values(array_map(
                static fn (array $r): string => (string) ($r['target'] ?? ''),
                $records['ns'] ?? []
            )),
            'a' => array_values(array_map(
                static fn (array $r): string => (string) ($r['ip'] ?? ''),
                $records['a'] ?? []
            )),
            'aaaa' => array_values(array_map(
                static fn (array $r): string => (string) ($r['ipv6'] ?? ''),
                $records['aaaa'] ?? []
            )),
            'mx' => array_values(array_map(
                static fn (array $r): array => [
                    'host'     => (string) ($r['target'] ?? ''),
                    'priority' => (int) ($r['pri'] ?? 0),
                ],
                $records['mx'] ?? []
            )),
            'txt' => $txt,
            'spf' => [
                'present' => $spfRecord !== null,
                'record'  => $spfRecord,
            ],
            'dmarc' => [
                'present' => $dmarcRecord !== null,
                'record'  => $dmarcRecord,
                'policy'  => $dmarcPolicy,
            ],
            'caa' => array_values(array_map(
                static fn (array $r): array => [
                    'flags' => (int) ($r['flags'] ?? 0),
                    'tag'   => (string) ($r['tag'] ?? ''),
                    'value' => (string) ($r['value'] ?? ''),
                ],
                $records['caa'] ?? []
            )),
            'dnssec' => null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function records(string $host, int $type): array
    {
        $result = @dns_get_record($host, $type);

        return $result === false ? [] : array_values($result);
    }
}
