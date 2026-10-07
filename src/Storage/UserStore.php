<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

use SatelliteWP\Manager\Support\JsonFileStore;
use Throwable;

final class UserStore
{
    public const string ROLE_ADMIN       = 'admin';
    public const string DEFAULT_ROLE     = 'maintenance';
    public const string STATUS_ACTIVE    = 'active';
    public const string STATUS_SUSPENDED = 'suspended';

    /**
     * @var list<array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }>|null lazy-loaded
     */
    private ?array $users = null;

    /**
     * currentUser() re-reads this file on every request: writes are atomic (a
     * half-written file would read as an empty allowlist) and owner-only.
     */
    private readonly JsonFileStore $json;

    /** @param list<string> $knownRoles roles add()/updateUser() will accept; empty = accept anything */
    public function __construct(string $file, private readonly array $knownRoles = [])
    {
        $this->json = new JsonFileStore($file, 0600);
    }

    /**
     * @return list<array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }>
     */
    public function all(): array
    {
        return $this->users ??= self::parse($this->json->read());
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }>
     */
    private static function parse(array $decoded): array
    {
        return self::isLegacyFormat($decoded) ? self::fromLegacy($decoded) : self::fromCurrent($decoded);
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
     * @return list<array{email:string,role:string,first_name:string,last_name:string,status:string,data:null,runcloud_api_key:null,public_ssh_key:null}>
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
                'email'            => $email,
                'role'             => $i === 0 ? self::ROLE_ADMIN : self::DEFAULT_ROLE,
                'first_name'       => '',
                'last_name'        => '',
                'status'           => self::STATUS_ACTIVE,
                'data'             => null,
                'runcloud_api_key' => null,
                'public_ssh_key'   => null,
            ];
        }

        return $clean;
    }

    /**
     * @param array<mixed> $decoded {email, role, first_name?, last_name?, status?, data?, runcloud_api_key?, public_ssh_key?} entries
     * @return list<array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }>
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
            $data   = $entry['data'] ?? null;
            $clean[] = [
                'email'            => $email,
                'role'             => $role,
                'first_name'       => is_scalar($entry['first_name'] ?? null) ? (string) $entry['first_name'] : '',
                'last_name'        => is_scalar($entry['last_name'] ?? null) ? (string) $entry['last_name'] : '',
                'status'           => in_array($status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true) ? $status : self::STATUS_ACTIVE,
                'data'             => is_array($data) ? $data : null,
                'runcloud_api_key' => is_scalar($entry['runcloud_api_key'] ?? null) ? (string) $entry['runcloud_api_key'] : null,
                'public_ssh_key'   => is_scalar($entry['public_ssh_key'] ?? null) ? (string) $entry['public_ssh_key'] : null,
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

    /**
     * @return array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }|null
     */
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

    /**
     * The full record for one address.
     *
     * @return array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }|null
     */
    public function get(string $email): ?array
    {
        return $this->find(self::normalize($email));
    }

    public function isAdmin(string $email): bool
    {
        return $this->roleOf($email) === self::ROLE_ADMIN;
    }

    /** @return bool false when the address is invalid, already listed, or the role is unknown */
    public function add(string $email, string $role = self::DEFAULT_ROLE, string $firstName = '', string $lastName = ''): bool
    {
        $email = self::normalize($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$this->isKnownRole($role)) {
            return false;
        }

        return $this->change(function (array $users) use ($email, $role, $firstName, $lastName): ?array {
            if ($this->exists($email)) {
                return null;
            }
            $users[] = [
                'email'            => $email,
                'role'             => $role,
                'first_name'       => trim($firstName),
                'last_name'        => trim($lastName),
                'status'           => self::STATUS_ACTIVE,
                'data'             => null,
                'runcloud_api_key' => null,
                'public_ssh_key'   => null,
            ];

            return $users;
        });
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
        if (!$this->isKnownRole($role) || $newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $this->change(function (array $users) use ($email, $newEmail, $firstName, $lastName, $role): ?array {
            $current = $this->find($email);
            if ($current === null || ($newEmail !== $email && $this->exists($newEmail))) {
                return null;
            }

            $wasActiveAdmin   = $current['role'] === self::ROLE_ADMIN && $current['status'] === self::STATUS_ACTIVE;
            $staysActiveAdmin = $role === self::ROLE_ADMIN && $current['status'] === self::STATUS_ACTIVE;
            if ($wasActiveAdmin && !$staysActiveAdmin && !self::hasOtherActiveAdmin($users, $email)) {
                return null;
            }

            foreach ($users as $i => $user) {
                if ($user['email'] === $email) {
                    // Profile fields belong to updateProfile(); carried over untouched.
                    $users[$i] = [
                        'email'            => $newEmail,
                        'role'             => $role,
                        'first_name'       => trim($firstName),
                        'last_name'        => trim($lastName),
                        'status'           => $user['status'],
                        'data'             => $user['data'],
                        'runcloud_api_key' => $user['runcloud_api_key'],
                        'public_ssh_key'   => $user['public_ssh_key'],
                    ];
                }
            }

            return $users;
        });
    }

    /**
     * @param array<string, mixed>|null $data
     * @return bool false when the address is unknown
     */
    public function updateProfile(string $email, ?array $data, ?string $runcloudApiKey, ?string $publicSshKey): bool
    {
        $email          = self::normalize($email);
        $runcloudApiKey = $runcloudApiKey !== null && trim($runcloudApiKey) !== '' ? trim($runcloudApiKey) : null;
        $publicSshKey   = $publicSshKey !== null && trim($publicSshKey) !== '' ? trim($publicSshKey) : null;

        return $this->change(function (array $users) use ($email, $data, $runcloudApiKey, $publicSshKey): ?array {
            if ($this->find($email) === null) {
                return null;
            }
            foreach ($users as $i => $user) {
                if ($user['email'] === $email) {
                    $users[$i]['data']             = $data;
                    $users[$i]['runcloud_api_key'] = $runcloudApiKey;
                    $users[$i]['public_ssh_key']   = $publicSshKey;
                }
            }

            return $users;
        });
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
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
            return false;
        }

        return $this->change(function (array $users) use ($email, $status): ?array {
            $user = $this->find($email);
            if ($user === null) {
                return null;
            }
            $isActiveAdmin = $user['role'] === self::ROLE_ADMIN && $user['status'] === self::STATUS_ACTIVE;
            if ($isActiveAdmin && $status === self::STATUS_SUSPENDED && !self::hasOtherActiveAdmin($users, $email)) {
                return null;
            }

            foreach ($users as $i => $u) {
                if ($u['email'] === $email) {
                    $users[$i]['status'] = $status;
                }
            }

            return $users;
        });
    }

    /**
     * The last active admin cannot be removed (a suspended admin doesn't count):
     * nobody could manage the list afterwards.
     *
     * @return bool false when absent, or when it is the last active admin
     */
    public function remove(string $email): bool
    {
        $email = self::normalize($email);

        return $this->change(function (array $users) use ($email): ?array {
            $user = $this->find($email);
            if ($user === null) {
                return null;
            }
            $isActiveAdmin = $user['role'] === self::ROLE_ADMIN && $user['status'] === self::STATUS_ACTIVE;
            if ($isActiveAdmin && !self::hasOtherActiveAdmin($users, $email)) {
                return null;
            }

            return array_values(array_filter($users, static fn (array $u): bool => $u['email'] !== $email));
        });
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

    /**
     * Applies $edit to the list re-read under the file lock, so the checks it
     * makes (duplicate address, last active admin) see concurrent writes.
     *
     * @param callable(list<array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }>): (list<array{
     *     email:string,role:string,first_name:string,last_name:string,status:string,
     *     data:array<string,mixed>|null,runcloud_api_key:string|null,public_ssh_key:string|null
     * }>|null) $edit returns the new list, or null to refuse without writing
     * @return bool false when $edit refused
     */
    private function change(callable $edit): bool
    {
        try {
            return $this->json->mutate(function (array $raw) use ($edit): array {
                $this->users = self::parse($raw);
                $users       = $edit($this->users);
                if ($users === null) {
                    return [false, null];
                }
                $this->users = $users;

                return [true, $users];
            });
        } catch (Throwable $e) {
            $this->users = null;

            throw $e;
        }
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
