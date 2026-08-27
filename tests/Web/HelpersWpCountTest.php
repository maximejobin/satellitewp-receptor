<?php

declare(strict_types=1);

namespace SatelliteWP\Xtractor\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

/**
 * wp_count() + field(): the plugin ships wp_count_posts(), wp_count_comments(),
 * wp_count_attachments() and count_users() verbatim, so these arrive as maps.
 * Rendering one straight into a field() printed the literal word "Array".
 */
final class HelpersWpCountTest extends TestCase
{
    public function testPublishedPostsAreReadFromTheStatusMap(): void
    {
        $counts = ['publish' => '1200', 'draft' => '3', 'trash' => '7', 'auto-draft' => '12'];

        $this->assertSame(1200, \wp_count($counts, 'publish'));
    }

    public function testTotalUsersIsReadFromCountUsers(): void
    {
        $counts = ['total_users' => 42, 'avail_roles' => ['administrator' => 1]];

        $this->assertSame(42, \wp_count($counts, 'total_users'));
    }

    public function testApprovedWinsOverTotalComments(): void
    {
        $counts = ['approved' => 350, 'spam' => 128, 'total_comments' => 354];

        $this->assertSame(350, \wp_count($counts, 'approved', 'total_comments'));
    }

    public function testUnnamedMapIsSummedWithoutTrash(): void
    {
        // wp_count_attachments() is keyed by mime type and appends a trash count.
        $counts = ['image/jpeg' => '620', 'image/png' => '180', 'application/pdf' => '90', 'trash' => 4];

        $this->assertSame(890, \wp_count($counts));
    }

    public function testPlainIntegerStillPassesThrough(): void
    {
        $this->assertSame(1200, \wp_count(1200, 'publish'));
        $this->assertSame(1200, \wp_count('1200', 'publish'));
    }

    #[DataProvider('emptyValues')]
    public function testAbsentValuesAreNull(mixed $value): void
    {
        $this->assertNull(\wp_count($value, 'publish'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function emptyValues(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
            'not numeric'  => ['n/a'],
        ];
    }

    public function testFieldNeverRendersTheWordArray(): void
    {
        $row = \field('Total users', ['total_users' => 42, 'avail_roles' => []]);

        $this->assertStringNotContainsString('Array', $row);
        $this->assertStringContainsString('—', $row);
    }

    public function testFieldRendersTheCountItIsGiven(): void
    {
        $row = \field('Posts', \wp_count(['publish' => '1200'], 'publish'));

        $this->assertStringContainsString('1200', $row);
    }
}
