<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Integration;

use RuntimeException;
use Throwable;

/** Raised for any failed Wordfence Intelligence feed fetch. */
final class WordfenceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
