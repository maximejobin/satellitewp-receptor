<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Http;

use SatelliteWP\Xtractor\Support\ErrorLog;
use Throwable;

/**
 * Turns anything that would produce a blank HTTP 500 into a logged entry plus
 * a short answer carrying its reference id.
 *
 * Three ways a 500 happens, all covered:
 *   1. an uncaught throwable          -> set_exception_handler
 *   2. a fatal error (OOM, type error in a template, missing file)
 *                                     -> register_shutdown_function
 *   3. code that just sets the status -> the same shutdown pass, which logs any
 *      5xx response that nothing else has recorded (the receptor's own storage
 *      failure logs itself, and is not counted twice).
 *
 * Installed by each front controller right after the autoloader, before the
 * application boots, so a broken config.local.php is logged like anything else.
 */
final class ErrorHandler
{
    private bool $handled = false;

    /**
     * @param string $source which front controller — 'receptor' or 'admin'
     * @param bool   $json   answer in JSON (the receptor) rather than plain text
     */
    public function __construct(
        private readonly ErrorLog $log,
        private readonly string $source,
        private readonly bool $json = false,
    ) {
    }

    /** Build one and hook it into PHP. Separate so the handlers stay testable. */
    public static function install(ErrorLog $log, string $source, bool $json = false): self
    {
        $handler = new self($log, $source, $json);

        set_exception_handler($handler->onException(...));
        register_shutdown_function($handler->onShutdown(...));

        return $handler;
    }

    public function onException(Throwable $e): void
    {
        $this->handled = true;

        $this->respond($this->log->recordThrowable($this->source, $e));
    }

    public function onShutdown(): void
    {
        $fatal = error_get_last();

        if (!$this->handled && $fatal !== null && self::isFatal($fatal['type'])) {
            $this->handled = true;

            $this->respond($this->log->record($this->source, $fatal['message'], [
                'error_type' => $fatal['type'],
                'file'       => $fatal['file'],
                'line'       => $fatal['line'],
            ]));

            return;
        }

        // Nothing crashed, but the response is a 5xx and no entry was written
        // for it: some code set the status by hand. The promise is that every
        // 500 is in logs/, so record it.
        $status = http_response_code();

        if (!$this->handled && is_int($status) && $status >= 500 && ErrorLog::entriesWritten() === 0) {
            $this->log->record($this->source, 'Request finished with HTTP ' . $status);
        }
    }

    /** Error types that end the request; anything else is a warning or a notice. */
    public static function isFatal(int $type): bool
    {
        return (bool) ($type & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR));
    }

    /**
     * A deliberately blank answer: the reference id and nothing else. Whoever
     * hit this reads it back to us, and the detail stays in logs/.
     */
    private function respond(string $ref): void
    {
        if (headers_sent()) {
            // A half-rendered page. The status is already out; leave the
            // reference where whoever is looking at the page can quote it.
            if (!$this->json) {
                echo "\n<!-- internal error, ref {$ref} -->\n";
            }

            return;
        }

        http_response_code(500);
        header('X-Content-Type-Options: nosniff');

        if ($this->json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['status' => 'error', 'message' => 'Internal error', 'ref' => $ref],
                JSON_UNESCAPED_SLASHES
            );

            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo "Internal error (ref {$ref})\n";
    }
}
