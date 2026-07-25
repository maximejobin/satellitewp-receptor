<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Rules;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Rules\Pastille;
use SatelliteWP\Xtractor\Rules\Severity;
use SatelliteWP\Xtractor\Rules\Status;

final class PastilleTest extends TestCase
{
    public function testPassIsGreen(): void
    {
        $this->assertSame(Pastille::Green, Pastille::for(Status::Pass, Severity::Elevee));
        $this->assertSame(Pastille::Green, Pastille::for(Status::Pass, Severity::Moyenne));
    }

    public function testFailCriticalOrHighIsRed(): void
    {
        $this->assertSame(Pastille::Red, Pastille::for(Status::Fail, Severity::Critique));
        $this->assertSame(Pastille::Red, Pastille::for(Status::Fail, Severity::Elevee));
    }

    public function testFailMediumIsOrange(): void
    {
        $this->assertSame(Pastille::Orange, Pastille::for(Status::Fail, Severity::Moyenne));
    }

    public function testInfoIsAlwaysBlue(): void
    {
        // Info-severity rules are informational, whatever the outcome.
        $this->assertSame(Pastille::Blue, Pastille::for(Status::Fail, Severity::Info));
        $this->assertSame(Pastille::Blue, Pastille::for(Status::Pass, Severity::Info));
    }

    public function testNaAndUnknownAreGreyEvenForInfoSeverity(): void
    {
        $this->assertSame(Pastille::Grey, Pastille::for(Status::NotApplicable, Severity::Moyenne));
        $this->assertSame(Pastille::Grey, Pastille::for(Status::Unknown, Severity::Elevee));
        // "No result" beats the Info-severity blue override.
        $this->assertSame(Pastille::Grey, Pastille::for(Status::Unknown, Severity::Info));
    }
}
