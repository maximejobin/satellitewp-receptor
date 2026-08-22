<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Google sign-in, OAuth 2.0 authorization-code flow.
 *
 * The email is read from Google's userinfo endpoint using the access token,
 * rather than by decoding the id_token ourselves. Both are equally valid; this
 * way Google validates the token and we need no JWT signature verification, no
 * JWKS cache and no extra dependency — which for a tool this size is the
 * difference between ~40 lines and a library.
 *
 * Identity only: the address returned here still has to appear in UserStore.
 */
final class GoogleAuth
{
    private const string AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    private const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly ?ClientInterface $http = null,
    ) {
    }

    /** @param array<string, mixed> $config the `auth.google` section */
    public static function fromConfig(array $config, ?ClientInterface $http = null): self
    {
        return new self(
            self::stringOrNull($config['client_id'] ?? null),
            self::stringOrNull($config['client_secret'] ?? null),
            $http,
        );
    }

    /** Google sign-in is only offered once both halves of the client are set. */
    public function isConfigured(): bool
    {
        return $this->clientId !== null && $this->clientSecret !== null;
    }

    /**
     * Where to send the browser. `state` is the CSRF guard: the caller stores it
     * in the session and must compare it on the way back.
     *
     * `prompt=select_account` avoids silently reusing whichever Google account
     * the browser happens to be signed into — on a shared machine that is how
     * you end up auditing as someone else.
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Google sign-in is not configured (auth.google.client_id / client_secret)');
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email',
            'state'         => $state,
            'prompt'        => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the one-time code for an access token, then read the account's
     * email. Returns null for any failure — a failed sign-in is never a
     * successful one, and the caller only needs "who, or nobody".
     */
    public function emailFromCode(string $code, string $redirectUri): ?string
    {
        if (!$this->isConfigured() || $code === '') {
            return null;
        }

        $http = $this->http ?? new Client(['timeout' => 15, 'http_errors' => false]);

        try {
            $response = $http->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'code'          => $code,
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri'  => $redirectUri,
                    'grant_type'    => 'authorization_code',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $token = json_decode((string) $response->getBody(), true);
            $access = is_array($token) ? self::stringOrNull($token['access_token'] ?? null) : null;
            if ($access === null) {
                return null;
            }

            $info = $http->request('GET', self::USERINFO_URL, [
                'headers' => ['Authorization' => "Bearer {$access}"],
            ]);

            if ($info->getStatusCode() !== 200) {
                return null;
            }

            $profile = json_decode((string) $info->getBody(), true);
        } catch (GuzzleException) {
            return null;
        }

        if (!is_array($profile)) {
            return null;
        }

        // An unverified address proves nothing about who is signing in.
        if (($profile['email_verified'] ?? false) !== true && ($profile['email_verified'] ?? null) !== 'true') {
            return null;
        }

        $email = self::stringOrNull($profile['email'] ?? null);

        return $email === null ? null : strtolower($email);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
