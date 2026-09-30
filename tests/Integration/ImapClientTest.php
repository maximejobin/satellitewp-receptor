<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Xtractor\Integration\ImapClient;
use SatelliteWP\Xtractor\Integration\ImapException;

final class ImapClientTest extends TestCase
{
    /** @return array{ImapClient, resource} */
    private function connect(string $script): array
    {
        [$clientEnd, $serverEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($serverEnd, $script);
        stream_set_timeout($clientEnd, 1);
        stream_set_blocking($serverEnd, false);

        return [new ImapClient($clientEnd), $serverEnd];
    }

    public function testCommandsAreTaggedAndArgumentsQuoted(): void
    {
        [$client, $server] = $this->connect("* OK ready\r\nA1 OK\r\nA2 OK\r\n* SEARCH 4 9\r\nA3 OK\r\n");

        $client->login('validator@example.com', 'pa"ss\\word');
        $client->examine('[Gmail]/Spam');
        $uids = $client->uidSearch('SINCE 29-Sep-2026 SUBJECT "abc"');

        self::assertSame([4, 9], $uids);
        self::assertSame(
            "A1 LOGIN \"validator@example.com\" \"pa\\\"ss\\\\word\"\r\n"
            . "A2 EXAMINE \"[Gmail]/Spam\"\r\n"
            . "A3 UID SEARCH SINCE 29-Sep-2026 SUBJECT \"abc\"\r\n",
            (string) stream_get_contents($server)
        );
    }

    public function testFetchedHeadersUseBodyPeekSoNothingIsMarkedRead(): void
    {
        $headers = "Subject: Hi\r\n\r\n";
        [$client, $server] = $this->connect(
            "* OK ready\r\n* 1 FETCH (UID 4 INTERNALDATE \"30-Sep-2026 09:00:00 +0000\" BODY[HEADER.FIELDS (SUBJECT)] {" . strlen($headers) . "}\r\n{$headers})\r\nA1 OK\r\n"
        );

        $messages = $client->uidFetchHeaders([4], ['SUBJECT']);

        self::assertSame([['uid' => 4, 'internal_date' => '30-Sep-2026 09:00:00 +0000', 'headers' => $headers]], $messages);
        self::assertStringContainsString('BODY.PEEK[HEADER.FIELDS (SUBJECT)]', (string) stream_get_contents($server));
    }

    public function testLineBreaksInACredentialAreRefused(): void
    {
        [$client] = $this->connect("* OK ready\r\n");

        $this->expectException(ImapException::class);
        $client->login("user\r\nA9 DELETE INBOX", 'x');
    }

    public function testAServerThatDoesNotGreetIsRejected(): void
    {
        $this->expectException(ImapException::class);
        $this->connect("HTTP/1.1 400 Bad Request\r\n");
    }
}
