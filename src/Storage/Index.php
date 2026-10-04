<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

use PDO;

/**
 * SQLite index over the data/ tree. Rebuildable at any time (index:rebuild) —
 * never the source of truth.
 */
final class Index
{
    /** Received, awaiting an analyst's decision. */
    public const string STATUS_PENDING = 'pending';
    /** An analyst pressed "run"; the only status the cron worker picks up, so arrivals spend no probe quota. */
    public const string STATUS_QUEUED  = 'queued';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_DONE    = 'done';
    public const string STATUS_ERROR   = 'error';
    public const string STATUS_ABORTED = 'aborted';

    /** Bumped whenever an upgrade step is added to migrate(); stored in PRAGMA user_version. */
    public const int SCHEMA_VERSION = 1;

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
        $pdo = $this->pdo;
        if ($pdo === null || (int) $pdo->query('PRAGMA user_version')->fetchColumn() >= self::SCHEMA_VERSION) {
            return;
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sites (
                site_id    TEXT PRIMARY KEY,
                site_url   TEXT,
                home_url   TEXT
            );
            CREATE TABLE IF NOT EXISTS extractions (
                id               TEXT NOT NULL,
                site_id          TEXT NOT NULL,
                received_at      TEXT NOT NULL,
                schema_version   TEXT,
                wp_version       TEXT,
                php_version      TEXT,
                database_type    TEXT,
                database_version TEXT,
                status           TEXT NOT NULL DEFAULT 'pending',
                processed_at     TEXT,
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

        // Version 1: bring an index created by an older build to today's columns.
        $columns = array_column($pdo->query('PRAGMA table_info(extractions)')->fetchAll(), 'name');
        foreach (['database_type', 'database_version'] as $column) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec("ALTER TABLE extractions ADD COLUMN {$column} TEXT");
            }
        }
        $siteColumns = array_column($pdo->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
        foreach (['first_seen', 'last_seen', 'name'] as $column) {
            if (in_array($column, $siteColumns, true)) {
                $pdo->exec("ALTER TABLE sites DROP COLUMN {$column}");
            }
        }

        $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /** @param array<string, mixed> $payload */
    public function upsertSite(string $siteId, array $payload): void
    {
        $this->pdo()->prepare(<<<'SQL'
            INSERT INTO sites (site_id, site_url, home_url)
            VALUES (:site_id, :site_url, :home_url)
            ON CONFLICT(site_id) DO UPDATE SET
                site_url = COALESCE(excluded.site_url, site_url),
                home_url = COALESCE(excluded.home_url, home_url)
            SQL)->execute([
            'site_id'  => $siteId,
            'site_url' => $payload['site_url'] ?? null,
            'home_url' => $payload['home_url'] ?? null,
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
                (id, site_id, received_at, schema_version, wp_version, php_version, database_type, database_version, status)
            VALUES (:id, :site_id, :received_at, :schema_version, :wp_version, :php_version, :database_type, :database_version, :status)
            SQL)->execute([
            'id'                => $extractionId,
            'site_id'           => $siteId,
            'received_at'       => $receivedAt,
            'schema_version'    => $payload['schema_version'] ?? null,
            'wp_version'        => $payload['wp_version'] ?? null,
            'php_version'       => $payload['php']['version'] ?? null,
            'database_type'     => $payload['database_type'] ?? null,
            'database_version'  => $payload['database_version'] ?? null,
            'status'            => $status,
        ]);
    }

    public function setExtractionStatus(string $siteId, string $extractionId, string $status): void
    {
        // Stamped on running/done/error so requeueStale() measures from the run's start, not receipt.
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
     * Extractions an analyst has explicitly queued for analysis — the only
     * ones the cron worker processes.
     *
     * @return list<array<string, mixed>>
     */
    public function queuedExtractions(int $limit = 50): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM extractions WHERE status = 'queued' ORDER BY received_at ASC LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Reset extractions stuck in "running" for longer than $minutes back to "queued", so the worker picks them up again.
     */
    public function requeueStale(int $minutes): int
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $minutes * 60);
        $stmt   = $this->pdo()->prepare(<<<'SQL'
            UPDATE extractions SET status = 'queued'
            WHERE status = 'running' AND COALESCE(processed_at, received_at) < :cutoff
            SQL);
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * @return array{pending: int, running: int, done_24h: int, error: int}
     */
    public function statusCounts(): array
    {
        $pdo    = $this->pdo();
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);

        $pending = (int) $pdo->query(
            "SELECT COUNT(*) FROM extractions WHERE status IN ('pending', 'queued')"
        )->fetchColumn();
        $running = (int) $pdo->query(
            "SELECT COUNT(*) FROM extractions WHERE status = 'running'"
        )->fetchColumn();
        $error = (int) $pdo->query(
            "SELECT COUNT(*) FROM extractions WHERE status = 'error'"
        )->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM extractions WHERE status = 'done' AND COALESCE(processed_at, received_at) >= :cutoff"
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $done24h = (int) $stmt->fetchColumn();

        return ['pending' => $pending, 'running' => $running, 'done_24h' => $done24h, 'error' => $error];
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
                   (SELECT e.received_at FROM extractions e
                     WHERE e.site_id = s.site_id ORDER BY e.received_at DESC LIMIT 1) AS last_extraction_received_at,
                   (SELECT COUNT(*) FROM extractions e WHERE e.site_id = s.site_id) AS extraction_count
            FROM sites s
            SQL;

        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE s.site_url LIKE :q ' . SqlLike::ESCAPE . ' OR s.site_id LIKE :q ' . SqlLike::ESCAPE;
            $params['q'] = SqlLike::contains($search);
        }
        // A site with no extraction yet (NULL) sorts last under DESC.
        $sql .= ' ORDER BY last_extraction_received_at DESC';

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
            $site = $store->readSiteInfo($siteId) ?? [];
            $this->upsertSite($siteId, [
                'site_url' => $site['site_url'] ?? null,
                'home_url' => $site['home_url'] ?? null,
            ]);

            foreach ($store->listExtractionIds($siteId) as $extractionId) {
                $payload    = $store->readExtractionPayload($siteId, $extractionId) ?? [];
                $meta       = $store->readMeta($siteId, $extractionId) ?? [];
                // Only a hand-damaged tree lacks received_at; "now" invents no past date.
                $receivedAt = (string) ($meta['received_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
                $probes     = $store->readAllProbeResults($siteId, $extractionId);

                // From disk: no probes = never ran; probes + findings = done;
                // probes without findings = stopped part-way. A queued-only
                // extraction leaves no trace and comes back pending.
                $status = match (true) {
                    $probes === []                                           => self::STATUS_PENDING,
                    $store->readFindings($siteId, $extractionId) !== null    => self::STATUS_DONE,
                    default                                                  => self::STATUS_ERROR,
                };
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
