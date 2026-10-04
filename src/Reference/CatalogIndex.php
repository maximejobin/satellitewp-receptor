<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Reference;

use PDO;
use SatelliteWP\Manager\Catalog\SoftwareCatalog;

/**
 * Rebuildable SQLite copy of the Wordfence cache and the software catalogue —
 * never the source of truth (the JSON files are). It makes the two joinable
 * and lets /data/vulnerabilities sort by column without streaming the cache.
 * software_catalog.notes has no writer; rebuilds deliberately preserve it.
 */
final class CatalogIndex
{
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
            CREATE TABLE IF NOT EXISTS vulnerabilities (
                type              TEXT NOT NULL,
                slug              TEXT NOT NULL,
                name              TEXT,
                vuln_id           TEXT,
                title             TEXT,
                cve_id            TEXT,
                cvss_score        REAL,
                cvss_rating       TEXT,
                published_at      TEXT,
                patched           INTEGER NOT NULL DEFAULT 0,
                patched_versions  TEXT,
                informational     INTEGER NOT NULL DEFAULT 0,
                source            TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_vuln_type_slug ON vulnerabilities(type, slug);
            CREATE INDEX IF NOT EXISTS idx_vuln_cve ON vulnerabilities(cve_id);
            CREATE INDEX IF NOT EXISTS idx_vuln_published ON vulnerabilities(published_at);

            CREATE TABLE IF NOT EXISTS software_catalog (
                type      TEXT NOT NULL,
                slug      TEXT NOT NULL,
                name      TEXT,
                license   TEXT,
                suggested TEXT,
                source    TEXT,
                notes     TEXT,
                PRIMARY KEY (type, slug)
            );
            SQL);
    }

    /**
     * Wipes and rebuilds the vulnerabilities table from the JSON Lines cache
     * file (source of truth, untouched) in one transaction — streamed line
     * by line, same as WordfenceIndex's own reads, so this never holds the
     * whole ~84 000-row feed in PHP memory at once.
     */
    public function rebuildVulnerabilities(string $wordfenceCacheFile): int
    {
        $pdo   = $this->pdo();
        $count = 0;

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM vulnerabilities');
            $stmt = $pdo->prepare(<<<'SQL'
                INSERT INTO vulnerabilities
                    (type, slug, name, vuln_id, title, cve_id, cvss_score, cvss_rating,
                     published_at, patched, patched_versions, informational, source)
                VALUES
                    (:type, :slug, :name, :vuln_id, :title, :cve_id, :cvss_score, :cvss_rating,
                     :published_at, :patched, :patched_versions, :informational, :source)
                SQL);

            $handle = is_file($wordfenceCacheFile) ? @fopen($wordfenceCacheFile, 'rb') : false;
            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode($line, true);
                    if (!is_array($row) || !isset($row['k'], $row['v']) || !is_array($row['v'])) {
                        continue;
                    }
                    [$type, $slug] = array_pad(explode(':', (string) $row['k'], 2), 2, '');

                    foreach ($row['v'] as $vuln) {
                        if (!is_array($vuln)) {
                            continue;
                        }
                        $stmt->execute([
                            'type'             => $type,
                            'slug'             => $slug,
                            'name'             => (string) ($vuln['name'] ?? $slug),
                            'vuln_id'          => isset($vuln['id']) ? (string) $vuln['id'] : null,
                            'title'            => isset($vuln['title']) ? (string) $vuln['title'] : null,
                            'cve_id'           => isset($vuln['cve_id']) ? (string) $vuln['cve_id'] : null,
                            'cvss_score'       => isset($vuln['cvss_score']) ? (float) $vuln['cvss_score'] : null,
                            'cvss_rating'      => isset($vuln['cvss_rating']) ? (string) $vuln['cvss_rating'] : null,
                            'published_at'     => isset($vuln['published_at']) ? (string) $vuln['published_at'] : null,
                            'patched'          => !empty($vuln['patched']) ? 1 : 0,
                            'patched_versions' => json_encode(array_values((array) ($vuln['patched_versions'] ?? []))),
                            'informational'    => !empty($vuln['informational']) ? 1 : 0,
                            'source'           => isset($vuln['source']) ? (string) $vuln['source'] : null,
                        ]);
                        $count++;
                    }
                }
                fclose($handle);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    /**
     * Number of vulnerabilities on file for one component — the join used to
     * surface a "Vulnerable" indicator on the software catalogue.
     */
    public function vulnerabilityCount(string $type, string $slug): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM vulnerabilities WHERE type = :type AND slug = :slug'
        );
        $stmt->execute(['type' => $type, 'slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Server-side search for /data/vulnerabilities. $orderColumn is untrusted
     * request input: checked against an allowlist, never interpolated as-is.
     *
     * @param list<string> $ignored lowercase vulnerability ids; rows matching one come back with 'ignored' => true
     * @param 'all'|'only'|'hide' $ignoredMode restrict to (only) or exclude (hide) the ignored rows
     * @return array{total: int, filtered: int, rows: list<array<string, mixed>>}
     */
    public function searchVulnerabilities(
        string $query,
        int $start,
        int $length,
        string $orderColumn = 'published_at',
        string $orderDir = 'desc',
        array $ignored = [],
        string $ignoredMode = 'all',
    ): array {
        $pdo = $this->pdo();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM vulnerabilities')->fetchColumn();

        $conditions = [];
        $params     = [];
        $needle     = trim($query);
        if ($needle !== '') {
            $conditions[] = '(slug LIKE :q OR name LIKE :q OR title LIKE :q OR cve_id LIKE :q OR vuln_id LIKE :q)';
            $params['q']  = '%' . $needle . '%';
        }

        $ignoredSql = $ignored === [] ? '0' : 'LOWER(vuln_id) IN (' . implode(',', array_map(
            static fn (int $i): string => ':ig' . $i,
            array_keys($ignored)
        )) . ')';
        if ($ignoredMode === 'only' || $ignoredMode === 'hide') {
            $conditions[] = $ignoredMode === 'only' ? $ignoredSql : "NOT ({$ignoredSql})";
            foreach ($ignored as $i => $id) {
                $params['ig' . $i] = $id;
            }
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $filtered = (int) self::bound(
            $pdo->prepare("SELECT COUNT(*) FROM vulnerabilities {$where}"),
            $params
        )->fetchColumn();

        $sortable = ['name', 'type', 'cve_id', 'title', 'published_at', 'cvss_score'];
        $column   = in_array($orderColumn, $sortable, true) ? $orderColumn : 'published_at';
        $dir      = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';

        // NULLs (unscored/undated entries) always sort last, whichever
        // direction is picked — a NULL "first" under ASC would otherwise put
        // the least informative rows at the very top of the table.
        $stmt = $pdo->prepare(
            "SELECT * FROM vulnerabilities {$where} "
            . "ORDER BY {$column} IS NULL, {$column} {$dir}, published_at DESC "
            . 'LIMIT :length OFFSET :start'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('length', $length, PDO::PARAM_INT);
        $stmt->bindValue('start', $start, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $row) use ($ignored): array {
            $row['patched']          = (bool) $row['patched'];
            $row['informational']    = (bool) $row['informational'];
            $row['ignored']          = in_array(strtolower((string) ($row['vuln_id'] ?? '')), $ignored, true);
            $row['patched_versions'] = (array) json_decode((string) $row['patched_versions'], true);

            return $row;
        }, $stmt->fetchAll());

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $rows];
    }

    /** @param array<string, mixed> $params */
    private static function bound(\PDOStatement $stmt, array $params): \PDOStatement
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * Insert or refresh one catalogue entry (never "notes") — for a full
     * rebuild and for the single row an analyst just classified.
     *
     * @param array<string, mixed> $entry a SoftwareCatalog record (type, slug, name, license, suggested, source)
     */
    public function upsertCatalogEntry(array $entry): void
    {
        $this->pdo()->prepare(<<<'SQL'
            INSERT INTO software_catalog (type, slug, name, license, suggested, source)
            VALUES (:type, :slug, :name, :license, :suggested, :source)
            ON CONFLICT(type, slug) DO UPDATE SET
                name      = excluded.name,
                license   = excluded.license,
                suggested = excluded.suggested,
                source    = excluded.source
            SQL)->execute([
            'type'      => (string) $entry['type'],
            'slug'      => (string) $entry['slug'],
            'name'      => (string) ($entry['name'] ?? $entry['slug']),
            'license'   => (string) ($entry['license'] ?? SoftwareCatalog::LICENSE_UNKNOWN),
            'suggested' => $entry['suggested'] ?? null,
            'source'    => $entry['source'] ?? null,
        ]);
    }

    /** Re-syncs every entry from the catalogue's own JSON file. */
    public function rebuildCatalog(SoftwareCatalog $catalog): int
    {
        $count = 0;
        foreach ($catalog->all() as $entry) {
            $this->upsertCatalogEntry($entry);
            $count++;
        }

        return $count;
    }
}
