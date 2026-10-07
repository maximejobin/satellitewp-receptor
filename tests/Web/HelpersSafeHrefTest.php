<?php

declare(strict_types=1);

namespace SatelliteWP\Manager\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Web/helpers.php';

final class HelpersSafeHrefTest extends TestCase
{
    /** @return iterable<string, array{mixed, string|null}> */
    public static function urls(): iterable
    {
        yield 'https'              => ['https://example.com/path?q=1', 'https://example.com/path?q=1'];
        yield 'http, upper scheme' => ['HTTP://example.com', 'HTTP://example.com'];
        yield 'javascript'         => ['javascript:alert(1)', null];
        yield 'javascript, case'   => ['JaVaScRiPt:alert(1)', null];
        yield 'leading space'      => [' javascript:alert(1)', null];
        yield 'embedded tab'       => ["java\tscript:alert(1)", null];
        yield 'data'               => ['data:text/html,<script>alert(1)</script>', null];
        yield 'protocol-relative'  => ['//example.com', null];
        yield 'relative'           => ['/wp-admin', null];
        yield 'no host'            => ['https:///path', null];
        yield 'array'              => [['https://example.com'], null];
        yield 'null'               => [null, null];
    }

    #[Test]
    #[DataProvider('urls')]
    public function onlyAbsoluteHttpUrlsAreLinkable(mixed $url, ?string $expected): void
    {
        $this->assertSame($expected, safe_href($url));
    }

    #[Test]
    public function linkOrTextFallsBackToEscapedText(): void
    {
        $this->assertSame('javascript:alert(&quot;x&quot;)', link_or_text('javascript:alert("x")', 'javascript:alert("x")'));
        $this->assertSame(
            '<a href="https://example.com/?a=1&amp;b=&quot;2&quot;" rel="noopener noreferrer">example.com</a>',
            link_or_text('https://example.com/?a=1&b="2"', 'example.com')
        );
    }

    #[Test]
    public function escapingAStructureRendersNothing(): void
    {
        $this->assertSame('', e(['<b>']));
        $this->assertSame('&lt;b&gt;', e('<b>'));
        $this->assertSame('3', e(3));
    }
}
