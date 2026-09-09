<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Reference;

use SatelliteWP\Xtractor\Reference\WordPressVersions;
use SatelliteWP\Xtractor\Tests\TestCase;

final class WordPressVersionsTest extends TestCase
{
    private function seed(array $versions): WordPressVersions
    {
        $file = $this->tmpDir . '/wordpress-versions.json';
        file_put_contents($file, (string) json_encode($versions));

        return new WordPressVersions($file);
    }

    public function testMajorVersionsBehindCountsBranchesNotPointReleases(): void
    {
        // 7.0.4 installed, 7.1 latest, one point release apart on 7.0's own
        // branch (7.1) — but still only ONE branch behind (7.0 -> 7.1), the
        // distinction rule F1 now cares about (2026-09-07).
        $wp = $this->seed(['6.9' => '', '7.0.4' => '', '7.1' => 'latest']);

        $this->assertSame(1, $wp->majorVersionsBehind('7.0.4'));
    }

    public function testMajorVersionsBehindOnTheLatestBranchIsZero(): void
    {
        $wp = $this->seed(['7.0' => '', '7.1.2' => 'latest']);

        $this->assertSame(0, $wp->majorVersionsBehind('7.1.0'));
    }

    public function testMajorVersionsBehindFourOrMoreBranches(): void
    {
        $wp = $this->seed([
            '5.9' => '', '6.0' => '', '6.1' => '', '6.2' => '',
            '6.3' => '', '6.4' => '', '6.5' => 'latest',
        ]);

        // 5.9 -> 6.5 is six branches behind.
        $this->assertSame(6, $wp->majorVersionsBehind('5.9.3'));
    }

    public function testMajorVersionsBehindIsNullWithoutALatestMarker(): void
    {
        $wp = $this->seed(['6.8' => '', '6.9' => '']);

        $this->assertNull($wp->majorVersionsBehind('6.8.1'));
    }

    public function testMajorVersionsBehindIsNullWhenCacheNeverRefreshed(): void
    {
        $wp = new WordPressVersions($this->tmpDir . '/never-written.json');

        $this->assertNull($wp->majorVersionsBehind('6.8.1'));
    }

    /**
     * The installed branch doesn't have to appear in the cache itself (e.g.
     * a brand-new point release wordpress.org hasn't listed explicitly yet —
     * see the "6.5.1 is absent from stable-check" note elsewhere in this
     * project) as long as its own major.minor branch can still be derived
     * and placed in version order.
     */
    public function testMajorVersionsBehindWorksWhenInstalledVersionItselfIsMissingFromCache(): void
    {
        $wp = $this->seed(['6.8' => '', '7.0' => '', '7.1' => 'latest']);

        $this->assertSame(1, $wp->majorVersionsBehind('7.0.99'));
    }
}
