<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Config;
use SatelliteWP\Manager\Http\Controller\ExtractionController;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Tests\TestCase;

final class ExtractionRerunRouteTest extends TestCase
{
    private const string SITE  = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string CSRF  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $extractionId;

    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE  = ['swp_csrf' => self::CSRF];
        $_POST    = ['_csrf' => self::CSRF];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $_POST = $_SESSION = [];
        parent::tearDown();
    }

    public function testRerunQueuesTheSelectedProbesAndKeepsTheExtractionDone(): void
    {
        $app      = $this->app([]);
        $response = $this->post($app, ['probes' => ['http', 'not-a-probe']]);

        $this->assertSame(303, $response->status);
        $this->assertSame([['site_id' => self::SITE, 'extraction_id' => $this->extractionId, 'probes' => ['http']]], $app->index()->queuedProbeRuns());
        $this->assertSame(Index::STATUS_DONE, $app->index()->getExtraction(self::SITE, $this->extractionId)['status']);

        $page = new RecordingResponse();
        ob_start();
        (new Router($app, $page))->dispatch('/site/' . self::SITE . '/extraction/' . $this->extractionId);
        $page->body .= (string) ob_get_clean();
        $this->assertStringContainsString('<span class="mono">http</span> <span class="badge badge-muted">Not started</span>', $page->body);
        $this->assertStringContainsString('id="rerun-dialog"', $page->body);
    }

    public function testProbeProgressReadsEachProbesRow(): void
    {
        $this->assertSame(
            ['dns' => 'not_started', 'http' => 'not_started', 'tls' => 'in_progress', 'seranking' => 'in_progress', 'mail' => 'failed', 'rdap' => 'completed', 'crm' => 'completed'],
            ExtractionController::probeProgress(['dns', 'http', 'tls', 'seranking', 'mail', 'rdap', 'crm'], [
                ['probe' => 'http', 'status' => 'queued'],
                ['probe' => 'tls', 'status' => 'running'],
                ['probe' => 'seranking', 'status' => 'pending'],
                ['probe' => 'mail', 'status' => 'error'],
                ['probe' => 'rdap', 'status' => 'ok'],
                ['probe' => 'crm', 'status' => 'warn'],
            ])
        );
    }

    public function testRerunIsForbiddenWithoutTheExtractionRunCapability(): void
    {
        $app = $this->app([
            'auth'            => ['google' => ['client_id' => 'id', 'client_secret' => 'secret']],
        ]);
        $app->userStore()->add('admin@example.com', 'admin');
        $app->userStore()->add('coordinator@example.com', 'coordinator');
        $_SESSION['user_email'] = 'coordinator@example.com';

        $response = $this->post($app, ['probes' => ['http']]);

        $this->assertSame(403, $response->status);
    }

    public function testRerunOfAnUnknownExtractionIsNotFound(): void
    {
        $app      = $this->app([]);
        $response = new RecordingResponse();

        (new Router($app, $response))->handlePost('/site/' . self::SITE . '/extraction/20260101T000000Z/rerun');

        $this->assertSame(404, $response->status);
    }

    public function testRerunWithNoKnownProbeSelectedRunsNothing(): void
    {
        $response = $this->post($this->app([]), ['probes' => ['not-a-probe']]);

        $this->assertSame(303, $response->status);
        $this->assertStringContainsString('notice=rerun-none', (string) $response->location());
    }

    public function testAPostWithoutAValidCsrfTokenIsRejectedBeforeRouting(): void
    {
        $_POST['_csrf'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $response = $this->post($this->app([]), ['probes' => ['http']]);

        $this->assertSame(400, $response->status);
    }

    /** @param array<string, mixed> $fields */
    private function post(App $app, array $fields): RecordingResponse
    {
        $_POST   += $fields;
        $response = new RecordingResponse();

        ob_start();
        (new Router($app, $response))->handlePost('/site/' . self::SITE . '/extraction/' . $this->extractionId . '/rerun');
        ob_end_clean();

        return $response;
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides): App
    {
        $defaults = require dirname(__DIR__, 2) . '/config/config.php';
        $app      = new App(new Config(array_replace_recursive($defaults, [
            'data_dir' => $this->tmpDir,
            'auth'     => ['users_file' => $this->tmpDir . '/users.json', 'open_mode' => true],
        ], $overrides)));

        $this->extractionId = $app->dataStore()->storeExtraction(self::SITE, $this->fixture('extraction-valid.json'), [
            'received_at' => '2026-07-22T14:30:00Z',
        ]);
        $app->index()->insertExtraction(self::SITE, $this->extractionId, '2026-07-22T14:30:00Z', [], Index::STATUS_DONE);

        return $app;
    }
}
