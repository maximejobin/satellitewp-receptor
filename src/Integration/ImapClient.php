<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Integration;

/**
 * The read-only slice of IMAP the mail probe needs: LOGIN, EXAMINE, UID SEARCH
 * and UID FETCH of header fields. PHP 8.4 ships no imap extension.
 *
 * Works on an injected stream so tests can feed canned server output.
 */
final class ImapClient
{
    private int $tagCounter = 0;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        $greeting = fgets($this->stream);
        if ($greeting === false || !str_starts_with($greeting, '* OK')) {
            throw new ImapException('The IMAP server did not greet us');
        }
    }

    public static function connect(string $host, int $port, int $timeout): self
    {
        $stream = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if ($stream === false) {
            throw new ImapException("Cannot reach {$host}:{$port} ({$error})");
        }
        stream_set_timeout($stream, $timeout);

        return new self($stream);
    }

    public function login(string $username, string $password): void
    {
        $this->command('LOGIN ' . self::quote($username) . ' ' . self::quote($password), 'IMAP login refused');
    }

    /**
     * The mailbox the server flags as spam (RFC 6154 \Junk), in the server's own
     * encoding, ready for examine(). Gmail localizes the folder's name.
     */
    public function junkMailbox(): ?string
    {
        foreach ($this->command('LIST "" "*"', 'IMAP list failed') as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) "((?:[^"\\\\]|\\\\.)*)"\r?$/i', $line, $m) === 1
                && preg_match('/\\\\Junk\b/i', $m[1]) === 1) {
                return stripcslashes($m[2]);
            }
        }

        return null;
    }

    /** Read-only select: nothing is ever marked read, moved or deleted. */
    public function examine(string $mailbox): void
    {
        $this->command('EXAMINE ' . self::quote($mailbox), "Cannot open the mailbox \"{$mailbox}\"");
    }

    /**
     * @param string $criteria raw IMAP search keys, already quoted
     * @return list<int> matching UIDs, ascending
     */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria, 'IMAP search failed') as $line) {
            if (preg_match('/^\* SEARCH ?([\d ]*)\r?$/', $line, $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $uid) {
                    $uids[] = (int) $uid;
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /**
     * @param list<int>    $uids
     * @param list<string> $headers header names to fetch
     * @return list<array{uid: int, internal_date: string, headers: string}>
     */
    public function uidFetchHeaders(array $uids, array $headers): array
    {
        if ($uids === []) {
            return [];
        }
        $fields = implode(' ', $headers);
        $lines  = $this->command(
            'UID FETCH ' . implode(',', $uids) . " (UID INTERNALDATE BODY.PEEK[HEADER.FIELDS ({$fields})])",
            'IMAP fetch failed'
        );

        $messages = [];
        foreach ($lines as $entry) {
            if (preg_match('/^\* \d+ FETCH /', $entry) !== 1) {
                continue;
            }
            if (
                preg_match('/\bUID (\d+)/', $entry, $uid) !== 1
                || preg_match('/INTERNALDATE "([^"]+)"/', $entry, $date) !== 1
                || preg_match('/\{(\d+)\}\r\n/', $entry, $literal, PREG_OFFSET_CAPTURE) !== 1
            ) {
                continue;
            }
            $start      = $literal[0][1] + strlen($literal[0][0]);
            $messages[] = [
                'uid'           => (int) $uid[1],
                'internal_date' => $date[1],
                'headers'       => substr($entry, $start, (int) $literal[1][0]),
            ];
        }

        return $messages;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT', 'IMAP logout failed');
        } catch (ImapException) {
            // The connection is dropped either way.
        }
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** A quoted string; CR/LF cannot be escaped in one, so they are refused. */
    private static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new ImapException('Control characters in an IMAP argument');
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    /**
     * Sends one tagged command and returns the untagged responses, each with
     * its literals (e.g. header blocks) inlined after their "{n}" marker.
     *
     * @return list<string>
     */
    private function command(string $command, string $failure): array
    {
        $tag = 'A' . ++$this->tagCounter;
        if (fwrite($this->stream, "{$tag} {$command}\r\n") === false) {
            throw new ImapException($failure . ': connection lost');
        }

        $untagged = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, "{$tag} ")) {
                if (!str_starts_with($line, "{$tag} OK")) {
                    throw new ImapException($failure . ': ' . trim(substr($line, strlen($tag) + 1)));
                }

                return $untagged;
            }

            $entry = $line;
            while (preg_match('/\{(\d+)\}\r\n$/', $entry, $m) === 1) {
                $entry .= $this->readBytes((int) $m[1]) . $this->readLine();
            }
            $untagged[] = $entry;
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new ImapException('IMAP connection closed or timed out');
        }

        return $line;
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new ImapException('IMAP connection closed or timed out');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
