<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

/**
 * User input used as a LIKE "contains" search, matched literally: %, _ and
 * the escape character are escaped, and every such LIKE must carry the
 * ESCAPE clause below. The escape character is "!", not "\": MySQL reads
 * '\' as an unterminated string literal, SQLite does not.
 */
final class SqlLike
{
    public const string ESCAPE = "ESCAPE '!'";

    public static function contains(string $needle): string
    {
        return '%' . strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
