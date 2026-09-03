<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Integration\BlogVaultClient;
use SatelliteWP\Xtractor\Integration\BlogVaultException;

final class BlogVaultClientTest extends TestCase
{
    /** @var list<Request> */
    private array $sent = [];

    /**
     * @param list<Response> $responses
     * @param array<string, mixed> $configExtra
     */
    private function client(array $responses, array $configExtra = []): BlogVaultClient
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

        return BlogVaultClient::fromConfig(array_merge([
            'base_url' => 'https://api.blogvault.test/v6',
            'api_key'  => 'secret-key',
        ], $configExtra), $http);
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testGetHitsTheRightUrlAndReturnsDecodedJson(): void
    {
        $client = $this->client([$this->json(['vulnerabilities' => [], 'count' => 0])]);

        $result = $client->get('sites/vulnerabilities', ['site_url' => 'https://ex.com']);

        $this->assertSame(['vulnerabilities' => [], 'count' => 0], $result);

        $request = $this->sent[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v6/sites/vulnerabilities', $request->getUri()->getPath());
        $this->assertStringContainsString('site_url=', $request->getUri()->getQuery());
    }

    public function testBearerAuthByDefault(): void
    {
        $client = $this->client([$this->json([])]);
        $client->get('ping');

        $this->assertSame('Bearer secret-key', $this->sent[0]->getHeaderLine('Authorization'));
    }

    public function testHeaderAuthScheme(): void
    {
        $client = $this->client([$this->json([])], ['auth' => ['type' => 'header', 'name' => 'X-BV-Key']]);
        $client->get('ping');

        $this->assertSame('secret-key', $this->sent[0]->getHeaderLine('X-BV-Key'));
        $this->assertFalse($this->sent[0]->hasHeader('Authorization'));
    }

    public function testQueryAuthScheme(): void
    {
        $client = $this->client([$this->json([])], ['auth' => ['type' => 'query', 'name' => 'apikey']]);
        $client->get('ping');

        $this->assertStringContainsString('apikey=secret-key', $this->sent[0]->getUri()->getQuery());
    }

    public function testDefaultQueryIsSentOnEveryRequest(): void
    {
        $client = $this->client([$this->json([])], ['default_query' => ['account' => 'acme']]);
        $client->get('ping', ['x' => '1']);

        $query = $this->sent[0]->getUri()->getQuery();
        $this->assertStringContainsString('account=acme', $query);
        $this->assertStringContainsString('x=1', $query);
    }

    public function testPostSendsJsonBody(): void
    {
        $client = $this->client([$this->json(['ok' => true])]);
        $client->post('sites/scan', ['site_url' => 'https://ex.com']);

        $request = $this->sent[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(['site_url' => 'https://ex.com'], json_decode((string) $request->getBody(), true));
    }

    /**
     * v6 needs "site_ids[]=x" — Guzzle's own builder emits "site_ids=x" and
     * http_build_query emits "site_ids[0]=x", both rejected with 400.
     */
    public function testListParamsUseBracketsWithoutAnIndex(): void
    {
        $client = $this->client([$this->json([])]);
        $client->get('sites/wp/plugins', ['site_ids' => ['abc', 'def'], 'perPage' => 100]);

        $query = $this->sent[0]->getUri()->getQuery();
        $this->assertStringContainsString('site_ids%5B%5D=abc', $query);
        $this->assertStringContainsString('site_ids%5B%5D=def', $query);
        $this->assertStringNotContainsString('site_ids%5B0%5D', $query);
        $this->assertStringContainsString('perPage=100', $query);
    }

    /** Nested maps keep their key: "filters[site_id:eq]=x". */
    public function testNestedFilterParamsKeepTheirKey(): void
    {
        $client = $this->client([$this->json([])]);
        $client->get('staging-sites', ['filters' => ['site_id:eq' => 'abc']]);

        $this->assertStringContainsString(
            'filters%5Bsite_id%3Aeq%5D=abc',
            $this->sent[0]->getUri()->getQuery()
        );
    }

    /** The v6 envelope is {"error":{...}} — reading it as a string used to yield "Array". */
    public function testV6ErrorEnvelopeIsUnwrapped(): void
    {
        $client = $this->client([$this->json([
            'error' => [
                'status'  => 400,
                'code'    => 'bad_request',
                'message' => 'Invalid parameters.',
                'details' => [['code' => 'invalid_type', 'param' => 'site_ids', 'message' => 'Must be an array']],
            ],
        ], 400)]);

        try {
            $client->get('sites/wp/plugins');
            $this->fail('expected BlogVaultException');
        } catch (BlogVaultException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertStringContainsString('Invalid parameters.', $e->getMessage());
            $this->assertStringContainsString('site_ids: Must be an array', $e->getMessage());
            $this->assertStringNotContainsString('Array', str_replace('Must be an array', '', $e->getMessage()));
        }
    }

    public function testApiErrorRaisesWithStatusAndBody(): void
    {
        $client = $this->client([$this->json(['message' => 'Invalid API key'], 401)]);

        try {
            $client->get('ping');
            $this->fail('expected BlogVaultException');
        } catch (BlogVaultException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
            $this->assertSame(['message' => 'Invalid API key'], $e->responseBody);
        }
    }

    public function testNonJsonResponseRaises(): void
    {
        $client = $this->client([new Response(200, [], '<html>gateway</html>')]);

        $this->expectException(BlogVaultException::class);
        $this->expectExceptionMessage('non-JSON');

        $client->get('ping');
    }

    public function testNoAuthHeaderWhenKeyAbsent(): void
    {
        $client = $this->client([$this->json([])], ['api_key' => null]);
        $client->get('ping');

        $this->assertFalse($this->sent[0]->hasHeader('Authorization'));
    }

    /**
     * With 'query' auth the key rides in the request URL — Guzzle transport
     * exceptions commonly embed that URL verbatim in getMessage(). A failed
     * request must not leak the key into probes/blogvault.json (or logs) via
     * that message (2026-08-31).
     */
    public function testTransportErrorMessageNeverContainsTheApiKey(): void
    {
        $request = new Request('GET', 'https://api.blogvault.test/v6/ping?apikey=secret-key');
        $mock    = new MockHandler([
            new ConnectException(
                'cURL error 6: Could not resolve host for GET https://api.blogvault.test/v6/ping?apikey=secret-key',
                $request
            ),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);

        $client = BlogVaultClient::fromConfig([
            'base_url' => 'https://api.blogvault.test/v6',
            'api_key'  => 'secret-key',
            'auth'     => ['type' => 'query', 'name' => 'apikey'],
        ], $http);

        try {
            $client->get('ping');
            $this->fail('expected BlogVaultException');
        } catch (BlogVaultException $e) {
            $this->assertStringNotContainsString('secret-key', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }
}
