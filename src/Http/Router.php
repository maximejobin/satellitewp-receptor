<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\App;

/**
 * Read-only web UI. Lists come from SQLite, detail pages from JSON files.
 *
 * Routes:
 *   /                                        sites list
 *   /site/{site_id}                          site detail (history, events, trends)
 *   /site/{site_id}/extraction/{id}          extraction detail (summary + probes + payload)
 *   /site/{site_id}/extraction/{id}/raw/{f}  serve a JSON file (allowlisted)
 */
final class Router
{
    /** Files servable through /raw/ — never anything else. */
    private const array RAW_FILES = [
        'payload', 'meta', 'findings',
        'dns', 'rdap', 'tls', 'http', 'pagespeed',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function dispatch(string $path): void
    {
        if (!$this->authenticate()) {
            return;
        }

        $match  = self::matchRoute($path);
        $params = $match['params'];

        match ($match['route']) {
            'sites'      => $this->sitesPage(),
            'catalog'    => $this->catalogPage(),
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
            'row'          => $this->app->index()->getExtraction($siteId, $extractionId),
            'eol'          => $this->app->endOfLife(),
            'catalog'      => $this->app->softwareCatalog(),
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

        if (!in_array($name, self::RAW_FILES, true)) {
            return null;
        }

        return in_array($name, ['payload', 'meta', 'summary', 'findings'], true)
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

    private function authenticate(): bool
    {
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
            setcookie('swp_csrf', $token, ['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
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
        $vars['csrf'] = $this->csrfToken();
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
