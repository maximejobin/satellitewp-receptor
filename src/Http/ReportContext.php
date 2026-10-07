<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use SatelliteWP\Manager\Reference\EndOfLife;
use SatelliteWP\Manager\Reference\WordPressVersions;
use SatelliteWP\Manager\Rules\Translator;

/**
 * The data a report contract (config/reports/*.php) is resolved against —
 * pure functions of one extraction's stored files plus Manager's own
 * reference caches, so report.json's inputs are unit-testable.
 */
final class ReportContext
{
    /**
     * @param array<string, mixed>                $payload
     * @param array<string, mixed>                $site           site.json
     * @param array<string, mixed>                $meta           meta.json
     * @param array<string, array<string, mixed>> $probeEnvelopes probe name => stored envelope
     * @param array<string, mixed>                $licenses       licenses.json
     * @param array<string, mixed>                $reference      see reference()
     * @return array<string, mixed>
     */
    public static function build(
        array $payload,
        array $site,
        array $meta,
        array $probeEnvelopes,
        array $licenses,
        array $reference,
        string $extractionId,
        string $reportBy,
        string $today,
    ): array {
        return [
            'payload'       => $payload,
            'site'          => $site,
            'meta'          => $meta,
            'probe'         => array_map(static fn (array $p): array => (array) ($p['data'] ?? []), $probeEnvelopes),
            'host'          => (string) (parse_url((string) ($payload['home_url'] ?? $payload['site_url'] ?? ''), PHP_URL_HOST) ?? ''),
            'extraction_id' => $extractionId,
            'report_by'     => $reportBy,
            // When the report was filled, distinct from the extraction's own date.
            'today'         => $today,
            'reference'     => $reference,
            // Per-extraction licence-key status ("plugin:<slug>" => active/missing/…).
            'licenses'      => $licenses,
        ];
    }

    /**
     * Manager's own reference facts — never the extraction's claim about itself.
     *
     * @param array<string, mixed> $payload
     * @return array{wordpress_latest_version: string, wordpress_status: string|null, php_eol: bool|null, database_eol: bool|null, database_eol_date: string}
     */
    public static function reference(array $payload, EndOfLife $eol, WordPressVersions $wordPressVersions): array
    {
        $phpVersion = (string) ($payload['php']['version'] ?? '');
        $phpEol     = $phpVersion !== '' ? $eol->eolStatus('php', $phpVersion) : null;

        $dbType    = (string) ($payload['database_type'] ?? '');
        $dbVersion = (string) ($payload['database_version'] ?? '');
        $dbEol     = ($dbType !== '' && $dbVersion !== '') ? $eol->eolStatus($dbType, $dbVersion) : null;

        // Raw stable-check verdict for the installed version; null when the
        // cache doesn't list it (never guessed as good or bad).
        $wpInstalled = (string) ($payload['wp_version'] ?? '');
        $wpStatus    = $wpInstalled !== '' ? ($wordPressVersions->all()[$wpInstalled] ?? null) : null;

        return [
            'wordpress_latest_version' => $wordPressVersions->latestVersion() ?? '',
            'wordpress_status'         => $wpStatus !== null ? (string) $wpStatus : null,
            'php_eol'                  => isset($phpEol[0]) ? (bool) $phpEol[0] : null,
            'database_eol'             => isset($dbEol[0]) ? (bool) $dbEol[0] : null,
            'database_eol_date'        => (string) ($dbEol[1] ?? ''),
        ];
    }

    /**
     * Every finding with a phrase for its status, translated once: stable
     * category code (for grouping) plus display label, and a title that
     * follows the outcome (title_success/title_failure).
     *
     * @param array<string, mixed> $findingsData one extraction's findings.json
     * @return list<array{id: string, category_code: string, category: string, pastille: string, severity: string, title: string, message: string, status: string}>
     */
    public static function translatedFindings(array $findingsData, Translator $t): array
    {
        $findings = [];
        foreach ((array) ($findingsData['findings'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $message = $t->message($f);
            if ($message === null) {
                continue;
            }
            $id     = (string) ($f['id'] ?? '');
            $status = isset($f['status']) ? (string) $f['status'] : null;

            $findings[] = [
                'id'            => $id,
                'category_code' => (string) ($f['category'] ?? ''),
                'category'      => $t->category((string) ($f['category'] ?? '')),
                'pastille'      => (string) ($f['pastille'] ?? 'grey'),
                'severity'      => $t->severity((string) ($f['severity'] ?? '')),
                'title'         => $t->title($id, $status, is_string($f['data']['variant'] ?? null) ? $f['data']['variant'] : null),
                'message'       => $message,
                'status'        => (string) $status,
            ];
        }

        return $findings;
    }
}
