<?php

declare(strict_types=1);

/**
 * Admin front controller — the analyst UI, behind Google sign-in.
 *
 * Deliberately a separate application from the extractor: it never accepts a
 * plugin push, so no unauthenticated request can reach the data store through
 * this vhost. Keep it off the public extractor hostname.
 *
 * Docroot this directory on its own vhost, e.g.
 *   manager.satellitewp.com -> /var/www/manager/public/admin
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SatelliteWP\Manager\Bootstrap;
use SatelliteWP\Manager\Http\ErrorHandler;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Support\ErrorLog;

// Before anything else, including the config load: a 500 raised while booting
// is exactly the one nobody would otherwise see.
ErrorHandler::install(new ErrorLog(ErrorLog::defaultDir()), 'admin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

$router = new Router(Bootstrap::app());
$method === 'POST' ? $router->handlePost($path) : $router->dispatch($path);
