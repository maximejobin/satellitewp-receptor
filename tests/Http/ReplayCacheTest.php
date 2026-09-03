<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use SatelliteWP\Xtractor\Http\ReplayCache;
use SatelliteWP\Xtractor\Tests\TestCase;

final class ReplayCacheTest extends TestCase
{
    private function cache(): ReplayCache
    {
        return new ReplayCache($this->tmpDir . '/replay-cache.json');
    }

    public function testFirstSightingIsNotAReplay(): void
    {
        $this->assertFalse($this->cache()->seenBefore('sig-a', time(), 300));
    }

    public function testSecondSightingWithinTheWindowIsAReplay(): void
    {
        $cache = $this->cache();
        $cache->seenBefore('sig-a', time(), 300);

        $this->assertTrue($cache->seenBefore('sig-a', time(), 300));
    }

    public function testAnEntryPastItsWindowIsPrunedAndNoLongerCountsAsSeen(): void
    {
        $cache = $this->cache();
        // Already-expired the moment it's recorded (timestamp in the past,
        // zero-width window) — the very next check must treat it as fresh.
        $cache->seenBefore('sig-a', time() - 1000, 1);

        $this->assertFalse($cache->seenBefore('sig-a', time(), 300));
    }

    public function testPersistsAcrossInstances(): void
    {
        $file = $this->tmpDir . '/replay-cache.json';
        (new ReplayCache($file))->seenBefore('sig-a', time(), 300);

        $this->assertTrue((new ReplayCache($file))->seenBefore('sig-a', time(), 300));
    }
}
