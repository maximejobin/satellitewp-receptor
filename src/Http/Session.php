<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

/** The admin UI's PHP session and the request's transport security. */
final class Session
{
    public const string NAME = 'swp_session';

    /** __Host- pins the CSRF cookie to this exact origin: Secure, Path=/, no Domain attribute. */
    public const string CSRF_COOKIE = '__Host-swp_csrf';

    /** Browsers refuse a Secure (hence __Host-) cookie over plain http, so a non-https dev server keeps this name. */
    public const string CSRF_COOKIE_PLAIN_HTTP = 'swp_csrf';

    public static function csrfCookieName(): string
    {
        return self::isHttps() ? self::CSRF_COOKIE : self::CSRF_COOKIE_PLAIN_HTTP;
    }

    public static function start(): void
    {
        // No browser session under the CLI (console, tests), nor once output has
        // begun; callers then read whatever $_SESSION already holds.
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax', // the OAuth callback is a top-level GET redirect
            'secure'   => self::isHttps(),
            'path'     => '/',
        ]);
        session_name(self::NAME);
        session_start();
    }

    /**
     * Whether the browser's connection is HTTPS. X-Forwarded-Proto is honoured
     * for a TLS-terminating proxy; spoofing it can only make cookies and the
     * OAuth redirect_uri stricter, never downgrade them.
     */
    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }

        // May be a comma-separated list when proxies are chained.
        return str_starts_with(strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))), 'https');
    }
}
