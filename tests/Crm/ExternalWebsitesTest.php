<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Crm;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SatelliteWP\Manager\Crm\ExternalWebsites;
use SatelliteWP\Manager\Tests\TestCase;

final class ExternalWebsitesTest extends TestCase
{
    private ExternalWebsites $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ExternalWebsites($this->tmpDir . '/crm/external-websites.json');
    }

    public function testAMissingFileHasNoWebsite(): void
    {
        $this->assertSame([], $this->store->all());
        $this->assertSame([], $this->store->links());
        $this->assertNull($this->store->get(1));
    }

    public function testAddedWebsitesGetTheirOwnSequence(): void
    {
        $first  = $this->store->add('https://shop.example.com', [], 'ann@example.com');
        $second = $this->store->add('https://booking.example.org/', [], 'ann@example.com');

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame(['https://booking.example.org/', 'https://shop.example.com'], array_column($this->store->all(), 'url'), 'sorted by URL');
        $this->assertSame('ann@example.com', $this->store->get(1)['created_by'] ?? null);

        $this->assertTrue($this->store->delete(2, []));
        $this->assertSame(3, $this->store->add('https://other.example.net', [], ''), 'a deleted id is never reused');
    }

    public function testAUrlAlreadyKnownAsAWebsiteIsRefused(): void
    {
        $this->store->add('https://shop.example.com', [], '');

        foreach (['http://www.shop.example.com/', 'HTTPS://SHOP.EXAMPLE.COM'] as $url) {
            try {
                $this->store->add($url, [], '');
                $this->fail("{$url} duplicates an external website");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('duplicate', $e->getMessage());
            }
        }

        $this->expectExceptionMessage('duplicate');
        $this->store->add('https://blog.example.com', ['https://www.blog.example.com/'], '');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'no scheme' => ['shop.example.com'];
        yield 'ftp'       => ['ftp://shop.example.com'];
        yield 'empty'     => [''];
        yield 'credentials' => ['https://user:secret@shop.example.com'];
        yield 'space'     => ['https://shop.example.com/a b'];
    }

    #[DataProvider('invalidUrls')]
    public function testAnInvalidUrlIsRefused(string $url): void
    {
        $this->expectExceptionMessage('invalid');
        $this->store->add($url, [], '');
    }

    public function testUpdateUrlKeepsTheLinksAndChecksDuplicates(): void
    {
        $id = $this->store->add('https://shop.example.com', [], '');
        $this->store->add('https://booking.example.org', [], '');
        $this->store->link(101, $id);

        $this->assertTrue($this->store->updateUrl($id, 'https://shop.example.com/fr', []));
        $this->assertSame([101], $this->store->get($id)['subscription_ids'] ?? null);
        $this->assertTrue($this->store->updateUrl($id, 'https://shop.example.com/fr/', []), 'its own URL is no duplicate');
        $this->assertFalse($this->store->updateUrl(99, 'https://new.example.com', []));

        $this->expectExceptionMessage('duplicate');
        $this->store->updateUrl($id, 'https://booking.example.org', []);
    }

    public function testASubscriptionIsLinkedToOneExternalWebsiteAtMost(): void
    {
        $a = $this->store->add('https://a.example.com', [], '');
        $b = $this->store->add('https://b.example.com', [], '');

        $this->assertTrue($this->store->link(101, $a));
        $this->assertTrue($this->store->link(102, $a));
        $this->assertTrue($this->store->link(101, $b));
        $this->assertSame([102 => $a, 101 => $b], $this->store->links());

        $this->assertTrue($this->store->link(102, null));
        $this->assertSame([101 => $b], $this->store->links());

        $this->assertFalse($this->store->link(101, 99), 'unknown website');
        $this->assertSame([101 => $b], $this->store->links(), 'nothing changed');
    }

    public function testAWebsiteWithSubscriptionsCannotBeDeleted(): void
    {
        $id = $this->store->add('https://a.example.com', [], '');
        $this->store->link(101, $id);

        $this->assertFalse($this->store->delete($id, [101]));
        $this->assertNotNull($this->store->get($id));

        $this->assertTrue($this->store->delete($id, []), 'a subscription gone from the CRM does not hold it');
        $this->assertNull($this->store->get($id));
        $this->assertFalse($this->store->delete($id, []));
    }

    public function testParseIdAcceptsOnlyThePrefixedForm(): void
    {
        $this->assertSame(3, ExternalWebsites::parseId('x3'));
        $this->assertSame('x3', ExternalWebsites::publicId(3));
        foreach (['3', 'x0', 'x03', 'X3', 'x3a', 'x', '', 'x99999999999'] as $value) {
            $this->assertNull(ExternalWebsites::parseId($value), $value);
        }
        $this->assertNull(ExternalWebsites::parseId(3));
    }

    public function testACorruptFileIsNeverOverwritten(): void
    {
        mkdir($this->tmpDir . '/crm');
        file_put_contents($this->tmpDir . '/crm/external-websites.json', '{not json');

        $this->expectException(\RuntimeException::class);
        $this->store->add('https://a.example.com', [], '');
    }
}
