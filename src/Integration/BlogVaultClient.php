<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;

/**
 * Generic, parameter-driven client for the BlogVault API v6.
 *
 * There is deliberately no method per endpoint: you call any endpoint by path,
 * and the parameters do the work —
 *
 *   $bv = BlogVaultClient::fromConfig($config['blogvault']);
 *   $vulns = $bv->get('sites/vulnerabilities', ['site_url' => $url]);
 *   $bv->post('sites/scan', ['site_url' => $url]);
 *
 * Base URL, authentication scheme and any always-present parameters come from
 * configuration, so wiring the real v6 endpoints later needs no code change —
 * only config. Every call returns the decoded JSON array; failures raise a
 * BlogVaultException carrying the status code and the API's own error body.
 */
final class BlogVaultClient
{
    /**
     * @param array{type?: string, name?: string, username?: string} $auth
     * @param array<string, scalar> $defaultQuery  sent on every request
     * @param array<string, string> $defaultHeaders sent on every request
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly array $auth = ['type' => 'bearer', 'name' => 'Authorization'],
        private readonly array $defaultQuery = [],
        private readonly array $defaultHeaders = [],
    ) {
        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('BlogVault base_url is required');
        }
    }

    /**
     * Build from a config array. Injects a real Guzzle client unless one is
     * supplied (tests pass a mocked ClientInterface).
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, ?ClientInterface $http = null): self
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

        return new self(
            $http ?? new Client([
                'timeout'     => (int) ($config['timeout'] ?? 20),
                'http_errors' => false,
            ]),
            $baseUrl,
            $config['api_key'] ?? null,
            (array) ($config['auth'] ?? ['type' => 'bearer', 'name' => 'Authorization']),
            (array) ($config['default_query'] ?? []),
            (array) ($config['default_headers'] ?? []),
        );
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body sent as JSON
     * @param array<string, mixed> $query
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        return $this->request('POST', $path, ['json' => $body, 'query' => $query]);
    }

    /**
     * The one generic entry point.
     *
     * @param array{query?: array<string, mixed>, json?: array<string, mixed>, headers?: array<string, string>} $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $query = array_merge($this->defaultQuery, $options['query'] ?? []);
        $query = $this->applyAuthQuery($query);

        $guzzleOptions = [
            'headers' => array_merge($this->defaultHeaders, $this->authHeaders(), $options['headers'] ?? []),
        ];
        if ($query !== []) {
            $guzzleOptions['query'] = $query;
        }
        if (isset($options['json'])) {
            $guzzleOptions['json'] = $options['json'];
        }

        try {
            $response = $this->http->request(strtoupper($method), $this->url($path), $guzzleOptions);
        } catch (GuzzleException $e) {
            throw new BlogVaultException("BlogVault transport error: {$e->getMessage()}", null, null, $e);
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new BlogVaultException("BlogVault returned non-JSON (HTTP {$status})", $status);
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? "HTTP {$status}";

            throw new BlogVaultException("BlogVault error: {$message}", $status, $decoded);
        }

        return $decoded;
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return [];
        }

        return match ($this->auth['type'] ?? 'bearer') {
            'bearer' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'header' => [($this->auth['name'] ?? 'X-Api-Key') => $this->apiKey],
            'basic'  => ['Authorization' => 'Basic ' . base64_encode(
                ($this->auth['username'] ?? '') . ':' . $this->apiKey
            )],
            default  => [], // 'query' or 'none' — handled elsewhere / not needed
        };
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function applyAuthQuery(array $query): array
    {
        if (($this->auth['type'] ?? null) === 'query'
            && $this->apiKey !== null && $this->apiKey !== '') {
            $query[$this->auth['name'] ?? 'api_key'] = $this->apiKey;
        }

        return $query;
    }
}
