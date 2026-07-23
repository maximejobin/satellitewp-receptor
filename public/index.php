<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SatelliteWP\Xtractor\Bootstrap;
use SatelliteWP\Xtractor\Http\Router;

$app = Bootstrap::app();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Receptor: the plugin POSTs to any path — route on X-SWP-Type, not the URL.
    $result = $app->receptor()->handle(
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
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Web UI (read-only).
(new Router($app))->dispatch(
    (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/')
);
