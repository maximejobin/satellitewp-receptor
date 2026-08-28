<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Support;

use Throwable;

/**
 * Append-only log of every HTTP 500 the two front controllers produce.
 *
 * One JSON Lines file per UTC day under logs/ (`logs/error-2026-08-28.log`), so
 * a failure can be found with grep and old days deleted by hand or by logrotate.
 * The entries are structured for reading, not for a log pipeline: this is a
 * ~10-person shop, not a fleet.
 *
 * Every entry carries a short `ref` which is also returned to the client, so a
 * "500, ref 9f3a1c02" from a site owner points straight at one line here.
 *
 * Deliberately never throws: a logger that takes the request down with it is
 * worse than no logger. A write that fails falls back to error_log().
 *
 * Nothing secret is recorded — no request body, no headers beyond the site id,
 * no cookies. A payload that failed to store is already on disk or lost; what
 * is needed here is why.
 */
final class ErrorLog
{
    /** Stack frames kept per entry — enough to place the failure, not a novel. */
    private const int TRACE_FRAMES = 20;

    /** Longest message kept, in bytes. Guzzle can raise very long ones. */
    private const int MAX_MESSAGE = 2000;

    /** Entries written by this process, across instances (see entriesWritten). */
    private static int $written = 0;

    public function __construct(private readonly string $dir)
    {
    }

    /** logs/ at the project root — the one place the front controllers agree on. */
    public static function defaultDir(): string
    {
        return dirname(__DIR__, 2) . '/logs';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Today's file. Rotated by UTC date, matching the timestamps inside it. */
    public function file(): string
    {
        return $this->dir . '/error-' . gmdate('Y-m-d') . '.log';
    }

    /**
     * How many entries this process has written. The shutdown handler uses it
     * to tell "nobody logged this 500" from "already logged", so a failure that
     * reported itself is not recorded twice.
     */
    public static function entriesWritten(): int
    {
        return self::$written;
    }

    /**
     * @param  array<string, mixed> $context
     * @return string the reference id of the entry
     */
    public function record(string $source, string $message, array $context = []): string
    {
        return $this->write($source, $message, null, $context);
    }

    /**
     * @param  array<string, mixed> $context
     * @return string the reference id of the entry
     */
    public function recordThrowable(string $source, Throwable $e, array $context = []): string
    {
        return $this->write($source, $e->getMessage(), $e, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $source, string $message, ?Throwable $e, array $context): string
    {
        $ref = self::reference();

        $entry = [
            'time'    => gmdate('Y-m-d\TH:i:s\Z'),
            'ref'     => $ref,
            'source'  => $source,
            'message' => self::truncate($message),
        ];

        if ($e !== null) {
            $entry['exception'] = self::describe($e);
        }
        if ($context !== []) {
            $entry['context'] = $context;
        }

        $request = self::request();
        if ($request !== []) {
            $entry['request'] = $request;
        }

        $this->append($entry);

        return $ref;
    }

    /** @param array<string, mixed> $entry */
    private function append(array $entry): void
    {
        self::$written++;

        $line = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($line === false) {
            $line = json_encode(['time' => $entry['time'], 'ref' => $entry['ref'], 'message' => 'unencodable entry']);
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            error_log('[xtractor] cannot create log directory ' . $this->dir . ' — ' . $line);

            return;
        }

        if (@file_put_contents($this->file(), $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[xtractor] cannot write ' . $this->file() . ' — ' . $line);
        }
    }

    /**
     * Type, origin and trace of a throwable, following the `previous` chain
     * (the root cause of a wrapped exception is usually the interesting one).
     *
     * @return array<string, mixed>
     */
    private static function describe(Throwable $e, int $depth = 0): array
    {
        $described = [
            'type'    => $e::class,
            'message' => self::truncate($e->getMessage()),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, self::TRACE_FRAMES),
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < 3) {
            $described['previous'] = self::describe($previous, $depth + 1);
        }

        return $described;
    }

    /**
     * What the request was, from $_SERVER only. The site id is the plugin's
     * X-SWP-Site header — an opaque uuid, and the one thing that ties a
     * receptor failure to a site; the signature and body are never touched.
     *
     * @return array<string, string>
     */
    private static function request(): array
    {
        $fields = [
            'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri'        => $_SERVER['REQUEST_URI'] ?? null,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'site'       => $_SERVER['HTTP_X_SWP_SITE'] ?? null,
            'type'       => $_SERVER['HTTP_X_SWP_TYPE'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        return array_map(
            static fn (mixed $v): string => self::truncate((string) $v),
            array_filter($fields, static fn (mixed $v): bool => is_string($v) && $v !== '')
        );
    }

    private static function truncate(string $value, int $max = self::MAX_MESSAGE): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }

    /** Short, human-quotable, unique enough for a log a human reads. */
    private static function reference(): string
    {
        return bin2hex(random_bytes(4));
    }
}
