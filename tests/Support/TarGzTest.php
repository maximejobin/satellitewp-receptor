<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Support;

use InvalidArgumentException;
use SatelliteWP\Manager\Support\TarGz;
use SatelliteWP\Manager\Tests\TestCase;

final class TarGzTest extends TestCase
{
    public function testTheArchiveExtractsToTheSameFilesAndContents(): void
    {
        $files = [
            '.swp-extraction/meta.json'             => "{\"a\":1}\n",
            '.swp-extraction/raw/probes/tls.json'   => str_repeat('x', 1500),
            '.swp-extraction/analysis/findings.md'  => "é « »\n",
        ];
        $archive = $this->tmpDir . '/a.tar.gz';
        file_put_contents($archive, TarGz::build($files, 1760000000));

        (new \PharData($archive))->extractTo($this->tmpDir . '/out');

        foreach ($files as $path => $content) {
            $this->assertSame($content, file_get_contents($this->tmpDir . '/out/' . $path), $path);
        }
    }

    public function testAPathThatCouldEscapeTheDirectoryIsRefused(): void
    {
        foreach (['../x', '/etc/x', 'a/../../x', ''] as $path) {
            try {
                TarGz::build([$path => 'x'], 0);
                $this->fail($path . ' was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
