<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Config;
use SatelliteWP\Manager\Http\Controller\ReportController;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Tests\TestCase;

final class ReportScriptRouteTest extends TestCase
{
    private const string SITE = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string EID  = '20260722T143000Z';

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testTheEngineIsServedWithItsVersionToTheExtractionsOwnToken(): void
    {
        $app      = $this->app();
        $_GET     = ['token' => $app->reportTokenStore()->issue(self::SITE, self::EID)];
        $response = $this->get($app, self::EID);

        $this->assertSame(200, $response->status);
        $body = json_decode($response->body, true);
        $this->assertSame(ReportController::engineScript(dirname(__DIR__, 2) . '/' . ReportController::ENGINE_FILE), $body);
        $this->assertIsInt($body['version']);
        $this->assertStringContainsString('return { version: VERSION', $body['code']);
    }

    public function testATokenForAnotherExtractionOrNoTokenIsRefused(): void
    {
        $app = $this->app();

        $_GET = ['token' => $app->reportTokenStore()->issue(self::SITE, '20260101T000000Z')];
        $this->assertSame(401, $this->get($app, self::EID)->status);

        $_GET = [];
        $this->assertSame(401, $this->get($app, self::EID)->status);
    }

    public function testEngineVersionIsReadFromItsOwnVersionLine(): void
    {
        $file = $this->tmpDir . '/engine.gs';

        file_put_contents($file, "// x\nvar VERSION = 42;\nreturn {};\n");
        $this->assertSame(42, ReportController::engineScript($file)['version'] ?? null);

        file_put_contents($file, "var VERSIONS = 42;\n");
        $this->assertNull(ReportController::engineScript($file));
        $this->assertNull(ReportController::engineScript($this->tmpDir . '/missing.gs'));
    }

    public function testTheShippedLoaderAsksForTheEngineNextToTheData(): void
    {
        $loader = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/apps-script/loader.gs');

        $this->assertStringContainsString("'/report.json?', '/report-script.json?'", $loader);
        $this->assertStringContainsString("var TRUSTED_DOMAIN = 'satellitewp.com';", $loader);
    }

    private function get(App $app, string $extractionId): RecordingResponse
    {
        $response = new RecordingResponse();
        (new Router($app, $response))->dispatch('/site/' . self::SITE . '/extraction/' . $extractionId . '/report-script.json');

        return $response;
    }

    private function app(): App
    {
        $defaults = require dirname(__DIR__, 2) . '/config/config.php';

        return new App(new Config(array_replace_recursive($defaults, [
            'data_dir' => $this->tmpDir,
            'auth'     => ['users_file' => $this->tmpDir . '/users.json'],
        ])));
    }
}
