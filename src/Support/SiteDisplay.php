<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Support;

final class SiteDisplay
{
    public static function of(mixed $url): string
    {
        $url = trim((string) $url);
        $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url);
        $url = (string) preg_replace('#^www\.#i', '', $url);

        return rtrim($url, '/');
    }
}
