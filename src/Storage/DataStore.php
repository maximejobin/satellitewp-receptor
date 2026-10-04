<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

use RuntimeException;
use Throwable;

/**
 * All reads/writes under data/. JSON files are the source of truth.
 *
 * Layout:
 *   data/sites/<site_id>/site.json
 *   data/sites/<site_id>/extractions/<id>/{payload,meta,findings,observations,licenses}.json
 *   data/sites/<site_id>/extractions/<id>/probes/<probe>.json
 *   data/sites/<site_id>/extractions/latest        (symlink)
 *   data/sites/<site_id>/events/<YYYY-MM>.jsonl
 *   data/sites/<site_id>/integrity/<id>.json
 */
final class DataStore
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function siteDir(string $siteId): string
    {
        return $this->dataDir . '/sites/' . $siteId;
    }

    public function extractionDir(string $siteId, string $extractionId): string
    {
        return $this->siteDir($siteId) . '/extractions/' . $extractionId;
    }

    /**
     * Store a raw extraction payload. Returns the new extraction id.
     *
     * @param array<string, mixed> $meta
     */
    public function storeExtraction(string $siteId, string $rawBody, array $meta): string
    {
        $extractionId = $this->newExtractionId($siteId);
        $dir          = $this->extractionDir($siteId, $extractionId);

        $this->mkdir($dir . '/probes');

        $this->writeRaw($dir . '/payload.json', $rawBody);
        $this->writeJson($dir . '/meta.json', $meta);

        $this->updateLatestLink($siteId, $extractionId);

        return $extractionId;
    }

    /** @param array<string, mixed> $payload */
    public function updateSiteInfo(string $siteId, array $payload): void
    {
        $file     = $this->siteDir($siteId) . '/site.json';
        $existing = $this->readJson($file) ?? [];

        $this->writeJson($file, [
            'site_id'  => $siteId,
            'site_url' => $payload['site_url'] ?? $existing['site_url'] ?? null,
            'home_url' => $payload['home_url'] ?? $existing['home_url'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function appendEvents(string $siteId, array $payload, string $receivedAt): void
    {
        $dir = $this->siteDir($siteId) . '/events';
        $this->mkdir($dir);

        $file = $dir . '/' . substr($receivedAt, 0, 7) . '.jsonl';
        $line = json_encode(
            ['received_at' => $receivedAt] + $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";

        if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to append events to {$file}");
        }
    }

    /** @param array<string, mixed> $payload */
    public function storeIntegrity(string $siteId, array $payload, string $receivedAt): string
    {
        $dir = $this->siteDir($siteId) . '/integrity';
        $this->mkdir($dir);

        $id   = $this->timestampId($receivedAt);
        $file = $dir . '/' . $id . '.json';

        // Avoid clobbering when two reports land in the same second.
        for ($n = 2; is_file($file); $n++) {
            $file = $dir . '/' . $id . '-' . $n . '.json';
        }

        $this->writeJson($file, $payload);

        return basename($file, '.json');
    }

    /** @return array<string, mixed>|null */
    public function readExtractionPayload(string $siteId, string $extractionId): ?array
    {
        return $this->readJson($this->extractionDir($siteId, $extractionId) . '/payload.json');
    }

    /** @param array<string, mixed> $result */
    public function writeProbeResult(string $siteId, string $extractionId, string $probe, array $result): void
    {
        $dir = $this->extractionDir($siteId, $extractionId) . '/probes';
        $this->mkdir($dir);
        $this->writeJson($dir . '/' . $probe . '.json', $result);
    }

    /** @return array<string, mixed>|null */
    public function readProbeResult(string $siteId, string $extractionId, string $probe): ?array
    {
        return $this->readJson($this->extractionDir($siteId, $extractionId) . '/probes/' . $probe . '.json');
    }

    /** @return array<string, array<string, mixed>> probe name => envelope */
    public function readAllProbeResults(string $siteId, string $extractionId): array
    {
        $dir     = $this->extractionDir($siteId, $extractionId) . '/probes';
        $results = [];

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $envelope = $this->readJson($file);
            if ($envelope !== null) {
                $results[basename($file, '.json')] = $envelope;
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $findings */
    public function writeFindings(string $siteId, string $extractionId, array $findings): void
    {
        $this->writeJson($this->extractionDir($siteId, $extractionId) . '/findings.json', $findings);
    }

    /** @return array<string, mixed>|null */
    public function readFindings(string $siteId, string $extractionId): ?array
    {
        return $this->readJson($this->extractionDir($siteId, $extractionId) . '/findings.json');
    }

    /**
     * observations.json — analyst-authored report lines:
     * {"items": [{id, section, color, title, description, include}]}, where
     * 'section' is one of the report contract's 'observations' fields.
     *
     * @param array<string, mixed> $data
     */
    public function writeObservations(string $siteId, string $extractionId, array $data): void
    {
        $this->writeJson($this->extractionDir($siteId, $extractionId) . '/observations.json', $data);
    }

    /** @return array<string, mixed>|null */
    public function readObservations(string $siteId, string $extractionId): ?array
    {
        return $this->readJson($this->extractionDir($siteId, $extractionId) . '/observations.json');
    }

    /**
     * Read-modify-write of observations.json under the extraction's lock:
     * $fn gets the current item list (a list, [] when nothing is stored yet)
     * and returns the new one, written back as {"items": [...]}, so
     * concurrent saves never drop an edit.
     *
     * @param callable(list<mixed>): list<mixed> $fn
     */
    public function mutateObservations(string $siteId, string $extractionId, callable $fn): void
    {
        $this->withExtractionLock($siteId, $extractionId, function () use ($siteId, $extractionId, $fn): void {
            $items = array_values((array) ($this->readObservations($siteId, $extractionId)['items'] ?? []));
            $this->writeObservations($siteId, $extractionId, ['items' => $fn($items)]);
        });
    }

    /**
     * This extraction's licence-key status per plugin/theme (a snapshot, not
     * carried forward; unrelated to SoftwareCatalog's free/premium class).
     *
     * licenses.json: {"plugin:<slug>"/"theme:<slug>" => "active"|"missing"|"to_validate"|"n_a"}.
     *
     * @return array<string, string>|null
     */
    public function readLicenses(string $siteId, string $extractionId): ?array
    {
        $decoded = $this->readJson($this->extractionDir($siteId, $extractionId) . '/licenses.json');

        return $decoded !== null ? array_map('strval', $decoded) : null;
    }

    /** False when $status isn't a known one — nothing is written. */
    public function setLicenseStatus(string $siteId, string $extractionId, string $type, string $slug, string $status): bool
    {
        if (!in_array($status, ['n_a', 'active', 'missing', 'to_validate'], true)) {
            return false;
        }

        $this->withExtractionLock($siteId, $extractionId, function () use ($siteId, $extractionId, $type, $slug, $status): void {
            $licenses = $this->readLicenses($siteId, $extractionId) ?? [];
            $licenses[$type . ':' . $slug] = $status;
            $this->writeJson($this->extractionDir($siteId, $extractionId) . '/licenses.json', $licenses);
        });

        return true;
    }

    /** @return array<string, mixed>|null */
    public function readMeta(string $siteId, string $extractionId): ?array
    {
        return $this->readJson($this->extractionDir($siteId, $extractionId) . '/meta.json');
    }

    /**
     * Merges $patch into meta.json (written at ingest, before choices such as
     * the report language exist).
     *
     * @param array<string, mixed> $patch
     */
    public function updateMeta(string $siteId, string $extractionId, array $patch): void
    {
        $this->withExtractionLock($siteId, $extractionId, function () use ($siteId, $extractionId, $patch): void {
            $meta = $this->readMeta($siteId, $extractionId) ?? [];
            $this->writeJson($this->extractionDir($siteId, $extractionId) . '/meta.json', array_merge($meta, $patch));
        });
    }

    /** @return array<string, mixed>|null */
    public function readSiteInfo(string $siteId): ?array
    {
        return $this->readJson($this->siteDir($siteId) . '/site.json');
    }

    /** @return list<string> site ids present on disk */
    public function listSiteIds(): array
    {
        $ids = [];
        foreach (glob($this->dataDir . '/sites/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $ids[] = basename($dir);
        }

        return $ids;
    }

    /** @return list<string> extraction ids, newest first */
    public function listExtractionIds(string $siteId): array
    {
        $ids = [];
        foreach (glob($this->siteDir($siteId) . '/extractions/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_link($dir)) { // skip the "latest" symlink
                $ids[] = basename($dir);
            }
        }
        rsort($ids);

        return $ids;
    }

    public function latestExtractionId(string $siteId): ?string
    {
        return $this->listExtractionIds($siteId)[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    public function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException("Unable to encode JSON for {$file}");
        }

        $this->writeRaw($file, $json . "\n");
    }

    /**
     * Atomic write: temp file in the same directory, then rename.
     */
    private function writeRaw(string $file, string $contents): void
    {
        $this->mkdir(dirname($file));

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Unable to write {$tmp}");
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to move {$tmp} to {$file}");
        }
    }

    /**
     * Claims the directory itself (a plain, non-recursive mkdir is atomic:
     * exactly one caller succeeds), so two pushes in the same second can
     * never both get the same id and write into one directory.
     */
    private function newExtractionId(string $siteId): string
    {
        $id = $this->timestampId(gmdate('c'));
        $this->mkdir($this->siteDir($siteId) . '/extractions');

        for ($n = 1; $n < 1000; $n++) {
            $candidate = $n === 1 ? $id : $id . '-' . $n;
            if (@mkdir($this->extractionDir($siteId, $candidate), 0775)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Unable to claim an extraction directory for {$siteId}");
    }

    /**
     * Runs $fn holding an exclusive lock on this extraction (a .lock file in
     * its directory). Readers never take it — every write is an atomic
     * rename, so a reader sees the old or the new file, never a torn one;
     * the lock only serialises read-modify-write sequences.
     */
    private function withExtractionLock(string $siteId, string $extractionId, callable $fn): void
    {
        $dir = $this->extractionDir($siteId, $extractionId);
        $this->mkdir($dir);

        $handle = fopen($dir . '/.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException("Unable to open lock file in {$dir}");
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Unable to lock {$dir}");
            }
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function timestampId(string $isoDate): string
    {
        $ts = strtotime($isoDate) ?: time();

        return gmdate('Ymd\THis\Z', $ts);
    }

    /**
     * The `latest` shortcut, for browsing data/ by hand only — nothing reads it.
     * symlink() is often in disable_functions, which raises an Error that `@`
     * does not suppress, hence the catch.
     */
    private function updateLatestLink(string $siteId, string $extractionId): void
    {
        $link = $this->siteDir($siteId) . '/extractions/latest';

        try {
            if (is_link($link)) {
                @unlink($link);
            }
            // Relative target so data/ stays relocatable.
            @symlink($extractionId, $link);
        } catch (Throwable) {
            // A missing shortcut costs nothing; failing the extraction costs the push.
        }
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory {$dir}");
        }
    }
}
