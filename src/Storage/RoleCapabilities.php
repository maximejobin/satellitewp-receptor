<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Storage;

/**
 * Role -> capability lookup, loaded from config/roles.php.
 *
 * Router::requireCapability()/currentUserCan() call can() to gate every
 * mutation and data-sensitive page when Google sign-in is configured.
 * config/roles.php is the role => capability map itself ('*' = every
 * capability); the capability names are the strings passed at each call site.
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
