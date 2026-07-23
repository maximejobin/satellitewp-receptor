<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use Exception;

final class SignatureException extends Exception
{
    public function __construct(string $message, public readonly int $statusCode = 401)
    {
        parent::__construct($message);
    }
}
