<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Integration\WordfenceClient;
use SatelliteWP\Xtractor\Integration\WordfenceException;

final class WordfenceClientTest extends TestCase
{
    /** @var list<Request> */
    private array $sent = [];

    /** @param list<Response> $responses */
    private function client(array $responses, ?string $apiKey = 'secret-key'): WordfenceClient
    {
        $this->sent = [];
        $mock       = new MockHandler($responses);
        $stack      = HandlerStack::create($mock);
        $stack->push(function (callable $handler) {
            return function (Request $request, array $options) use ($handler) {
                $this->sent[] = $request;

                return $handler($request, $options);
            };
        });

        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        return WordfenceClient::fromConfig([
            'base_url' => 'https://www.wordfence.test/api/intelligence/v3',
            'api_key'  => $apiKey,
        ], $http);
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testFetchProductionHitsTheRightUrlWithBearerAuth(): void
    {
        $client = $this->client([$this->json(['uuid-1' => ['id' => 'uuid-1']])]);

        $result = $client->fetch(WordfenceClient::VARIANT_PRODUCTION);

        $this->assertSame(['uuid-1' => ['id' => 'uuid-1']], $result);

        $request = $this->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/intelligence/v3/vulnerabilities/production', $request->getUri()->getPath());
        // No "cli-" prefix — confirmed live against the real API.
        $this->assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
    }

    public function testFetchScannerUsesTheScannerPath(): void
    {
        $client = $this->client([$this->json([])]);
        $client->fetch(WordfenceClient::VARIANT_SCANNER);

        $this->assertSame(
            '/api/intelligence/v3/vulnerabilities/scanner',
            $this->sent[0]->getUri()->getPath()
        );
    }

    public function testUnknownVariantIsRejectedWithoutARequest(): void
    {
        $client = $this->client([]);

        $this->expectException(InvalidArgumentException::class);
        $client->fetch('bogus');

        $this->assertSame([], $this->sent);
    }

    public function testMissingApiKeyRaisesWithoutARequest(): void
    {
        $client = $this->client([], null);

        $this->expectException(WordfenceException::class);
        $this->expectExceptionMessage('api_key is not configured');
        $client->fetch(WordfenceClient::VARIANT_SCANNER);

        $this->assertSame([], $this->sent);
    }

    public function testRateLimitErrorHasAClearMessage(): void
    {
        $client = $this->client([$this->json(['error' => 'Too Many Requests'], 429)]);

        try {
            $client->fetch(WordfenceClient::VARIANT_PRODUCTION);
            $this->fail('expected WordfenceException');
        } catch (WordfenceException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertStringContainsString('rate limited', $e->getMessage());
        }
    }

    public function testOtherApiErrorCarriesTheStatusAndMessage(): void
    {
        $client = $this->client([$this->json(['message' => 'Invalid API key'], 401)]);

        try {
            $client->fetch(WordfenceClient::VARIANT_PRODUCTION);
            $this->fail('expected WordfenceException');
        } catch (WordfenceException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
        }
    }

    public function testNonJsonResponseRaises(): void
    {
        $client = $this->client([new Response(200, [], '<html>gateway</html>')]);

        $this->expectException(WordfenceException::class);
        $this->expectExceptionMessage('non-JSON');

        $client->fetch(WordfenceClient::VARIANT_SCANNER);
    }
}
