<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

/**
 * User input used as a LIKE "contains" search, matched literally: %, _ and \
 * are escaped, and every such LIKE must carry the ESCAPE clause below (same
 * syntax in SQLite and MySQL).
 */
final class SqlLike
{
    public const string ESCAPE = "ESCAPE '\\'";

    public static function contains(string $needle): string
    {
        return '%' . addcslashes($needle, '\\%_') . '%';
    }
}
