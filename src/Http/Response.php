<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

/**
 * Every status line, header, cookie and body the admin UI emits goes through
 * here, so controllers can be exercised in tests without real SAPI output.
 */
class Response
{
    public function status(int $code): void
    {
        http_response_code($code);
    }

    public function header(string $line, bool $replace = true, int $code = 0): void
    {
        header($line, $replace, $code);
    }

    /** @param array<string, mixed> $options setcookie() options */
    public function cookie(string $name, string $value, array $options): void
    {
        setcookie($name, $value, $options);
    }

    public function write(string $body): void
    {
        echo $body;
    }

    public function redirect(string $to): void
    {
        $this->header('Location: ' . $to, true, 303);
    }

    public function text(int $code, string $body): void
    {
        $this->status($code);
        $this->header('Content-Type: text/plain; charset=utf-8');
        $this->write($body);
    }

    public function json(mixed $data, int $code = 200, int $flags = 0): void
    {
        if ($code !== 200) {
            $this->status($code);
        }
        $this->header('Content-Type: application/json; charset=utf-8');
        $this->header('X-Content-Type-Options: nosniff');
        $this->write((string) json_encode($data, $flags));
    }
}
