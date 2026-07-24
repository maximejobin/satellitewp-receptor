<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Integration;

use RuntimeException;
use Throwable;

/**
 * Raised for any failed BlogVault call. Carries the HTTP status and the decoded
 * response body when there is one, so callers can inspect the API's own error.
 */
final class BlogVaultException extends RuntimeException
{
    /** @param array<string, mixed>|null $responseBody */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
