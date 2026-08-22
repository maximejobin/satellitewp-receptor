<?php

declare(strict_types=1);

/**
 * Receptor front controller — the ONLY thing exposed to the internet.
 *
 * Deliberately a separate application from the admin UI: it never loads the
 * Router, never starts a session, and serves no HTML. A site pushing here can
 * reach the signature check and the data store, and nothing else.
 *
 * Docroot this directory on its own vhost, e.g.
 *   receptor.satellitewp.com -> /var/www/xtractor/public/receptor
 *
 * The plugin may POST to any path under it; anything that is not a signed
 * POST gets a flat 404, which tells an unauthenticated visitor nothing.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SatelliteWP\Xtractor\Bootstrap;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_SERVER['HTTP_X_SWP_TYPE'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$result = Bootstrap::app()->receptor()->handle(
    [
        'site'      => $_SERVER['HTTP_X_SWP_SITE'] ?? null,
        'type'      => $_SERVER['HTTP_X_SWP_TYPE'] ?? null,
        'timestamp' => $_SERVER['HTTP_X_SWP_TIMESTAMP'] ?? null,
        'signature' => $_SERVER['HTTP_X_SWP_SIGNATURE'] ?? null,
    ],
    (string) file_get_contents('php://input'),
    $_SERVER['REMOTE_ADDR'] ?? null
);

http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
