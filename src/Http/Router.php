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
    private const array RAW_FILES = ['payload', 'meta', 'summary', 'dns', 'rdap', 'tls', 'http'];

    public function __construct(private readonly App $app)
    {
    }

    public function dispatch(string $path): void
    {
        if (!$this->authenticate()) {
            return;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        match (true) {
            $segments === []
                => $this->sitesPage(),

            count($segments) === 2 && $segments[0] === 'site' && PayloadValidator::isUuid($segments[1])
                => $this->sitePage($segments[1]),

            count($segments) === 4 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && PayloadValidator::isUuid($segments[1]) && $this->isExtractionId($segments[3])
                => $this->extractionPage($segments[1], $segments[3]),

            count($segments) === 6 && $segments[0] === 'site' && $segments[2] === 'extraction'
                && $segments[4] === 'raw' && PayloadValidator::isUuid($segments[1])
                && $this->isExtractionId($segments[3])
                => $this->rawFile($segments[1], $segments[3], $segments[5]),

            default => $this->notFound(),
        };
    }

    private function sitesPage(): void
    {
        $this->render('sites', [
            'title' => 'Sites',
            'sites' => $this->app->index()->listSites($_GET['q'] ?? null),
            'search' => (string) ($_GET['q'] ?? ''),
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

        // Trends: headline values from the last summaries.
        $trends = [];
        foreach (array_slice($extractions, 0, 12) as $extraction) {
            $summary = $store->readSummary($siteId, (string) $extraction['id']);
            if ($summary !== null) {
                $trends[] = [
                    'id'              => $extraction['id'],
                    'received_at'     => $extraction['received_at'],
                    'db_total_bytes'  => $summary['db_total_bytes'] ?? null,
                    'autoload_bytes'  => $summary['autoload_bytes'] ?? null,
                    'plugins_with_update' => $summary['plugins_with_update'] ?? null,
                    'admins_count'    => $summary['admins_count'] ?? null,
                ];
            }
        }

        $this->render('site', [
            'title'       => $site['name'] ?? $site['site_url'] ?? $siteId,
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

        $this->render('extraction', [
            'title'        => 'Extraction ' . $extractionId,
            'siteId'       => $siteId,
            'extractionId' => $extractionId,
            'site'         => $store->readSiteInfo($siteId) ?? [],
            'payload'      => $payload,
            'meta'         => $store->readMeta($siteId, $extractionId) ?? [],
            'summary'      => $store->readSummary($siteId, $extractionId),
            'probes'       => $store->readAllProbeResults($siteId, $extractionId),
            'row'          => $this->app->index()->getExtraction($siteId, $extractionId),
        ]);
    }

    private function rawFile(string $siteId, string $extractionId, string $name): void
    {
        $name = basename($name, '.json');

        if (!in_array($name, self::RAW_FILES, true)) {
            $this->notFound();

            return;
        }

        $dir  = $this->app->dataStore()->extractionDir($siteId, $extractionId);
        $file = in_array($name, ['payload', 'meta', 'summary'], true)
            ? "{$dir}/{$name}.json"
            : "{$dir}/probes/{$name}.json";

        if (!is_file($file)) {
            $this->notFound();

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
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

    private function isExtractionId(string $value): bool
    {
        return (bool) preg_match('/^\d{8}T\d{6}Z(-\d+)?$/', $value);
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

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
