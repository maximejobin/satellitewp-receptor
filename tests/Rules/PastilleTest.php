<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Rules;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Rules\Pastille;
use SatelliteWP\Manager\Rules\Severity;
use SatelliteWP\Manager\Rules\Status;

final class PastilleTest extends TestCase
{
    public function testPassIsGreen(): void
    {
        $this->assertSame(Pastille::Green, Pastille::for(Status::Pass, Severity::High));
        $this->assertSame(Pastille::Green, Pastille::for(Status::Pass, Severity::Medium));
    }

    public function testFailCriticalOrHighIsRed(): void
    {
        $this->assertSame(Pastille::Red, Pastille::for(Status::Fail, Severity::Critical));
        $this->assertSame(Pastille::Red, Pastille::for(Status::Fail, Severity::High));
    }

    public function testFailMediumIsOrange(): void
    {
        $this->assertSame(Pastille::Orange, Pastille::for(Status::Fail, Severity::Medium));
    }

    public function testInfoIsAlwaysBlue(): void
    {
        // Info-severity rules are informational, whatever the outcome.
        $this->assertSame(Pastille::Blue, Pastille::for(Status::Fail, Severity::Info));
        $this->assertSame(Pastille::Blue, Pastille::for(Status::Pass, Severity::Info));
    }

    public function testNaAndUnknownAreGreyEvenForInfoSeverity(): void
    {
        $this->assertSame(Pastille::Grey, Pastille::for(Status::NotApplicable, Severity::Medium));
        $this->assertSame(Pastille::Grey, Pastille::for(Status::Unknown, Severity::High));
        // "No result" beats the Info-severity blue override.
        $this->assertSame(Pastille::Grey, Pastille::for(Status::Unknown, Severity::Info));
    }

    public function testClientActionIsPurpleRegardlessOfStatusOrSeverity(): void
    {
        $this->assertSame(Pastille::Purple, Pastille::for(Status::Pass, Severity::Info, true));
        $this->assertSame(Pastille::Purple, Pastille::for(Status::Fail, Severity::Critical, true));
        $this->assertSame(Pastille::Purple, Pastille::for(Status::Fail, Severity::Medium, true));
    }

    public function testClientActionStillYieldsGreyWithNoResult(): void
    {
        $this->assertSame(Pastille::Grey, Pastille::for(Status::NotApplicable, Severity::Info, true));
        $this->assertSame(Pastille::Grey, Pastille::for(Status::Unknown, Severity::Info, true));
    }
}
