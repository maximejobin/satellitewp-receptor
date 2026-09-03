<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Crm;

use PDO;

/**
 * Connection to the external CRM/billing MySQL database (clients,
 * subscriptions, products, websites, website items — see the schema this
 * was built against). Read-only from this app's side: nothing here ever
 * writes to it, the sync that populates it runs elsewhere.
 *
 * Same "isConfigured() gate, nullable service" pattern as BlogVault/Wordfence
 * in App.php — a fresh install with no host/database set in config.local.php
 * gets a clean "not connected" page instead of a connection error, since a
 * real connection may only be handed over later.
 */
final class ClientsDb
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly ?string $host,
        private readonly int $port,
        private readonly ?string $database,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly string $charset = 'utf8mb4',
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self(
            isset($config['host']) && is_string($config['host']) && $config['host'] !== '' ? $config['host'] : null,
            (int) ($config['port'] ?? 3306),
            isset($config['database']) && is_string($config['database']) && $config['database'] !== '' ? $config['database'] : null,
            isset($config['username']) && is_string($config['username']) ? $config['username'] : null,
            isset($config['password']) && is_string($config['password']) ? $config['password'] : null,
            (string) ($config['charset'] ?? 'utf8mb4')
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== null && $this->database !== null;
    }

    /**
     * PDO DSN string. Credentials are separate PDO constructor arguments in
     * this driver, never part of the DSN, so this is safe to log/display as-is.
     *
     * @throws \RuntimeException if unconfigured
     */
    public function dsn(): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('ClientsDb is not configured — call isConfigured() first.');
        }

        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->host, $this->port, $this->database, $this->charset);
    }

    /** @throws \PDOException on connection failure, or \RuntimeException if unconfigured */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn(), $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $this->pdo;
    }
}
