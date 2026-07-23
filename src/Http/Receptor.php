<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use InvalidArgumentException;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use Throwable;

/**
 * Handles incoming plugin POSTs (extraction | event | integrity).
 * Verifies, stores, indexes — never runs probes (the cron worker does).
 */
final class Receptor
{
    public function __construct(
        private readonly SignatureVerifier $signatures,
        private readonly PayloadValidator $validator,
        private readonly DataStore $store,
        private readonly Index $index,
        private readonly int $maxBodyBytes,
    ) {
    }

    /**
     * @param array<string, string|null> $headers keys: site, type, timestamp, signature
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(array $headers, string $rawBody, ?string $remoteIp = null): array
    {
        $siteId    = trim((string) ($headers['site'] ?? ''));
        $type      = trim((string) ($headers['type'] ?? ''));
        $timestamp = trim((string) ($headers['timestamp'] ?? ''));
        $signature = $headers['signature'] ?? null;

        if (strlen($rawBody) > $this->maxBodyBytes) {
            return $this->error(413, 'Payload too large');
        }
        if ($rawBody === '') {
            return $this->error(400, 'Empty body');
        }
        if (!PayloadValidator::isUuid($siteId)) {
            return $this->error(400, 'Missing or malformed X-SWP-Site');
        }
        if (!in_array($type, PayloadValidator::TYPES, true)) {
            return $this->error(400, 'Missing or unknown X-SWP-Type');
        }

        try {
            $signatureResult = $this->signatures->verify($siteId, $timestamp, $signature, $rawBody);
        } catch (SignatureException $e) {
            return $this->error($e->statusCode, $e->getMessage());
        }

        try {
            $payload = $this->validator->validate($rawBody, $type, $siteId);
        } catch (InvalidArgumentException $e) {
            return $this->error(422, $e->getMessage());
        }

        $receivedAt = gmdate('Y-m-d\TH:i:s\Z');

        try {
            return match ($type) {
                PayloadValidator::TYPE_EXTRACTION => $this->storeExtraction(
                    $siteId, $rawBody, $payload, $receivedAt, $signatureResult, $remoteIp
                ),
                PayloadValidator::TYPE_EVENT     => $this->storeEvents($siteId, $payload, $receivedAt),
                PayloadValidator::TYPE_INTEGRITY => $this->storeIntegrity($siteId, $payload, $receivedAt),
            };
        } catch (Throwable $e) {
            error_log('[xtractor] receptor storage failure: ' . $e->getMessage());

            return $this->error(500, 'Storage failure');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function storeExtraction(
        string $siteId,
        string $rawBody,
        array $payload,
        string $receivedAt,
        string $signatureResult,
        ?string $remoteIp,
    ): array {
        $extractionId = $this->store->storeExtraction($siteId, $rawBody, [
            'received_at'       => $receivedAt,
            'remote_ip'         => $remoteIp,
            'signature_valid'   => $signatureResult === SignatureVerifier::RESULT_VALID,
            'schema_version'    => $payload['schema_version'] ?? null,
            'extractor_version' => $payload['extractor_version'] ?? null,
            'body_bytes'        => strlen($rawBody),
        ]);

        $this->store->updateSiteInfo($siteId, $payload, $receivedAt);
        $this->index->upsertSite($siteId, $payload, $receivedAt);
        $this->index->insertExtraction($siteId, $extractionId, $receivedAt, $payload);

        return ['status' => 200, 'body' => ['status' => 'received', 'id' => $extractionId]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function storeEvents(string $siteId, array $payload, string $receivedAt): array
    {
        $this->store->appendEvents($siteId, $payload, $receivedAt);

        return ['status' => 200, 'body' => [
            'status' => 'received',
            'events' => count((array) ($payload['events'] ?? [])),
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function storeIntegrity(string $siteId, array $payload, string $receivedAt): array
    {
        $id = $this->store->storeIntegrity($siteId, $payload, $receivedAt);

        return ['status' => 200, 'body' => ['status' => 'received', 'id' => $id]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => 'error', 'message' => $message]];
    }
}
