<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

use PDO;

/**
 * SQLite index over the data/ tree. Rebuildable at any time (index:rebuild) —
 * never the source of truth.
 */
final class Index
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_DONE    = 'done';
    public const string STATUS_ERROR   = 'error';

    private ?PDO $pdo = null;

    public function __construct(private readonly string $dbFile)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dir = dirname($this->dbFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $this->pdo = new PDO('sqlite:' . $this->dbFile, options: [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->migrate();
        }

        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo?->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sites (
                site_id    TEXT PRIMARY KEY,
                site_url   TEXT,
                home_url   TEXT,
                name       TEXT,
                first_seen TEXT NOT NULL,
                last_seen  TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS extractions (
                id             TEXT NOT NULL,
                site_id        TEXT NOT NULL,
                received_at    TEXT NOT NULL,
                schema_version TEXT,
                wp_version     TEXT,
                php_version    TEXT,
                status         TEXT NOT NULL DEFAULT 'pending',
                processed_at   TEXT,
                PRIMARY KEY (site_id, id)
            );
            CREATE TABLE IF NOT EXISTS probe_runs (
                site_id       TEXT NOT NULL,
                extraction_id TEXT NOT NULL,
                probe         TEXT NOT NULL,
                status        TEXT NOT NULL,
                ran_at        TEXT NOT NULL,
                duration_ms   INTEGER,
                PRIMARY KEY (site_id, extraction_id, probe)
            );
            CREATE INDEX IF NOT EXISTS idx_extractions_status ON extractions(status);
            CREATE INDEX IF NOT EXISTS idx_extractions_site ON extractions(site_id, received_at DESC);
            SQL);
    }

    /** @param array<string, mixed> $payload */
    public function upsertSite(string $siteId, array $payload, string $seenAt): void
    {
        $this->pdo()->prepare(<<<'SQL'
            INSERT INTO sites (site_id, site_url, home_url, name, first_seen, last_seen)
            VALUES (:site_id, :site_url, :home_url, :name, :seen, :seen)
            ON CONFLICT(site_id) DO UPDATE SET
                site_url = COALESCE(excluded.site_url, site_url),
                home_url = COALESCE(excluded.home_url, home_url),
                name     = COALESCE(excluded.name, name),
                last_seen = excluded.last_seen
            SQL)->execute([
            'site_id'  => $siteId,
            'site_url' => $payload['site_url'] ?? null,
            'home_url' => $payload['home_url'] ?? null,
            'name'     => $payload['site_title'] ?? null,
            'seen'     => $seenAt,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function insertExtraction(
        string $siteId,
        string $extractionId,
        string $receivedAt,
        array $payload,
        string $status = self::STATUS_PENDING,
    ): void {
        $this->pdo()->prepare(<<<'SQL'
            INSERT OR REPLACE INTO extractions
                (id, site_id, received_at, schema_version, wp_version, php_version, status)
            VALUES (:id, :site_id, :received_at, :schema_version, :wp_version, :php_version, :status)
            SQL)->execute([
            'id'             => $extractionId,
            'site_id'        => $siteId,
            'received_at'    => $receivedAt,
            'schema_version' => $payload['schema_version'] ?? null,
            'wp_version'     => $payload['wp_version'] ?? null,
            'php_version'    => $payload['php']['version'] ?? null,
            'status'         => $status,
        ]);
    }

    public function setExtractionStatus(string $siteId, string $extractionId, string $status): void
    {
        // Stamp the transition time on running/done/error so requeueStale
        // measures staleness from when the run STARTED, not from receipt.
        $this->pdo()->prepare(<<<'SQL'
            UPDATE extractions
            SET status = :status,
                processed_at = CASE WHEN :status IN ('running', 'done', 'error') THEN :now ELSE processed_at END
            WHERE site_id = :site_id AND id = :id
            SQL)->execute([
            'status'  => $status,
            'now'     => gmdate('Y-m-d\TH:i:s\Z'),
            'site_id' => $siteId,
            'id'      => $extractionId,
        ]);
    }

    public function upsertProbeRun(
        string $siteId,
        string $extractionId,
        string $probe,
        string $status,
        string $ranAt,
        int $durationMs,
    ): void {
        $this->pdo()->prepare(<<<'SQL'
            INSERT OR REPLACE INTO probe_runs (site_id, extraction_id, probe, status, ran_at, duration_ms)
            VALUES (:site_id, :extraction_id, :probe, :status, :ran_at, :duration_ms)
            SQL)->execute([
            'site_id'       => $siteId,
            'extraction_id' => $extractionId,
            'probe'         => $probe,
            'status'        => $status,
            'ran_at'        => $ranAt,
            'duration_ms'   => $durationMs,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function pendingExtractions(int $limit = 50): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM extractions WHERE status = 'pending' ORDER BY received_at ASC LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Reset extractions stuck in "running" for longer than $minutes back to "pending".
     */
    public function requeueStale(int $minutes): int
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $minutes * 60);
        $stmt   = $this->pdo()->prepare(<<<'SQL'
            UPDATE extractions SET status = 'pending'
            WHERE status = 'running' AND COALESCE(processed_at, received_at) < :cutoff
            SQL);
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function listSites(?string $search = null): array
    {
        $sql = <<<'SQL'
            SELECT s.*,
                   (SELECT e.id FROM extractions e
                     WHERE e.site_id = s.site_id ORDER BY e.received_at DESC LIMIT 1) AS last_extraction_id,
                   (SELECT e.status FROM extractions e
                     WHERE e.site_id = s.site_id ORDER BY e.received_at DESC LIMIT 1) AS last_extraction_status,
                   (SELECT COUNT(*) FROM extractions e WHERE e.site_id = s.site_id) AS extraction_count
            FROM sites s
            SQL;

        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE s.site_url LIKE :q OR s.name LIKE :q OR s.site_id LIKE :q';
            $params['q'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY s.last_seen DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listExtractions(string $siteId, int $limit = 100): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM extractions WHERE site_id = :site_id ORDER BY received_at DESC LIMIT :limit'
        );
        $stmt->bindValue('site_id', $siteId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listProbeRuns(string $siteId, string $extractionId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM probe_runs WHERE site_id = :site_id AND extraction_id = :extraction_id ORDER BY probe'
        );
        $stmt->execute(['site_id' => $siteId, 'extraction_id' => $extractionId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function getExtraction(string $siteId, string $extractionId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM extractions WHERE site_id = :site_id AND id = :id'
        );
        $stmt->execute(['site_id' => $siteId, 'id' => $extractionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Wipe and regenerate every table from the data/ tree.
     */
    public function rebuildFrom(DataStore $store): int
    {
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM probe_runs');
        $pdo->exec('DELETE FROM extractions');
        $pdo->exec('DELETE FROM sites');

        $count = 0;
        foreach ($store->listSiteIds() as $siteId) {
            $site   = $store->readSiteInfo($siteId) ?? [];
            $seenAt = (string) ($site['last_seen'] ?? gmdate('Y-m-d\TH:i:s\Z'));
            $this->upsertSite($siteId, [
                'site_url'   => $site['site_url'] ?? null,
                'home_url'   => $site['home_url'] ?? null,
                'site_title' => $site['name'] ?? null,
            ], $seenAt);

            foreach ($store->listExtractionIds($siteId) as $extractionId) {
                $payload    = $store->readExtractionPayload($siteId, $extractionId) ?? [];
                $meta       = $store->readMeta($siteId, $extractionId) ?? [];
                $receivedAt = (string) ($meta['received_at'] ?? $seenAt);
                $probes     = $store->readAllProbeResults($siteId, $extractionId);

                $status = $probes === [] ? self::STATUS_PENDING : self::STATUS_DONE;
                $this->insertExtraction($siteId, $extractionId, $receivedAt, $payload, $status);

                foreach ($probes as $name => $envelope) {
                    $this->upsertProbeRun(
                        $siteId,
                        $extractionId,
                        $name,
                        (string) ($envelope['status'] ?? 'error'),
                        (string) ($envelope['ran_at'] ?? $receivedAt),
                        (int) ($envelope['duration_ms'] ?? 0)
                    );
                }
                $count++;
            }
        }

        return $count;
    }
}
