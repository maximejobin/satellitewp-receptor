<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SatelliteWP\Xtractor\Http\ErrorHandler;
use SatelliteWP\Xtractor\Support\ErrorLog;
use SatelliteWP\Xtractor\Tests\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private ErrorLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new ErrorLog($this->tmpDir . '/logs');
    }

    /** @return array<string, mixed> the single entry written to the log */
    private function onlyEntry(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->log->file())));
        self::assertCount(1, $lines);

        return (array) json_decode((string) reset($lines), true);
    }

    public function testAnUncaughtThrowableIsLoggedAndAnsweredWithItsReference(): void
    {
        $handler = new ErrorHandler($this->log, 'admin');

        ob_start();
        $handler->onException(new RuntimeException('template blew up'));
        $output = (string) ob_get_clean();

        $entry = $this->onlyEntry();

        self::assertSame('template blew up', $entry['message']);
        self::assertSame('admin', $entry['source']);
        self::assertStringContainsString($entry['ref'], $output);
    }

    /** The receptor speaks JSON to the plugin, never HTML or a bare line. */
    public function testTheReceptorAnswersAnUncaughtThrowableInJson(): void
    {
        $handler = new ErrorHandler($this->log, 'receptor', json: true);

        ob_start();
        $handler->onException(new RuntimeException('boom'));
        $body = (array) json_decode((string) ob_get_clean(), true);

        self::assertSame('error', $body['status']);
        self::assertSame($this->onlyEntry()['ref'], $body['ref']);
    }

    /** The message stays in logs/; the client only ever gets the reference. */
    public function testTheAnswerDoesNotLeakTheExceptionMessage(): void
    {
        $handler = new ErrorHandler($this->log, 'admin');

        ob_start();
        $handler->onException(new RuntimeException('SQLSTATE[HY000] /var/www/data/index.sqlite'));
        $output = (string) ob_get_clean();

        self::assertStringNotContainsString('SQLSTATE', $output);
        self::assertStringNotContainsString('/var/www', $output);
    }

    #[DataProvider('errorTypes')]
    public function testOnlyRequestEndingErrorTypesCountAsFatal(int $type, bool $expected): void
    {
        self::assertSame($expected, ErrorHandler::isFatal($type));
    }

    /** @return array<string, array{int, bool}> */
    public static function errorTypes(): array
    {
        return [
            'fatal error'   => [E_ERROR, true],
            'parse error'   => [E_PARSE, true],
            'compile error' => [E_COMPILE_ERROR, true],
            'user error'    => [E_USER_ERROR, true],
            'warning'       => [E_WARNING, false],
            'notice'        => [E_NOTICE, false],
            'deprecation'   => [E_DEPRECATED, false],
        ];
    }
}
