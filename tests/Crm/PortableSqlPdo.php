<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Crm;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * SQLite PDO that rejects SQL which SQLite accepts but MySQL reads
 * differently: a backslash (an escape inside MySQL string literals, so
 * '\' is unterminated there) or a double quote (a string in MySQL, an
 * identifier in SQLite). Every repository query goes through it.
 */
final class PortableSqlPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function assertPortable(string $sql): void
    {
        foreach (['\\' => 'a backslash', '"' => 'a double quote'] as $char => $label) {
            if (str_contains($sql, $char)) {
                throw new RuntimeException("Non-portable SQL (contains {$label}; MySQL parses it differently): {$sql}");
            }
        }
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        self::assertPortable($query);

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        self::assertPortable($query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        self::assertPortable($statement);

        return parent::exec($statement);
    }
}
