<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Reference;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use SatelliteWP\Xtractor\Integration\WordfenceClient;
use SatelliteWP\Xtractor\Reference\WordfenceIndex;
use SatelliteWP\Xtractor\Tests\TestCase;

/**
 * buildIndex()/rangesInclude() are exercised against real records captured
 * live from the scanner feed (tests/fixtures/wordfence/scanner-samples.json)
 * — a wildcard "from" range, an explicit range, a theme, a core entry, and a
 * multi-software record all come from the actual API, not invented data.
 */
final class WordfenceIndexTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function samples(): array
    {
        return $this->fixtureArray('wordfence/scanner-samples.json');
    }

    private function cacheFile(): string
    {
        return $this->tmpDir . '/reference/wordfence.json';
    }

    /**
     * Reads the JSON Lines cache back as one map, for assertions about the
     * file itself (the production code streams it instead).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function readCache(): array
    {
        $index = [];
        foreach (file($this->cacheFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            $index[(string) $row['k']] = $row['v'];
        }

        return $index;
    }

    public function testBuildIndexKeysByTypeAndSlug(): void
    {
        $samples = $this->samples();
        $raw     = [
            $samples['single_plugin_wildcard']['id'] => $samples['single_plugin_wildcard'],
            $samples['theme']['id']                  => $samples['theme'],
            $samples['core']['id']                   => $samples['core'],
        ];

        $index = WordfenceIndex::buildIndex($raw, 'scanner');

        $this->assertArrayHasKey('plugin:opening-hours', $index);
        $this->assertArrayHasKey('theme:webenvo', $index);
        $this->assertArrayHasKey('core:wordpress', $index);
        $this->assertSame('scanner', $index['plugin:opening-hours'][0]['source']);
    }

    public function testWildcardFromVersionMatchesAnyLowerVersion(): void
    {
        // opening-hours: "*-1.37" — vulnerable from the very first release.
        $sample = $this->samples()['single_plugin_wildcard'];
        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write($this->cacheFile(), WordfenceIndex::buildIndex([$sample['id'] => $sample], 'scanner'));
        $index = new WordfenceIndex($this->cacheFile());

        $this->assertCount(1, $index->vulnerabilitiesFor('plugin', 'opening-hours', '1.0'));
        $this->assertCount(1, $index->vulnerabilitiesFor('plugin', 'opening-hours', '1.37'));
        $this->assertCount(0, $index->vulnerabilitiesFor('plugin', 'opening-hours', '1.38'));
    }

    public function testExplicitRangeRespectsBothBounds(): void
    {
        // easy-login-woocommerce inside the multi-software fixture: "2.7.1-2.7.2".
        $sample = $this->samples()['multi_software'];
        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write($this->cacheFile(), WordfenceIndex::buildIndex([$sample['id'] => $sample], 'production'));
        $index = new WordfenceIndex($this->cacheFile());

        $this->assertCount(0, $index->vulnerabilitiesFor('plugin', 'easy-login-woocommerce', '2.7.0'));
        $this->assertCount(1, $index->vulnerabilitiesFor('plugin', 'easy-login-woocommerce', '2.7.1'));
        $this->assertCount(1, $index->vulnerabilitiesFor('plugin', 'easy-login-woocommerce', '2.7.2'));
        $this->assertCount(0, $index->vulnerabilitiesFor('plugin', 'easy-login-woocommerce', '2.7.3'));
    }

    public function testMultiSoftwareRecordIndexesEveryComponentSeparately(): void
    {
        $sample = $this->samples()['multi_software'];
        $index  = WordfenceIndex::buildIndex([$sample['id'] => $sample], 'scanner');

        foreach (['waitlist-woocommerce', 'side-cart-woocommerce', 'easy-login-woocommerce', 'mobile-login-woocommerce'] as $slug) {
            $this->assertArrayHasKey("plugin:{$slug}", $index, "missing {$slug}");
        }
    }

    public function testPatchedVersionsCarryThroughToTheEntry(): void
    {
        $sample = $this->samples()['single_plugin_wildcard'];
        $index  = WordfenceIndex::buildIndex([$sample['id'] => $sample], 'scanner');

        $this->assertSame(['1.38'], $index['plugin:opening-hours'][0]['patched_versions']);
        $this->assertTrue($index['plugin:opening-hours'][0]['patched']);
    }

    public function testCaseInsensitiveSlugMatching(): void
    {
        $sample = $this->samples()['single_plugin_wildcard'];
        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write($this->cacheFile(), WordfenceIndex::buildIndex([$sample['id'] => $sample], 'scanner'));
        $index = new WordfenceIndex($this->cacheFile());

        $this->assertCount(1, $index->vulnerabilitiesFor('PLUGIN', 'Opening-Hours', '1.0'));
    }

    /**
     * One Wordfence record can list the same slug more than once — a plugin
     * sold in editions gets one software entry per edition. When those ranges
     * overlap, the same vulnerability id matched repeatedly and rendered as
     * "N CVE" for a single issue: seen live on
     * miniorange-oauth-oidc-single-sign-on 18.5.3, counted 7 times.
     */
    public function testSameVulnerabilityListedTwiceIsCountedOnce(): void
    {
        $record = [
            'id'    => 'dup-1',
            'title' => 'Editions plugin - Missing Authorization',
            'software' => [
                [
                    'type' => 'plugin', 'name' => 'Editions', 'slug' => 'editions',
                    'patched' => true, 'patched_versions' => ['7.9.0'],
                    'affected_versions' => ['a' => [
                        'from_version' => '1.0.0', 'from_inclusive' => true,
                        'to_version' => '9.0.0', 'to_inclusive' => true,
                    ]],
                ],
                [   // overlapping range for the same slug
                    'type' => 'plugin', 'name' => 'Editions', 'slug' => 'editions',
                    'patched' => true, 'patched_versions' => ['8.1.0'],
                    'affected_versions' => ['b' => [
                        'from_version' => '5.0.0', 'from_inclusive' => true,
                        'to_version' => '8.0.0', 'to_inclusive' => true,
                    ]],
                ],
            ],
        ];

        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write($this->cacheFile(), WordfenceIndex::buildIndex(['dup-1' => $record], 'scanner'));
        $index = new WordfenceIndex($this->cacheFile());

        // 6.0.0 falls in BOTH ranges — one vulnerability, reported once.
        $both = $index->vulnerabilitiesFor('plugin', 'editions', '6.0.0');
        $this->assertCount(1, $both);
        $this->assertSame('dup-1', $both[0]['id']);

        // A version in only one range still matches.
        $this->assertCount(1, $index->vulnerabilitiesFor('plugin', 'editions', '8.5.0'));
        $this->assertSame([], $index->vulnerabilitiesFor('plugin', 'editions', '9.5.0'));
    }

    /** Distinct vulnerabilities on the same component are all kept. */
    public function testDifferentVulnerabilitiesAreNotCollapsed(): void
    {
        $mk = static fn (string $id): array => [
            'id' => $id, 'title' => $id,
            'software' => [[
                'type' => 'plugin', 'slug' => 'thing', 'name' => 'Thing',
                'patched' => true, 'patched_versions' => ['2.0'],
                'affected_versions' => ['r' => [
                    'from_version' => '*', 'from_inclusive' => true,
                    'to_version' => '1.9', 'to_inclusive' => true,
                ]],
            ]],
        ];

        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write(
            $this->cacheFile(),
            WordfenceIndex::buildIndex(['a' => $mk('a'), 'b' => $mk('b')], 'scanner')
        );

        $this->assertCount(
            2,
            (new WordfenceIndex($this->cacheFile()))->vulnerabilitiesFor('plugin', 'thing', '1.0')
        );
    }

    public function testUnknownComponentReturnsEmpty(): void
    {
        mkdir(dirname($this->cacheFile()), 0775, true);
        file_put_contents($this->cacheFile(), '');
        $index = new WordfenceIndex($this->cacheFile());

        $this->assertSame([], $index->vulnerabilitiesFor('plugin', 'nonexistent-plugin', '1.0'));
    }

    public function testNullOrEmptyVersionNeverMatches(): void
    {
        $sample = $this->samples()['single_plugin_wildcard'];
        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write($this->cacheFile(), WordfenceIndex::buildIndex([$sample['id'] => $sample], 'scanner'));
        $index = new WordfenceIndex($this->cacheFile());

        $this->assertSame([], $index->vulnerabilitiesFor('plugin', 'opening-hours', null));
        $this->assertSame([], $index->vulnerabilitiesFor('plugin', 'opening-hours', ''));
    }

    public function testIsAvailableReflectsCacheFilePresence(): void
    {
        $index = new WordfenceIndex($this->cacheFile());
        $this->assertFalse($index->isAvailable());

        mkdir(dirname($this->cacheFile()), 0775, true);
        file_put_contents($this->cacheFile(), '');
        $this->assertTrue((new WordfenceIndex($this->cacheFile()))->isAvailable());
    }

    public function testRefreshWithoutAClientThrows(): void
    {
        $index = new WordfenceIndex($this->cacheFile());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        $index->refresh();
    }

    /**
     * Real-world case hit live on the very first refresh: both feeds came
     * back 429 (rate limited) before any cache ever existed. refresh() must
     * NOT leave behind an empty-but-present file — that would make
     * isAvailable() lie "refreshed" and the probe would report every site
     * clean instead of surfacing the real operational gap.
     */
    public function testFirstRefreshThatFullyFailsLeavesNoCacheFile(): void
    {
        $mock  = new MockHandler([
            new Response(429, [], (string) json_encode(['error' => 'rate limited'])),
            new Response(429, [], (string) json_encode(['error' => 'rate limited'])),
        ]);
        $http   = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $client = WordfenceClient::fromConfig(
            ['base_url' => 'https://www.wordfence.test/api/intelligence/v3', 'api_key' => 'k'],
            $http
        );
        $index = new WordfenceIndex($this->cacheFile(), $client);

        $result = $index->refresh();

        $this->assertCount(2, $result['errors']);
        $this->assertSame(0, $result['index_entries']);
        $this->assertFalse($index->isAvailable(), 'no cache file should be written');
        $this->assertFileDoesNotExist($this->cacheFile());
    }

    /**
     * A partial failure must carry forward ONLY the failing variant's old
     * entries. Feeding the whole previous cache back in re-appended the other
     * variant's stale entries on top of the fresh ones, so every
     * partial-failure run duplicated the variant that had just succeeded —
     * a week of scanner rate-limiting would have shown each CVE seven times.
     */
    public function testPartialFailureDoesNotDuplicateTheSuccessfulVariant(): void
    {
        $sample = $this->samples()['single_plugin_wildcard'];
        $raw    = [$sample['id'] => $sample];

        // Yesterday: both variants succeeded.
        mkdir(dirname($this->cacheFile()), 0775, true);
        WordfenceIndex::write($this->cacheFile(), array_merge_recursive(
            WordfenceIndex::buildIndex($raw, 'production'),
            WordfenceIndex::buildIndex($raw, 'scanner')
        ));

        // Today: production succeeds, scanner is rate limited.
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode($raw)),
            new Response(429, [], (string) json_encode(['error' => 'rate limited'])),
        ]);
        $http   = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $client = WordfenceClient::fromConfig(
            ['base_url' => 'https://www.wordfence.test/api/intelligence/v3', 'api_key' => 'k'],
            $http
        );

        (new WordfenceIndex($this->cacheFile(), $client))->refresh();

        $entries  = $this->readCache()['plugin:opening-hours'];
        $bySource = array_count_values(array_column($entries, 'source'));

        $this->assertSame(1, $bySource['production'], 'fresh production must not be duplicated');
        $this->assertSame(1, $bySource['scanner'], 'stale scanner entry must be preserved');
    }

    /** A partial failure that still has real data to write must write it. */
    public function testPartialFailureStillWritesTheSuccessfulVariant(): void
    {
        $sample = $this->samples()['single_plugin_wildcard'];
        $mock   = new MockHandler([
            new Response(200, [], (string) json_encode([$sample['id'] => $sample])), // production
            new Response(429, [], (string) json_encode(['error' => 'rate limited'])), // scanner
        ]);
        $http   = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]);
        $client = WordfenceClient::fromConfig(
            ['base_url' => 'https://www.wordfence.test/api/intelligence/v3', 'api_key' => 'k'],
            $http
        );
        $index = new WordfenceIndex($this->cacheFile(), $client);

        $result = $index->refresh();

        $this->assertCount(1, $result['errors']);
        $this->assertGreaterThan(0, $result['index_entries']);
        $this->assertTrue($index->isAvailable());
        $this->assertNotEmpty($index->vulnerabilitiesFor('plugin', 'opening-hours', '1.0'));
    }
}
