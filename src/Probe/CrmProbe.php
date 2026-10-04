<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Probe;

use Closure;
use PDOException;
use SatelliteWP\Manager\Crm\ClientsRepository;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;

/**
 * Snapshot of the CRM side of a site: the client(s) and maintenance plan
 * reached from the BlogVault site id through website → subscription → client.
 * SELECT only, and only at probe time — display and reports read the stored file.
 */
final class CrmProbe extends AbstractProbe
{
    /** @param Closure(): ?ClientsRepository $repository null when the CRM database is not configured */
    public function __construct(private readonly Closure $repository)
    {
    }

    public function name(): string
    {
        return 'crm';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        $blogvaultId = $site->blogvaultSiteId;
        if ($blogvaultId === null || $blogvaultId === '') {
            return ['target' => 'crm', 'data' => ['linked' => false, 'reason' => 'no_blogvault_id']];
        }

        $repository = ($this->repository)();
        if ($repository === null) {
            return ['target' => 'crm', 'status' => ProbeResult::STATUS_ERROR, 'errors' => ['CRM database is not configured (crm_db)']];
        }

        try {
            $websites      = $repository->websitesByBlogvaultSiteId($blogvaultId);
            $subscriptions = count($websites) === 1 ? $repository->subscriptionsForWebsite((int) $websites[0]['id']) : [];
        } catch (PDOException $e) {
            // The driver message can carry host and user names: keep the SQLSTATE only.
            return ['target' => 'crm', 'status' => ProbeResult::STATUS_ERROR, 'errors' => ['CRM database query failed (SQLSTATE ' . (string) $e->getCode() . ')']];
        }

        return ['target' => 'crm', 'data' => self::build($websites, $subscriptions)];
    }

    /**
     * @param list<array<string, mixed>> $websites CRM websites matching the BlogVault site id
     * @param list<array<string, mixed>> $subscriptions ClientsRepository::subscriptionsForWebsite() rows
     * @return array<string, mixed>
     */
    public static function build(array $websites, array $subscriptions): array
    {
        if ($websites === []) {
            return ['linked' => false, 'reason' => 'website_not_found'];
        }
        if (count($websites) > 1) {
            return ['linked' => false, 'reason' => 'website_ambiguous'];
        }

        $website = $websites[0];
        $clients = [];
        $entries = [];
        foreach ($subscriptions as $row) {
            $clientId = (int) $row['client_id'];
            $clients[$clientId] ??= [
                'id'         => $clientId,
                'label'      => ClientsRepository::clientLabel($row),
                'company'    => self::text($row['company'] ?? null),
                'first_name' => self::text($row['first_name'] ?? null),
                'last_name'  => self::text($row['last_name'] ?? null),
            ];

            $isPlan    = (int) ($row['is_maintenance_plan'] ?? 0) === 1;
            $entries[] = [
                'id'           => (int) $row['id'],
                'client_id'    => $clientId,
                'product'      => (string) ($row['product_name'] ?? ''),
                'kind'         => $isPlan ? 'maintenance_plan' : (self::text($row['license_slug'] ?? null) !== null ? 'license' : 'other'),
                'status'       => (string) ($row['subscription_status'] ?? ''),
                'created'      => self::text($row['creation_date'] ?? null),
                'next_renewal' => self::text($row['next_renewal_date'] ?? null),
            ];
        }

        $data = [
            'linked'           => $clients !== [],
            'website'          => [
                'id'        => (int) $website['id'],
                'url'       => (string) ($website['url'] ?? ''),
                'synced_at' => self::text($website['date_sync'] ?? null),
            ],
            'clients'          => array_values($clients),
            'subscriptions'    => $entries,
            'maintenance_plan' => self::activePlan($entries),
        ];
        if ($clients === []) {
            $data['reason'] = 'no_client';
        }

        return $data;
    }

    /**
     * The one active maintenance plan, or null when there is none or several
     * (an ambiguous plan is not guessed).
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private static function activePlan(array $entries): ?array
    {
        $active = array_values(array_filter(
            $entries,
            static fn (array $e): bool => $e['kind'] === 'maintenance_plan' && $e['status'] === 'active'
        ));
        if (count($active) !== 1) {
            return null;
        }

        return [
            'subscription_id' => $active[0]['id'],
            'name'            => $active[0]['product'],
            'next_renewal'    => $active[0]['next_renewal'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
