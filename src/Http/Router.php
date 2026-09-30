<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\App;
use SatelliteWP\Xtractor\Http\Controller\AuthController;
use SatelliteWP\Xtractor\Http\Controller\CatalogController;
use SatelliteWP\Xtractor\Http\Controller\Controller;
use SatelliteWP\Xtractor\Http\Controller\CrmController;
use SatelliteWP\Xtractor\Http\Controller\DataController;
use SatelliteWP\Xtractor\Http\Controller\ExtractionController;
use SatelliteWP\Xtractor\Http\Controller\HomeController;
use SatelliteWP\Xtractor\Http\Controller\ReportController;
use SatelliteWP\Xtractor\Http\Controller\SiteController;
use SatelliteWP\Xtractor\Http\Controller\UserController;

/**
 * Admin UI front router: resolves a path to a named route (pure, tested),
 * applies authentication and CSRF, then hands off to a controller.
 */
final class Router
{
    /** @var array<string, array{0: class-string<Controller>, 1: string}> GET route name => handler */
    private const array GET_HANDLERS = [
        'home'                        => [HomeController::class, 'home'],
        'styleguide'                  => [HomeController::class, 'styleguide'],
        'sites'                       => [SiteController::class, 'list'],
        'site'                        => [SiteController::class, 'show'],
        'extraction'                  => [ExtractionController::class, 'show'],
        'raw'                         => [ExtractionController::class, 'raw'],
        'extraction_report_json'      => [ReportController::class, 'json'],
        'status'                      => [DataController::class, 'status'],
        'data_wp_versions'            => [DataController::class, 'wordPressVersions'],
        'data_php_versions'           => [DataController::class, 'phpVersions'],
        'data_databases'              => [DataController::class, 'databases'],
        'data_vulnerabilities'        => [DataController::class, 'vulnerabilities'],
        'data_vulnerabilities_search' => [DataController::class, 'vulnerabilitiesSearch'],
        'catalog'                     => [CatalogController::class, 'page'],
        'catalog_search'              => [CatalogController::class, 'search'],
        'users'                       => [UserController::class, 'list'],
        'profile'                     => [UserController::class, 'profile'],
        'crm_clients'                 => [CrmController::class, 'clients'],
        'crm_clients_search'          => [CrmController::class, 'clientsSearch'],
        'crm_client'                  => [CrmController::class, 'client'],
        'crm_websites'                => [CrmController::class, 'websites'],
        'crm_websites_search'         => [CrmController::class, 'websitesSearch'],
        'crm_tags_search'             => [CrmController::class, 'tagsSearch'],
        'crm_website'                 => [CrmController::class, 'website'],
        'crm_products'                => [CrmController::class, 'products'],
        'crm_items'                   => [CrmController::class, 'items'],
        'crm_items_search'            => [CrmController::class, 'itemsSearch'],
    ];

    /** @var array<string, array{0: class-string<Controller>, 1: string}> POST route name => handler */
    private const array POST_HANDLERS = [
        'extraction_run'          => [ExtractionController::class, 'run'],
        'extraction_abort'        => [ExtractionController::class, 'abort'],
        'extraction_rerun'        => [ExtractionController::class, 'rerun'],
        'extraction_report_token' => [ExtractionController::class, 'reportToken'],
        'extraction_observations' => [ExtractionController::class, 'observations'],
        'extraction_licenses'     => [ExtractionController::class, 'licenses'],
        'users'                   => [UserController::class, 'save'],
        'profile'                 => [UserController::class, 'saveProfile'],
        'keys'                    => [SiteController::class, 'keys'],
        'catalog'                 => [CatalogController::class, 'save'],
        'subscriptions'           => [CrmController::class, 'linkSubscription'],
    ];

    /** Actions POSTed to /site/{uuid}/extraction/{id}/<action>. */
    private const array EXTRACTION_ACTIONS = [
        'run'          => 'extraction_run',
        'abort'        => 'extraction_abort',
        'rerun'        => 'extraction_rerun',
        'report-token' => 'extraction_report_token',
        'observations' => 'extraction_observations',
        'licenses'     => 'extraction_licenses',
    ];

    /** Single-segment POST targets with no GET page of their own (except users/profile). */
    private const array FLAT_POST_ROUTES = ['users', 'profile', 'keys', 'catalog', 'subscriptions'];

    private readonly Response $response;

    public function __construct(private readonly App $app, ?Response $response = null)
    {
        $this->response = $response ?? new Response();
    }

    public function dispatch(string $path): void
    {
        // The sign-in flow itself must not require being signed in.
        if (str_starts_with($path, '/auth/')) {
            $this->auth()->route($path);

            return;
        }

        $match = self::matchRoute($path);

        // report.json is fetched by a script with no browser session; it has its own credentials.
        if ($match['route'] !== 'extraction_report_json' && !$this->auth()->authenticate()) {
            return;
        }

        $this->invoke(self::GET_HANDLERS[$match['route']] ?? null, $match['params']);
    }

    public function handlePost(string $path): void
    {
        if (!$this->auth()->authenticate()) {
            return;
        }

        $cookieToken = (string) ($_COOKIE['swp_csrf'] ?? '');
        if ($cookieToken === '' || !hash_equals($cookieToken, (string) ($_POST['_csrf'] ?? ''))) {
            $this->response->text(400, 'Invalid CSRF token');

            return;
        }

        $match = self::matchPostRoute($path);
        if ($match['route'] === 'auth_logout') {
            $this->auth()->logout();

            return;
        }

        $this->invoke(self::POST_HANDLERS[$match['route']] ?? null, $match['params']);
    }

    /**
     * Pure GET route resolution with every identifier validated (UUID,
     * extraction id, numeric CRM id) — the first line of defence.
     *
     * @return array{route: string, params: array<string, string>}
     */
    public static function matchRoute(string $path): array
    {
        $segments = self::segments($path);

        $static = [
            ''                        => 'home',
            'extractions'             => 'sites',
            'status'                  => 'status',
            'catalog'                 => 'catalog',
            'catalog/search'          => 'catalog_search',
            'clients'                 => 'crm_clients',
            'clients/search'          => 'crm_clients_search',
            'websites'                => 'crm_websites',
            'websites/search'         => 'crm_websites_search',
            'websites/tags/search'    => 'crm_tags_search',
            'products'                => 'crm_products',
            'items'                   => 'crm_items',
            'items/search'            => 'crm_items_search',
            'users'                   => 'users',
            'profile'                 => 'profile',
            'styleguide'              => 'styleguide',
            'data/wp-versions'        => 'data_wp_versions',
            'data/databases'          => 'data_databases',
            'data/php-versions'       => 'data_php_versions',
            'data/vulnerabilities'    => 'data_vulnerabilities',
            'data/vulnerabilities/search' => 'data_vulnerabilities_search',
        ];
        $joined = implode('/', $segments);
        if (isset($static[$joined])) {
            return ['route' => $static[$joined], 'params' => []];
        }

        $count = count($segments);

        if ($count === 2 && in_array($segments[0], ['clients', 'websites'], true) && ctype_digit($segments[1])) {
            return ['route' => $segments[0] === 'clients' ? 'crm_client' : 'crm_website', 'params' => ['id' => $segments[1]]];
        }

        if ($count === 2 && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])) {
            return ['route' => 'site', 'params' => ['site_id' => $segments[1]]];
        }

        $extraction = self::extractionParams($segments);
        if ($extraction !== null) {
            if ($count === 4) {
                return ['route' => 'extraction', 'params' => $extraction];
            }
            if ($count === 5 && $segments[4] === 'report.json') {
                return ['route' => 'extraction_report_json', 'params' => $extraction];
            }
            if ($count === 6 && $segments[4] === 'raw') {
                return ['route' => 'raw', 'params' => $extraction + ['file' => $segments[5]]];
            }
        }

        return ['route' => 'not_found', 'params' => []];
    }

    /**
     * Pure POST route resolution — same identifier validation as matchRoute().
     *
     * @return array{route: string, params: array<string, string>}
     */
    public static function matchPostRoute(string $path): array
    {
        $segments = self::segments($path);

        if ($segments === ['auth', 'logout']) {
            return ['route' => 'auth_logout', 'params' => []];
        }

        if (count($segments) === 1 && in_array($segments[0], self::FLAT_POST_ROUTES, true)) {
            return ['route' => $segments[0], 'params' => []];
        }

        $extraction = self::extractionParams($segments);
        if ($extraction !== null && count($segments) === 5 && isset(self::EXTRACTION_ACTIONS[$segments[4]])) {
            return ['route' => self::EXTRACTION_ACTIONS[$segments[4]], 'params' => $extraction];
        }

        return ['route' => 'not_found', 'params' => []];
    }

    public static function isExtractionId(string $value): bool
    {
        return (bool) preg_match('/^\d{8}T\d{6}Z(-\d+)?$/', $value);
    }

    /**
     * Only same-site relative paths: a target must start with a single '/'
     * followed by neither '/' nor '\' (browsers turn '/\evil.com' into
     * '//evil.com', an off-site redirect).
     */
    public static function safeReturn(mixed $target, string $fallback = '/catalog'): string
    {
        $target = is_string($target) ? $target : '';

        return (str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_starts_with($target, '/\\'))
            ? $target
            : $fallback;
    }

    /** Adds or replaces one query parameter, keeping the existing query and #fragment. */
    public static function withQueryParam(string $url, string $key, string $value): string
    {
        [$beforeFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query]              = array_pad(explode('?', $beforeFragment, 2), 2, '');

        parse_str((string) $query, $params);
        $params[$key] = $value;

        return $path . '?' . http_build_query($params) . ($fragment !== null ? '#' . $fragment : '');
    }

    /**
     * A raw-file name from the URL, as a path relative to the extraction
     * directory, or null. basename() confines it to that directory; there is
     * deliberately no allowlist, since every file there is already on the
     * page and a list would only 404 whichever probe it forgot.
     */
    public static function resolveRawFile(string $name): ?string
    {
        $name = basename($name, '.json');

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
            return null;
        }

        return in_array($name, ['payload', 'meta', 'findings', 'observations', 'licenses'], true)
            ? "{$name}.json"
            : "probes/{$name}.json";
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
    }

    /**
     * site/{uuid}/extraction/{id}[/…] -> its two ids, else null.
     *
     * @param list<string> $segments
     * @return array{site_id: string, extraction_id: string}|null
     */
    private static function extractionParams(array $segments): ?array
    {
        return count($segments) >= 4
            && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
            && $segments[2] === 'extraction' && self::isExtractionId($segments[3])
            ? ['site_id' => $segments[1], 'extraction_id' => $segments[3]]
            : null;
    }

    /**
     * @param array{0: class-string<Controller>, 1: string}|null $handler
     * @param array<string, string>                               $params
     */
    private function invoke(?array $handler, array $params): void
    {
        if ($handler === null) {
            $this->response->text(404, "Not found\n");

            return;
        }

        [$class, $method] = $handler;
        (new $class($this->app, $this->response))->$method($params);
    }

    private function auth(): AuthController
    {
        return new AuthController($this->app, $this->response);
    }
}
