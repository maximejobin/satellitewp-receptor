<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Probe;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Integration\ImapClient;
use SatelliteWP\Xtractor\Integration\ImapException;
use SatelliteWP\Xtractor\Support\Secret;
use Throwable;

/**
 * Reads the site's SPF / DKIM / DMARC verdicts off its most recent test email.
 *
 * The plugin sends a test message to the validation mailbox with the site's
 * reference (sha256(site_id), 16 hex) in the subject. This probe finds the
 * newest such message in the mailbox and reads the receiving server's own
 * Authentication-Results header — the verdict Gmail computed on delivery, so
 * nothing is re-derived here.
 *
 * No recent message is a normal outcome ("found": false, every verdict null),
 * never a failed check.
 */
final class MailProbe extends AbstractProbe
{
    private const array HEADERS = ['SUBJECT', 'FROM', 'AUTHENTICATION-RESULTS'];

    /**
     * @param array<string, mixed> $config username, password, host, port, timeout,
     *        mailboxes, max_age_hours, authserv_id
     * @param (Closure(): ImapClient)|null $connector test seam; defaults to a TLS connection to host:port
     * @param (Closure(): DateTimeImmutable)|null $clock test seam
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?Closure $connector = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function name(): string
    {
        return 'mail';
    }

    public function version(): string
    {
        return '1.0';
    }

    /** The reference the plugin puts in its test email's subject and body. */
    public static function reference(string $siteId): string
    {
        return substr(hash('sha256', $siteId), 0, 16);
    }

    protected function collect(SiteContext $site): array
    {
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        if ($this->connector === null && ($username === '' || $password === '')) {
            return [
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => ['The validation mailbox is not configured (mail.username / mail.password)'],
            ];
        }

        $reference = self::reference($site->siteId);
        $now       = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $maxAge    = max(1, (int) ($this->config['max_age_hours'] ?? 24)) * 3600;

        try {
            $messages = $this->fetchCandidates($reference, $now, $maxAge, $username, $password);
        } catch (ImapException $e) {
            return [
                'target' => $username,
                'status' => ProbeResult::STATUS_ERROR,
                'errors' => [Secret::redact($e->getMessage(), $password)],
            ];
        }

        $message = self::pickLatest($messages, $reference, $now, $maxAge);
        $data    = ['reference' => $reference, 'found' => $message !== null] + self::emptyVerdicts();
        if ($message !== null) {
            $data = array_merge($data, self::describe($message));
        }

        return ['target' => $username, 'data' => $data, 'status' => ProbeResult::STATUS_OK];
    }

    /**
     * @return list<array{internal_date: string, headers: string}>
     */
    private function fetchCandidates(string $reference, DateTimeImmutable $now, int $maxAge, string $username, string $password): array
    {
        $client = $this->connector !== null
            ? ($this->connector)()
            : ImapClient::connect(
                (string) ($this->config['host'] ?? 'imap.gmail.com'),
                (int) ($this->config['port'] ?? 993),
                (int) ($this->config['timeout'] ?? 15)
            );

        try {
            $client->login($username, $password);

            $since     = $now->modify("-{$maxAge} seconds")->modify('-1 day')->format('j-M-Y');
            $found     = [];
            $mailboxes = array_values(array_filter((array) ($this->config['mailboxes'] ?? ['INBOX']), 'is_string'));
            foreach ($mailboxes as $i => $mailbox) {
                try {
                    $client->examine($mailbox);
                } catch (ImapException $e) {
                    // Only the first mailbox is mandatory; a Spam folder named differently is not fatal.
                    if ($i === 0) {
                        throw $e;
                    }
                    continue;
                }
                $uids = $client->uidSearch('SINCE ' . $since . ' SUBJECT "' . $reference . '"');
                foreach ($client->uidFetchHeaders(array_slice($uids, -10), self::HEADERS) as $message) {
                    $found[] = $message;
                }
            }

            return $found;
        } finally {
            $client->logout();
        }
    }

    /**
     * The newest message whose subject carries the reference and that arrived
     * within $maxAge seconds of $now.
     *
     * @param list<array{internal_date: string, headers: string}> $messages
     * @return array{internal_date: string, headers: string}|null
     */
    public static function pickLatest(array $messages, string $reference, DateTimeImmutable $now, int $maxAge): ?array
    {
        $best     = null;
        $bestTime = null;
        foreach ($messages as $message) {
            $received = self::parseInternalDate($message['internal_date']);
            if ($received === null || $received > $now || $now->getTimestamp() - $received->getTimestamp() > $maxAge) {
                continue;
            }
            $subject = self::headerValues($message['headers'], 'subject')[0] ?? '';
            if (!str_contains(strtolower(self::decodeHeader($subject)), strtolower($reference))) {
                continue;
            }
            if ($bestTime === null || $received > $bestTime) {
                $best     = $message;
                $bestTime = $received;
            }
        }

        return $best;
    }

    /**
     * @param array{internal_date: string, headers: string} $message
     * @return array<string, mixed>
     */
    private static function describe(array $message): array
    {
        $received = self::parseInternalDate($message['internal_date']);
        $from     = self::headerValues($message['headers'], 'from')[0] ?? '';

        return [
            'received_at' => $received?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'from_domain' => preg_match('/@([A-Za-z0-9.-]+)>?\s*$/', trim($from), $m) === 1 ? strtolower($m[1]) : null,
        ] + self::authResults(self::headerValues($message['headers'], 'authentication-results'), 'mx.google.com');
    }

    /** @return array{received_at: null, from_domain: null, spf: null, dkim: null, dmarc: null} */
    private static function emptyVerdicts(): array
    {
        return ['received_at' => null, 'from_domain' => null, 'spf' => null, 'dkim' => null, 'dmarc' => null];
    }

    /**
     * SPF / DKIM / DMARC verdicts from the Authentication-Results header added
     * by the receiving server. Earlier hops can write any header they like, so
     * only the first one whose authserv-id is the receiver's counts; without it
     * every verdict stays null (unknown).
     *
     * @param list<string> $headers every Authentication-Results value, top of the message first
     * @return array{spf: ?string, dkim: ?string, dmarc: ?string}
     */
    public static function authResults(array $headers, string $authservId): array
    {
        $out = ['spf' => null, 'dkim' => null, 'dmarc' => null];
        foreach ($headers as $value) {
            // Comments carry free text ("(google.com: domain of … designates …)"); drop them, innermost first.
            do {
                $value = preg_replace('/\([^()]*\)/', '', $value, -1, $count) ?? $value;
            } while ($count > 0);

            $parts = explode(';', $value);
            $id    = strtolower(trim((string) preg_replace('/\s.*$/s', '', trim((string) array_shift($parts)))));
            if ($id !== strtolower($authservId)) {
                continue;
            }

            foreach ($parts as $part) {
                if (preg_match('/^\s*(spf|dkim|dmarc)\s*=\s*([a-z]+)/i', $part, $m) !== 1) {
                    continue;
                }
                $method  = strtolower($m[1]);
                $verdict = strtolower($m[2]);
                // A message can carry several DKIM signatures: one passing is enough.
                if ($out[$method] === null || ($method === 'dkim' && $verdict === 'pass')) {
                    $out[$method] = $verdict;
                }
            }

            return $out;
        }

        return $out;
    }

    /**
     * Values of every header called $name, unfolded, in message order.
     *
     * @return list<string>
     */
    public static function headerValues(string $block, string $name): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $block) ?? $block;
        $values   = [];
        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false && strtolower(substr($line, 0, $pos)) === strtolower($name)) {
                $values[] = trim(substr($line, $pos + 1));
            }
        }

        return $values;
    }

    private static function decodeHeader(string $value): string
    {
        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($value) : $value;
    }

    private static function parseInternalDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('j-M-Y H:i:s O', trim($value));

        return $date === false ? null : $date;
    }
}
