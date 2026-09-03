<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * nearest_patched_version() — a WordPress core fix commonly lands in several
 * branches at once (6.4.5 *and* 6.5.2 for the same CVE). Showing whichever a
 * source happened to list first could name a branch behind the one actually
 * installed, which reads as "downgrade to fix this".
 */
final class HelpersNearestPatchedVersionTest extends TestCase
{
    public function testPrefersTheFixInTheInstalledBranch(): void
    {
        // Installed 6.5.1: the 6.5.x fix applies without a branch change,
        // even though a lower-numbered 6.4.x fix also exists.
        $this->assertSame(
            '6.5.2',
            \nearest_patched_version(['6.4.5', '6.5.2', '6.6.0'], '6.5.1')
        );
    }

    public function testFallsBackToTheNearestHigherBranchWhenNoFixInTheInstalledBranch(): void
    {
        // Installed 6.3.0, no 6.3.x fix exists: the nearest branch above it
        // that does, not the lowest version number overall.
        $this->assertSame(
            '6.4.5',
            \nearest_patched_version(['6.4.5', '6.5.2'], '6.3.0')
        );
    }

    public function testSameBranchButLowerPatchIsIgnored(): void
    {
        // A "patch" below the installed version in the same branch would
        // mean downgrading — never recommend that.
        $this->assertSame(
            '6.5.2',
            \nearest_patched_version(['6.5.0', '6.5.2'], '6.5.1')
        );
    }

    public function testEveryKnownPatchAlreadyBelowInstalledReturnsTheHighestSeen(): void
    {
        $this->assertSame(
            '6.5.2',
            \nearest_patched_version(['6.4.0', '6.5.2'], '6.6.0')
        );
    }

    public function testWildcardAndEmptyCandidatesAreIgnored(): void
    {
        $this->assertSame('6.5.2', \nearest_patched_version(['*', '', '6.5.2'], '6.5.0'));
    }

    public function testNoCandidatesReturnsNull(): void
    {
        $this->assertNull(\nearest_patched_version([], '6.5.0'));
        $this->assertNull(\nearest_patched_version(['*', ''], '6.5.0'));
    }

    public function testWithoutAnInstalledVersionReturnsTheLowestCandidate(): void
    {
        $this->assertSame('6.4.5', \nearest_patched_version(['6.6.0', '6.4.5', '6.5.2'], null));
    }
}
