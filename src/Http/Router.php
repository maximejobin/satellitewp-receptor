<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\App;

/**
 * Read-only web UI routes. Data comes from SQLite (lists) and JSON files (detail).
 */
final class Router
{
    public function __construct(private readonly App $app)
    {
    }

    public function dispatch(string $path): void
    {
        // Placeholder until the web UI phase.
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo "SatelliteWP Xtractor\n";
    }
}
