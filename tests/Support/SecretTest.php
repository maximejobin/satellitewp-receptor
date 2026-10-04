<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Support\Secret;

final class SecretTest extends TestCase
{
    public function testRedactRemovesTheSecretRawAndUrlEncoded(): void
    {
        $key = 'AIza+Sy/abc=';
        $msg = 'cURL error 28 for https://api.example.com/run?url=x&key='
            . rawurlencode($key) . ' (raw ' . $key . ', form ' . urlencode($key) . ')';

        $out = Secret::redact($msg, $key);

        $this->assertStringNotContainsString($key, $out);
        $this->assertStringNotContainsString(rawurlencode($key), $out);
        $this->assertStringNotContainsString(urlencode($key), $out);
        $this->assertStringContainsString('key=[redacted]', $out);
    }

    public function testRedactLeavesTheMessageAloneWithoutASecret(): void
    {
        $this->assertSame('no key here', Secret::redact('no key here', null));
        $this->assertSame('no key here', Secret::redact('no key here', ''));
    }

    public function testRedactUsesTheGivenMask(): void
    {
        $this->assertSame('token=***', Secret::redact('token=abc', 'abc', '***'));
    }
}
