<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Rules;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Rules\Context;

final class ContextTest extends TestCase
{
    private function context(): Context
    {
        return new Context(
            ['php' => ['version' => '8.3.11', 'memory_limit' => '256M'], 'is_ssl' => true, 'plugins' => [1, 2, 3]],
            [
                'http' => ['status' => 'warn', 'data' => ['ttfb_ms' => 42, 'redirects' => ['forces_https' => false]]],
                'tls'  => ['status' => 'error', 'data' => []],
            ]
        );
    }

    public function testResolvesPayloadAndProbePaths(): void
    {
        $c = $this->context();

        $this->assertSame('8.3.11', $c->string('payload.php.version'));
        $this->assertSame(42.0, $c->number('probe.http.ttfb_ms'));
        $this->assertFalse($c->bool('probe.http.redirects.forces_https'));
        $this->assertSame(3, $c->count('payload.plugins'));
    }

    public function testMissingPathsReturnNull(): void
    {
        $c = $this->context();

        $this->assertNull($c->get('payload.nope.deeper'));
        $this->assertNull($c->number('probe.http.absent'));
        $this->assertNull($c->get('probe.unknownprobe.field'));
        $this->assertSame('fallback', $c->get('payload.nope', 'fallback'));
    }

    public function testProbeRanDistinguishesErrorFromMissing(): void
    {
        $c = $this->context();

        $this->assertTrue($c->probeRan('http'));
        $this->assertFalse($c->probeRan('tls'), 'a probe in error state has not usably run');
        $this->assertFalse($c->probeRan('dns'), 'a probe that never ran');
    }

    public function testParsesPhpIniShorthandToBytes(): void
    {
        $c = new Context(['a' => '256M', 'b' => '64K', 'c' => '1G', 'd' => '-1', 'e' => '512', 'f' => 'garbage']);

        $this->assertSame(268435456.0, $c->bytes('payload.a'));
        $this->assertSame(65536.0, $c->bytes('payload.b'));
        $this->assertSame(1073741824.0, $c->bytes('payload.c'));
        $this->assertSame(INF, $c->bytes('payload.d'), '-1 means unlimited');
        $this->assertSame(512.0, $c->bytes('payload.e'));
        $this->assertNull($c->bytes('payload.f'));
    }
}
