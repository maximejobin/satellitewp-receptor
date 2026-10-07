<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

use JsonException;
use RuntimeException;

/**
 * A small JSON document on disk shared by concurrent requests and CLI runs.
 * mutate() holds an exclusive lock on a sibling `.lock` file across
 * read → change → write, so two writers never lose each other's update, and
 * an unencodable document throws before anything is written.
 */
final class JsonFileStore
{
    /** @param int $mode permissions applied to the file before it replaces the old one */
    public function __construct(private readonly string $file, private readonly int $mode = 0600)
    {
    }

    /** @return array<mixed> the decoded document; [] when absent or unreadable */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Runs $change on the freshly re-read document under the lock. It returns
     * [result, new document]; a null document means "nothing to write".
     *
     * @template T
     * @param callable(array<mixed>): array{0: T, 1: array<mixed>|null} $change
     * @return T
     */
    public function mutate(callable $change): mixed
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException("Cannot lock {$this->file}");
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException("Cannot lock {$this->file}");
        }

        try {
            [$result, $document] = $change($this->read());
            if ($document !== null) {
                $this->write($document);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<mixed> $document */
    private function write(array $document): void
    {
        try {
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Unable to encode {$this->file}: " . $e->getMessage(), 0, $e);
        }

        AtomicFile::write($this->file, $json . "\n", $this->mode);
    }
}
