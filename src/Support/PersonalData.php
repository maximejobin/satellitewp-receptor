<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Support;

/**
 * Leaves personal data out before an extraction leaves Manager: email
 * addresses and administrator logins are omitted, never replaced by a
 * placeholder. A login that is exactly "admin" stays: it is
 * itself a security finding (M2) and names no one.
 */
final class PersonalData
{
    private const string EMAIL = '[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,}';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function payload(array $payload): array
    {
        foreach (['administrators', 'super_admins'] as $list) {
            foreach ((array) ($payload[$list] ?? []) as $i => $user) {
                if (is_array($user)) {
                    $payload[$list][$i] = self::withoutLogin($user, 'login');
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
        foreach ((array) ($probes['blogvault']['data']['users']['items'] ?? []) as $i => $user) {
            if (is_array($user)) {
                $probes['blogvault']['data']['users']['items'][$i] = self::withoutLogin($user, 'username');
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

    /** Free text with its email addresses taken out. */
    public function text(string $text): string
    {
        $out = (string) preg_replace('/' . self::EMAIL . '/i', '', $text);

        return $out === $text ? $text : trim((string) preg_replace('/[ \t]{2,}/', ' ', $out));
    }

    /**
     * @param array<array-key, mixed> $user
     * @return array<array-key, mixed>
     */
    private static function withoutLogin(array $user, string $field): array
    {
        if (strtolower((string) ($user[$field] ?? '')) !== 'admin') {
            unset($user[$field]);
        }

        return $user;
    }

    /** A value that is only an email address is dropped with its key; text loses its addresses. */
    private function walk(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->text($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($v) && preg_match('/^\s*' . self::EMAIL . '\s*$/i', $v) === 1) {
                continue;
            }
            $out[$k] = $this->walk($v);
        }

        return array_is_list($value) ? array_values($out) : $out;
    }
}
