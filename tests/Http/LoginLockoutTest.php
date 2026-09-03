<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use SatelliteWP\Xtractor\Http\LoginLockout;
use SatelliteWP\Xtractor\Tests\TestCase;

final class LoginLockoutTest extends TestCase
{
    private function lockout(): LoginLockout
    {
        return new LoginLockout($this->tmpDir . '/login-lockout.json');
    }

    public function testAFreshKeyIsNotLocked(): void
    {
        $this->assertFalse($this->lockout()->isLocked('1.2.3.4'));
    }

    public function testFourFailuresDoNotLockYet(): void
    {
        $lockout = $this->lockout();
        for ($i = 0; $i < 4; $i++) {
            $lockout->recordFailure('1.2.3.4');
        }

        $this->assertFalse($lockout->isLocked('1.2.3.4'));
    }

    public function testTheFifthFailureLocksTheKey(): void
    {
        $lockout = $this->lockout();
        for ($i = 0; $i < 5; $i++) {
            $lockout->recordFailure('1.2.3.4');
        }

        $this->assertTrue($lockout->isLocked('1.2.3.4'));
        $this->assertGreaterThan(0, $lockout->retryAfter('1.2.3.4'));
    }

    public function testLockingOneAddressDoesNotAffectAnother(): void
    {
        $lockout = $this->lockout();
        for ($i = 0; $i < 5; $i++) {
            $lockout->recordFailure('1.2.3.4');
        }

        $this->assertFalse($lockout->isLocked('5.6.7.8'));
    }

    public function testASuccessClearsPastFailures(): void
    {
        $lockout = $this->lockout();
        for ($i = 0; $i < 4; $i++) {
            $lockout->recordFailure('1.2.3.4');
        }
        $lockout->recordSuccess('1.2.3.4');
        $lockout->recordFailure('1.2.3.4');

        // Only one failure since the reset — nowhere near the lock threshold.
        $this->assertFalse($lockout->isLocked('1.2.3.4'));
    }

    public function testPersistsAcrossInstances(): void
    {
        $file = $this->tmpDir . '/login-lockout.json';
        $a    = new LoginLockout($file);
        for ($i = 0; $i < 5; $i++) {
            $a->recordFailure('1.2.3.4');
        }

        $this->assertTrue((new LoginLockout($file))->isLocked('1.2.3.4'));
    }
}
