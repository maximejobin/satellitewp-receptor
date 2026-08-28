<?php

declare(strict_types=1);

/**
 * Managed hosting routinely puts symlink() in disable_functions, and a disabled
 * function raises an Error — which `@` does not suppress. That took down every
 * extraction with an HTTP 500 while the failing call was a cosmetic shortcut
 * nothing reads.
 *
 * PHP resolves an unqualified call inside a namespace against that namespace
 * first, so declaring symlink() in DataStore's namespace is what a disabled
 * function looks like from its point of view. The production error named
 * SatelliteWP\Xtractor\Storage\symlink() for exactly this reason.
 */

namespace SatelliteWP\Xtractor\Storage {

    function symlink(string $target, string $link): bool
    {
        if (\SatelliteWP\Xtractor\Tests\Storage\DataStoreDisabledSymlinkTest::$disabled) {
            throw new \Error('Call to undefined function SatelliteWP\Xtractor\Storage\symlink()');
        }

        return \symlink($target, $link);
    }
}

namespace SatelliteWP\Xtractor\Tests\Storage {

    use SatelliteWP\Xtractor\Storage\DataStore;
    use SatelliteWP\Xtractor\Tests\TestCase;

    final class DataStoreDisabledSymlinkTest extends TestCase
    {
        /** Flipped on only for the test below; the stub is a pass-through otherwise. */
        public static bool $disabled = false;

        private const string SITE_ID = '3f2b1a9c-4d5e-4f6a-8b7c-9d0e1f2a3b4c';

        protected function tearDown(): void
        {
            self::$disabled = false;

            parent::tearDown();
        }

        public function testAnExtractionIsStoredWhenSymlinkIsDisabled(): void
        {
            self::$disabled = true;

            $store = new DataStore($this->tmpDir);
            $body  = $this->fixture('extraction-valid.json');

            $id = $store->storeExtraction(self::SITE_ID, $body, ['received_at' => '2026-08-28T12:00:00Z']);

            $this->assertNotSame('', $id);
            $this->assertSame(
                $body,
                (string) file_get_contents($store->extractionDir(self::SITE_ID, $id) . '/payload.json'),
                'the payload must be stored even though the latest shortcut could not be made'
            );
            $this->assertFileDoesNotExist($store->siteDir(self::SITE_ID) . '/extractions/latest');
        }

        public function testTheShortcutIsStillMadeWhenSymlinkWorks(): void
        {
            $store = new DataStore($this->tmpDir);
            $id    = $store->storeExtraction(
                self::SITE_ID,
                $this->fixture('extraction-valid.json'),
                ['received_at' => '2026-08-28T12:00:00Z']
            );

            $link = $store->siteDir(self::SITE_ID) . '/extractions/latest';

            $this->assertTrue(is_link($link), 'the convenience shortcut is made on a normal host');
            $this->assertSame($id, readlink($link));
        }
    }
}
