<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

/**
 * Masks personal data before an extraction leaves Manager: email local parts,
 * administrator logins and CRM client names. Logins become stable "user-N"
 * pseudonyms (the same person keeps the same alias in every file); "admin"
 * stays as is, since that exact login is itself a security finding (M2).
 */
final class PersonalData
{
    public const string MASK = '[redacted]';

    private const string EMAIL = '/[A-Z0-9._%+\-]+@([A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,})/i';

    /** @var array<string, string> lowercased login => pseudonym */
    private array $aliases = [];

    /**
     * @param array<string, mixed>                $payload
     * @param array<string, array<string, mixed>> $probes probe name => stored envelope
     */
    public function __construct(array $payload, array $probes)
    {
        $logins = [];
        foreach (['administrators', 'super_admins'] as $list) {
            foreach ((array) ($payload[$list] ?? []) as $user) {
                $logins[] = (string) (((array) $user)['login'] ?? '');
            }
        }
        foreach ((array) ($probes['blogvault']['data']['users']['items'] ?? []) as $user) {
            $logins[] = (string) (((array) $user)['username'] ?? '');
        }
        foreach ($logins as $login) {
            $key = strtolower($login);
            if ($key !== '' && $key !== 'admin' && !isset($this->aliases[$key])) {
                $this->aliases[$key] = 'user-' . (count($this->aliases) + 1);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function payload(array $payload): array
    {
        foreach (['administrators', 'super_admins'] as $list) {
            foreach ((array) ($payload[$list] ?? []) as $i => $user) {
                if (is_array($user) && isset($user['login'])) {
                    $payload[$list][$i]['login'] = $this->alias((string) $user['login']);
                }
            }
        }

        return (array) $this->walk($payload);
    }

    /**
     * @param array<string, array<string, mixed>> $probes
     * @return array<string, array<string, mixed>>
     */
    public function probes(array $probes): array
    {
        if (isset($probes['crm']['data']['clients']) && is_array($probes['crm']['data']['clients'])) {
            foreach ($probes['crm']['data']['clients'] as $i => $client) {
                foreach (['label', 'company', 'first_name', 'last_name', 'email', 'phone'] as $field) {
                    if (is_array($client) && isset($client[$field]) && $client[$field] !== '') {
                        $probes['crm']['data']['clients'][$i][$field] = self::MASK;
                    }
                }
            }
        }

        foreach ((array) ($probes['blogvault']['data']['users']['items'] ?? []) as $i => $user) {
            if (is_array($user) && isset($user['username'])) {
                $probes['blogvault']['data']['users']['items'][$i]['username'] = $this->alias((string) $user['username']);
            }
        }

        /** @var array<string, array<string, mixed>> */
        return (array) $this->walk($probes);
    }

    /** Any other data (findings, observations, licences): emails. */
    public function any(mixed $data): mixed
    {
        return $this->walk($data);
    }

    public function text(string $text): string
    {
        return (string) preg_replace(self::EMAIL, 'redacted@$1', $text);
    }

    private function alias(string $login): string
    {
        return $this->aliases[strtolower($login)] ?? $login;
    }

    /** Emails in every string; logins are replaced only in their own fields, never by value. */
    private function walk(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->walk($v), $value);
        }
        if (!is_string($value)) {
            return $value;
        }

        return $this->text($value);
    }
}
