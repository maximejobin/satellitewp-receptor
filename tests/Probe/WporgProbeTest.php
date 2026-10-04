<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Probe\WporgProbe;

final class WporgProbeTest extends TestCase
{
    public function testAPluginTimestampWithATimeAndGmtSuffixParsesCorrectly(): void
    {
        $result = WporgProbe::parseInfo(['slug' => 'akismet', 'last_updated' => '2026-08-18 11:42pm GMT']);

        $this->assertTrue($result['on_wporg']);
        $this->assertSame('2026-08-18T23:42:00Z', $result['last_updated']);
    }

    public function testABareDateThemeTimestampParsesCorrectly(): void
    {
        $result = WporgProbe::parseInfo(['slug' => 'storefront', 'last_updated' => '2025-12-09']);

        $this->assertTrue($result['on_wporg']);
        $this->assertSame('2025-12-09T00:00:00Z', $result['last_updated']);
    }

    public function testAnUnknownThemeAnswersTheBareJsonScalarFalse(): void
    {
        $result = WporgProbe::parseInfo(false);

        $this->assertSame(['on_wporg' => false, 'last_updated' => null], $result);
    }

    public function testAnUnknownPluginAnswersAnErrorObject(): void
    {
        $result = WporgProbe::parseInfo(['error' => 'Plugin not found.']);

        $this->assertSame(['on_wporg' => false, 'last_updated' => null], $result);
    }

    public function testAMissingLastUpdatedFieldIsOnWporgButNull(): void
    {
        $result = WporgProbe::parseInfo(['slug' => 'something']);

        $this->assertTrue($result['on_wporg']);
        $this->assertNull($result['last_updated']);
    }

    public function testAnUnparseableLastUpdatedStringIsOnWporgButNull(): void
    {
        $result = WporgProbe::parseInfo(['slug' => 'something', 'last_updated' => 'not a date']);

        $this->assertTrue($result['on_wporg']);
        $this->assertNull($result['last_updated']);
    }

    /**
     * A handler answering by URL substring, so the result never depends on
     * the order the concurrent pool happens to send requests in.
     *
     * @param array<string, Response|\Throwable> $routes
     */
    private function probeAnswering(array $routes): WporgProbe
    {
        $handler = static function (RequestInterface $request) use ($routes) {
            foreach ($routes as $needle => $answer) {
                if (str_contains((string) $request->getUri(), $needle)) {
                    return $answer instanceof \Throwable ? Create::rejectionFor($answer) : Create::promiseFor($answer);
                }
            }

            return Create::promiseFor(new Response(404, [], 'false'));
        };

        return new WporgProbe(5, 10, 'test-agent', $handler);
    }

    private function site(): SiteContext
    {
        return new SiteContext('site-1', 'https://example.com', 'https://example.com', 'example.com', 'example.com', [
            ['slug' => 'akismet/akismet.php'],
            ['slug' => 'premium-thing/premium-thing.php'],
            ['slug' => 'flaky/flaky.php'],
        ], [
            ['slug' => 'storefront'],
        ]);
    }

    public function testFetchTreats404AsNotOnWporgAndOnlyOtherFailuresAsErrors(): void
    {
        $probe = $this->probeAnswering([
            'plugins/info/1.0/akismet.json'       => new Response(200, [], (string) json_encode(['slug' => 'akismet', 'last_updated' => '2026-08-18 11:42pm GMT'])),
            'plugins/info/1.0/premium-thing.json' => new Response(404, [], (string) json_encode(['error' => 'Plugin not found.'])),
            'plugins/info/1.0/flaky.json'         => new Response(503),
            'request%5Bslug%5D=storefront'        => new Response(200, [], (string) json_encode(['slug' => 'storefront', 'last_updated' => '2025-12-09'])),
        ]);

        $result = $probe->run($this->site());

        $this->assertSame(['on_wporg' => true, 'last_updated' => '2026-08-18T23:42:00Z'], $result->data['plugins']['akismet']);
        $this->assertSame(['on_wporg' => false, 'last_updated' => null], $result->data['plugins']['premium-thing']);
        $this->assertArrayNotHasKey('flaky', $result->data['plugins'], 'a 5xx is unknown, never "not on wp.org"');
        $this->assertSame('2025-12-09T00:00:00Z', $result->data['themes']['storefront']['last_updated']);
        $this->assertSame(ProbeResult::STATUS_WARN, $result->status);
        $this->assertSame(['wp.org plugin "flaky": HTTP 503'], $result->errors);
    }

    public function testATransportFailureIsRecordedWithoutAbortingTheOtherSlugs(): void
    {
        $probe = $this->probeAnswering([
            'flaky.json'   => new ConnectException('timeout', new \GuzzleHttp\Psr7\Request('GET', 'https://api.wordpress.org/')),
            'akismet.json' => new Response(200, [], (string) json_encode(['slug' => 'akismet', 'last_updated' => '2026-01-01'])),
        ]);

        $result = $probe->run($this->site());

        $this->assertTrue($result->data['plugins']['akismet']['on_wporg']);
        $this->assertStringContainsString('flaky', $result->errors[0]);
    }
}
