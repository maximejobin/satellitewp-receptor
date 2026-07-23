<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;

/**
 * Performance via Google PageSpeed Insights v5 (Lighthouse + CrUX field data).
 * A plain HTTPS GET — no headless Chrome to install. An API key is strongly
 * recommended: the anonymous quota returns HTTP 429 almost immediately.
 */
final class PageSpeedProbe extends AbstractProbe
{
    private const string ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** Attempts per strategy when PSI returns a transient error. */
    private const int MAX_ATTEMPTS = 3;

    /** Lab audits we keep, mapped to short names. Values are ms except CLS (unitless). */
    private const array LAB_AUDITS = [
        'first-contentful-paint'   => 'fcp',
        'largest-contentful-paint' => 'lcp',
        'total-blocking-time'      => 'tbt',
        'cumulative-layout-shift'  => 'cls',
        'speed-index'              => 'speed_index',
        'interactive'              => 'tti',
        'server-response-time'     => 'ttfb',
    ];

    /** CrUX field metrics, mapped to short names. */
    private const array FIELD_METRICS = [
        'FIRST_CONTENTFUL_PAINT_MS'      => 'fcp',
        'LARGEST_CONTENTFUL_PAINT_MS'    => 'lcp',
        'CUMULATIVE_LAYOUT_SHIFT_SCORE'  => 'cls',
        'INTERACTION_TO_NEXT_PAINT'      => 'inp',
        'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb',
    ];

    /**
     * @param list<string> $strategies one or two of 'mobile'/'desktop'
     * @param list<string> $categories Lighthouse categories to request
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly array $strategies,
        private readonly array $categories,
        private readonly string $locale,
        private readonly int $timeout,
        private readonly int $minScore,
        private readonly string $userAgent,
    ) {
    }

    public function name(): string
    {
        return 'pagespeed';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        $url = $site->homeUrl !== '' ? $site->homeUrl : $site->siteUrl;
        if ($url === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No URL in site context']];
        }

        $client = new Client([
            'timeout'     => $this->timeout,
            'headers'     => ['User-Agent' => $this->userAgent],
            'http_errors' => false,
        ]);

        $data   = [];
        $errors = [];
        $scores = [];

        foreach ($this->strategies as $strategy) {
            [$result, $error] = $this->runStrategyWithRetries($client, $url, $strategy);

            if ($error !== null) {
                $errors[] = $error;
                continue;
            }

            $data[$strategy] = $result;
            if ($result['performance_score'] !== null) {
                $scores[] = $result['performance_score'];
            }
        }

        if ($data === []) {
            return ['target' => $url, 'status' => ProbeResult::STATUS_ERROR, 'errors' => $errors];
        }

        $worst  = $scores !== [] ? min($scores) : null;
        $status = match (true) {
            // A partial run (one strategy failed) is never "ok" — data is missing.
            $errors !== []           => ProbeResult::STATUS_WARN,
            $worst === null          => ProbeResult::STATUS_WARN,
            $worst < $this->minScore => ProbeResult::STATUS_WARN,
            default                  => ProbeResult::STATUS_OK,
        };

        return ['target' => $url, 'data' => $data, 'status' => $status, 'errors' => $errors];
    }

    /**
     * PSI regularly returns transient 500s ("Lighthouse returned error") and
     * 429s. Retry those a couple of times before giving up on the strategy.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function runStrategyWithRetries(Client $client, string $url, string $strategy): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            [$result, $error] = $this->runStrategy($client, $url, $strategy);

            if ($error === null) {
                return [$result, null];
            }

            $lastError = $error;

            if (!$this->isTransient($error) || $attempt === self::MAX_ATTEMPTS) {
                break;
            }

            sleep($attempt * 2); // 2 s, then 4 s
        }

        return [null, $lastError];
    }

    private function isTransient(string $error): bool
    {
        return (bool) preg_match('/HTTP (429|500|502|503|504)\b/', $error)
            || str_contains($error, 'cURL error')
            || str_contains($error, 'timed out');
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function runStrategy(Client $client, string $url, string $strategy): array
    {
        // PSI expects the category parameter repeated, not category[]= — so the
        // query string is built by hand rather than through Guzzle's array form.
        $parts = [
            'url=' . rawurlencode($url),
            'strategy=' . rawurlencode($strategy),
            'locale=' . rawurlencode($this->locale),
        ];
        foreach ($this->categories as $category) {
            $parts[] = 'category=' . rawurlencode($category);
        }
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $parts[] = 'key=' . rawurlencode($this->apiKey);
        }

        try {
            $response = $client->get(self::ENDPOINT, ['query' => implode('&', $parts)]);
        } catch (GuzzleException $e) {
            return [null, "PSI {$strategy}: {$e->getMessage()}"];
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            return [null, "PSI {$strategy}: invalid JSON (HTTP {$response->getStatusCode()})"];
        }

        if (isset($decoded['error'])) {
            $message = $decoded['error']['message'] ?? 'unknown error';
            $code    = $decoded['error']['code'] ?? $response->getStatusCode();

            return [null, "PSI {$strategy}: HTTP {$code} — {$message}"];
        }

        return [self::parseResponse($decoded), null];
    }

    /**
     * Pure transformation of a PSI v5 response — unit-testable with a fixture.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function parseResponse(array $response): array
    {
        $lighthouse = (array) ($response['lighthouseResult'] ?? []);
        $audits     = (array) ($lighthouse['audits'] ?? []);

        // Every returned category (performance, accessibility, best-practices,
        // seo, …) as a 0-100 score, plus its localized title.
        $scores     = [];
        $categories = [];
        foreach ((array) ($lighthouse['categories'] ?? []) as $id => $category) {
            $raw = $category['score'] ?? null;
            $scores[$id] = $raw !== null ? (int) round((float) $raw * 100) : null;
            $categories[$id] = [
                'title' => $category['title'] ?? null,
                'score' => $scores[$id],
            ];
        }

        $lab = [];
        foreach (self::LAB_AUDITS as $auditId => $short) {
            $audit = (array) ($audits[$auditId] ?? []);
            $lab[$short] = [
                'value'   => $audit['numericValue'] ?? null,
                'display' => $audit['displayValue'] ?? null,
            ];
        }

        return [
            'strategy'          => $lighthouse['configSettings']['formFactor'] ?? null,
            'locale'            => $lighthouse['configSettings']['locale'] ?? null,
            'scores'            => $scores,
            'categories'        => $categories,
            'performance_score' => $scores['performance'] ?? null,
            'lab'               => $lab,
            'field'             => self::parseField((array) ($response['loadingExperience'] ?? [])),
            'origin_field'      => self::parseField((array) ($response['originLoadingExperience'] ?? [])),
            'lighthouse_version' => $lighthouse['lighthouseVersion'] ?? null,
            'fetch_time'        => $lighthouse['fetchTime'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $experience CrUX loadingExperience block
     * @return array<string, mixed>
     */
    private static function parseField(array $experience): array
    {
        if ($experience === [] || !isset($experience['metrics'])) {
            return ['available' => false];
        }

        $metrics = [];
        foreach (self::FIELD_METRICS as $key => $short) {
            if (isset($experience['metrics'][$key])) {
                $metrics[$short] = [
                    'percentile' => $experience['metrics'][$key]['percentile'] ?? null,
                    'category'   => $experience['metrics'][$key]['category'] ?? null,
                ];
            }
        }

        return [
            'available'        => true,
            'overall_category' => $experience['overall_category'] ?? null,
            'metrics'          => $metrics,
        ];
    }
}
