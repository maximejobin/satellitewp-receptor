<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

use InvalidArgumentException;

/**
 * A gzipped ustar archive built in memory: `tar -xz` reads it on any Linux or
 * macOS shell, with no zip tool or PHP extension needed on either side.
 */
final class TarGz
{
    private const int BLOCK = 512;

    /**
     * @param array<string, string> $files archive path => content; parent directories are added
     */
    public static function build(array $files, int $mtime): string
    {
        $tar  = '';
        $dirs = [];
        foreach (array_keys($files) as $path) {
            self::assertSafePath($path);
            $parts = explode('/', $path);
            array_pop($parts);
            for ($i = 1; $i <= count($parts); $i++) {
                $dirs[implode('/', array_slice($parts, 0, $i)) . '/'] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            $tar .= self::header($dir, 0, $mtime, '5', 0755);
        }
        foreach ($files as $path => $content) {
            $tar .= self::header($path, strlen($content), $mtime, '0', 0644);
            $tar .= $content . str_repeat("\0", (self::BLOCK - strlen($content) % self::BLOCK) % self::BLOCK);
        }
        $tar .= str_repeat("\0", self::BLOCK * 2);

        return (string) gzencode($tar, 9);
    }

    /** Relative, no "..", short enough for the plain ustar name field. */
    private static function assertSafePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || strlen($path) > 99
            || in_array('..', explode('/', $path), true) || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Unsafe archive path: ' . $path);
        }
    }

    private static function header(string $name, int $size, int $mtime, string $type, int $mode): string
    {
        $header = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', $mtime) . "\0"
            . str_repeat(' ', 8)
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_pad('root', 32, "\0")
            . str_pad('root', 32, "\0")
            . str_repeat("\0", 8 + 8 + 155 + 12);

        // The checksum is computed with its own field read as eight spaces.
        $sum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }
}
