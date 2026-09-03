<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

use RuntimeException;

/**
 * Who may use the web UI, and which role each address has: data/users.json.
 *
 * Signing in with Google is not enough — the address must also be in this
 * list, **and** currently active (see STATUS_SUSPENDED below). Every address
 * has a role (admin/maintenance/coordinator/sale, see config/roles.php);
 * ROLE_ADMIN is the only one that may add, edit, remove or suspend others.
 * Nothing else reads or gates on role yet — see RoleCapabilities/config/roles.php.
 *
 * File format is `{"email", "role", "first_name", "last_name", "status"}` per
 * entry. Two older shapes are read transparently and upgraded on next save():
 *   - a pre-2026-09-02 file: a flat list of email strings — the first entry
 *     becomes admin and every other becomes maintenance, the exact access
 *     every non-first entry already had.
 *   - a 2026-09-02..2026-09-02 file: `{"email", "role"}` only — first/last
 *     name default to '' and status defaults to STATUS_ACTIVE, so an
 *     existing account's access is unchanged by the upgrade.
 *
 * **Suspended vs. removed**: suspending keeps the record (name, role,
 * history) but blocks sign-in immediately (isAllowed() is re-checked every
 * request — see Router::currentUser()) and is reversible. Removing deletes
 * the record outright. Both refuse to leave the list with no *active* admin
 * reachable — an admin record that still exists but is suspended does not
 * count, since nobody could sign in to fix that.
 */
final class UserStore
{
    public const string ROLE_ADMIN       = 'admin';
    public const string DEFAULT_ROLE     = 'maintenance';
    public const string STATUS_ACTIVE    = 'active';
    public const string STATUS_SUSPENDED = 'suspended';

    /** @var list<array{email:string,role:string,first_name:string,last_name:string,status:string}>|null lazy-loaded */
    private ?array $users = null;

    /** @param list<string> $knownRoles roles add()/updateUser() will accept; empty = accept anything */
    public function __construct(private readonly string $file, private readonly array $knownRoles = [])
    {
    }

    /** @return list<array{email:string,role:string,first_name:string,last_name:string,status:string}> */
    public function all(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        if (!is_file($this->file)) {
            return $this->users = [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($decoded)) {
            return $this->users = [];
        }

        return $this->users = self::isLegacyFormat($decoded)
            ? self::fromLegacy($decoded)
            : self::fromCurrent($decoded);
    }

    /** @param array<mixed> $decoded */
    private static function isLegacyFormat(array $decoded): bool
    {
        foreach ($decoded as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $decoded plain email strings
     * @return list<array{email:string,role:string,first_name:string,last_name:string,status:string}>
     */
    private static function fromLegacy(array $decoded): array
    {
        $clean = [];
        foreach (array_values($decoded) as $i => $email) {
            $email = self::normalize((string) $email);
            if ($email === '' || in_array($email, array_column($clean, 'email'), true)) {
                continue;
            }
            $clean[] = [
                'email'      => $email,
                'role'       => $i === 0 ? self::ROLE_ADMIN : self::DEFAULT_ROLE,
                'first_name' => '',
                'last_name'  => '',
                'status'     => self::STATUS_ACTIVE,
            ];
        }

        return $clean;
    }

    /**
     * @param array<mixed> $decoded {email, role, first_name?, last_name?, status?} entries
     * @return list<array{email:string,role:string,first_name:string,last_name:string,status:string}>
     */
    private static function fromCurrent(array $decoded): array
    {
        $clean = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $email = self::normalize(is_scalar($entry['email'] ?? null) ? (string) $entry['email'] : '');
            $role  = is_scalar($entry['role'] ?? null) ? (string) $entry['role'] : '';
            if ($email === '' || $role === '' || in_array($email, array_column($clean, 'email'), true)) {
                continue;
            }
            $status = is_scalar($entry['status'] ?? null) ? (string) $entry['status'] : self::STATUS_ACTIVE;
            $clean[] = [
                'email'      => $email,
                'role'       => $role,
                'first_name' => is_scalar($entry['first_name'] ?? null) ? (string) $entry['first_name'] : '',
                'last_name'  => is_scalar($entry['last_name'] ?? null) ? (string) $entry['last_name'] : '',
                'status'     => in_array($status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true) ? $status : self::STATUS_ACTIVE,
            ];
        }

        return $clean;
    }

    /** An empty list locks everyone out — the caller must treat it as "not set up yet". */
    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /** Listed **and** currently active — the actual "may sign in" check. */
    public function isAllowed(string $email): bool
    {
        $user = $this->find(self::normalize($email));

        return $user !== null && $user['status'] === self::STATUS_ACTIVE;
    }

    /** Listed at all, regardless of status — for duplicate-detection, not sign-in. */
    public function exists(string $email): bool
    {
        return $this->find(self::normalize($email)) !== null;
    }

    /** @return array{email:string,role:string,first_name:string,last_name:string,status:string}|null */
    private function find(string $normalizedEmail): ?array
    {
        foreach ($this->all() as $user) {
            if ($user['email'] === $normalizedEmail) {
                return $user;
            }
        }

        return null;
    }

    public function roleOf(string $email): ?string
    {
        return $this->find(self::normalize($email))['role'] ?? null;
    }

    public function isAdmin(string $email): bool
    {
        return $this->roleOf($email) === self::ROLE_ADMIN;
    }

    /** @return bool false when the address is invalid, already listed, or the role is unknown */
    public function add(string $email, string $role = self::DEFAULT_ROLE, string $firstName = '', string $lastName = ''): bool
    {
        $email = self::normalize($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($this->exists($email) || !$this->isKnownRole($role)) {
            return false;
        }

        $users   = $this->all();
        $users[] = [
            'email'      => $email,
            'role'       => $role,
            'first_name' => trim($firstName),
            'last_name'  => trim($lastName),
            'status'     => self::STATUS_ACTIVE,
        ];
        $this->save($users);

        return true;
    }

    /**
     * Edits an already-listed user's name, role and/or email in one save.
     * $newEmail may equal $email (no rename) or a genuinely different,
     * not-already-taken address.
     *
     * @return bool false when $email is unknown, $newEmail is invalid or
     *              already taken by someone else, the role is unknown, or
     *              this would leave no *active* admin reachable
     */
    public function updateUser(string $email, string $newEmail, string $firstName, string $lastName, string $role): bool
    {
        $email    = self::normalize($email);
        $newEmail = self::normalize($newEmail);
        $current  = $this->find($email);

        if ($current === null || !$this->isKnownRole($role)) {
            return false;
        }
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($newEmail !== $email && $this->exists($newEmail)) {
            return false;
        }

        $users = $this->all();
        $wasActiveAdmin = $current['role'] === self::ROLE_ADMIN && $current['status'] === self::STATUS_ACTIVE;
        $staysActiveAdmin = $role === self::ROLE_ADMIN && $current['status'] === self::STATUS_ACTIVE;
        if ($wasActiveAdmin && !$staysActiveAdmin && !self::hasOtherActiveAdmin($users, $email)) {
            return false;
        }

        foreach ($users as $i => $user) {
            if ($user['email'] === $email) {
                $users[$i] = [
                    'email'      => $newEmail,
                    'role'       => $role,
                    'first_name' => trim($firstName),
                    'last_name'  => trim($lastName),
                    'status'     => $user['status'],
                ];
            }
        }
        $this->save($users);

        return true;
    }

    /**
     * Suspends or reactivates a user. Suspending blocks sign-in immediately
     * (isAllowed() is re-checked every request) without deleting the record.
     *
     * @param string $status one of the STATUS_* constants; anything else is rejected
     * @return bool false when the address is unknown, the status is
     *              unrecognized, or suspending this user would leave no
     *              active admin reachable
     */
    public function setStatus(string $email, string $status): bool
    {
        $email = self::normalize($email);
        $user  = $this->find($email);
        if ($user === null || !in_array($status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
            return false;
        }

        $users = $this->all();
        $isActiveAdmin = $user['role'] === self::ROLE_ADMIN && $user['status'] === self::STATUS_ACTIVE;
        if ($isActiveAdmin && $status === self::STATUS_SUSPENDED && !self::hasOtherActiveAdmin($users, $email)) {
            return false;
        }

        foreach ($users as $i => $u) {
            if ($u['email'] === $email) {
                $users[$i]['status'] = $status;
            }
        }
        $this->save($users);

        return true;
    }

    /**
     * The last remaining *active* admin cannot be removed: dropping it would
     * either lock everyone out of managing the list, or (with no active
     * admin left) make this very restriction unenforceable the next time it
     * matters. A suspended admin record doesn't count as "another admin" —
     * nobody can sign in as them either.
     *
     * @return bool false when absent, or when it is the last active admin
     */
    public function remove(string $email): bool
    {
        $email = self::normalize($email);
        $user  = $this->find($email);
        if ($user === null) {
            return false;
        }

        $users = $this->all();
        $isActiveAdmin = $user['role'] === self::ROLE_ADMIN && $user['status'] === self::STATUS_ACTIVE;
        if ($isActiveAdmin && !self::hasOtherActiveAdmin($users, $email)) {
            return false;
        }

        $this->save(array_values(array_filter($users, static fn (array $u): bool => $u['email'] !== $email)));

        return true;
    }

    /** @param list<array{email:string,role:string,first_name:string,last_name:string,status:string}> $users */
    private static function hasOtherActiveAdmin(array $users, string $exceptEmail): bool
    {
        foreach ($users as $user) {
            if ($user['email'] !== $exceptEmail && $user['role'] === self::ROLE_ADMIN && $user['status'] === self::STATUS_ACTIVE) {
                return true;
            }
        }

        return false;
    }

    private function isKnownRole(string $role): bool
    {
        return $this->knownRoles === [] || in_array($role, $this->knownRoles, true);
    }

    /** @param list<array{email:string,role:string,first_name:string,last_name:string,status:string}> $users */
    private function save(array $users): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory for the users file: {$dir}");
        }

        file_put_contents(
            $this->file,
            (string) json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
        $this->users = $users;
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
