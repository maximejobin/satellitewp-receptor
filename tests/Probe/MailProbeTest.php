<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Probe;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Integration\ImapClient;
use SatelliteWP\Manager\Probe\MailProbe;

final class MailProbeTest extends TestCase
{
    private const string SITE_ID = '3f2b8c1e-9d4a-4c7b-8e21-5a6d7f0b1c2d';

    private const string RESULTS = "mx.google.com;\r\n"
        . "       dkim=pass header.i=@example.com header.s=s1 header.b=abc;\r\n"
        . "       spf=pass (google.com: domain of wordpress@example.com designates 203.0.113.5 as permitted sender) smtp.mailfrom=wordpress@example.com;\r\n"
        . "       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=example.com";

    /** @var list<resource> server ends kept open for the test's lifetime */
    private array $serverEnds = [];

    public function testReferenceMirrorsThePluginsValidationHash(): void
    {
        self::assertSame(substr(hash('sha256', self::SITE_ID), 0, 16), MailProbe::reference(self::SITE_ID));
    }

    public function testAuthResultsReadsAllThreeVerdictsAndIgnoresComments(): void
    {
        self::assertSame(
            ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass', 'dkim_domains' => ['example.com']],
            MailProbe::authResults([self::RESULTS], 'mx.google.com')
        );
    }

    public function testAuthResultsReportsFailuresAsTheyAre(): void
    {
        $header = 'mx.google.com; dkim=none; spf=softfail (google.com: domain of transitioning x@example.com does not designate 1.2.3.4) smtp.mailfrom=x@example.com; dmarc=fail (p=REJECT) header.from=example.com';

        self::assertSame(
            ['spf' => 'softfail', 'dkim' => 'none', 'dmarc' => 'fail', 'dkim_domains' => []],
            MailProbe::authResults([$header], 'mx.google.com')
        );
    }

    public function testAHeaderWrittenByAnotherServerIsNeverTrusted(): void
    {
        $forged = 'relay.example.net; spf=pass; dkim=pass; dmarc=pass';

        self::assertSame(['spf' => null, 'dkim' => null, 'dmarc' => null, 'dkim_domains' => []], MailProbe::authResults([$forged], 'mx.google.com'));
        self::assertSame(
            ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass', 'dkim_domains' => ['example.com']],
            MailProbe::authResults([$forged, self::RESULTS], 'mx.google.com')
        );
    }

    public function testOneDkimSignaturePassingIsEnough(): void
    {
        $header = 'mx.google.com; dkim=fail header.i=@relay.example.net; dkim=pass header.i=@example.com; spf=pass; dmarc=pass';

        self::assertSame('pass', MailProbe::authResults([$header], 'mx.google.com')['dkim']);
    }

    public function testMethodsTheReceiverDidNotRecordAreNoneExceptSpf(): void
    {
        self::assertSame(
            ['spf' => 'pass', 'dkim' => 'none', 'dmarc' => 'none', 'dkim_domains' => []],
            MailProbe::authResults(['mx.google.com; spf=pass'], 'mx.google.com')
        );
        self::assertSame(
            ['spf' => null, 'dkim' => 'pass', 'dmarc' => 'none', 'dkim_domains' => [null]],
            MailProbe::authResults(['mx.google.com; dkim=pass'], 'mx.google.com')
        );
    }

    public function testDkimDomainsComeFromHeaderDElseTheIdentitysDomain(): void
    {
        $header = 'mx.google.com; dkim=pass header.d=Mail.Example.com header.s=s1; dkim=pass header.i=@sender.example.net; dkim=fail header.i=@example.org; spf=pass';

        self::assertSame(['mail.example.com', 'sender.example.net'], MailProbe::authResults([$header], 'mx.google.com')['dkim_domains']);
    }

    public function testDkimAlignmentIsRelaxedLikeDmarc(): void
    {
        self::assertTrue(MailProbe::dkimAligned(['example.com'], 'example.com'));
        self::assertTrue(MailProbe::dkimAligned(['mail.example.com'], 'example.com'), 'subdomain of the From domain');
        self::assertTrue(MailProbe::dkimAligned(['example.com'], 'news.example.com'), 'same organisational domain');
        self::assertTrue(MailProbe::dkimAligned(['example.co.uk'], 'shop.example.co.uk'), 'two-label public suffix');
        self::assertTrue(MailProbe::dkimAligned(['sender.example.net', 'example.com'], 'example.com'), 'one aligned signature is enough');
        self::assertFalse(MailProbe::dkimAligned(['sender.example.net'], 'example.com'), "a provider's own signature");
        self::assertFalse(MailProbe::dkimAligned(['other.co.uk'], 'example.co.uk'));
        self::assertNull(MailProbe::dkimAligned([null], 'example.com'), 'signing domain unreadable');
        self::assertNull(MailProbe::dkimAligned(['sender.example.net', null], 'example.com'));
        self::assertNull(MailProbe::dkimAligned(['example.com'], null), 'From domain unreadable');
    }

    public function testHeaderValuesUnfoldsAndKeepsMessageOrder(): void
    {
        $block = "Subject: Hello\r\n there\r\nAuthentication-Results: a;\r\n\tspf=pass\r\nauthentication-results: b\r\n\r\n";

        self::assertSame(['Hello there'], MailProbe::headerValues($block, 'subject'));
        self::assertSame(['a; spf=pass', 'b'], MailProbe::headerValues($block, 'Authentication-Results'));
    }

    public function testTheNewestRecentMessageCarryingTheReferenceWins(): void
    {
        $ref      = MailProbe::reference(self::SITE_ID);
        $now      = new DateTimeImmutable('2026-09-30 12:00:00', new DateTimeZone('UTC'));
        $messages = [
            ['internal_date' => '30-Sep-2026 09:00:00 +0000', 'headers' => "Subject: Test [{$ref}]\r\nX-Marker: older\r\n\r\n"],
            ['internal_date' => '30-Sep-2026 11:00:00 +0000', 'headers' => "Subject: Test [{$ref}]\r\nX-Marker: newest\r\n\r\n"],
            ['internal_date' => '30-Sep-2026 11:30:00 +0000', 'headers' => "Subject: Test [ffffffffffffffff]\r\n\r\n"],
            ['internal_date' => '28-Sep-2026 11:00:00 +0000', 'headers' => "Subject: Test [{$ref}]\r\n\r\n"],
        ];

        $picked = MailProbe::pickLatest($messages, $ref, $now, 24 * 3600);

        self::assertNotNull($picked);
        self::assertStringContainsString('newest', $picked['headers']);
        self::assertNull(MailProbe::pickLatest([$messages[3]], $ref, $now, 24 * 3600));
        self::assertNull(MailProbe::pickLatest([$messages[2]], $ref, $now, 24 * 3600));
    }

    public function testProbeReadsVerdictsFromTheMostRecentMessage(): void
    {
        $ref = MailProbe::reference(self::SITE_ID);
        $old = "Subject: SatelliteWP test email from Example [{$ref}]\r\nFrom: a@old.example.org\r\n\r\n";
        $new = "Subject: SatelliteWP test email from Example [{$ref}]\r\nFrom: WordPress <wordpress@example.com>\r\n"
            . 'Authentication-Results: ' . self::RESULTS . "\r\n\r\n";

        $client = $this->client([
            'A1 OK logged in',
            $this->inboxOnly(),
            "* 3 EXISTS\r\nA3 OK [READ-ONLY] examined",
            "* SEARCH 41 42\r\nA4 OK done",
            $this->fetch(41, '29-Sep-2026 15:00:00 +0000', $old) . $this->fetch(42, '30-Sep-2026 09:00:00 +0000', $new) . 'A5 OK done',
            "* BYE\r\nA6 OK bye",
        ]);

        $result = $this->probe($client)->run($this->site());

        self::assertSame(ProbeResult::STATUS_OK, $result->status, implode(" | ", $result->errors));
        self::assertSame([
            'reference'   => $ref,
            'found'       => true,
            'received_at' => '2026-09-30T09:00:00Z',
            'from_domain' => 'example.com',
            'spf'         => 'pass',
            'dkim'        => 'pass',
            'dmarc'       => 'pass',
            'dkim_domains' => ['example.com'],
            'dkim_aligned' => true,
        ], $result->data);
    }

    public function testNoRecentMessageIsFoundFalseWithUnknownVerdicts(): void
    {
        $client = $this->client([
            'A1 OK logged in',
            $this->inboxOnly(),
            'A3 OK examined',
            "* SEARCH\r\nA4 OK done",
            "* BYE\r\nA5 OK bye",
        ]);

        $result = $this->probe($client)->run($this->site());

        self::assertSame(ProbeResult::STATUS_OK, $result->status, implode(' | ', $result->errors));
        self::assertFalse($result->data['found']);
        self::assertNull($result->data['spf']);
        self::assertNull($result->data['dkim']);
        self::assertNull($result->data['dmarc']);
    }

    public function testAMessageWithoutTheReceiversVerdictsIsFoundButUnknown(): void
    {
        $ref = MailProbe::reference(self::SITE_ID);
        $raw = "Subject: Test [{$ref}]\r\nFrom: wordpress@example.com\r\n\r\n";

        $client = $this->client([
            'A1 OK logged in',
            $this->inboxOnly(),
            'A3 OK examined',
            "* SEARCH 7\r\nA4 OK done",
            $this->fetch(7, '30-Sep-2026 10:00:00 +0000', $raw) . 'A5 OK done',
            "* BYE\r\nA6 OK bye",
        ]);

        $result = $this->probe($client)->run($this->site());

        self::assertTrue($result->data['found']);
        self::assertNull($result->data['spf']);
        self::assertSame('example.com', $result->data['from_domain']);
    }

    public function testAMessageFiledAsSpamIsFoundInTheServersJunkFolder(): void
    {
        $ref = MailProbe::reference(self::SITE_ID);
        $raw = "Subject: Test [{$ref}]\r\nFrom: wordpress@example.com\r\n"
            . "Authentication-Results: mx.google.com; spf=softfail; dkim=none; dmarc=fail\r\n\r\n";

        $client = $this->client([
            'A1 OK logged in',
            "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n* LIST (\\HasNoChildren \\Junk) \"/\" \"[Gmail]/Pourriel\"\r\nA2 OK listed",
            'A3 OK examined',
            "* SEARCH\r\nA4 OK done",
            'A5 OK examined',
            "* SEARCH 3\r\nA6 OK done",
            $this->fetch(3, '30-Sep-2026 10:00:00 +0000', $raw) . 'A7 OK done',
            "* BYE\r\nA8 OK bye",
        ]);

        $result = $this->probe($client)->run($this->site());

        self::assertTrue($result->data['found'], implode(' | ', $result->errors));
        self::assertSame('softfail', $result->data['spf']);
        self::assertSame('fail', $result->data['dmarc']);
    }

    public function testAnInboxThatCannotBeOpenedIsAnError(): void
    {
        $client = $this->client([
            'A1 OK logged in',
            $this->inboxOnly(),
            'A3 NO [NONEXISTENT] Unknown Mailbox',
            "* BYE\r\nA4 OK bye",
        ]);

        self::assertSame(ProbeResult::STATUS_ERROR, $this->probe($client)->run($this->site())->status);
    }

    public function testARefusedLoginIsAnErrorThatNeverCarriesThePassword(): void
    {
        $client = $this->client([
            'A1 NO [AUTHENTICATIONFAILED] Invalid credentials for app-password-1234',
            "* BYE\r\nA2 OK bye",
        ]);

        $result = $this->probe($client)->run($this->site());

        self::assertSame(ProbeResult::STATUS_ERROR, $result->status);
        self::assertStringContainsString('login refused', $result->errors[0]);
        self::assertStringNotContainsString('app-password-1234', $result->errors[0]);
    }

    public function testAnUnconfiguredMailboxIsAConfigurationError(): void
    {
        $result = (new MailProbe([]))->run($this->site());

        self::assertSame(ProbeResult::STATUS_ERROR, $result->status);
        self::assertStringContainsString('not configured', $result->errors[0]);
    }

    private function site(): SiteContext
    {
        return SiteContext::fromExtractionPayload(self::SITE_ID, ['site_url' => 'https://example.com', 'home_url' => 'https://example.com']);
    }

    private function probe(ImapClient $client): MailProbe
    {
        return new MailProbe(
            ['username' => 'validator@example.com', 'password' => 'app-password-1234', 'max_age_hours' => 24],
            static fn (): ImapClient => $client,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-30 12:00:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * An ImapClient whose server side is pre-recorded: the greeting, then one
     * reply per command in order (the last line of each ends with the tagged status).
     *
     * @param list<string> $replies
     */
    private function client(array $replies): ImapClient
    {
        [$clientEnd, $serverEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->serverEnds[] = $serverEnd;
        fwrite($serverEnd, "* OK Gimap ready\r\n");
        foreach ($replies as $reply) {
            fwrite($serverEnd, $reply . "\r\n");
        }
        stream_set_timeout($clientEnd, 1);

        return new ImapClient($clientEnd);
    }

    private function inboxOnly(): string
    {
        return "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\nA2 OK listed";
    }

    private function fetch(int $uid, string $internalDate, string $headers): string
    {
        return "* {$uid} FETCH (UID {$uid} INTERNALDATE \"{$internalDate}\" BODY[HEADER.FIELDS (SUBJECT FROM AUTHENTICATION-RESULTS)] {" . strlen($headers) . "}\r\n{$headers})\r\n";
    }
}
