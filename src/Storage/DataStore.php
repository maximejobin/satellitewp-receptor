<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

use RuntimeException;

/**
 * All reads/writes under data/. JSON files are the source of truth.
 *
 * Layout:
 *   data/sites/<site_id>/site.json
 *   data/sites/<site_id>/extractions/<id>/{payload,meta,findings}.json
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
    public function updateSiteInfo(string $siteId, array $payload, string $seenAt): void
    {
        $file     = $this->siteDir($siteId) . '/site.json';
        $existing = $this->readJson($file) ?? [];

        $this->writeJson($file, [
            'site_id'    => $siteId,
            'site_url'   => $payload['site_url'] ?? $existing['site_url'] ?? null,
            'home_url'   => $payload['home_url'] ?? $existing['home_url'] ?? null,
            'name'       => $payload['site_title'] ?? $existing['name'] ?? null,
            'first_seen' => $existing['first_seen'] ?? $seenAt,
            'last_seen'  => $seenAt,
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

    /** @return array<string, mixed>|null */
    public function readMeta(string $siteId, string $extractionId): ?array
    {
        return $this->readJson($this->extractionDir($siteId, $extractionId) . '/meta.json');
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

    private function newExtractionId(string $siteId): string
    {
        $id  = $this->timestampId(gmdate('c'));
        $dir = $this->extractionDir($siteId, $id);

        for ($n = 2; is_dir($dir); $n++) {
            $dir = $this->extractionDir($siteId, $id . '-' . $n);
        }

        return basename($dir);
    }

    private function timestampId(string $isoDate): string
    {
        $ts = strtotime($isoDate) ?: time();

        return gmdate('Ymd\THis\Z', $ts);
    }

    private function updateLatestLink(string $siteId, string $extractionId): void
    {
        $link = $this->siteDir($siteId) . '/extractions/latest';

        if (is_link($link)) {
            @unlink($link);
        }
        // Relative target so data/ stays relocatable. Failure is harmless (index has the info).
        @symlink($extractionId, $link);
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory {$dir}");
        }
    }
}
