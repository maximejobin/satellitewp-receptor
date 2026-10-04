<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use RuntimeException;
use SatelliteWP\Manager\Support\AtomicFile;
use SatelliteWP\Manager\Tests\TestCase;

final class AtomicFileTest extends TestCase
{
    public function testWriteReplacesTheFileAndLeavesNoTempBehind(): void
    {
        $file = $this->tmpDir . '/cache.json';
        file_put_contents($file, 'old');

        AtomicFile::write($file, 'new');

        $this->assertSame('new', file_get_contents($file));
        $this->assertSame([$file], glob($this->tmpDir . '/cache.json*'));
    }

    public function testWriteChunksStreamsEveryChunkInOrder(): void
    {
        $file = $this->tmpDir . '/lines.jsonl';

        AtomicFile::writeChunks($file, (static function (): \Generator {
            yield "a\n";
            yield "b\n";
        })());

        $this->assertSame("a\nb\n", file_get_contents($file));
    }

    public function testAnUnwritableTargetThrowsAndKeepsTheOldFile(): void
    {
        $this->expectException(RuntimeException::class);

        AtomicFile::write($this->tmpDir . '/missing-dir/cache.json', 'x');
    }
}
