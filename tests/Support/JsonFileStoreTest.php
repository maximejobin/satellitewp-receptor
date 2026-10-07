<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use RuntimeException;
use SatelliteWP\Manager\Support\JsonFileStore;
use SatelliteWP\Manager\Tests\TestCase;

final class JsonFileStoreTest extends TestCase
{
    public function testMutateWritesTheReturnedDocumentWithTheGivenMode(): void
    {
        $file  = $this->tmpDir . '/sub/doc.json';
        $store = new JsonFileStore($file, 0600);

        $result = $store->mutate(static fn (array $doc): array => ['ok', $doc + ['a' => 1]]);

        self::assertSame('ok', $result);
        self::assertSame(['a' => 1], $store->read());
        self::assertSame('600', substr(sprintf('%o', fileperms($file)), -3));
        self::assertSame([], glob($file . '.tmp*') ?: []);
    }

    public function testANullDocumentWritesNothing(): void
    {
        $file  = $this->tmpDir . '/doc.json';
        $store = new JsonFileStore($file);

        self::assertFalse($store->mutate(static fn (array $doc): array => [false, null]));
        self::assertFileDoesNotExist($file);
    }

    public function testAnUnencodableDocumentLeavesTheFileUntouched(): void
    {
        $file  = $this->tmpDir . '/doc.json';
        $store = new JsonFileStore($file);
        $store->mutate(static fn (array $doc): array => [null, ['keep' => 'me']]);
        $before = (string) file_get_contents($file);

        try {
            $store->mutate(static fn (array $doc): array => [null, $doc + ['bad' => "\xB1\x31"]]);
            self::fail('an invalid UTF-8 string must not be encoded');
        } catch (RuntimeException) {
        }

        self::assertSame($before, file_get_contents($file));
        self::assertSame([], glob($file . '.tmp*') ?: []);
    }

    /** Two processes incrementing one counter: without the lock, increments are lost. */
    public function testConcurrentMutationsFromTwoProcessesAreNotLost(): void
    {
        $file     = $this->tmpDir . '/counter.json';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script   = $this->tmpDir . '/increment.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1];
            $store = new SatelliteWP\Manager\Support\JsonFileStore($argv[2]);
            for ($i = 0; $i < 100; $i++) {
                $store->mutate(static function (array $doc): array {
                    $n = (int) ($doc['n'] ?? 0);
                    usleep(100);

                    return [null, ['n' => $n + 1]];
                });
            }
            PHP);

        $processes = [];
        for ($p = 0; $p < 2; $p++) {
            $command     = [PHP_BINARY, $script, $autoload, $file];
            $processes[] = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        }
        foreach ($processes as $process) {
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
        }

        self::assertSame(['n' => 200], (new JsonFileStore($file))->read());
    }
}
