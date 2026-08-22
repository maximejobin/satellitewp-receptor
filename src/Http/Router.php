<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Probe\BlogVaultProbe;
use SatelliteWP\Xtractor\Storage\Index;

/**
 * Read-only web UI. Lists come from SQLite, detail pages from JSON files.
 *
 * Routes:
 *   /                                        sites list
 *   /site/{site_id}                          site detail (history, events, trends)
 *   /site/{site_id}/extraction/{id}          extraction detail (findings + probes + payload)
 *   /site/{site_id}/extraction/{id}/raw/{f}  serve a JSON file (allowlisted)
 */
final class Router
{
    public function __construct(private readonly App $app)
    {
    }

    public function dispatch(string $path): void
    {
        // The sign-in dance itself must not require being signed in.
        if (str_starts_with($path, '/auth/')) {
            $this->authRoute($path);

            return;
        }

        if (!$this->authenticate()) {
            return;
        }

        $match  = self::matchRoute($path);
        $params = $match['params'];

        match ($match['route']) {
            'sites'      => $this->sitesPage(),
            'catalog'    => $this->catalogPage(),
            'users'      => $this->usersPage(),
            'site'       => $this->sitePage($params['site_id']),
            'extraction' => $this->extractionPage($params['site_id'], $params['extraction_id']),
            'raw'        => $this->rawFile($params['site_id'], $params['extraction_id'], $params['file']),
            default      => $this->notFound(),
        };
    }

    /**
     * Pure route resolution — no side effects, so it is unit-testable. Returns
     * the matched route name and its validated params, or 'not_found'. All
     * identifiers are validated here (UUID, extraction id), which is the first
     * line of defence for the read-only web surface.
     *
     * @return array{route: string, params: array<string, string>}
     */
    public static function matchRoute(string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        $isSite       = static fn (int $i): bool => PayloadValidator::isUuid($segments[$i] ?? '');
        $isExtraction = static fn (int $i): bool => self::isExtractionId($segments[$i] ?? '');

        return match (true) {
            $segments === []
                => ['route' => 'sites', 'params' => []],

            $segments === ['catalog']
                => ['route' => 'catalog', 'params' => []],

            $segments === ['users']
                => ['route' => 'users', 'params' => []],

            count($segments) === 2 && $segments[0] === 'site' && $isSite(1)
                => ['route' => 'site', 'params' => ['site_id' => $segments[1]]],

            count($segments) === 4 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && $isSite(1) && $isExtraction(3)
                => ['route' => 'extraction', 'params' => ['site_id' => $segments[1], 'extraction_id' => $segments[3]]],

            count($segments) === 6 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && $segments[4] === 'raw' && $isSite(1) && $isExtraction(3)
                => ['route' => 'raw', 'params' => [
                    'site_id'       => $segments[1],
                    'extraction_id' => $segments[3],
                    'file'          => $segments[5],
                ]],

            default => ['route' => 'not_found', 'params' => []],
        };
    }

    private function sitesPage(): void
    {
        $this->render('sites', [
            'title'      => 'Sites',
            'nav'        => 'sites',
            'breadcrumb' => '<b>Sites</b>',
            'sites'      => $this->app->index()->listSites($_GET['q'] ?? null),
            'search'     => (string) ($_GET['q'] ?? ''),
        ]);
    }

    private function catalogPage(): void
    {
        $needsOnly = !empty($_GET['needs']);

        $this->render('catalog', [
            'title'      => 'Software catalogue',
            'nav'        => 'catalog',
            'breadcrumb' => '<a href="/">Sites</a> › <b>Software catalogue</b>',
            'entries'    => $this->app->softwareCatalog()->all($_GET['type'] ?? null, $needsOnly),
            'needsOnly'  => $needsOnly,
        ]);
    }

    private function sitePage(string $siteId): void
    {
        $store = $this->app->dataStore();
        $site  = $store->readSiteInfo($siteId);

        if ($site === null) {
            $this->notFound();

            return;
        }

        $extractions = $this->app->index()->listExtractions($siteId);

        // Trends: headline values read straight from each payload (no derived file).
        $trends = [];
        foreach (array_slice($extractions, 0, 12) as $extraction) {
            $payload = $store->readExtractionPayload($siteId, (string) $extraction['id']);
            if ($payload !== null) {
                $plugins = is_array($payload['plugins'] ?? null) ? $payload['plugins'] : [];
                $trends[] = [
                    'id'              => $extraction['id'],
                    'received_at'     => $extraction['received_at'],
                    'db_total_bytes'  => $payload['database']['total_bytes'] ?? null,
                    'autoload_bytes'  => $payload['autoload']['total_bytes'] ?? null,
                    'plugins_with_update' => count(array_filter($plugins, static fn ($p) => !empty($p['new_version']))),
                    'admins_count'    => is_array($payload['administrators'] ?? null) ? count($payload['administrators']) : null,
                ];
            }
        }

        $this->render('site', [
            'title'       => $site['name'] ?? $site['site_url'] ?? $siteId,
            'nav'         => 'sites',
            'breadcrumb'  => '<a href="/">Sites</a> › <b>' . htmlspecialchars((string) ($site['name'] ?? $siteId), ENT_QUOTES) . '</b>',
            'site'        => $site,
            'siteId'      => $siteId,
            'extractions' => $extractions,
            'probeRuns'   => $this->probeRunsByExtraction($siteId, $extractions),
            'events'      => $this->recentEvents($siteId, 20),
            'trends'      => $trends,
        ]);
    }

    private function extractionPage(string $siteId, string $extractionId): void
    {
        $store   = $this->app->dataStore();
        $payload = $store->readExtractionPayload($siteId, $extractionId);

        if ($payload === null) {
            $this->notFound();

            return;
        }

        $site = $store->readSiteInfo($siteId) ?? [];
        $bc   = '<a href="/">Sites</a> › <a href="/site/' . htmlspecialchars($siteId, ENT_QUOTES) . '">'
            . htmlspecialchars((string) ($site['name'] ?? $siteId), ENT_QUOTES)
            . '</a> › <span class="mono">' . htmlspecialchars($extractionId, ENT_QUOTES) . '</span>';

        $row = $this->app->index()->getExtraction($siteId, $extractionId);

        // The BlogVault pre-flight is one cheap call and only matters while the
        // analyst is still deciding whether to run, so it is skipped once the
        // extraction has been analysed — a finished report must not pay for it
        // on every refresh.
        $awaiting  = in_array((string) ($row['status'] ?? ''), [Index::STATUS_PENDING, Index::STATUS_QUEUED], true);
        $blogVault = $awaiting ? $this->blogVaultPreflight($payload) : null;

        $this->render('extraction', [
            'title'        => 'Extraction ' . $extractionId,
            'nav'          => 'sites',
            'breadcrumb'   => $bc,
            'siteId'       => $siteId,
            'extractionId' => $extractionId,
            'site'         => $site,
            'payload'      => $payload,
            'meta'         => $store->readMeta($siteId, $extractionId) ?? [],
            'findings'     => $store->readFindings($siteId, $extractionId),
            'probes'       => $store->readAllProbeResults($siteId, $extractionId),
            'row'          => $row,
            'eol'          => $this->app->endOfLife(),
            'catalog'      => $this->app->softwareCatalog(),
            'csrf'         => $this->csrfToken(),
            'blogVault'    => $blogVault,
        ]);
    }

    private function rawFile(string $siteId, string $extractionId, string $name): void
    {
        $relative = self::resolveRawFile($name);
        if ($relative === null) {
            $this->notFound();

            return;
        }

        $file = $this->app->dataStore()->extractionDir($siteId, $extractionId) . '/' . $relative;

        if (!is_file($file)) {
            $this->notFound();

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
    }

    /**
     * Map a requested raw-file name to its path relative to the extraction dir,
     * or null when the name is not allowlisted. Pure and path-traversal-safe:
     * the name is basename-stripped and matched against a fixed allowlist, so a
     * request like "../../keys" or "payload/../meta" can never escape.
     */
    public static function resolveRawFile(string $name): ?string
    {
        $name = basename($name, '.json');

        // The name comes from the URL, so it is untrusted. basename() above
        // already strips every path component, which confines the result to the
        // one extraction directory; this pattern additionally rejects anything
        // that is not a plain file stem (no dotfiles, no empty name).
        //
        // There is deliberately no allowlist of probe names: everything in an
        // extraction directory is already rendered on the page, so a list would
        // guard nothing, while silently 404-ing every probe someone forgot to
        // add to it — which is exactly how blogvault.json and wordfence.json
        // ended up as dead links. A file that does not exist 404s on its own.
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
            return null;
        }

        return in_array($name, ['payload', 'meta', 'findings'], true)
            ? "{$name}.json"
            : "probes/{$name}.json";
    }

    /**
     * @param list<array<string, mixed>> $extractions
     * @return array<string, list<array<string, mixed>>>
     */
    private function probeRunsByExtraction(string $siteId, array $extractions): array
    {
        $runs = [];
        foreach ($extractions as $extraction) {
            $runs[(string) $extraction['id']] = $this->app->index()->listProbeRuns($siteId, (string) $extraction['id']);
        }

        return $runs;
    }

    /** @return list<array<string, mixed>> newest first */
    private function recentEvents(string $siteId, int $limit): array
    {
        $files = glob($this->app->dataStore()->siteDir($siteId) . '/events/*.jsonl') ?: [];
        rsort($files);

        $events = [];
        foreach ($files as $file) {
            $lines = array_reverse(array_filter(explode("\n", (string) file_get_contents($file))));
            foreach ($lines as $line) {
                $batch = json_decode($line, true);
                if (!is_array($batch)) {
                    continue;
                }
                foreach (array_reverse((array) ($batch['events'] ?? [])) as $event) {
                    $events[] = (array) $event + ['received_at' => $batch['received_at'] ?? null];
                    if (count($events) >= $limit) {
                        return $events;
                    }
                }
            }
        }

        return $events;
    }

    private function usersPage(): void
    {
        $users = $this->app->userStore();
        $me    = $this->currentUser();

        $this->render('users', [
            'title'   => 'Utilisateurs',
            'nav'     => 'users',
            'users'   => $users->all(),
            'admin'   => $users->admin(),
            'me'      => $me,
            'isAdmin' => $me !== null && $users->isAdmin($me),
            'csrf'    => $this->csrfToken(),
            'notice'  => (string) ($_GET['notice'] ?? ''),
        ]);
    }

    /**
     * Session-backed identity. Re-checks the allowlist on every request, so
     * removing someone from the users file logs them out on their next click
     * rather than whenever their session happens to expire.
     */
    private function currentUser(): ?string
    {
        self::startSession();

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

    /**
     * Whether the *browser's* connection is HTTPS. Behind a TLS-terminating
     * proxy PHP sees plain HTTP, so X-Forwarded-Proto has to be honoured — get
     * this wrong and the OAuth redirect_uri comes out as http://, which Google
     * rejects as redirect_uri_mismatch on the very first sign-in.
     *
     * Spoofing the header can only make us stricter (a secure-flagged cookie,
     * an https redirect_uri Google will not recognise): it cannot downgrade
     * anything, so trusting it is safe even when no proxy is in front.
     */
    private static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }

        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        // The header may carry a list when several proxies are chained.
        return str_starts_with($forwarded, 'https');
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',   // the OAuth callback is a top-level GET redirect
            'secure'   => self::isHttps(),
            'path'     => '/',
        ]);
        session_name('swp_session');
        session_start();
    }

    /** /auth/login · /auth/callback · /auth/logout */
    private function authRoute(string $path): void
    {
        $google = $this->app->googleAuth();
        if (!$google->isConfigured()) {
            $this->notFound();

            return;
        }

        self::startSession();

        match (trim($path, '/')) {
            'auth/login'    => $this->authLogin(),
            'auth/callback' => $this->authCallback(),
            'auth/logout'   => $this->authLogout(),
            default         => $this->notFound(),
        };
    }

    private function authLogin(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $this->redirect($this->app->googleAuth()->authorizationUrl($state, $this->redirectUri()));
    }

    private function authCallback(): void
    {
        $expected = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);   // single use, whatever happens next

        // Mismatched state means the callback was not started by this browser.
        if (!is_string($expected) || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
            $this->loginPage('Session expirée ou requête invalide. Réessaie.');

            return;
        }

        $email = $this->app->googleAuth()->emailFromCode((string) ($_GET['code'] ?? ''), $this->redirectUri());
        if ($email === null) {
            $this->loginPage('Authentification Google refusée.');

            return;
        }

        $users = $this->app->userStore();

        // Deliberately NO "first sign-in becomes admin" bootstrap: this UI sits
        // on a publicly reachable host (the receptor has to be), so whoever hit
        // the URL first would claim the account. The list is seeded out of band
        // with `bin/xtractor users:add`.
        if ($users->isEmpty()) {
            $this->loginPage('Aucun utilisateur enregistré. Amorce la liste avec : bin/xtractor users:add <email>');

            return;
        }

        if (!$users->isAllowed($email)) {
            $this->loginPage("{$email} n'est pas autorisé à accéder à cette interface.");

            return;
        }

        session_regenerate_id(true);       // no session fixation across the login boundary
        $_SESSION['user_email'] = $email;

        $this->redirect('/');
    }

    private function authLogout(): void
    {
        $_SESSION = [];

        // Expire the cookie as well: session_destroy() only drops the data,
        // leaving the browser to keep presenting a dead session id.
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'] ?: '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);

        session_destroy();
        $this->loginPage('Déconnecté.');
    }

    /**
     * Configured value wins; otherwise derive it from the request so a plain
     * vhost needs no extra setting. Must match what is registered on the Google
     * client, character for character.
     */
    private function redirectUri(): string
    {
        $configured = (string) $this->app->config->get('auth.google.redirect_uri', '');
        if ($configured !== '') {
            return $configured;
        }

        return (self::isHttps() ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/callback';
    }

    private function loginPage(string $message = ''): void
    {
        http_response_code($message === '' ? 401 : 403);
        $this->render('login', [
            'title'   => 'Connexion',
            'nav'     => '',
            'message' => $message,
            'firstRun' => $this->app->userStore()->isEmpty(),
        ]);
    }

    /**
     * Is this site managed in BlogVault? A single "url:contains" lookup, matched
     * on exact host. Absence is a business signal in its own right — the site is
     * not on a maintenance plan — so it is reported, never treated as an error.
     *
     * @param array<string, mixed> $payload
     * @return array{configured: bool, found: bool, host: string, name?: string, id?: string, error?: string}
     */
    private function blogVaultPreflight(array $payload): array
    {
        $host = (string) (parse_url(
            (string) ($payload['home_url'] ?? $payload['site_url'] ?? ''),
            PHP_URL_HOST
        ) ?? '');

        $configured = (string) $this->app->config->get('blogvault.base_url', '') !== ''
            && (string) $this->app->config->get('blogvault.api_key', '') !== '';

        if (!$configured || $host === '') {
            return ['configured' => $configured, 'found' => false, 'host' => $host];
        }

        try {
            $listed = $this->app->blogVault()->get('sites', ['filters' => ['url:contains' => $host]]);
            $match  = BlogVaultProbe::matchSite($listed, $host);
        } catch (\Throwable $e) {
            return ['configured' => true, 'found' => false, 'host' => $host, 'error' => $e->getMessage()];
        }

        if ($match === null) {
            return ['configured' => true, 'found' => false, 'host' => $host];
        }

        return [
            'configured' => true,
            'found'      => true,
            'host'       => $host,
            'name'       => (string) ($match['title'] ?? $match['url'] ?? $host),
            'id'         => (string) ($match['id'] ?? ''),
        ];
    }

    /**
     * Google sign-in when configured, Basic auth as a dev fallback, open when
     * neither is set (rely on server-level protection then).
     */
    private function authenticate(): bool
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
            return true; // auth not configured (dev) — rely on server-level protection in prod
        }

        $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
        $givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';

        if (hash_equals((string) $user, $givenUser) && password_verify($givenPass, (string) $passHash)) {
            return true;
        }

        header('WWW-Authenticate: Basic realm="SatelliteWP Xtractor"');
        http_response_code(401);
        echo 'Authentication required.';

        return false;
    }

    public static function isExtractionId(string $value): bool
    {
        return (bool) preg_match('/^\d{8}T\d{6}Z(-\d+)?$/', $value);
    }

    /**
     * Handle a web form POST (the only mutation the UI allows: setting a
     * plugin/theme licence). Protected by Basic auth + a double-submit CSRF
     * token, then follows the POST/redirect/GET pattern.
     */
    public function handlePost(string $path): void
    {
        if (!$this->authenticate()) {
            return;
        }

        if (!hash_equals((string) ($_COOKIE['swp_csrf'] ?? ''), (string) ($_POST['_csrf'] ?? '_'))) {
            http_response_code(400);
            echo 'Invalid CSRF token';

            return;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        // Queue an extraction for analysis. The web request only flips a status;
        // the cron worker does the slow part, so nothing here can time out.
        if (count($segments) === 5
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            && $segments[4] === 'run'
        ) {
            $this->app->index()->setExtractionStatus($segments[1], $segments[3], Index::STATUS_QUEUED);
            $this->redirect(self::safeReturn(
                $_POST['return'] ?? "/site/{$segments[1]}/extraction/{$segments[3]}"
            ));

            return;
        }

        if ($segments === ['users']) {
            $users = $this->app->userStore();
            $me    = $this->currentUser();

            // Only the admin may change the list, and only when sign-in is on:
            // with Basic auth there is no identity to check against.
            if ($me === null || !$users->isAdmin($me)) {
                http_response_code(403);
                echo 'Seul l\'administrateur peut gérer les utilisateurs.';

                return;
            }

            $notice = match ((string) ($_POST['action'] ?? '')) {
                'add'    => $users->add((string) ($_POST['email'] ?? ''))
                    ? 'added' : 'add-failed',
                'remove' => $users->remove((string) ($_POST['email'] ?? ''))
                    ? 'removed' : 'remove-failed',
                default  => '',
            };

            $this->redirect('/users' . ($notice !== '' ? '?notice=' . $notice : ''));

            return;
        }

        if ($segments === ['catalog']) {
            $type = (string) ($_POST['type'] ?? '');
            if (in_array($type, ['plugin', 'theme'], true)) {
                $this->app->softwareCatalog()->setLicense(
                    $type,
                    (string) ($_POST['slug'] ?? ''),
                    (string) ($_POST['license'] ?? '')
                );
            }
            $this->redirect(self::safeReturn($_POST['return'] ?? '/catalog'));

            return;
        }

        $this->notFound();
    }

    /**
     * Only same-site relative paths are allowed as a redirect target. A target
     * must start with a single '/' followed by neither '/' nor '\': browsers
     * normalise a backslash to a slash in the Location authority, so '/\evil.com'
     * would resolve to '//evil.com' — an off-site open redirect.
     */
    public static function safeReturn(mixed $target): string
    {
        $target = is_string($target) ? $target : '';

        return (str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_starts_with($target, '/\\'))
            ? $target
            : '/catalog';
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }

    /** Double-submit CSRF token, stored in a cookie and echoed into forms. */
    private function csrfToken(): string
    {
        $token = (string) ($_COOKIE['swp_csrf'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            setcookie('swp_csrf', $token, [
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => self::isHttps(),
                'path'     => '/',
            ]);
        }

        return $token;
    }

    /** Locale for this request: ?lang=fr|en, else the configured default. */
    private function locale(): string
    {
        $requested = strtolower((string) ($_GET['lang'] ?? ''));

        return in_array($requested, ['en', 'fr'], true)
            ? $requested
            : (string) $this->app->config->get('lang.default', 'en');
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $vars['t']    = $this->app->translator($this->locale());
        $vars['lang'] = $vars['t']->locale;
        $vars['csrf'] = $vars['csrf'] ?? $this->csrfToken();
        // Drives the account block in the sidebar; null under Basic auth, where
        // there is no identity to show.
        $vars['currentUser'] = $vars['currentUser']
            ?? ($this->app->googleAuth()->isConfigured() ? $this->currentUser() : null);
        extract($vars, EXTR_SKIP);
        $templateFile = dirname(__DIR__) . '/Web/templates/' . $template . '.php';

        require dirname(__DIR__) . '/Web/templates/layout.php';
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
    }
}
