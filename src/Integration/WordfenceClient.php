<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;

final class WordfenceClient
{
    public const string VARIANT_PRODUCTION = 'production';
    public const string VARIANT_SCANNER    = 'scanner';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $userAgent = 'SatelliteWP-Xtractor/1.0',
    ) {
        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('Wordfence base_url is required');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, ?ClientInterface $http = null): self
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $timeout = (int) ($config['timeout'] ?? 120); // the feed is tens of MB; a short timeout guarantees failure

        return new self(
            $http ?? new Client(['timeout' => $timeout, 'http_errors' => false]),
            $baseUrl,
            $config['api_key'] ?? null,
        );
    }

    /**
     * Fetch one feed variant in full. Returns the raw decoded map
     * (vulnerability id => record) — WordfenceIndex is responsible for
     * reducing this into the compact local cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetch(string $variant): array
    {
        if (!in_array($variant, [self::VARIANT_PRODUCTION, self::VARIANT_SCANNER], true)) {
            throw new InvalidArgumentException("Unknown Wordfence feed variant: {$variant}");
        }
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new WordfenceException('Wordfence api_key is not configured');
        }

        try {
            $response = $this->http->request('GET', "{$this->baseUrl}/vulnerabilities/{$variant}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'User-Agent'    => $this->userAgent,
                    'Accept'        => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new WordfenceException("Wordfence transport error: {$e->getMessage()}", null, $e);
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            $message = $status === 429
                ? 'rate limited (the feed allows very few requests — refresh at most once a day)'
                : self::errorMessage($raw, $status);

            throw new WordfenceException("Wordfence error fetching '{$variant}': {$message}", $status);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new WordfenceException("Wordfence '{$variant}' feed returned non-JSON (HTTP {$status})", $status);
        }

        /** @var array<string, array<string, mixed>> $decoded */
        return $decoded;
    }

    private static function errorMessage(string $raw, int $status): string
    {
        $decoded = json_decode($raw, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : "HTTP {$status}";
    }
}
