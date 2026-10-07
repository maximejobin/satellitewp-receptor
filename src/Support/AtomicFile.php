<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

use RuntimeException;

/**
 * Temp file + rename: a concurrent reader sees the old file or the new one,
 * never a truncated one.
 */
final class AtomicFile
{
    /** @param int|null $mode set on the temp file, so the new file is never readable with looser permissions */
    public static function write(string $file, string $contents, ?int $mode = null): void
    {
        self::writeChunks($file, [$contents], $mode);
    }

    /** @param iterable<string> $chunks streamed to disk, so a large file is never built in memory */
    public static function writeChunks(string $file, iterable $chunks, ?int $mode = null): void
    {
        $tmp    = $file . '.tmp.' . bin2hex(random_bytes(4));
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write {$file}");
        }

        $ok = true;
        foreach ($chunks as $chunk) {
            if (fwrite($handle, $chunk) !== strlen($chunk)) {
                $ok = false;
                break;
            }
        }
        $ok = fclose($handle) && $ok;
        if ($ok && $mode !== null) {
            $ok = chmod($tmp, $mode);
        }

        if (!$ok || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write {$file}");
        }
    }
}
