<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Support;

use RuntimeException;
use SatelliteWP\Xtractor\Support\ErrorLog;
use SatelliteWP\Xtractor\Tests\TestCase;

final class ErrorLogTest extends TestCase
{
    private ErrorLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new ErrorLog($this->tmpDir . '/logs');
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        $file = $this->log->file();
        self::assertFileExists($file);

        $lines = array_filter(explode("\n", (string) file_get_contents($file)));

        return array_map(static fn (string $l): array => (array) json_decode($l, true), array_values($lines));
    }

    public function testTheLogDirectoryIsCreatedOnFirstWrite(): void
    {
        self::assertDirectoryDoesNotExist($this->tmpDir . '/logs');

        $this->log->record('receptor', 'Storage failure');

        self::assertDirectoryExists($this->tmpDir . '/logs');
    }

    public function testEntriesGoIntoOneFilePerUtcDay(): void
    {
        $this->log->record('admin', 'boom');

        self::assertSame(
            $this->tmpDir . '/logs/error-' . gmdate('Y-m-d') . '.log',
            $this->log->file()
        );
    }

    public function testEachEntryIsOneJsonLine(): void
    {
        $this->log->record('receptor', 'first');
        $this->log->record('admin', 'second');

        $entries = $this->entries();

        self::assertCount(2, $entries);
        self::assertSame(['receptor', 'first'], [$entries[0]['source'], $entries[0]['message']]);
        self::assertSame(['admin', 'second'], [$entries[1]['source'], $entries[1]['message']]);
    }

    public function testTheReferenceIsReturnedAndStoredWithTheEntry(): void
    {
        $ref = $this->log->record('admin', 'boom');

        self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $ref);
        self::assertSame($ref, $this->entries()[0]['ref']);
    }

    public function testTwoEntriesGetDistinctReferences(): void
    {
        self::assertNotSame(
            $this->log->record('admin', 'boom'),
            $this->log->record('admin', 'boom')
        );
    }

    public function testAThrowableIsRecordedWithItsTypeOriginAndTrace(): void
    {
        $this->log->recordThrowable('receptor', new RuntimeException('disk full'));

        $entry = $this->entries()[0];

        self::assertSame('disk full', $entry['message']);
        self::assertSame(RuntimeException::class, $entry['exception']['type']);
        self::assertSame(__FILE__, $entry['exception']['file']);
        self::assertNotEmpty($entry['exception']['trace']);
    }

    /** The root cause of a wrapped exception is usually the interesting one. */
    public function testThePreviousExceptionChainIsFollowed(): void
    {
        $this->log->recordThrowable(
            'receptor',
            new RuntimeException('storing failed', 0, new RuntimeException('database is locked'))
        );

        self::assertSame(
            'database is locked',
            $this->entries()[0]['exception']['previous']['message']
        );
    }

    public function testContextIsRecordedAlongsideTheMessage(): void
    {
        $this->log->record('receptor', 'boom', ['site_id' => 'abc', 'payload' => 'extraction']);

        self::assertSame(
            ['site_id' => 'abc', 'payload' => 'extraction'],
            $this->entries()[0]['context']
        );
    }

    /** A logger that takes the request down with it is worse than no logger. */
    public function testAnUnwritableDirectoryDoesNotThrow(): void
    {
        touch($this->tmpDir . '/blocked');          // a file where the dir should be
        $log = new ErrorLog($this->tmpDir . '/blocked');

        // The fallback is error_log(); keep it out of the suite's output.
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $this->tmpDir . '/php-error.log');

        try {
            self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $log->record('admin', 'boom'));
            self::assertStringContainsString('cannot create log directory', (string) file_get_contents($this->tmpDir . '/php-error.log'));
        } finally {
            ini_set('error_log', $previous);
        }
    }

    /** Drives the shutdown handler's "has anything logged this 500 already?". */
    public function testEntriesWrittenCountsEveryEntry(): void
    {
        $before = ErrorLog::entriesWritten();

        $this->log->record('admin', 'boom');
        $this->log->recordThrowable('admin', new RuntimeException('boom'));

        self::assertSame($before + 2, ErrorLog::entriesWritten());
    }

    /** A very long message must not turn one entry into the whole log file. */
    public function testALongMessageIsTruncated(): void
    {
        $this->log->record('admin', str_repeat('x', 5000));

        self::assertLessThan(2100, strlen((string) $this->entries()[0]['message']));
    }
}
