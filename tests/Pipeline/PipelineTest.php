<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Pipeline;

use RuntimeException;
use SatelliteWP\Xtractor\Domain\ProbeResult;
use SatelliteWP\Xtractor\Domain\SiteContext;
use SatelliteWP\Xtractor\Pipeline\Pipeline;
use SatelliteWP\Xtractor\Probe\AbstractProbe;
use SatelliteWP\Xtractor\Probe\ProbeRegistry;
use SatelliteWP\Xtractor\Storage\DataStore;
use SatelliteWP\Xtractor\Storage\Index;
use SatelliteWP\Xtractor\Tests\TestCase;

final class PipelineTest extends TestCase
{
    private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

    private DataStore $store;
    private Index $index;
    private string $extractionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DataStore($this->tmpDir);
        $this->index = new Index($this->tmpDir . '/index.sqlite');

        $body = $this->fixture('extraction-valid.json');
        $this->extractionId = $this->store->storeExtraction(self::SITE_ID, $body, [
            'received_at' => '2026-07-22T14:30:00Z',
        ]);
        $this->index->insertExtraction(
            self::SITE_ID,
            $this->extractionId,
            '2026-07-22T14:30:00Z',
            $this->fixtureArray('extraction-valid.json')
        );
    }

    private function pipeline(ProbeRegistry $registry): Pipeline
    {
        return new Pipeline($registry, $this->store, $this->index);
    }

    public function testFailingProbeDoesNotStopTheRun(): void
    {
        $registry = new ProbeRegistry(['boom', 'fine']);
        $registry->register(new ThrowingProbe());
        $registry->register(new StubProbe('fine', ProbeResult::STATUS_OK));

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId);

        $this->assertSame(ProbeResult::STATUS_ERROR, $results['boom']->status);
        $this->assertStringContainsString('RuntimeException', $results['boom']->errors[0]);
        $this->assertSame(ProbeResult::STATUS_OK, $results['fine']->status);

        // Both probe files were written.
        $this->assertNotNull($this->store->readProbeResult(self::SITE_ID, $this->extractionId, 'boom'));
        $this->assertNotNull($this->store->readProbeResult(self::SITE_ID, $this->extractionId, 'fine'));

        // Extraction is done, probe_runs reflect statuses.
        $row = $this->index->getExtraction(self::SITE_ID, $this->extractionId);
        $this->assertSame('done', $row['status']);

        $runs = $this->index->listProbeRuns(self::SITE_ID, $this->extractionId);
        $this->assertCount(2, $runs);
    }

    public function testWritesNeutralFindingsWhenRuleEnginePresent(): void
    {
        $rules  = \SatelliteWP\Xtractor\Rules\RuleCatalog::load(dirname(__DIR__, 2) . '/config/rules.php');
        $engine = new \SatelliteWP\Xtractor\Rules\RuleEngine($rules);

        $registry = new ProbeRegistry(['fine']);
        $registry->register(new StubProbe('fine', ProbeResult::STATUS_WARN));

        $pipeline = new Pipeline($registry, $this->store, $this->index, $engine);
        $pipeline->run(self::SITE_ID, $this->extractionId);

        $findings = $this->store->readFindings(self::SITE_ID, $this->extractionId);
        $this->assertNotNull($findings);
        $this->assertArrayHasKey('counts', $findings);

        // findings.json is language-neutral: no rendered sentences on disk.
        $first = $findings['findings'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('status', $first);
        $this->assertArrayNotHasKey('message', $first);
        $this->assertArrayNotHasKey('title', $first);
    }

    public function testNoFindingsFileWithoutRuleEngine(): void
    {
        $registry = new ProbeRegistry(['fine']);
        $registry->register(new StubProbe('fine', ProbeResult::STATUS_OK));

        $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId);

        $this->assertNull($this->store->readFindings(self::SITE_ID, $this->extractionId));
    }

    public function testOnlyProbesFilterRunsSubset(): void
    {
        $registry = new ProbeRegistry(['a', 'b']);
        $registry->register(new StubProbe('a', ProbeResult::STATUS_OK));
        $registry->register(new StubProbe('b', ProbeResult::STATUS_OK));

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId, ['b']);

        $this->assertSame(['b'], array_keys($results));
        $this->assertNull($this->store->readProbeResult(self::SITE_ID, $this->extractionId, 'a'));
    }

    public function testUnknownExtractionThrows(): void
    {
        $registry = new ProbeRegistry([]);

        $this->expectException(RuntimeException::class);

        $this->pipeline($registry)->run(self::SITE_ID, '19990101T000000Z');
    }
}

final class StubProbe extends AbstractProbe
{
    public function __construct(
        private readonly string $probeName,
        private readonly string $status,
    ) {
    }

    public function name(): string
    {
        return $this->probeName;
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        return ['data' => ['stub' => true], 'status' => $this->status];
    }
}

final class ThrowingProbe extends AbstractProbe
{
    public function name(): string
    {
        return 'boom';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        throw new RuntimeException('kaboom');
    }
}
