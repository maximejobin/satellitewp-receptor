<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Storage\ReportTokenStore;

final class ReportTokenStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $raw = tempnam(sys_get_temp_dir(), 'swp-report-tokens-');
        unlink($raw); // issue() must create the real file (below) from nothing
        $this->file = $raw . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testIssuedTokenVerifiesOnlyForItsOwnSiteAndExtraction(): void
    {
        $store = new ReportTokenStore($this->file);
        $token = $store->issue('site-1', 'extraction-1');

        self::assertTrue($store->verify($token, 'site-1', 'extraction-1'));
        self::assertFalse($store->verify($token, 'site-2', 'extraction-1'));
        self::assertFalse($store->verify($token, 'site-1', 'extraction-2'));
        self::assertFalse($store->verify('not-a-real-token', 'site-1', 'extraction-1'));
    }

    public function testIssuedByCarriesTheAnalystsNameCapturedAtIssueTime(): void
    {
        $store = new ReportTokenStore($this->file);
        $token = $store->issue('site-1', 'extraction-1', 'Maxime Jobin');

        self::assertSame('Maxime Jobin', $store->issuedBy($token));
    }

    public function testIssuedByIsEmptyWhenNoneWasGivenOrTheTokenIsUnknown(): void
    {
        $store = new ReportTokenStore($this->file);
        $token = $store->issue('site-1', 'extraction-1'); // no $issuedBy — the shared reports.api_key path

        self::assertSame('', $store->issuedBy($token));
        self::assertSame('', $store->issuedBy('not-a-real-token'));
    }

    public function testTokenFileIsOwnerOnlyAndLeavesNoTempFileBehind(): void
    {
        $store = new ReportTokenStore($this->file);
        $store->issue('site-1', 'extraction-1');

        self::assertSame('600', substr(sprintf('%o', fileperms($this->file)), -3));
        self::assertSame([], glob($this->file . '.tmp*') ?: []);
    }
}
