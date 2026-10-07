<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use InvalidArgumentException;
use SatelliteWP\Manager\Storage\DataStore;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Storage\KeyStore;
use SatelliteWP\Manager\Support\ErrorLog;
use Throwable;

/**
 * Handles incoming plugin POSTs (extraction | event | integrity).
 * Verifies, stores, indexes — never runs probes (the cron worker does).
 */
final class Extractor
{
    /** The plugin's event queue holds 200 small events: tens of KB. */
    public const int MAX_EVENT_BODY_BYTES = 1024 * 1024;
    /** One month of events per site; the site page reads its tail. */
    public const int MAX_EVENT_FILE_BYTES = 20 * 1024 * 1024;
    /** Extractions accepted per site per clock hour: each stores the raw body. */
    public const int MAX_EXTRACTIONS_PER_HOUR = 30;

    public function __construct(
        private readonly SignatureVerifier $signatures,
        private readonly PayloadValidator $validator,
        private readonly DataStore $store,
        private readonly Index $index,
        private readonly int $maxBodyBytes,
        private readonly ?KeyStore $keys = null,
        private readonly ?ErrorLog $errorLog = null,
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
        if ($type === PayloadValidator::TYPE_EVENT && strlen($rawBody) > self::MAX_EVENT_BODY_BYTES) {
            return $this->error(413, 'Payload too large');
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

        if ($type === PayloadValidator::TYPE_EXTRACTION) {
            $originError = $this->checkOrigin($siteId, $payload);
            if ($originError !== null) {
                return $originError;
            }
        }

        $receivedAt = gmdate('Y-m-d\TH:i:s\Z');

        // A key holder can sign as many pushes as it likes; disk is the cost.
        if ($type === PayloadValidator::TYPE_EXTRACTION
            && $this->store->countExtractionsInHour($siteId, $receivedAt) >= self::MAX_EXTRACTIONS_PER_HOUR) {
            return $this->error(429, 'Too many extractions this hour');
        }
        if ($type === PayloadValidator::TYPE_EVENT
            && $this->store->eventFileBytes($siteId, $receivedAt) >= self::MAX_EVENT_FILE_BYTES) {
            return $this->error(429, 'Event quota for this month reached');
        }

        try {
            return match ($type) {
                PayloadValidator::TYPE_EXTRACTION => $this->storeExtraction(
                    $siteId, $rawBody, $payload, $receivedAt, $signatureResult, $remoteIp
                ),
                PayloadValidator::TYPE_EVENT     => $this->storeEvents($siteId, $payload, $receivedAt),
                PayloadValidator::TYPE_INTEGRITY => $this->storeIntegrity($siteId, $payload, $receivedAt),
            };
        } catch (Throwable $e) {
            // The one 500 the extractor raises on purpose. It logs itself rather
            // than falling through to the shutdown handler, so the entry carries
            // which site and which payload type were being stored.
            $ref = $this->errorLog?->recordThrowable('extractor', $e, [
                'site_id' => $siteId,
                'payload' => $type,
            ]);

            if ($ref === null) {
                error_log('[manager] extractor storage failure: ' . $e->getMessage());

                return $this->error(500, 'Storage failure');
            }

            // The reference goes back to the plugin, which logs the response:
            // the site owner can quote it and it points at one line in logs/.
            return $this->error(500, "Storage failure (ref {$ref})");
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

        $this->store->updateSiteInfo($siteId, $payload);
        $this->index->upsertSite($siteId, $payload);
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

    /**
     * Refuses an extraction whose site no longer answers on the address it was
     * bound to — the signature of a copy restored from a backup, which would
     * otherwise report over the original site's history.
     *
     * A site with no origin on file is bound by its first extraction, so an
     * existing pairing does not have to be touched. The plugin makes the same
     * check before sending, but that one runs on the client; this is the one that
     * holds.
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}|null null when accepted
     */
    private function checkOrigin(string $siteId, array $payload): ?array
    {
        if ($this->keys === null) {
            return null;
        }

        // home_url is a validated, non-empty URL: an empty claim can no longer
        // slip past the binding and point the probes at another host.
        $claimed = PayloadValidator::normalizeOrigin((string) $payload['home_url']);

        $bound = $this->keys->getOrigin($siteId);

        if ($bound === null) {
            $this->keys->setOrigin($siteId, $claimed);

            return null;
        }

        if (hash_equals($bound, $claimed)) {
            return null;
        }

        return $this->error(
            409,
            "This site is registered as {$bound} but reported from {$claimed}. "
            . 'Refusing it so a restored copy cannot report over the original. '
            . "Run 'manager keys:rebind' if the site really moved."
        );
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => 'error', 'message' => $message]];
    }
}
