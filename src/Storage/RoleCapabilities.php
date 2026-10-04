<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Storage;

/**
 * Role -> capability lookup from config/roles.php ('*' = every capability),
 * checked by the controllers when Google sign-in is configured.
 */
final class RoleCapabilities
{
    /** @param array<string, list<string>> $map role => capabilities ('*' = every capability) */
    public function __construct(private readonly array $map)
    {
    }

    public static function load(string $file): self
    {
        $map = is_file($file) ? include $file : [];

        return new self(is_array($map) ? $map : []);
    }

    /** @return list<string> known role names, in config/roles.php's declared order */
    public function roles(): array
    {
        return array_keys($this->map);
    }

    public function knowsRole(string $role): bool
    {
        return array_key_exists($role, $this->map);
    }

    public function can(string $role, string $capability): bool
    {
        $capabilities = $this->map[$role] ?? [];

        return in_array('*', $capabilities, true) || in_array($capability, $capabilities, true);
    }
}
