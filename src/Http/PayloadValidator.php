<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Shape, type and size checks on every signed payload, before anything is
 * stored. A valid signature only proves the sender holds the site's key: a
 * hijacked plugin signs whatever it likes, so everything downstream (index,
 * catalogue, probes, rules, templates, report) relies on the shapes enforced
 * here. The raw body is stored verbatim, so a payload is accepted as is or
 * refused (HTTP 422 with the offending path), never rewritten.
 *
 * Known sections are checked against what the plugin's collectors produce;
 * unknown keys still pass (the schema evolves plugin-side) but every node of
 * the tree, known or not, is under the global depth/count/length caps.
 */
final class PayloadValidator
{
    public const string TYPE_EXTRACTION = 'extraction';
    public const string TYPE_EVENT      = 'event';
    public const string TYPE_INTEGRITY  = 'integrity';

    public const array TYPES = [self::TYPE_EXTRACTION, self::TYPE_EVENT, self::TYPE_INTEGRITY];

    /** json_decode() nesting limit; the deepest real section (connectors.*.details.addons) is 4. */
    public const int MAX_DEPTH = 16;
    /**
     * Scalars + containers in one payload; bounds the decoded size (worst case
     * about 250 bytes per node, ~75 MB) and every later walk.
     */
    public const int MAX_NODES = 300_000;
    public const int MAX_STRING = 65_536;
    public const int MAX_KEY = 255;
    public const int MAX_URL = 2_048;
    /** Short identifiers: versions, locales, statuses, modes. */
    public const int MAX_SHORT = 255;

    public const int MAX_PLUGINS = 2_000;
    public const int MAX_THEMES = 500;
    public const int MAX_MU_PLUGINS = 500;
    public const int MAX_DROPINS = 64;
    public const int MAX_TABLES = 20_000;
    public const int MAX_LIST = 10_000;
    /** The plugin's queue holds 200 events; a little headroom, no more. */
    public const int MAX_EVENTS = 500;
    public const int MAX_EVENT_STRING = 16_384;
    public const int MAX_INTEGRITY_FILES = 50_000;

    private int $nodes = 0;

    /**
     * @return array<string, mixed> the decoded payload
     * @throws InvalidArgumentException on invalid payloads (HTTP 422)
     */
    public function validate(string $rawBody, string $type, string $headerSiteId): array
    {
        // Counted before decoding: json_decode() materialises every node, and a
        // few MB of "[0]," costs hundreds of MB of zvals. Separators inside
        // strings are counted too, so this is an upper bound of the node count.
        $bound = substr_count($rawBody, ',') + substr_count($rawBody, '[') + substr_count($rawBody, '{') + 1;
        if ($bound > self::MAX_NODES) {
            throw new InvalidArgumentException('Payload has too many entries (max ' . self::MAX_NODES . ')');
        }

        try {
            $payload = json_decode($rawBody, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Body is not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new InvalidArgumentException('Body is not a JSON object');
        }

        $this->nodes = 0;
        $this->walk($payload, '');

        if (!is_string($payload['schema_version'] ?? null) || $payload['schema_version'] === '') {
            throw new InvalidArgumentException('Missing schema_version');
        }
        $this->str($payload, 'schema_version', '', self::MAX_SHORT);

        if (($payload['site_id'] ?? null) !== $headerSiteId) {
            throw new InvalidArgumentException('Body site_id does not match X-SWP-Site header');
        }

        match ($type) {
            self::TYPE_EXTRACTION => $this->extraction($payload),
            self::TYPE_EVENT      => $this->events($payload),
            self::TYPE_INTEGRITY  => $this->integrity($payload),
            default               => throw new InvalidArgumentException('Unknown payload type'),
        };

        return $payload;
    }

    /**
     * Normalizes a site address for comparison with a bound origin.
     *
     * Mirrors ConfigFile::normalize_url() in the plugin — scheme and a leading
     * "www." dropped, trailing slash trimmed — so an http->https move or a www
     * redirect does not read as a different site. Keep the two in step.
     */
    public static function normalizeOrigin(string $url): string
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $url);
        $url = (string) preg_replace('#^www\.#', '', $url);

        return rtrim($url, '/');
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    /**
     * An absolute http(s) URL with a plain host: no credentials, no control
     * characters or spaces. The probes connect to this host and the UI links
     * to it, so nothing else is a site address.
     */
    public static function isSiteUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = (string) ($parts['host'] ?? '');

        return $host !== '' && (
            filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)([\p{L}\p{N}_]([\p{L}\p{N}_-]{0,62})?)(\.[\p{L}\p{N}_]([\p{L}\p{N}_-]{0,62})?)*\.?$/u', $host) === 1
        );
    }

    /** "dir/file.php" or "file.php", as get_plugins() keys them. */
    public static function isPluginFile(string $file): bool
    {
        return strlen($file) <= self::MAX_KEY
            && preg_match('#^(?:[^/\\\\\x00-\x1f\x7f]+/)?[^/\\\\\x00-\x1f\x7f]+\.php$#', $file) === 1
            && !self::hasDotSegment($file);
    }

    /** A theme's stylesheet: its directory, possibly one level deep in a theme root. */
    public static function isThemeStylesheet(string $stylesheet): bool
    {
        return strlen($stylesheet) <= self::MAX_KEY
            && preg_match('#^[^/\\\\\x00-\x1f\x7f]+(?:/[^/\\\\\x00-\x1f\x7f]+)?$#', $stylesheet) === 1
            && !self::hasDotSegment($stylesheet);
    }

    private static function hasDotSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Global pass over the whole tree: node count, key and string lengths,
     * NUL bytes (a C-string truncation and a classic path/log trick).
     */
    private function walk(mixed $value, string $path): void
    {
        if (++$this->nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('Payload has too many entries (max ' . self::MAX_NODES . ')');
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING) {
                throw new InvalidArgumentException(self::label($path) . ': string too long');
            }
            if (str_contains($value, "\0")) {
                throw new InvalidArgumentException(self::label($path) . ': NUL byte');
            }

            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && (strlen($key) > self::MAX_KEY || str_contains($key, "\0"))) {
                throw new InvalidArgumentException(self::label($path) . ': invalid key');
            }
            $this->walk($child, $path === '' ? (string) $key : $path . '.' . $key);
        }
    }

    /** @param array<string, mixed> $p */
    private function extraction(array $p): void
    {
        // An extraction is complete or it is not sent: a missing section would
        // read as "nothing there" to the rules.
        if (!empty($p['collector_errors'])) {
            throw new InvalidArgumentException('Partial extraction: collector_errors is not empty');
        }

        foreach (['site_url', 'home_url'] as $key) {
            if (!is_string($p[$key] ?? null) || !self::isSiteUrl($p[$key])) {
                throw new InvalidArgumentException("{$key}: expected an absolute http(s) URL");
            }
        }
        if (isset($p['admin_url']) && $p['admin_url'] !== '' && (!is_string($p['admin_url']) || !self::isSiteUrl($p['admin_url']))) {
            throw new InvalidArgumentException('admin_url: expected an absolute http(s) URL');
        }

        foreach (['extractor_version', 'generated_at', 'wp_version', 'site_locale', 'timezone_string', 'db_table_prefix',
            'multisite_type', 'database_type', 'database_version', 'os_family', 'architecture', 'active_theme'] as $key) {
            $this->optType($p, $key, '', '?string', self::MAX_SHORT);
        }
        foreach (['site_title', 'permalink_structure', 'web_server', 'document_root', 'website_administrator_email',
            'os_name', 'kernel_release'] as $key) {
            $this->optType($p, $key, '', '?string', 4_096);
        }
        foreach (['is_ssl', 'is_backend_ssl', 'is_multisite'] as $key) {
            $this->optType($p, $key, '', 'bool');
        }
        $this->optType($p, 'timezone_offset', '', 'number');
        $this->optType($p, 'multisite_count', '', 'int');
        $this->optType($p, 'page_count', '', 'int');
        $this->map($p, 'multisite_sites_status', '', 64, fn ($v, $k) => $this->scalar($v, 'multisite_sites_status.' . $k, 'int'));

        $this->section($p, 'php', function (array $php): void {
            $this->optStr($php, 'version', 'php', self::MAX_SHORT);
            $this->list($php, 'extensions', 'php', self::MAX_LIST, fn ($v, $i) => $this->scalar($v, "php.extensions.{$i}", 'string', self::MAX_SHORT));
            $this->list($php, 'disable_functions', 'php', self::MAX_LIST, fn ($v, $i) => $this->scalar($v, "php.disable_functions.{$i}", 'string', self::MAX_SHORT));
            // ini_get() answers a string, or false for an unknown directive.
            foreach (['max_execution_time', 'max_input_vars', 'post_max_size', 'upload_max_filesize', 'memory_limit'] as $key) {
                $this->optType($php, $key, 'php', 'scalar', self::MAX_SHORT);
            }
            $this->optType($php, 'upload_max_size', 'php', 'int');
        });

        $this->section($p, 'database', function (array $db): void {
            $this->optType($db, 'total_bytes', 'database', 'int');
            $this->list($db, 'tables', 'database', self::MAX_TABLES, function ($t, $i): void {
                $this->object($t, "database.tables.{$i}", function (array $t, string $path): void {
                    $this->optStr($t, 'name', $path, self::MAX_SHORT);
                    foreach (['size_bytes', 'row_count', 'overhead_bytes'] as $key) {
                        $this->optType($t, $key, $path, 'int');
                    }
                });
            });
            $this->section($db, 'transients', function (array $tr): void {
                $this->optType($tr, 'total', 'database.transients', 'int');
                $this->optType($tr, 'expired', 'database.transients', 'int');
            }, 'database');
        });

        $this->map($p, 'constants', '', 200, fn ($v, $k) => $this->scalar($v, "constants.{$k}", '?scalar', 4_096));

        $this->section($p, 'cron', function (array $c): void {
            $this->optType($c, 'disabled', 'cron', 'bool');
            foreach (['scheduled_events', 'overdue_events', 'distinct_hooks'] as $key) {
                $this->optType($c, $key, 'cron', 'int');
            }
            $this->optType($c, 'overdue_minutes', 'cron', '?int');
            $this->optType($c, 'next_event_gmt', 'cron', '?string', self::MAX_SHORT);
        });

        $this->section($p, 'object_cache', function (array $o): void {
            foreach (['external', 'dropin', 'page_cache'] as $key) {
                $this->optType($o, $key, 'object_cache', 'bool');
            }
        });

        $this->section($p, 'filesystem', function (array $fs): void {
            $this->optType($fs, 'disk_free_bytes', 'filesystem', 'number');
            $this->optType($fs, 'disk_total_bytes', 'filesystem', 'number');
            $this->optType($fs, 'core_writable', 'filesystem', 'bool');
            $this->optType($fs, 'uploads_writable', 'filesystem', 'bool');
            $this->map($fs, 'permissions', 'filesystem', 32, function ($perm, $k): void {
                $this->object($perm, "filesystem.permissions.{$k}", function (array $perm, string $path): void {
                    $this->optStr($perm, 'mode', $path, 8);
                    $this->optType($perm, 'writable', $path, 'bool');
                    $this->optType($perm, 'readable', $path, 'bool');
                });
            });
        });

        $this->section($p, 'mail', function (array $m): void {
            foreach (['sendmail_path', 'smtp', 'smtp_port', 'validation_hash'] as $key) {
                $this->optStr($m, $key, 'mail', 1_024);
            }
        });

        $this->section($p, 'core_update', function (array $cu): void {
            foreach (['available_version', 'status', 'minor_update_version'] as $key) {
                $this->optType($cu, $key, 'core_update', '?string', self::MAX_SHORT);
            }
            $this->optType($cu, 'auto_update_core', 'core_update', '?scalar', self::MAX_SHORT);
        });

        $this->map($p, 'plugins', '', self::MAX_PLUGINS, function ($plugin, $file): void {
            $path = 'plugins.' . $file;
            if (!self::isPluginFile($file)) {
                throw new InvalidArgumentException(self::label($path) . ': invalid plugin file');
            }
            $this->object($plugin, $path, function (array $pl, string $path) use ($file): void {
                if (($pl['slug'] ?? null) !== $file) {
                    throw new InvalidArgumentException(self::label($path) . '.slug: must equal its key');
                }
                $this->optStr($pl, 'name', $path, 1_024);
                foreach (['version', 'new_version', 'requires_wp', 'requires_php'] as $key) {
                    $this->optStr($pl, $key, $path, self::MAX_SHORT);
                }
                $this->optType($pl, 'network_activated', $path, 'bool');
                $this->optType($pl, 'active', $path, 'bool');
            });
        });

        foreach (['active_plugins', 'auto_update_plugins', 'plugin_updates'] as $key) {
            $this->list($p, $key, '', self::MAX_PLUGINS, fn ($v, $i) => $this->scalar($v, "{$key}.{$i}", 'string', self::MAX_KEY));
        }
        $this->list($p, 'theme_updates', '', self::MAX_THEMES, fn ($v, $i) => $this->scalar($v, "theme_updates.{$i}", 'string', self::MAX_KEY));

        foreach (['mu_plugins' => self::MAX_MU_PLUGINS, 'dropin_plugins' => self::MAX_DROPINS] as $key => $max) {
            $this->map($p, $key, '', $max, function ($headers, $file) use ($key): void {
                $path = $key . '.' . $file;
                if (!preg_match('#^[^/\\\\\x00-\x1f\x7f]+\.php$#', $file) || strlen($file) > self::MAX_KEY) {
                    throw new InvalidArgumentException(self::label($path) . ': invalid file name');
                }
                $this->object($headers, $path, function (array $h, string $path): void {
                    foreach ($h as $name => $value) {
                        $this->scalar($value, $path . '.' . $name, 'scalar', 8_192);
                    }
                });
            });
        }

        $this->map($p, 'themes', '', self::MAX_THEMES, function ($theme, $stylesheet): void {
            $path = 'themes.' . $stylesheet;
            if (!self::isThemeStylesheet($stylesheet)) {
                throw new InvalidArgumentException(self::label($path) . ': invalid theme stylesheet');
            }
            $this->object($theme, $path, function (array $t, string $path) use ($stylesheet): void {
                if (($t['slug'] ?? null) !== $stylesheet) {
                    throw new InvalidArgumentException(self::label($path) . '.slug: must equal its key');
                }
                foreach (['name', 'parent_name'] as $key) {
                    $this->optStr($t, $key, $path, 1_024);
                }
                foreach (['version', 'new_version', 'template', 'requires_wp', 'requires_php', 'parent_slug'] as $key) {
                    $this->optStr($t, $key, $path, self::MAX_SHORT);
                }
                $this->optType($t, 'active', $path, 'bool');
            });
        });

        $this->section($p, 'users_count', function (array $u): void {
            $this->optType($u, 'total_users', 'users_count', 'int');
            $this->map($u, 'avail_roles', 'users_count', 1_000, fn ($v, $k) => $this->scalar($v, "users_count.avail_roles.{$k}", 'int'));
        });

        foreach (['administrators', 'super_admins'] as $key) {
            $this->list($p, $key, '', self::MAX_LIST, function ($user, $i) use ($key): void {
                $this->object($user, "{$key}.{$i}", function (array $user, string $path): void {
                    $this->optType($user, 'id', $path, '?int');
                    $this->optStr($user, 'login', $path, self::MAX_SHORT);
                    $this->optStr($user, 'email', $path, self::MAX_SHORT);
                });
            });
        }

        // wp_count_posts()/wp_count_comments()/wp_count_attachments() objects:
        // counts arrive as ints or numeric strings depending on the database driver.
        foreach (['posts_count', 'comments_count', 'media_count'] as $key) {
            $this->map($p, $key, '', 1_000, fn ($v, $k) => $this->count($v, "{$key}.{$k}"));
        }
        $this->map($p, 'post_types', '', 1_000, fn ($v, $k) => $this->scalar($v, "post_types.{$k}", 'string', self::MAX_SHORT));
        $this->map($p, 'post_type_count', '', 1_000, function ($counts, $type): void {
            $this->map(['c' => $counts], 'c', 'post_type_count.' . $type, 1_000, fn ($v, $k) => $this->count($v, "post_type_count.{$type}.{$k}"));
        });

        $this->section($p, 'autoload', function (array $a): void {
            $this->optType($a, 'total_bytes', 'autoload', 'int');
            $this->optType($a, 'count', 'autoload', 'int');
            $this->list($a, 'top', 'autoload', 1_000, function ($row, $i): void {
                $this->object($row, "autoload.top.{$i}", function (array $row, string $path): void {
                    $this->optStr($row, 'name', $path, self::MAX_SHORT);
                    $this->optType($row, 'bytes', $path, 'int');
                });
            });
        });

        $this->map($p, 'connectors', '', 32, function ($connector, $key): void {
            $this->object($connector, 'connectors.' . $key, function (array $c, string $path): void {
                foreach (['version', 'db_version', 'provider', 'label', 'default_language', 'url_mode'] as $field) {
                    $this->optStr($c, $field, $path, self::MAX_SHORT);
                }
                foreach (['product_count', 'order_count'] as $field) {
                    $this->optType($c, $field, $path, 'int');
                }
                $this->optType($c, 'hpos_enabled', $path, 'bool');
                $this->optType($c, 'browser_redirect', $path, '?bool');
                foreach (['active_gateways', 'active_languages'] as $field) {
                    $this->list($c, $field, $path, 1_000, fn ($v, $i) => $this->scalar($v, "{$path}.{$field}.{$i}", 'string', self::MAX_SHORT));
                }
                $this->map($c, 'locales', $path, 1_000, fn ($v, $k) => $this->scalar($v, "{$path}.locales.{$k}", 'string', self::MAX_SHORT));
                $this->map($c, 'language_urls', $path, 1_000, function ($v, $k) use ($path): void {
                    $this->scalar($v, "{$path}.language_urls.{$k}", 'string', self::MAX_URL);
                    if ($v !== '' && !self::isSiteUrl($v)) {
                        throw new InvalidArgumentException(self::label("{$path}.language_urls.{$k}") . ': expected an absolute http(s) URL');
                    }
                });
            });
        });
    }

    /** @param array<string, mixed> $p */
    private function events(array $p): void
    {
        if (!is_array($p['events'] ?? null) || !array_is_list($p['events'])) {
            throw new InvalidArgumentException('Event payload requires an "events" array');
        }
        $this->list($p, 'events', '', self::MAX_EVENTS, function ($event, $i): void {
            $this->object($event, "events.{$i}", function (array $e, string $path): void {
                if (!is_string($e['event'] ?? null) || !preg_match('/^[a-z0-9_.:-]{1,64}$/', $e['event'])) {
                    throw new InvalidArgumentException(self::label($path) . '.event: expected an event name');
                }
                $this->optStr($e, 'event_id', $path, 64);
                $this->optStr($e, 'schema_version', $path, self::MAX_SHORT);
                $this->optType($e, 'actor_user_id', $path, '?int');
                $this->optStr($e, 'actor_login', $path, self::MAX_SHORT);
                $this->optStr($e, 'timestamp_gmt', $path, 32);
                // Details vary per event; nested structures are allowed but small.
                $this->bounded($e, $path, self::MAX_EVENT_STRING, 3);
            });
        });
    }

    /** @param array<string, mixed> $p */
    private function integrity(array $p): void
    {
        if (!is_array($p['integrity'] ?? null) || ($p['integrity'] !== [] && array_is_list($p['integrity']))) {
            throw new InvalidArgumentException('Integrity payload requires an "integrity" object');
        }
        $this->section($p, 'integrity', function (array $in): void {
            $this->optStr($in, 'version', 'integrity', self::MAX_SHORT);
            $this->optStr($in, 'locale', 'integrity', self::MAX_SHORT);
            $this->optStr($in, 'error', 'integrity', 4_096);
            $this->optType($in, 'checked', 'integrity', 'int');
            foreach (['modified', 'missing', 'unexpected', 'valid'] as $key) {
                $this->list($in, $key, 'integrity', self::MAX_INTEGRITY_FILES, fn ($v, $i) => $this->scalar($v, "integrity.{$key}.{$i}", 'string', 1_024));
            }
        });
    }

    // --- primitives -------------------------------------------------------

    /**
     * An optional object section: absent or null passes; anything else must
     * be an object (an empty PHP array encodes as []).
     *
     * @param array<array-key, mixed> $parent
     * @param callable(array<array-key, mixed>): void $check
     */
    private function section(array $parent, string $key, callable $check, string $at = ''): void
    {
        if (!isset($parent[$key])) {
            return;
        }
        $path = $at === '' ? $key : $at . '.' . $key;
        $this->object($parent[$key], $path, fn (array $v) => $check($v));
    }

    /** @param callable(array<array-key, mixed>, string): void $check */
    private function object(mixed $value, string $path, callable $check): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException(self::label($path) . ': expected an object');
        }
        $check($value, $path);
    }

    /**
     * Optional map: absent/null passes, else an object with string keys
     * (an empty one arrives as []).
     *
     * @param array<array-key, mixed> $parent
     * @param callable(mixed, string): void $check
     */
    private function map(array $parent, string $key, string $at, int $max, callable $check): void
    {
        if (!isset($parent[$key])) {
            return;
        }
        $path  = $at === '' ? $key : $at . '.' . $key;
        $value = $parent[$key];
        if (!is_array($value)) {
            throw new InvalidArgumentException(self::label($path) . ': expected an object');
        }
        if (count($value) > $max) {
            throw new InvalidArgumentException(self::label($path) . ": too many entries (max {$max})");
        }
        if ($value === []) {
            return;
        }
        foreach ($value as $k => $v) {
            // A numeric JSON key ("123") decodes to an int, which string-typed
            // code downstream cannot take.
            if (!is_string($k)) {
                throw new InvalidArgumentException(self::label($path) . ': keys must be non-numeric strings');
            }
            $check($v, $k);
        }
    }

    /**
     * @param array<array-key, mixed> $parent
     * @param callable(mixed, int): void $check
     */
    private function list(array $parent, string $key, string $at, int $max, callable $check): void
    {
        if (!isset($parent[$key])) {
            return;
        }
        $path  = $at === '' ? $key : $at . '.' . $key;
        $value = $parent[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(self::label($path) . ': expected a list');
        }
        if (count($value) > $max) {
            throw new InvalidArgumentException(self::label($path) . ": too many entries (max {$max})");
        }
        foreach ($value as $i => $v) {
            $check($v, $i);
        }
    }

    /** @param array<array-key, mixed> $parent */
    private function optStr(array $parent, string $key, string $at, int $max): void
    {
        $this->optType($parent, $key, $at, 'string', $max);
    }

    /** @param array<array-key, mixed> $parent */
    private function str(array $parent, string $key, string $at, int $max): void
    {
        $this->scalar($parent[$key] ?? null, $at === '' ? $key : $at . '.' . $key, 'string', $max);
    }

    /**
     * Absent passes; present must match. A nullable type ('?int') also takes null.
     *
     * @param array<array-key, mixed> $parent
     */
    private function optType(array $parent, string $key, string $at, string $type, int $max = self::MAX_STRING): void
    {
        if (!array_key_exists($key, $parent)) {
            return;
        }
        $this->scalar($parent[$key], $at === '' ? $key : $at . '.' . $key, $type, $max);
    }

    private function scalar(mixed $value, string $path, string $type, int $max = self::MAX_STRING): void
    {
        if (str_starts_with($type, '?')) {
            if ($value === null) {
                return;
            }
            $type = substr($type, 1);
        }
        $ok = match ($type) {
            'string' => is_string($value),
            'int'    => is_int($value),
            'number' => is_int($value) || is_float($value),
            'bool'   => is_bool($value),
            'scalar' => is_scalar($value),
            default  => false,
        };
        if (!$ok) {
            throw new InvalidArgumentException(self::label($path) . ": expected {$type}");
        }
        if (is_string($value) && strlen($value) > $max) {
            throw new InvalidArgumentException(self::label($path) . ": too long (max {$max})");
        }
    }

    private function count(mixed $value, string $path): void
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,18}$/', $value))) {
            throw new InvalidArgumentException(self::label($path) . ': expected a count');
        }
    }

    /**
     * Free-form subtree: scalars under $maxString, at most $maxDepth levels.
     *
     * @param array<array-key, mixed> $value
     */
    private function bounded(array $value, string $path, int $maxString, int $maxDepth): void
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                if ($maxDepth <= 1) {
                    throw new InvalidArgumentException(self::label("{$path}.{$k}") . ': nested too deep');
                }
                $this->bounded($v, "{$path}.{$k}", $maxString, $maxDepth - 1);
            } elseif ($v !== null) {
                $this->scalar($v, "{$path}.{$k}", 'scalar', $maxString);
            }
        }
    }

    /** The path as quoted back to the sender: payload keys are not echoed raw. */
    private static function label(string $path): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9_.\/-]/', '?', $path);

        return strlen($clean) > 120 ? substr($clean, 0, 120) . '…' : $clean;
    }
}
