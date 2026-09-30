<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http\Controller;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Http\Response;
use SatelliteWP\Xtractor\Http\Session;

/**
 * Shared request plumbing for the admin UI controllers: identity,
 * capability gates, CSRF token, locale and template rendering.
 */
abstract class Controller
{
    public function __construct(
        protected readonly App $app,
        protected readonly Response $response,
    ) {
    }

    /**
     * Session-backed identity, re-checked against the allowlist on every
     * request so a removed or suspended user is out on their next click.
     */
    protected function currentUser(): ?string
    {
        Session::start();

        $email = $_SESSION['user_email'] ?? null;
        if (!is_string($email) || $email === '') {
            return null;
        }

        if (!$this->app->userStore()->isAllowed($email)) {
            unset($_SESSION['user_email']);

            return null;
        }

        return $email;
    }

    /** "First Last" for the signed-in analyst, else their email, else ''. */
    protected function currentUserDisplayName(): string
    {
        $email = $this->currentUser();
        if ($email === null) {
            return '';
        }

        $profile = $this->app->userStore()->get($email);
        $name    = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));

        return $name !== '' ? $name : $email;
    }

    /**
     * Without Google sign-in there is no per-user role to check (Basic auth /
     * open dev), so every capability is granted — the /users mutations
     * require a real identity separately.
     */
    protected function currentUserCan(string $capability): bool
    {
        if (!$this->app->googleAuth()->isConfigured()) {
            return true;
        }

        $me   = $this->currentUser();
        $role = $me !== null ? $this->app->userStore()->roleOf($me) : null;

        return $role !== null && $this->app->roleCapabilities()->can($role, $capability);
    }

    /** Writes the 403 itself; call sites just `return` on false. */
    protected function requireCapability(string $capability): bool
    {
        if ($this->currentUserCan($capability)) {
            return true;
        }

        $this->forbidden('You do not have permission to do this.');

        return false;
    }

    /**
     * The extraction must exist (a well-formed unknown id would otherwise
     * create a phantom directory) and, when $allowed is given, be in one of
     * those statuses. Writes the 404/409 itself.
     *
     * @param list<string>|null $allowed
     */
    protected function requireExtractionStatus(string $siteId, string $extractionId, ?array $allowed): bool
    {
        $row = $this->app->index()->getExtraction($siteId, $extractionId);
        if ($row === null || $this->app->dataStore()->readExtractionPayload($siteId, $extractionId) === null) {
            $this->notFound();

            return false;
        }

        $status = (string) ($row['status'] ?? '');
        if ($allowed !== null && !in_array($status, $allowed, true)) {
            $this->response->text(409, "This action is not available for an extraction in status \"{$status}\".");

            return false;
        }

        return true;
    }

    /** Double-submit CSRF token, stored in a cookie and echoed into forms. */
    protected function csrfToken(): string
    {
        $token = (string) ($_COOKIE['swp_csrf'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            $this->response->cookie('swp_csrf', $token, [
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => Session::isHttps(),
                'path'     => '/',
            ]);
        }

        return $token;
    }

    /** ?lang=fr|en, else the configured default. */
    protected function locale(): string
    {
        $requested = strtolower((string) ($_GET['lang'] ?? ''));

        return in_array($requested, ['en', 'fr'], true)
            ? $requested
            : (string) $this->app->config->get('lang.default', 'en');
    }

    /**
     * Absolute base URL for links handed to something outside the browser
     * (the report data key). app.base_url wins so the Host header can't
     * choose it; the request host is only a fallback for an unset config.
     */
    protected function baseUrl(): string
    {
        $configured = rtrim((string) $this->app->config->get('app.base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        return (Session::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    protected function redirect(string $to): void
    {
        $this->response->redirect($to);
    }

    protected function notFound(): void
    {
        $this->response->text(404, "Not found\n");
    }

    protected function forbidden(string $message): void
    {
        $this->response->text(403, $message);
    }

    /** @param array<string, mixed> $vars */
    protected function render(string $template, array $vars): void
    {
        $this->response->header('Content-Type: text/html; charset=utf-8');
        $this->response->header('X-Content-Type-Options: nosniff');

        $vars['t']           = $this->app->translator($this->locale());
        $vars['lang']        = $vars['t']->locale;
        $vars['csrf']        = $vars['csrf'] ?? $this->csrfToken();
        $vars['appVersion']  = (string) $this->app->config->get('app.version', '');
        $vars['currentUser'] = $vars['currentUser']
            ?? ($this->app->googleAuth()->isConfigured() ? $this->currentUser() : null);
        // The /users page gates every action server-side; this only hides a
        // link a non-admin could do nothing with.
        $vars['canSeeUsersNav'] = $vars['canSeeUsersNav']
            ?? (!$this->app->googleAuth()->isConfigured()
                || ($vars['currentUser'] !== null && $this->app->userStore()->isAdmin($vars['currentUser'])));

        extract($vars, EXTR_SKIP);
        $templateFile = dirname(__DIR__, 2) . '/Web/templates/' . $template . '.php';

        require dirname(__DIR__, 2) . '/Web/templates/layout.php';
    }
}
