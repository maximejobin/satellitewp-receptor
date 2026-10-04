<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\TestCase;
use SatelliteWP\Manager\Web\ObservationsCsv;

final class ObservationsCsvTest extends TestCase
{
    private const array SECTIONS = ['security_observations', 'performance_observations'];

    /** @return array{items: list<array<string, mixed>>, errors: list<string>} */
    private static function parse(string $csv): array
    {
        return ObservationsCsv::parse($csv, self::SECTIONS);
    }

    public function testParsesEveryColumnWithQuotedCommasAndLineBreaks(): void
    {
        $result = self::parse("section,color,title,description,include\n"
            . "security_observations,red,Weak passwords,\"Three admins, **no** 2FA\nsee example.com\",1\n"
            . "performance_observations,Orange,Heavy images,,0\n");

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            ['section' => 'security_observations', 'color' => 'red', 'title' => 'Weak passwords', 'description' => "Three admins, **no** 2FA\nsee example.com", 'include' => true],
            ['section' => 'performance_observations', 'color' => 'orange', 'title' => 'Heavy images', 'description' => '', 'include' => false],
        ], $result['items']);
    }

    public function testReadsAFrenchExcelExport(): void
    {
        // BOM, semicolons, CRLF, column order and case of a spreadsheet export.
        $result = self::parse("\u{FEFF}Title;Section;Include\r\nMots de passe faibles, à revoir;security_observations;oui\r\n;;\r\n");

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [['section' => 'security_observations', 'color' => 'blue', 'title' => 'Mots de passe faibles, à revoir', 'description' => '', 'include' => true]],
            $result['items']
        );
    }

    public function testOneInvalidRowRejectsTheWholeFileAndNamesEachLine(): void
    {
        $result = self::parse("section,color,title,include\n"
            . "security_observations,red,Fine,1\n"
            . "seo_observations,pink,,maybe\n"
            . "performance_observations,green,Too,many,cells\n");

        $this->assertSame([], $result['items']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringStartsWith('Line 3: unknown section "seo_observations"; unknown color "pink"', $result['errors'][0]);
        $this->assertStringContainsString('empty title', $result['errors'][0]);
        $this->assertStringContainsString('include "maybe"', $result['errors'][0]);
        $this->assertStringStartsWith('Line 4: more cells than columns', $result['errors'][1]);
    }

    public function testHeaderProblemsAreReportedBeforeAnyRow(): void
    {
        $result = self::parse("section,colour,description\nsecurity_observations,red,x\n");

        $this->assertSame([], $result['items']);
        $this->assertSame([
            'Unknown column "colour" (expected: section, color, title, description, include).',
            'Missing column "title".',
        ], $result['errors']);
    }

    public function testRejectsEmptyNonUtf8AndOversizedFiles(): void
    {
        $this->assertSame(['The file is empty.'], self::parse(" \n")['errors']);
        $this->assertSame(['The file has a header but no observation.'], self::parse("section,title\n")['errors']);
        $this->assertStringContainsString('CSV UTF-8', self::parse("section,title\nsecurity_observations,\xE9t\xE9\n")['errors'][0]);
        $this->assertSame(['The file is larger than 1 MB.'], self::parse(str_repeat('a', ObservationsCsv::MAX_BYTES + 1))['errors']);
    }

    public function testCapsTheNumberOfRows(): void
    {
        $csv    = "section,title\n" . str_repeat("security_observations,t\n", ObservationsCsv::MAX_ROWS + 1);
        $result = self::parse($csv);

        $this->assertSame([], $result['items']);
        $this->assertSame(['More than 500 observations: split the file.'], $result['errors']);
    }
}
