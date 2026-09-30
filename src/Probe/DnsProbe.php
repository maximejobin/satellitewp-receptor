<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;

/**
 * DNS records of the site: NS, A/AAAA, MX, TXT (SPF), DMARC, CAA.
 *
 * A lookup that failed (timeout, SERVFAIL) is reported as null, never as an
 * empty list: "no SPF record" is a finding, "couldn't ask" is not.
 * DNSSEC is not detectable in pure PHP and stays null.
 */
final class DnsProbe extends AbstractProbe
{
    public function name(): string
    {
        return 'dns';
    }

    public function version(): string
    {
        return '1.1';
    }

    protected function collect(SiteContext $site): array
    {
        $domain = $site->registrableDomain;
        if ($domain === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No domain in site context']];
        }

        $queries = [
            'ns'    => [$domain, DNS_NS],
            'a'     => [$site->host, DNS_A],
            'aaaa'  => [$site->host, DNS_AAAA],
            'mx'    => [$domain, DNS_MX],
            'txt'   => [$domain, DNS_TXT],
            'caa'   => [$domain, DNS_CAA],
            'dmarc' => ['_dmarc.' . $domain, DNS_TXT],
        ];

        $records = [];
        $errors  = [];
        foreach ($queries as $key => [$name, $type]) {
            $records[$key] = self::lookup($name, $type);
            if ($records[$key] === null) {
                $errors[] = "DNS lookup failed: {$key} for {$name}";
            }
        }

        $data = self::parseRecords($records);

        if ($data['a'] === [] && $data['aaaa'] === []) {
            return [
                'target' => $domain,
                'data'   => $data,
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => [...$errors, 'No A or AAAA record — host does not resolve'],
            ];
        }

        $warn = $errors !== []
            || ($data['spf']['present'] ?? null) === false
            || ($data['dmarc']['present'] ?? null) === false;

        return [
            'target' => $domain,
            'data'   => $data,
            'status' => $warn ? ProbeResult::STATUS_WARN : ProbeResult::STATUS_OK,
            'errors' => $errors,
        ];
    }

    /**
     * Pure transformation of raw dns_get_record() output. A null entry is a
     * failed lookup and yields null for every field derived from it.
     *
     * @param array<string, list<array<string, mixed>>|null> $records
     * @return array<string, mixed>
     */
    public static function parseRecords(array $records): array
    {
        // A missing key means "not queried" (empty); only an explicit null is a failed lookup.
        $records += array_fill_keys(['ns', 'a', 'aaaa', 'mx', 'txt', 'caa', 'dmarc'], []);

        $txt = self::map($records['txt'], static fn (array $r): string => (string) ($r['txt'] ?? ''));
        $txt = $txt === null ? null : array_values(array_filter($txt, static fn (string $v): bool => $v !== ''));

        $spf = null;
        if ($txt !== null) {
            $spfRecord = null;
            foreach ($txt as $value) {
                if (str_starts_with(strtolower($value), 'v=spf1')) {
                    $spfRecord = $value;
                    break;
                }
            }
            $spf = ['present' => $spfRecord !== null, 'record' => $spfRecord];
        }

        $dmarc = null;
        if ($records['dmarc'] !== null) {
            $dmarcRecord = null;
            foreach ($records['dmarc'] as $r) {
                $value = (string) ($r['txt'] ?? '');
                if (str_starts_with(strtolower($value), 'v=dmarc1')) {
                    $dmarcRecord = $value;
                    break;
                }
            }
            $policy = $dmarcRecord !== null && preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $dmarcRecord, $m)
                ? strtolower($m[1])
                : null;
            $dmarc = ['present' => $dmarcRecord !== null, 'record' => $dmarcRecord, 'policy' => $policy];
        }

        return [
            'nameservers' => self::map($records['ns'], static fn (array $r): string => (string) ($r['target'] ?? '')),
            'a'           => self::map($records['a'], static fn (array $r): string => (string) ($r['ip'] ?? '')),
            'aaaa'        => self::map($records['aaaa'], static fn (array $r): string => (string) ($r['ipv6'] ?? '')),
            'mx'          => self::map($records['mx'], static fn (array $r): array => [
                'host'     => (string) ($r['target'] ?? ''),
                'priority' => (int) ($r['pri'] ?? 0),
            ]),
            'txt'   => $txt,
            'spf'   => $spf,
            'dmarc' => $dmarc,
            'caa'   => self::map($records['caa'], static fn (array $r): array => [
                'flags' => (int) ($r['flags'] ?? 0),
                'tag'   => (string) ($r['tag'] ?? ''),
                'value' => (string) ($r['value'] ?? ''),
            ]),
            'dnssec' => null,
        ];
    }

    /**
     * @template T
     * @param list<array<string, mixed>>|null $records
     * @param callable(array<string, mixed>): T $fn
     * @return list<T>|null
     */
    private static function map(?array $records, callable $fn): ?array
    {
        return $records === null ? null : array_map($fn, $records);
    }

    /** @return list<array<string, mixed>>|null null when the lookup itself failed */
    private static function lookup(string $host, int $type): ?array
    {
        $result = @dns_get_record($host, $type);

        return $result === false ? null : $result;
    }
}
