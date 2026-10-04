<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SatelliteWP\Manager\Support\Secret;

/**
 * SE Ranking Project API, Website Audit endpoints only. Creating an audit
 * spends crawl credits; status and report reads are free.
 */
final class SeRankingClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, ?ClientInterface $http = null): self
    {
        return new self(
            $http ?? new Client([
                'timeout'     => (int) ($config['timeout'] ?? 30),
                'http_errors' => false,
            ]),
            rtrim((string) ($config['base_url'] ?? ''), '/'),
            (string) ($config['api_key'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $settings only the overrides; the API fills the rest
     * @return int the new audit id
     */
    public function createAudit(string $domain, string $title, array $settings): int
    {
        $body = ['domain' => $domain, 'title' => mb_substr($title, 0, 300)];
        if ($settings !== []) {
            $body['settings'] = $settings;
        }

        $id = $this->request('POST', 'audits', ['json' => $body])['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new SeRankingException('SE Ranking returned no audit id');
        }

        return (int) $id;
    }

    /** @return array<string, mixed> status, total_pages, start_time, audit_time… */
    public function auditStatus(int $auditId): array
    {
        return $this->request('GET', 'audits/status', ['query' => ['audit_id' => $auditId]]);
    }

    /** @return array<string, mixed> is_finished, score_percent, totals, domain_props, sections */
    public function auditReport(int $auditId): array
    {
        return $this->request('GET', 'audits/report', ['query' => ['audit_id' => $auditId]]);
    }

    /**
     * @param array{query?: array<string, scalar>, json?: array<string, mixed>} $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $options['headers'] = ['Authorization' => 'Token ' . $this->apiKey, 'Accept' => 'application/json'];

        try {
            $response = $this->http->request($method, $this->baseUrl . '/' . $path, $options);
        } catch (GuzzleException $e) {
            throw new SeRankingException('SE Ranking transport error: ' . Secret::redact($e->getMessage(), $this->apiKey, '***'));
        }

        $status  = $response->getStatusCode();
        $raw     = (string) $response->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? '') : '';
            throw new SeRankingException(
                rtrim("SE Ranking error (HTTP {$status}) " . Secret::redact(mb_substr($detail, 0, 300), $this->apiKey, '***')),
                $status
            );
        }
        if (!is_array($decoded)) {
            throw new SeRankingException("SE Ranking returned non-JSON (HTTP {$status})", $status);
        }

        return $decoded;
    }
}
