<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Integration\SeRankingClient;
use SatelliteWP\Manager\Probe\SeRankingProbe;

final class SeRankingProbeTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @var list<Request> */
    private array $sent = [];

    /** @param list<Response|ConnectException> $responses */
    private function probe(array $responses, bool $configured = true): SeRankingProbe
    {
        $this->sent = [];
        $stack      = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $handler) => function (Request $request, array $options) use ($handler) {
            $this->sent[] = $request;

            return $handler($request, $options);
        });

        $client = new SeRankingClient(new Client(['handler' => $stack, 'http_errors' => false]), 'https://api.seranking.test/v1/project-management', 'secret-key');

        return new SeRankingProbe($configured ? $client : null, ['max_req' => 5], 5, 24);
    }

    private static function json(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private static function site(): SiteContext
    {
        return SiteContext::fromExtractionPayload('site-1', [
            'site_url' => 'https://www.example.com',
            'home_url' => 'https://www.example.com',
        ]);
    }

    /** @return array<string, mixed> */
    private static function pending(string $createdAt = '2027-01-15T08:00:00Z', ?string $polledAt = null): array
    {
        return [
            'probe'  => 'seranking',
            'ran_at' => $createdAt,
            'status' => ProbeResult::STATUS_PENDING,
            'data'   => ['audit_id' => 42, 'state' => 'queued', 'created_at' => $createdAt, 'polled_at' => $polledAt],
            'errors' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function report(): array
    {
        return [
            'is_finished'    => true,
            'score_percent'  => 80,
            'total_pages'    => 120,
            'total_errors'   => 3,
            'total_warnings' => 7,
            'total_notices'  => 9,
            'total_passed'   => 77,
            'audit_time'     => '2027-01-15 08:19:33',
            'domain_props'   => ['dt' => 44, 'domain' => 'www.example.com'],
            'sections'       => [
                ['uid' => 'security_v2', 'name' => 'Security', 'props' => [
                    'no_https' => ['code' => 'no_https', 'status' => 'error', 'name' => 'No HTTPS encryption', 'value' => 0],
                ]],
                ['uid' => 'crawling_v2', 'name' => 'Crawling & Indexing', 'props' => [
                    'http4xx' => ['code' => 'http4xx', 'status' => 'error', 'name' => '4XX HTTP Status Codes', 'value' => 3],
                ]],
            ],
            'version' => '2.0',
        ];
    }

    public function testRunCreatesTheAuditAndStaysPending(): void
    {
        $result = $this->probe([self::json(['id' => 42])])->run(self::site());

        $this->assertSame(ProbeResult::STATUS_PENDING, $result->status);
        $this->assertSame(42, $result->data['audit_id']);
        $this->assertSame('queued', $result->data['state']);

        $request = $this->sent[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.seranking.test/v1/project-management/audits', (string) $request->getUri());
        $this->assertSame('Token secret-key', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('www.example.com', $body['domain']);
        $this->assertSame(['max_req' => 5], $body['settings']);
    }

    public function testRunWithoutApiKeyIsAConfigurationError(): void
    {
        $result = $this->probe([], configured: false)->run(self::site());

        $this->assertSame(ProbeResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('seranking.api_key', $result->errors[0]);
    }

    public function testCreationFailureIsAnErrorWithoutTheKey(): void
    {
        $result = $this->probe([self::json(['message' => 'Invalid token secret-key'], 401)])->run(self::site());

        $this->assertSame(ProbeResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('HTTP 401', $result->errors[0]);
        $this->assertStringNotContainsString('secret-key', $result->errors[0]);
    }

    public function testPollKeepsAnUnfinishedAuditPendingWithItsProgress(): void
    {
        $envelope = $this->probe([self::json(['status' => 'processing', 'total_pages' => 37])])
            ->poll(self::pending(), self::NOW);

        $this->assertSame(ProbeResult::STATUS_PENDING, $envelope['status']);
        $this->assertSame('processing', $envelope['data']['state']);
        $this->assertSame(37, $envelope['data']['pages_crawled']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', self::NOW), $envelope['data']['polled_at']);
        $this->assertSame('https://api.seranking.test/v1/project-management/audits/status?audit_id=42', (string) $this->sent[0]->getUri());
    }

    public function testPollStoresTheReportOnceFinished(): void
    {
        $envelope = $this->probe([self::json(['status' => 'finished']), self::json(self::report())])
            ->poll(self::pending(), self::NOW);

        $this->assertSame(ProbeResult::STATUS_OK, $envelope['status']);
        $this->assertSame('2027-01-15T08:00:00Z', $envelope['ran_at']);
        $data = $envelope['data'];
        $this->assertSame('finished', $data['state']);
        $this->assertSame(80, $data['score']);
        $this->assertSame(['pages' => 120, 'errors' => 3, 'warnings' => 7, 'notices' => 9, 'passed' => 77], $data['totals']);
        $this->assertSame('2027-01-15 08:19:33', $data['finished_at']);
        $this->assertCount(2, $data['checks']);
        $this->assertSame(
            ['section' => 'crawling_v2', 'section_name' => 'Crawling & Indexing', 'code' => 'http4xx', 'name' => '4XX HTTP Status Codes', 'status' => 'error', 'pages' => 3],
            $data['checks'][1]
        );
        $this->assertStringEndsWith('/audits/report?audit_id=42', (string) $this->sent[1]->getUri());
    }

    public function testPollWaitsWhenTheReportIsNotFinishedYet(): void
    {
        $envelope = $this->probe([self::json(['status' => 'finished']), self::json(['is_finished' => false])])
            ->poll(self::pending(), self::NOW);

        $this->assertSame(ProbeResult::STATUS_PENDING, $envelope['status']);
    }

    public function testCancelledOrDeletedAuditsBecomeErrors(): void
    {
        $cancelled = $this->probe([self::json(['status' => 'cancelled'])])->poll(self::pending(), self::NOW);
        $this->assertSame(ProbeResult::STATUS_ERROR, $cancelled['status']);
        $this->assertStringContainsString('"cancelled"', $cancelled['errors'][0]);

        $gone = $this->probe([self::json([], 404)])->poll(self::pending(), self::NOW);
        $this->assertSame(ProbeResult::STATUS_ERROR, $gone['status']);
        $this->assertStringContainsString('no longer exists', $gone['errors'][0]);
    }

    public function testTransientFailureStaysPendingUntilTheGiveUpDelay(): void
    {
        $down = new ConnectException('Connection refused', new Request('GET', 'https://api.seranking.test'));

        $recent = $this->probe([$down])->poll(self::pending(gmdate('Y-m-d\TH:i:s\Z', self::NOW - 3600)), self::NOW);
        $this->assertSame(ProbeResult::STATUS_PENDING, $recent['status']);
        $this->assertStringContainsString('transport error', $recent['data']['last_error']);

        $stale = $this->probe([self::json(['status' => 'processing'])])->poll(self::pending(gmdate('Y-m-d\TH:i:s\Z', self::NOW - 25 * 3600)), self::NOW);
        $this->assertSame(ProbeResult::STATUS_ERROR, $stale['status']);
        $this->assertStringContainsString('not finished after 24 h', $stale['errors'][0]);
    }

    public function testIsDueHonoursThePollInterval(): void
    {
        $probe = $this->probe([]);

        $this->assertTrue($probe->isDue(self::pending(), self::NOW));
        $this->assertFalse($probe->isDue(self::pending(polledAt: gmdate('Y-m-d\TH:i:s\Z', self::NOW - 240)), self::NOW));
        $this->assertTrue($probe->isDue(self::pending(polledAt: gmdate('Y-m-d\TH:i:s\Z', self::NOW - 300)), self::NOW));
    }
}
