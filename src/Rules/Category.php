<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Rules;

/**
 * Short, language-neutral category codes for grouping findings. Display labels
 * live in the translation catalogue (config/lang/*.php), so these codes never
 * carry a language. Kept intentionally coarse (~15) rather than one per doc
 * section letter.
 */
final class Category
{
    public const string DOMAIN      = 'DOMAIN';
    public const string SSL         = 'SSL';
    public const string SECURITY    = 'SECURITY';
    public const string HTTP        = 'HTTP';
    public const string DNS         = 'DNS';
    public const string EMAIL       = 'EMAIL';
    public const string PERFORMANCE = 'PERFORMANCE';
    public const string SEO         = 'SEO';
    public const string UPDATES     = 'UPDATES';
    public const string PHP         = 'PHP';
    public const string DATABASE    = 'DATABASE';
    public const string HOSTING     = 'HOSTING';
    public const string CRON        = 'CRON';
    public const string USERS       = 'USERS';
    public const string CACHE       = 'CACHE';
    public const string CONTENT     = 'CONTENT';

    /** @var list<string> */
    public const array ALL = [
        self::DOMAIN, self::SSL, self::SECURITY, self::HTTP, self::DNS,
        self::EMAIL, self::PERFORMANCE, self::SEO, self::UPDATES, self::PHP,
        self::DATABASE, self::HOSTING, self::CRON, self::USERS, self::CACHE,
        self::CONTENT,
    ];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }
}
