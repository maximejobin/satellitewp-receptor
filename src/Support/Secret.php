<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

final class Secret
{
    /**
     * $message with $secret removed in raw and URL-encoded forms — a transport
     * error's message embeds the full request URI, and these messages are
     * stored under data/.
     */
    public static function redact(string $message, ?string $secret, string $mask = '[redacted]'): string
    {
        if ($secret === null || $secret === '') {
            return $message;
        }

        return str_replace(array_unique([rawurlencode($secret), urlencode($secret), $secret]), $mask, $message);
    }
}
