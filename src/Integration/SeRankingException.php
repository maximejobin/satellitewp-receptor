<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Integration;

use RuntimeException;

/** A failed SE Ranking call; carries the HTTP status when there was a response. */
final class SeRankingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }
}
