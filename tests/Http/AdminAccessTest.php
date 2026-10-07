<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use SatelliteWP\Manager\App;
use SatelliteWP\Manager\Config;
use SatelliteWP\Manager\Http\Router;
use SatelliteWP\Manager\Http\Session;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    private const string SITE = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';
    private const string CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = $_POST = $_COOKIE = $_SESSION = [];
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_COOKIE = $_SESSION = [];
        unset($_SERVER['HTTPS']);
        parent::tearDown();
    }

    #[Test]
    public function withNoSignInConfiguredTheUiIsClosed(): void
    {
        $response = $this->get($this->app(), '/catalog');

        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('open_mode', $response->body);
    }

    #[Test]
    public function postsAreClosedTooWithoutOpenMode(): void
    {
        $_COOKIE = [Session::CSRF_COOKIE_PLAIN_HTTP => self::CSRF];
        $_POST   = ['_csrf' => self::CSRF, 'type' => 'plugin', 'slug' => 'example', 'license' => 'free'];

        $response = new RecordingResponse();
        (new Router($this->app(), $response))->handlePost('/catalog');

        $this->assertSame(403, $response->status);
    }

    #[Test]
    public function explicitOpenModeServesTheUi(): void
    {
        $response = $this->get($this->app(['auth' => ['open_mode' => true]]), '/catalog');

        $this->assertSame(200, $response->status);
    }

    #[Test]
    public function openModeIsIgnoredOnceBasicAuthIsConfigured(): void
    {
        $app = $this->app([
            'auth' => ['open_mode' => true],
            'web'  => ['user' => 'admin', 'pass_hash' => password_hash('secret', PASSWORD_DEFAULT)],
        ]);

        $this->assertSame(401, $this->get($app, '/catalog')->status);

        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW']   = 'secret';
        $this->assertSame(200, $this->get($app, '/catalog')->status);
    }

    #[Test]
    public function theCsrfCookieIsHostPrefixedOverHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $response = $this->get($this->app(['auth' => ['open_mode' => true]]), '/catalog');

        $this->assertArrayHasKey('__Host-swp_csrf', $response->cookies);
        $this->assertArrayNotHasKey('swp_csrf', $response->cookies);
    }

    #[Test]
    public function plainHttpDevKeepsTheUnprefixedCookie(): void
    {
        $response = $this->get($this->app(['auth' => ['open_mode' => true]]), '/catalog');

        $this->assertArrayHasKey('swp_csrf', $response->cookies);
    }

    #[Test]
    public function overHttpsOnlyTheHostPrefixedCookieValidatesAPost(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $app              = $this->app(['auth' => ['open_mode' => true]]);
        $_POST            = ['_csrf' => self::CSRF];

        $_COOKIE  = ['swp_csrf' => self::CSRF];
        $response = new RecordingResponse();
        (new Router($app, $response))->handlePost('/catalog');
        $this->assertSame(400, $response->status);

        $_COOKIE  = ['__Host-swp_csrf' => self::CSRF];
        $response = new RecordingResponse();
        (new Router($app, $response))->handlePost('/catalog');
        // Past the CSRF gate: the catalogue itself rejects the empty licence.
        $this->assertStringNotContainsString('Invalid CSRF token', $response->body);
        $this->assertStringContainsString('Could not save the licence', $response->body);
    }

    #[Test]
    public function reportJsonIsRefusedUntilTheAnalysisIsDone(): void
    {
        $app          = $this->app();
        $extractionId = $this->storeExtraction($app, Index::STATUS_PENDING);
        $_GET         = ['token' => $app->reportTokenStore()->issue(self::SITE, $extractionId)];

        $response = $this->get($app, '/site/' . self::SITE . '/extraction/' . $extractionId . '/report.json');

        $this->assertSame(409, $response->status);
        $this->assertSame(['error' => 'The analysis of this extraction is not done'], json_decode($response->body, true));
    }

    #[Test]
    public function aReportTokenIsOnlyMintedForADoneExtraction(): void
    {
        $app          = $this->app(['auth' => ['open_mode' => true]]);
        $extractionId = $this->storeExtraction($app, Index::STATUS_QUEUED);

        $this->assertSame(409, $this->post($app, '/site/' . self::SITE . '/extraction/' . $extractionId . '/report-token')->status);
        $this->assertSame(404, $this->post($app, '/site/' . self::SITE . '/extraction/20260101T000000Z/report-token')->status);
    }

    #[Test]
    public function anObservationWithoutATitleIsRefusedWithAReason(): void
    {
        $app          = $this->app(['auth' => ['open_mode' => true]]);
        $extractionId = $this->storeExtraction($app, Index::STATUS_DONE);

        $response = $this->post($app, '/site/' . self::SITE . '/extraction/' . $extractionId . '/observations', [
            'action'  => 'add',
            'section' => 'not_a_section',
            'color'   => 'blue',
            'title'   => '   ',
        ]);

        $this->assertSame(303, $response->status);
        $this->assertSame([], (array) ($app->dataStore()->readObservations(self::SITE, $extractionId)['items'] ?? []));
        $errors = $_SESSION['observation_errors']['errors'] ?? [];
        $this->assertContains('empty title', $errors);
        $this->assertContains('unknown section "not_a_section"', $errors);
    }

    #[Test]
    public function anUnsafeReturnFallsBackToTheExtractionPage(): void
    {
        $app          = $this->app(['auth' => ['open_mode' => true]]);
        $extractionId = $this->storeExtraction($app, Index::STATUS_PENDING);

        $response = $this->post($app, '/site/' . self::SITE . '/extraction/' . $extractionId . '/abort', ['return' => '//example.com/']);

        $this->assertSame('/site/' . self::SITE . '/extraction/' . $extractionId, $response->location());
    }

    /** @param array<string, mixed> $fields */
    private function post(App $app, string $path, array $fields = []): RecordingResponse
    {
        $_COOKIE  = [Session::CSRF_COOKIE_PLAIN_HTTP => self::CSRF];
        $_POST    = ['_csrf' => self::CSRF] + $fields;
        $response = new RecordingResponse();

        ob_start();
        (new Router($app, $response))->handlePost($path);
        ob_end_clean();

        return $response;
    }

    private function get(App $app, string $path): RecordingResponse
    {
        $response = new RecordingResponse();

        ob_start();
        (new Router($app, $response))->dispatch($path);
        $response->body .= (string) ob_get_clean();

        return $response;
    }

    private function storeExtraction(App $app, string $status): string
    {
        $extractionId = $app->dataStore()->storeExtraction(self::SITE, $this->fixture('extraction-valid.json'), [
            'received_at' => '2026-07-22T14:30:00Z',
        ]);
        $app->index()->insertExtraction(self::SITE, $extractionId, '2026-07-22T14:30:00Z', [], $status);

        return $extractionId;
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        $defaults = require dirname(__DIR__, 2) . '/config/config.php';

        return new App(new Config(array_replace_recursive($defaults, [
            'data_dir' => $this->tmpDir,
            'auth'     => ['users_file' => $this->tmpDir . '/users.json'],
        ], $overrides)));
    }
}
