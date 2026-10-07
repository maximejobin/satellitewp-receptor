<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Probe;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Integration\ImapClient;
use SatelliteWP\Manager\Integration\ImapException;
use SatelliteWP\Manager\Support\Secret;
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
     * @param array<string, mixed> $config username, password, host, port, timeout, max_age_hours
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
        return '1.1';
    }

    /** The reference the plugin puts in its test email's subject and body. */
    public static function reference(string $siteId): string
    {
        return substr(hash('sha256', $siteId), 0, 16);
    }

    protected function collect(SiteContext $site): array
    {
        $username = trim((string) ($this->config['username'] ?? ''));
        // Google shows app passwords in groups of four; the spaces are not part of the secret.
        $password = str_replace(' ', '', (string) ($this->config['password'] ?? ''));
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

            // A message failing SPF/DKIM is often filed as spam; Gmail localizes that folder's name.
            $mailboxes = ['INBOX'];
            $junk      = $client->junkMailbox();
            if ($junk !== null) {
                $mailboxes[] = $junk;
            }

            $since = $now->modify("-{$maxAge} seconds")->modify('-1 day')->format('j-M-Y');
            $found = [];
            foreach ($mailboxes as $mailbox) {
                $client->examine($mailbox);
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
        $received   = self::parseInternalDate($message['internal_date']);
        $from       = self::headerValues($message['headers'], 'from')[0] ?? '';
        $fromDomain = preg_match('/@([A-Za-z0-9.-]+)>?\s*$/', trim($from), $m) === 1 ? strtolower($m[1]) : null;
        $results    = self::authResults(self::headerValues($message['headers'], 'authentication-results'), 'mx.google.com');

        return [
            'received_at'  => $received?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'from_domain'  => $fromDomain,
            'spf'          => $results['spf'],
            'dkim'         => $results['dkim'],
            'dmarc'        => $results['dmarc'],
            'dkim_domains' => $results['dkim_domains'],
            'dkim_aligned' => $results['dkim'] === 'pass' ? self::dkimAligned($results['dkim_domains'], $fromDomain) : null,
        ];
    }

    /** @return array{received_at: null, from_domain: null, spf: null, dkim: null, dmarc: null, dkim_domains: list<?string>, dkim_aligned: null} */
    private static function emptyVerdicts(): array
    {
        return ['received_at' => null, 'from_domain' => null, 'spf' => null, 'dkim' => null, 'dmarc' => null, 'dkim_domains' => [], 'dkim_aligned' => null];
    }

    /**
     * Pure. DMARC relaxed alignment: a passing signature counts for the From
     * domain when both share the organisational domain. null when the From
     * domain or a passing signature's domain could not be read.
     *
     * @param list<?string> $passingDomains signing domain of each passing DKIM signature (null = unreadable)
     */
    public static function dkimAligned(array $passingDomains, ?string $fromDomain): ?bool
    {
        if ($fromDomain === null || $fromDomain === '' || $passingDomains === []) {
            return null;
        }
        $org = SiteContext::registrableDomain($fromDomain);
        foreach ($passingDomains as $domain) {
            if ($domain !== null && SiteContext::registrableDomain($domain) === $org) {
                return true;
            }
        }

        return in_array(null, $passingDomains, true) ? null : false;
    }

    /**
     * SPF / DKIM / DMARC verdicts from the Authentication-Results header added
     * by the receiving server. Earlier hops can write any header they like, so
     * only the first one whose authserv-id is the receiver's counts; without it
     * every verdict stays null (unknown).
     *
     * dkim_domains lists the signing domain of each passing DKIM signature:
     * header.d, else the domain of header.i (always d= or a subdomain of it),
     * null when neither is recorded.
     *
     * @param list<string> $headers every Authentication-Results value, top of the message first
     * @return array{spf: ?string, dkim: ?string, dmarc: ?string, dkim_domains: list<?string>}
     */
    public static function authResults(array $headers, string $authservId): array
    {
        $out = ['spf' => null, 'dkim' => null, 'dmarc' => null, 'dkim_domains' => []];
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
                if ($method === 'dkim' && $verdict === 'pass') {
                    $out['dkim_domains'][] = self::signingDomain($part);
                }
                // A message can carry several DKIM signatures: one passing is enough.
                if ($out[$method] === null || ($method === 'dkim' && $verdict === 'pass')) {
                    $out[$method] = $verdict;
                }
            }

            // The receiver writes one entry per method it evaluated; a message with no
            // signature or no published policy simply has none, which RFC 8601 calls "none".
            $out['dkim']  ??= 'none';
            $out['dmarc'] ??= 'none';

            return $out;
        }

        return $out;
    }

    /** The d= domain of one "dkim=…" result, or the domain part of its i= identity. */
    private static function signingDomain(string $result): ?string
    {
        if (preg_match('/\bheader\.d\s*=\s*"?([A-Za-z0-9.-]+)/i', $result, $m) === 1) {
            return strtolower(rtrim($m[1], '.'));
        }
        if (preg_match('/\bheader\.i\s*=\s*"?[^@\s;"]*@([A-Za-z0-9.-]+)/i', $result, $m) === 1) {
            return strtolower(rtrim($m[1], '.'));
        }

        return null;
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
