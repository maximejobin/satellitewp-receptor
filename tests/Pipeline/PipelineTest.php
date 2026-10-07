<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Pipeline;

use RuntimeException;
use SatelliteWP\Manager\Domain\ProbeResult;
use SatelliteWP\Manager\Domain\SiteContext;
use SatelliteWP\Manager\Pipeline\Pipeline;
use SatelliteWP\Manager\Probe\AbstractProbe;
use SatelliteWP\Manager\Probe\ProbeRegistry;
use SatelliteWP\Manager\Storage\DataStore;
use SatelliteWP\Manager\Storage\Index;
use SatelliteWP\Manager\Tests\TestCase;

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
        $rules  = \SatelliteWP\Manager\Rules\RuleCatalog::load(dirname(__DIR__, 2) . '/config/rules.php');
        $engine = new \SatelliteWP\Manager\Rules\RuleEngine($rules);

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

    public function testCrmProbeReceivesTheBlogvaultIdFromThisRunOrTheStoredFile(): void
    {
        $registry = new ProbeRegistry(['blogvault', 'crm']);
        $registry->register(new BlogvaultIdProbe('abc123'));
        $registry->register(new SiteIdEchoProbe());

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId);
        $this->assertSame('abc123', $results['crm']->data['blogvault_site_id']);

        // A lone re-run of crm reads the id from the stored blogvault probe.
        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId, ['crm']);
        $this->assertSame('abc123', $results['crm']->data['blogvault_site_id']);
    }

    public function testCrmProbeGetsNoIdWhenBlogvaultDoesNotLinkTheSite(): void
    {
        $registry = new ProbeRegistry(['blogvault', 'crm']);
        $registry->register(new BlogvaultIdProbe('abc123', linked: false));
        $registry->register(new SiteIdEchoProbe());

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId);

        $this->assertNull($results['crm']->data['blogvault_site_id']);
    }

    public function testAFailedBlogvaultProbeIsFlaggedToTheCrmProbe(): void
    {
        $registry = new ProbeRegistry(['blogvault', 'crm']);
        $registry->register(new StubProbe('blogvault', ProbeResult::STATUS_ERROR));
        $registry->register(new SiteIdEchoProbe());

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId);
        $this->assertTrue($results['crm']->data['blogvault_failed']);

        // A lone re-run of crm reads the failure from the stored blogvault file.
        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId, ['crm']);
        $this->assertTrue($results['crm']->data['blogvault_failed']);
    }

    public function testTheWorkerSkipsAnExtractionNoLongerQueued(): void
    {
        $registry = new ProbeRegistry(['fine']);
        $registry->register(new StubProbe('fine', ProbeResult::STATUS_OK));
        $this->index->setExtractionStatus(self::SITE_ID, $this->extractionId, Index::STATUS_ABORTED);

        $this->assertNull($this->pipeline($registry)->runQueued(self::SITE_ID, $this->extractionId));
        $this->assertNull($this->store->readProbeResult(self::SITE_ID, $this->extractionId, 'fine'));
        $this->assertSame('aborted', $this->index->getExtraction(self::SITE_ID, $this->extractionId)['status']);

        $this->index->setExtractionStatus(self::SITE_ID, $this->extractionId, Index::STATUS_QUEUED);
        $results = $this->pipeline($registry)->runQueued(self::SITE_ID, $this->extractionId);
        $this->assertSame(['fine'], array_keys((array) $results));
        $this->assertSame('done', $this->index->getExtraction(self::SITE_ID, $this->extractionId)['status']);
    }

    public function testAnExplicitRunStillWorksOnADoneExtraction(): void
    {
        $registry = new ProbeRegistry(['fine']);
        $registry->register(new StubProbe('fine', ProbeResult::STATUS_OK));
        $this->index->setExtractionStatus(self::SITE_ID, $this->extractionId, Index::STATUS_DONE);

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId, ['fine']);

        $this->assertSame(['fine'], array_keys($results));
        $this->assertSame('done', $this->index->getExtraction(self::SITE_ID, $this->extractionId)['status']);
    }

    public function testAFailureMidRunMarksTheExtractionErrorNotRunning(): void
    {
        $registry = new ProbeRegistry(['a']);
        $registry->register(new StubProbe('a', ProbeResult::STATUS_OK));

        try {
            $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId, ['does-not-exist']);
            $this->fail('expected the unknown probe to throw');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame('error', $this->index->getExtraction(self::SITE_ID, $this->extractionId)['status']);
    }

    public function testOnlyProbesRunInTheRequestedOrder(): void
    {
        $registry = new ProbeRegistry(['a', 'b', 'c']);
        foreach (['a', 'b', 'c'] as $name) {
            $registry->register(new StubProbe($name, ProbeResult::STATUS_OK));
        }

        $results = $this->pipeline($registry)->run(self::SITE_ID, $this->extractionId, ['c', 'a']);

        $this->assertSame(['c', 'a'], array_keys($results));
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

final class BlogvaultIdProbe extends AbstractProbe
{
    public function __construct(private readonly string $id, private readonly bool $linked = true)
    {
    }

    public function name(): string
    {
        return 'blogvault';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function collect(SiteContext $site): array
    {
        return ['data' => ['linked' => $this->linked, 'site' => ['id' => $this->id]]];
    }
}

final class SiteIdEchoProbe extends AbstractProbe
{
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
        return ['data' => ['blogvault_site_id' => $site->blogvaultSiteId, 'blogvault_failed' => $site->blogvaultFailed]];
    }
}
