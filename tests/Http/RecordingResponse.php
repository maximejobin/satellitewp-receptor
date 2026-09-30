<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Http;

use SatelliteWP\Xtractor\Http\Response;

/** Captures what a controller would have sent, instead of emitting it. */
final class RecordingResponse extends Response
{
    public int $status = 200;

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, string> */
    public array $cookies = [];

    public string $body = '';

    public function status(int $code): void
    {
        $this->status = $code;
    }

    public function header(string $line, bool $replace = true, int $code = 0): void
    {
        $this->headers[] = $line;
        if ($code !== 0) {
            $this->status = $code;
        }
    }

    public function cookie(string $name, string $value, array $options): void
    {
        $this->cookies[$name] = $value;
    }

    public function write(string $body): void
    {
        $this->body .= $body;
    }

    public function location(): ?string
    {
        foreach ($this->headers as $line) {
            if (str_starts_with($line, 'Location: ')) {
                return substr($line, 10);
            }
        }

        return null;
    }
}
