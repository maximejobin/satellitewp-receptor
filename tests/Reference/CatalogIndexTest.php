<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Reference;

use SatelliteWP\Xtractor\Catalog\SoftwareCatalog;
use SatelliteWP\Xtractor\Reference\CatalogIndex;
use SatelliteWP\Xtractor\Reference\WordfenceIndex;
use SatelliteWP\Xtractor\Tests\TestCase;

final class CatalogIndexTest extends TestCase
{
    private function cacheFile(): string
    {
        return $this->tmpDir . '/reference/wordfence.json';
    }

    private function writeCache(array $raw, string $variant = 'production'): void
    {
        if (!is_dir(dirname($this->cacheFile()))) {
            mkdir(dirname($this->cacheFile()), 0775, true);
        }
        WordfenceIndex::write($this->cacheFile(), WordfenceIndex::buildIndex($raw, $variant));
    }

    private function index(): CatalogIndex
    {
        return new CatalogIndex($this->tmpDir . '/reference/catalog-index.sqlite');
    }

    /** @return array<string, mixed> */
    private static function record(string $id, string $slug, array $overrides = []): array
    {
        return array_merge([
            'id'    => $id,
            'title' => "Issue {$id}",
            'cve'   => null,
            'software' => [[
                'type' => 'plugin', 'slug' => $slug, 'name' => ucfirst($slug),
                'patched' => true, 'patched_versions' => ['2.0'],
                'affected_versions' => ['r' => [
                    'from_version' => '*', 'from_inclusive' => true,
                    'to_version' => '1.9', 'to_inclusive' => true,
                ]],
            ]],
        ], $overrides);
    }

    public function testRebuildVulnerabilitiesIndexesEveryRow(): void
    {
        $this->writeCache([
            'a' => self::record('a', 'alpha'),
            'b' => self::record('b', 'bravo'),
            'c' => self::record('c', 'charlie'),
        ]);

        $index = $this->index();
        $count = $index->rebuildVulnerabilities($this->cacheFile());

        $this->assertSame(3, $count);
        $this->assertSame(3, (int) $index->pdo()->query('SELECT COUNT(*) FROM vulnerabilities')->fetchColumn());
    }

    public function testRebuildVulnerabilitiesOnMissingCacheLeavesTableEmpty(): void
    {
        $index = $this->index();
        $count = $index->rebuildVulnerabilities($this->cacheFile());

        $this->assertSame(0, $count);
    }

    public function testRebuildVulnerabilitiesReplacesPreviousContent(): void
    {
        $this->writeCache(['a' => self::record('a', 'alpha')]);
        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());

        $this->writeCache(['b' => self::record('b', 'bravo')]);
        $count = $index->rebuildVulnerabilities($this->cacheFile());

        $this->assertSame(1, $count);
        $rows = $index->pdo()->query('SELECT slug FROM vulnerabilities')->fetchAll();
        $this->assertSame([['slug' => 'bravo']], $rows);
    }

    public function testVulnerabilityCountMatchesTypeAndSlug(): void
    {
        $this->writeCache(['a' => self::record('a', 'alpha')]);
        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());

        $this->assertSame(1, $index->vulnerabilityCount('plugin', 'alpha'));
        $this->assertSame(0, $index->vulnerabilityCount('plugin', 'bravo'));
        $this->assertSame(0, $index->vulnerabilityCount('theme', 'alpha'));
    }

    public function testSearchVulnerabilitiesFiltersBySlugTitleOrCve(): void
    {
        $this->writeCache([
            'v-1' => self::record('v-1', 'alpha-plugin', [
                'title' => 'SQL Injection in Alpha Plugin', 'cve' => 'CVE-2026-1234',
            ]),
            'v-2' => self::record('v-2', 'bravo-plugin', ['title' => 'Unrelated issue']),
        ]);
        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());

        $this->assertSame(2, $index->searchVulnerabilities('', 0, 100)['total']);

        $bySlug = $index->searchVulnerabilities('alpha-plugin', 0, 100);
        $this->assertSame(1, $bySlug['filtered']);
        $this->assertSame('alpha-plugin', $bySlug['rows'][0]['slug']);

        $byCve = $index->searchVulnerabilities('cve-2026-1234', 0, 100); // case-insensitive
        $this->assertSame(1, $byCve['filtered']);

        $byTitle = $index->searchVulnerabilities('unrelated', 0, 100);
        $this->assertSame(1, $byTitle['filtered']);
        $this->assertSame('bravo-plugin', $byTitle['rows'][0]['slug']);

        $this->assertSame(0, $index->searchVulnerabilities('no-such-match', 0, 100)['filtered']);
    }

    public function testSearchVulnerabilitiesPaginates(): void
    {
        $this->writeCache([
            'a' => self::record('a', 'alpha'),
            'b' => self::record('b', 'bravo'),
            'c' => self::record('c', 'charlie'),
        ]);
        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());

        $page1 = $index->searchVulnerabilities('', 0, 2);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(3, $page1['filtered']);
        $this->assertCount(2, $page1['rows']);

        $page2 = $index->searchVulnerabilities('', 2, 2);
        $this->assertCount(1, $page2['rows']);
    }

    public function testSearchVulnerabilitiesSortsByRequestedColumn(): void
    {
        $this->writeCache([
            'a' => self::record('a', 'zeta'),
            'b' => self::record('b', 'alpha'),
        ]);
        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());

        $asc = $index->searchVulnerabilities('', 0, 100, 'name', 'asc');
        $this->assertSame('Alpha', $asc['rows'][0]['name']);

        $desc = $index->searchVulnerabilities('', 0, 100, 'name', 'desc');
        $this->assertSame('Zeta', $desc['rows'][0]['name']);
    }

    public function testSearchVulnerabilitiesRejectsUnknownSortColumn(): void
    {
        $this->writeCache(['a' => self::record('a', 'alpha')]);
        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());

        // An unrecognised column name must not reach raw SQL — falls back to
        // the default order instead of erroring.
        $result = $index->searchVulnerabilities('', 0, 100, 'slug; DROP TABLE vulnerabilities;--', 'asc');
        $this->assertSame(1, $result['filtered']);
    }

    public function testUpsertCatalogEntryThenRebuildCatalogPreservesNotes(): void
    {
        $catalogFile = $this->tmpDir . '/catalog/software.json';
        $catalog     = new SoftwareCatalog($catalogFile);
        $catalog->recordExtraction(['plugins' => [
            'woocommerce/woocommerce.php' => ['slug' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce'],
        ]]);

        $index = $this->index();
        $index->rebuildCatalog($catalog);

        // Simulate a future notes feature writing directly to the index row
        // (the class docblock explains why: nothing does this yet).
        $index->pdo()->exec("UPDATE software_catalog SET notes = 'Client-specific fork, do not auto-update' "
            . "WHERE type = 'plugin' AND slug = 'woocommerce'");

        $catalog->setLicense('plugin', 'woocommerce', SoftwareCatalog::LICENSE_PREMIUM);
        $index->rebuildCatalog($catalog);

        $row = $index->pdo()->query(
            "SELECT license, notes FROM software_catalog WHERE type = 'plugin' AND slug = 'woocommerce'"
        )->fetch();

        $this->assertSame(SoftwareCatalog::LICENSE_PREMIUM, $row['license']);
        $this->assertSame('Client-specific fork, do not auto-update', $row['notes']);
    }

    public function testCrossReferenceFindsCatalogEntriesWithOpenVulnerabilities(): void
    {
        $this->writeCache(['a' => self::record('a', 'woocommerce')]);

        $catalog = new SoftwareCatalog($this->tmpDir . '/catalog/software.json');
        $catalog->recordExtraction(['plugins' => [
            'woocommerce/woocommerce.php' => ['slug' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce'],
            'akismet/akismet.php'         => ['slug' => 'akismet/akismet.php', 'name' => 'Akismet'],
        ]]);

        $index = $this->index();
        $index->rebuildVulnerabilities($this->cacheFile());
        $index->rebuildCatalog($catalog);

        $this->assertTrue($index->vulnerabilityCount('plugin', 'woocommerce') > 0);
        $this->assertSame(0, $index->vulnerabilityCount('plugin', 'akismet'));
    }
}
