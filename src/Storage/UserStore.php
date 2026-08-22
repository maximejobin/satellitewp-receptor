<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

use RuntimeException;

/**
 * Who may use the web UI: a flat list of email addresses in data/users.json.
 *
 * Signing in with Google is not enough — the address must also be in this list.
 * The FIRST entry is the admin, the only account allowed to add or remove
 * others. Keeping "admin" positional rather than a role flag is deliberate:
 * there is exactly one privilege in this tool, and a list needs no schema.
 */
final class UserStore
{
    /** @var list<string>|null lazy-loaded, lowercased */
    private ?array $users = null;

    public function __construct(private readonly string $file)
    {
    }

    /** @return list<string> */
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

        $clean = [];
        foreach ($decoded as $email) {
            $email = self::normalize(is_scalar($email) ? (string) $email : '');
            if ($email !== '' && !in_array($email, $clean, true)) {
                $clean[] = $email;
            }
        }

        return $this->users = $clean;
    }

    /** An empty list locks everyone out — the caller must treat it as "not set up yet". */
    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function isAllowed(string $email): bool
    {
        return in_array(self::normalize($email), $this->all(), true);
    }

    /** The first listed address, or null when the file is empty. */
    public function admin(): ?string
    {
        return $this->all()[0] ?? null;
    }

    public function isAdmin(string $email): bool
    {
        $admin = $this->admin();

        return $admin !== null && $admin === self::normalize($email);
    }

    /** @return bool false when the address is invalid or already listed */
    public function add(string $email): bool
    {
        $email = self::normalize($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($this->isAllowed($email)) {
            return false;
        }

        $users   = $this->all();
        $users[] = $email;
        $this->save($users);

        return true;
    }

    /**
     * The admin is the one account that cannot be removed: dropping it would
     * promote whoever happens to be next in the file, or lock everyone out.
     *
     * @return bool false when absent or when it is the admin
     */
    public function remove(string $email): bool
    {
        $email = self::normalize($email);
        if (!$this->isAllowed($email) || $this->isAdmin($email)) {
            return false;
        }

        $this->save(array_values(array_filter($this->all(), static fn (string $u): bool => $u !== $email)));

        return true;
    }

    /** @param list<string> $users */
    private function save(array $users): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory for the users file: {$dir}");
        }

        file_put_contents(
            $this->file,
            (string) json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
        $this->users = $users;
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
