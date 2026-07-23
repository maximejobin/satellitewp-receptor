<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor;

/**
 * Builds the App from the project configuration.
 */
final class Bootstrap
{
    public static function app(?string $configDir = null): App
    {
        $configDir ??= dirname(__DIR__) . '/config';

        return new App(Config::load($configDir));
    }
}
