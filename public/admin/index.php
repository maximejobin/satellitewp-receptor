<?php

declare(strict_types=1);

/**
 * Admin front controller — the analyst UI, behind Google sign-in.
 *
 * Deliberately a separate application from the receptor: it never accepts a
 * plugin push, so no unauthenticated request can reach the data store through
 * this vhost. Keep it off the public receptor hostname.
 *
 * Docroot this directory on its own vhost, e.g.
 *   xtractor.satellitewp.com -> /var/www/xtractor/public/admin
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SatelliteWP\Xtractor\Bootstrap;
use SatelliteWP\Xtractor\Http\ErrorHandler;
use SatelliteWP\Xtractor\Http\Router;
use SatelliteWP\Xtractor\Support\ErrorLog;

// Before anything else, including the config load: a 500 raised while booting
// is exactly the one nobody would otherwise see.
ErrorHandler::install(new ErrorLog(ErrorLog::defaultDir()), 'admin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

$router = new Router(Bootstrap::app());
$method === 'POST' ? $router->handlePost($path) : $router->dispatch($path);
