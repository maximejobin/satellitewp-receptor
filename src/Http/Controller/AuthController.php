<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http\Controller;

use SatelliteWP\Xtractor\Http\Session;

/** Google sign-in, the Basic-auth dev fallback and sign-out. */
final class AuthController extends Controller
{
    /** GET /auth/login and /auth/callback; sign-out is a CSRF-checked POST. */
    public function route(string $path): void
    {
        if (!$this->app->googleAuth()->isConfigured()) {
            $this->notFound();

            return;
        }

        Session::start();

        match (trim($path, '/')) {
            'auth/login'    => $this->login(),
            'auth/callback' => $this->callback(),
            default         => $this->notFound(),
        };
    }

    /**
     * Google sign-in when configured, Basic auth as a dev fallback, open when
     * neither is set (server-level protection is then expected).
     */
    public function authenticate(): bool
    {
        if ($this->app->googleAuth()->isConfigured()) {
            if ($this->currentUser() !== null) {
                return true;
            }

            $this->loginPage();

            return false;
        }

        $user     = $this->app->config->get('web.user');
        $passHash = $this->app->config->get('web.pass_hash');

        if ($user === null || $passHash === null) {
            return true;
        }

        $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $lockout = $this->app->loginLockout();

        if ($ip !== '' && $lockout->isLocked($ip)) {
            $this->response->header('Retry-After: ' . $lockout->retryAfter($ip));
            $this->response->text(429, 'Too many failed attempts. Try again later.');

            return false;
        }

        $givenUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $givenPass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

        if (hash_equals((string) $user, $givenUser) && password_verify($givenPass, (string) $passHash)) {
            if ($ip !== '') {
                $lockout->recordSuccess($ip);
            }

            return true;
        }

        if ($ip !== '') {
            $lockout->recordFailure($ip);
        }

        $this->response->header('WWW-Authenticate: Basic realm="SatelliteWP Xtractor"');
        $this->response->text(401, 'Authentication required.');

        return false;
    }

    /** POST /auth/logout */
    public function logout(): void
    {
        if (!$this->app->googleAuth()->isConfigured()) {
            $this->notFound();

            return;
        }

        Session::start();
        $_SESSION = [];

        // session_destroy() drops the data only; expire the cookie too.
        $this->response->cookie(Session::NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => Session::isHttps(),
        ]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->loginPage('Signed out.');
    }

    public function loginPage(string $message = ''): void
    {
        $this->response->status($message === '' ? 401 : 403);
        $this->render('login', [
            'title'    => 'Connexion',
            'nav'      => '',
            'bare'     => true,
            'message'  => $message,
            'firstRun' => $this->app->userStore()->isEmpty(),
        ]);
    }

    private function login(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $this->redirect($this->app->googleAuth()->authorizationUrl($state, $this->redirectUri()));
    }

    private function callback(): void
    {
        $expected = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        // A mismatched state means this browser did not start the flow.
        if (!is_string($expected) || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
            $this->loginPage('Session expired or invalid request. Try again.');

            return;
        }

        $email = $this->app->googleAuth()->emailFromCode((string) ($_GET['code'] ?? ''), $this->redirectUri());
        if ($email === null) {
            $this->loginPage('Google authentication refused.');

            return;
        }

        $users = $this->app->userStore();

        // No "first sign-in becomes admin": this host is public, so the first
        // visitor would claim it. Seed the list with `users:add`.
        if ($users->isEmpty()) {
            $this->loginPage('No user registered yet. Seed the list with: bin/xtractor users:add <email>');

            return;
        }

        if (!$users->isAllowed($email)) {
            $this->loginPage("{$email} is not allowed to access this interface.");

            return;
        }

        session_regenerate_id(true); // no session fixation across the login boundary
        $_SESSION['user_email'] = $email;

        $this->redirect('/');
    }

    /** Must match the URI registered on the Google client exactly. */
    private function redirectUri(): string
    {
        $configured = (string) $this->app->config->get('auth.google.redirect_uri', '');

        return $configured !== '' ? $configured : $this->baseUrl() . '/auth/callback';
    }
}
