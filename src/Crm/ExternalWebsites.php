<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Crm;

use InvalidArgumentException;
use SatelliteWP\Manager\Support\JsonFileStore;
use SatelliteWP\Manager\Support\SiteDisplay;

/**
 * Non-WordPress websites, kept by Manager because swp_websites only holds
 * what BlogVault syncs. Each one carries the subscriptions linked to it, so
 * a subscription sold for such a site counts as linked without any CRM
 * write. A subscription has one website at most: linking it here is paired
 * with removing its swp_subscriptions_websites row, and the reverse.
 *
 * Ids are their own sequence, shown with ID_PREFIX ("x3") so they never read
 * as a swp_websites id.
 */
final class ExternalWebsites
{
    public const string ID_PREFIX = 'x';

    private readonly JsonFileStore $store;

    public function __construct(string $file)
    {
        $this->store = new JsonFileStore($file, 0640);
    }

    /** @return int|null the external id behind "x3", null for anything else */
    public static function parseId(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^' . self::ID_PREFIX . '([1-9]\d{0,9})$/', $value, $m) === 1 ? (int) $m[1] : null;
    }

    public static function publicId(int $id): string
    {
        return self::ID_PREFIX . $id;
    }

    /** Comparison key of a URL: host and path, without scheme, "www." or trailing slash. */
    public static function urlKey(string $url): string
    {
        return strtolower(SiteDisplay::of($url));
    }

    /** @return string|null the URL trimmed, or null when it is not an absolute http(s) URL without credentials */
    public static function validUrl(string $url): ?string
    {
        $url   = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1 || strlen($url) > 255) {
            return null;
        }

        return $url;
    }

    /** @return list<array{id: int, url: string, subscription_ids: list<int>, created_by: string, created_at: string}> sorted by URL */
    public function all(): array
    {
        $websites = [];
        foreach ((array) ($this->store->read()['websites'] ?? []) as $w) {
            if (is_array($w) && is_int($w['id'] ?? null) && is_string($w['url'] ?? null)) {
                $websites[] = [
                    'id'               => $w['id'],
                    'url'              => $w['url'],
                    'subscription_ids' => array_values(array_filter((array) ($w['subscription_ids'] ?? []), 'is_int')),
                    'created_by'       => (string) ($w['created_by'] ?? ''),
                    'created_at'       => (string) ($w['created_at'] ?? ''),
                ];
            }
        }
        usort($websites, static fn (array $a, array $b): int => strcmp(self::urlKey($a['url']), self::urlKey($b['url'])));

        return $websites;
    }

    /** @return array{id: int, url: string, subscription_ids: list<int>, created_by: string, created_at: string}|null */
    public function get(int $id): ?array
    {
        foreach ($this->all() as $w) {
            if ($w['id'] === $id) {
                return $w;
            }
        }

        return null;
    }

    /** @return array<int, int> subscription id => external website id */
    public function links(): array
    {
        $links = [];
        foreach ($this->all() as $w) {
            foreach ($w['subscription_ids'] as $subscriptionId) {
                $links[$subscriptionId] = $w['id'];
            }
        }

        return $links;
    }

    /**
     * @param list<string> $crmUrls swp_websites URLs, which an external website may not duplicate
     * @return int the new id
     * @throws InvalidArgumentException 'invalid' or 'duplicate'
     */
    public function add(string $url, array $crmUrls, string $by): int
    {
        $url = self::validUrl($url) ?? throw new InvalidArgumentException('invalid');

        return $this->store->mutate(function (array $doc) use ($url, $crmUrls, $by): array {
            $this->assertUnique($doc, $url, $crmUrls, null);
            $id                = max(1, (int) ($doc['next_id'] ?? 1));
            $doc['next_id']    = $id + 1;
            $doc['websites']   = array_values((array) ($doc['websites'] ?? []));
            $doc['websites'][] = [
                'id'               => $id,
                'url'              => $url,
                'subscription_ids' => [],
                'created_by'       => $by,
                'created_at'       => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            return [$id, $doc];
        });
    }

    /**
     * @param list<string> $crmUrls
     * @return bool false when the website does not exist
     * @throws InvalidArgumentException 'invalid' or 'duplicate'
     */
    public function updateUrl(int $id, string $url, array $crmUrls): bool
    {
        $url = self::validUrl($url) ?? throw new InvalidArgumentException('invalid');

        return $this->store->mutate(function (array $doc) use ($id, $url, $crmUrls): array {
            $this->assertUnique($doc, $url, $crmUrls, $id);
            foreach ((array) ($doc['websites'] ?? []) as $i => $w) {
                if (($w['id'] ?? null) === $id) {
                    $doc['websites'][$i]['url'] = $url;

                    return [true, $doc];
                }
            }

            return [false, null];
        });
    }

    /**
     * @param list<int> $existingSubscriptionIds those of its subscriptions still in the CRM: a
     *                                           subscription deleted there does not hold the website
     * @return bool false when the website does not exist or still has subscriptions
     */
    public function delete(int $id, array $existingSubscriptionIds): bool
    {
        return $this->store->mutate(static function (array $doc) use ($id, $existingSubscriptionIds): array {
            foreach ((array) ($doc['websites'] ?? []) as $i => $w) {
                if (($w['id'] ?? null) === $id) {
                    if (array_intersect((array) ($w['subscription_ids'] ?? []), $existingSubscriptionIds) !== []) {
                        return [false, null];
                    }
                    unset($doc['websites'][$i]);
                    $doc['websites'] = array_values($doc['websites']);

                    return [true, $doc];
                }
            }

            return [false, null];
        });
    }

    /**
     * Links a subscription to one external website, or with null to none,
     * removing it from any other.
     *
     * @return bool false when $websiteId does not exist (nothing changed)
     */
    public function link(int $subscriptionId, ?int $websiteId): bool
    {
        return $this->store->mutate(static function (array $doc) use ($subscriptionId, $websiteId): array {
            $websites = array_values((array) ($doc['websites'] ?? []));
            if ($websiteId !== null && !in_array($websiteId, array_column($websites, 'id'), true)) {
                return [false, null];
            }

            $changed = false;
            foreach ($websites as $i => $w) {
                $ids  = array_values(array_filter((array) ($w['subscription_ids'] ?? []), 'is_int'));
                $want = ($w['id'] ?? null) === $websiteId;
                $has  = in_array($subscriptionId, $ids, true);
                if ($want && !$has) {
                    $ids[] = $subscriptionId;
                    sort($ids);
                } elseif (!$want && $has) {
                    $ids = array_values(array_diff($ids, [$subscriptionId]));
                } else {
                    continue;
                }
                $websites[$i]['subscription_ids'] = $ids;
                $changed                          = true;
            }
            $doc['websites'] = $websites;

            return [true, $changed ? $doc : null];
        });
    }

    /**
     * @param array<mixed> $doc
     * @param list<string> $crmUrls
     */
    private function assertUnique(array $doc, string $url, array $crmUrls, ?int $exceptId): void
    {
        $key = self::urlKey($url);
        foreach ((array) ($doc['websites'] ?? []) as $w) {
            if (($w['id'] ?? null) !== $exceptId && self::urlKey((string) ($w['url'] ?? '')) === $key) {
                throw new InvalidArgumentException('duplicate');
            }
        }
        foreach ($crmUrls as $crmUrl) {
            if (self::urlKey($crmUrl) === $key) {
                throw new InvalidArgumentException('duplicate');
            }
        }
    }
}
