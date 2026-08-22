<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Probe;

use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Probe\WordfenceProbe;
use SatelliteWP\Xtractor\Reference\WordfenceIndex;
use SatelliteWP\Xtractor\Tests\TestCase;

final class WordfenceProbeTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function samples(): array
    {
        return $this->fixtureArray('wordfence/scanner-samples.json');
    }

    private function indexWith(array $raw): WordfenceIndex
    {
        $file = $this->tmpDir . '/reference/wordfence.json';
        mkdir(dirname($file), 0775, true);
        WordfenceIndex::write($file, WordfenceIndex::buildIndex($raw, 'scanner'));

        return new WordfenceIndex($file);
    }

    private function site(array $plugins = [], array $themes = [], ?string $wpVersion = null): SiteContext
    {
        return new SiteContext('site-1', 'https://ex.com', 'https://ex.com', 'ex.com', 'ex.com', $plugins, $themes, $wpVersion);
    }

    public function testUnconfiguredReportsError(): void
    {
        $probe  = new WordfenceProbe(null);
        $result = $probe->run($this->site());

        $this->assertSame(ProbeResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('not configured', $result->errors[0]);
    }

    public function testMissingCacheReportsError(): void
    {
        $index  = new WordfenceIndex($this->tmpDir . '/reference/wordfence.json'); // never refreshed
        $probe  = new WordfenceProbe($index);
        $result = $probe->run($this->site());

        $this->assertSame(ProbeResult::STATUS_ERROR, $result->status);
        $this->assertStringContainsString('wordfence:refresh', $result->errors[0]);
    }

    public function testVulnerablePluginIsMatchedByNormalizedSlugAndVersion(): void
    {
        $sample = $this->samples()['single_plugin_wildcard']; // opening-hours, <=1.37
        $index  = $this->indexWith([$sample['id'] => $sample]);
        $probe  = new WordfenceProbe($index);

        // Payload slugs are "folder/file.php" — SoftwareCatalog::normalizeSlug
        // must turn this into "opening-hours" to match Wordfence's bare slug.
        $site = $this->site(plugins: [
            ['slug' => 'opening-hours/opening-hours.php', 'name' => 'We’re Open!', 'version' => '1.20'],
        ]);

        $result = $probe->run($site);

        $this->assertSame(ProbeResult::STATUS_WARN, $result->status);
        $this->assertSame(1, $result->data['plugins']['vulnerable_count']);
        $this->assertSame(1, $result->data['vulnerabilities_total']);
        $this->assertSame('opening-hours', $result->data['plugins']['items'][0]['slug']);
        $this->assertCount(1, $result->data['plugins']['items'][0]['vulnerabilities']);
    }

    public function testPatchedVersionIsNotFlagged(): void
    {
        $sample = $this->samples()['single_plugin_wildcard']; // patched in 1.38
        $index  = $this->indexWith([$sample['id'] => $sample]);
        $probe  = new WordfenceProbe($index);

        $site = $this->site(plugins: [
            ['slug' => 'opening-hours/opening-hours.php', 'name' => 'We’re Open!', 'version' => '1.38'],
        ]);

        $result = $probe->run($site);

        $this->assertSame(ProbeResult::STATUS_OK, $result->status);
        $this->assertSame(0, $result->data['plugins']['vulnerable_count']);
        $this->assertSame([], $result->data['plugins']['items'][0]['vulnerabilities']);
    }

    public function testThemeMatchingUsesBareSlugDirectly(): void
    {
        $sample = $this->samples()['theme']; // webenvo, <=0.0.6
        $index  = $this->indexWith([$sample['id'] => $sample]);
        $probe  = new WordfenceProbe($index);

        $site = $this->site(themes: [
            ['slug' => 'webenvo', 'name' => 'Webenvo', 'version' => '0.0.5'],
        ]);

        $result = $probe->run($site);

        $this->assertSame(1, $result->data['themes']['vulnerable_count']);
    }

    public function testCoreMatchesAgainstWordpressSlug(): void
    {
        $sample = $this->samples()['core']; // WordPress core, <=3.3
        $index  = $this->indexWith([$sample['id'] => $sample]);
        $probe  = new WordfenceProbe($index);

        $result = $probe->run($this->site(wpVersion: '3.2'));

        $this->assertCount(1, $result->data['core']['vulnerabilities']);
        $this->assertSame(ProbeResult::STATUS_WARN, $result->status);
    }

    public function testMissingWpVersionSkipsCoreMatchingWithoutError(): void
    {
        $sample = $this->samples()['core'];
        $index  = $this->indexWith([$sample['id'] => $sample]);
        $probe  = new WordfenceProbe($index);

        $result = $probe->run($this->site(wpVersion: null));

        $this->assertSame([], $result->data['core']['vulnerabilities']);
    }

    public function testCleanSiteReportsOkWithZeroTotals(): void
    {
        $sample = $this->samples()['single_plugin_wildcard'];
        $index  = $this->indexWith([$sample['id'] => $sample]);
        $probe  = new WordfenceProbe($index);

        $site = $this->site(plugins: [
            ['slug' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.6'], // not in the index at all
        ]);

        $result = $probe->run($site);

        $this->assertSame(ProbeResult::STATUS_OK, $result->status);
        $this->assertSame(0, $result->data['vulnerabilities_total']);
        $this->assertSame([], $result->data['plugins']['items'][0]['vulnerabilities']);
    }

    public function testPluginWithoutAVersionIsSkippedNotCrashed(): void
    {
        $index = $this->indexWith([]);
        $probe = new WordfenceProbe($index);

        $site = $this->site(plugins: [['slug' => 'no-version/no-version.php', 'name' => 'X']]);

        $result = $probe->run($site);

        $this->assertSame(ProbeResult::STATUS_OK, $result->status);
        $this->assertSame(0, $result->data['plugins']['total']);
    }
}
