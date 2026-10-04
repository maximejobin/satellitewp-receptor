<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Probe;

use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Integration\SeRankingClient;
use SatelliteWP\Manager\Integration\SeRankingException;

/**
 * SE Ranking website audit. A crawl takes minutes to hours, so the pipeline
 * only creates the audit and stores a `pending` envelope; poll() (run by the
 * cron worker) checks its status and stores the report once it is finished.
 */
final class SeRankingProbe extends AbstractProbe
{
    /** Remote states that end the audit without a report. */
    private const array FAILED_STATES = ['cancelled', 'expired'];

    /** @param array<string, mixed> $settings audit settings sent on creation */
    public function __construct(
        private readonly ?SeRankingClient $client,
        private readonly array $settings,
        private readonly int $pollMinutes,
        private readonly int $giveUpHours,
    ) {
    }

    public function name(): string
    {
        return 'seranking';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        if ($this->client === null) {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['SE Ranking is not configured (seranking.api_key)']];
        }
        if ($site->host === '') {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => ['No host in site context']];
        }

        try {
            $auditId = $this->client->createAudit($site->host, $site->host . ' · SatelliteWP Manager ' . gmdate('Y-m-d H:i'), $this->settings);
        } catch (SeRankingException $e) {
            return ['status' => ProbeResult::STATUS_ERROR, 'errors' => [$e->getMessage()]];
        }

        return [
            'status' => ProbeResult::STATUS_PENDING,
            'data'   => [
                'audit_id'   => $auditId,
                'state'      => 'queued',
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'polled_at'  => null,
            ],
        ];
    }

    /** @param array<string, mixed> $envelope a stored seranking.json */
    public function isDue(array $envelope, int $now): bool
    {
        $polled = strtotime((string) ($envelope['data']['polled_at'] ?? ''));

        return $polled === false || $now - $polled >= $this->pollMinutes * 60;
    }

    /**
     * The envelope after one status check: still pending (progress updated),
     * finished with the report, or error once the audit failed, vanished or
     * outlived the give-up delay. A transient API failure keeps it pending.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public function poll(array $envelope, int $now): array
    {
        $data    = (array) ($envelope['data'] ?? []);
        $auditId = (int) ($data['audit_id'] ?? 0);
        $data['polled_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
        unset($data['last_error']);

        if ($this->client === null || $auditId <= 0) {
            return self::failed($envelope, $data, $this->client === null ? 'SE Ranking is not configured (seranking.api_key)' : 'No audit id stored');
        }

        try {
            $status = $this->client->auditStatus($auditId);
            $state  = (string) ($status['status'] ?? '');

            if (in_array($state, self::FAILED_STATES, true)) {
                $data['state'] = $state;

                return self::failed($envelope, $data, "SE Ranking audit {$auditId} ended as \"{$state}\"");
            }

            if ($state === 'finished') {
                $report = $this->client->auditReport($auditId);
                if (($report['is_finished'] ?? false) === true) {
                    $data['state'] = 'finished';
                    $data          = array_merge($data, self::parseReport($report));

                    return array_merge($envelope, ['status' => ProbeResult::STATUS_OK, 'data' => $data, 'errors' => []]);
                }
            }

            $data['state'] = $state !== '' ? $state : ($data['state'] ?? 'queued');
            $data['pages_crawled'] = isset($status['total_pages']) ? (int) $status['total_pages'] : null;
        } catch (SeRankingException $e) {
            if ($e->statusCode === 404) {
                return self::failed($envelope, $data, "SE Ranking audit {$auditId} no longer exists");
            }
            $data['last_error'] = $e->getMessage();
        }

        $created = strtotime((string) ($data['created_at'] ?? ''));
        if ($created !== false && $now - $created >= $this->giveUpHours * 3600) {
            return self::failed($envelope, $data, "SE Ranking audit {$auditId} not finished after {$this->giveUpHours} h");
        }

        return array_merge($envelope, ['status' => ProbeResult::STATUS_PENDING, 'data' => $data]);
    }

    /**
     * The report reduced to what is kept: score, totals and every check as a
     * flat list (passed ones included, so a rule can tell "passed" from "absent").
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public static function parseReport(array $report): array
    {
        $checks = [];
        foreach ((array) ($report['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ((array) ($section['props'] ?? []) as $code => $check) {
                if (!is_array($check)) {
                    continue;
                }
                $checks[] = [
                    'section'      => (string) ($section['uid'] ?? ''),
                    'section_name' => (string) ($section['name'] ?? ''),
                    'code'         => (string) ($check['code'] ?? $code),
                    'name'         => (string) ($check['name'] ?? $code),
                    'status'       => (string) ($check['status'] ?? ''),
                    'pages'        => (int) ($check['value'] ?? 0),
                ];
            }
        }

        $int = static fn (string $k): ?int => isset($report[$k]) && is_numeric($report[$k]) ? (int) $report[$k] : null;

        return [
            'finished_at' => isset($report['audit_time']) ? (string) $report['audit_time'] : null,
            'score'       => $int('score_percent'),
            'totals'      => [
                'pages'    => $int('total_pages'),
                'errors'   => $int('total_errors'),
                'warnings' => $int('total_warnings'),
                'notices'  => $int('total_notices'),
                'passed'   => $int('total_passed'),
            ],
            'domain_props'   => (array) ($report['domain_props'] ?? []),
            'engine_version' => isset($report['version']) ? (string) $report['version'] : null,
            'checks'         => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function failed(array $envelope, array $data, string $error): array
    {
        return array_merge($envelope, ['status' => ProbeResult::STATUS_ERROR, 'data' => $data, 'errors' => [$error]]);
    }
}
