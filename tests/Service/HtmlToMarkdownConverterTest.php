<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Service;

use Coding9\AgentReady\Service\HtmlToMarkdownConverter;
use PHPUnit\Framework\TestCase;

class HtmlToMarkdownConverterTest extends TestCase
{
    private HtmlToMarkdownConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new HtmlToMarkdownConverter();
    }

    public function testConvertsHeadingsAndParagraphs(): void
    {
        $html = '<html><head><title>Demo Shop</title></head>'
            . '<body><main><h1>Welcome</h1><p>Hello <strong>world</strong>.</p></main></body></html>';

        $md = $this->converter->convert($html);

        self::assertStringContainsString('# Demo Shop', $md);
        self::assertStringContainsString('# Welcome', $md);
        self::assertStringContainsString('**world**', $md);
    }

    public function testConvertsAnchorsAndImages(): void
    {
        $html = '<main><p><a href="/p/1">Foo</a> and <img src="/img.png" alt="img"></p></main>';
        $md = $this->converter->convert($html);
        self::assertStringContainsString('[Foo](/p/1)', $md);
        self::assertStringContainsString('![img](/img.png)', $md);
    }

    public function testConvertsLists(): void
    {
        $html = '<main><ul><li>a</li><li>b</li></ul><ol><li>x</li><li>y</li></ol></main>';
        $md = $this->converter->convert($html);
        self::assertStringContainsString('- a', $md);
        self::assertStringContainsString('- b', $md);
        self::assertStringContainsString('1. x', $md);
        self::assertStringContainsString('2. y', $md);
    }

    public function testStripsScriptStyleNavFooterHeader(): void
    {
        $html = '<main><nav>NAV</nav><header>HEAD</header>'
            . '<script>alert(1)</script><style>body{}</style>'
            . '<p>Visible</p>'
            . '<footer>FOOT</footer></main>';
        $md = $this->converter->convert($html);
        self::assertStringContainsString('Visible', $md);
        self::assertStringNotContainsString('NAV', $md);
        self::assertStringNotContainsString('HEAD', $md);
        self::assertStringNotContainsString('FOOT', $md);
        self::assertStringNotContainsString('alert', $md);
        self::assertStringNotContainsString('body{}', $md);
    }

    public function testTableConversion(): void
    {
        $html = '<main><table><tr><th>Name</th><th>Price</th></tr>'
            . '<tr><td>Foo</td><td>9</td></tr></table></main>';
        $md = $this->converter->convert($html);
        self::assertStringContainsString('| Name | Price |', $md);
        self::assertStringContainsString('| --- | --- |', $md);
        self::assertStringContainsString('| Foo | 9 |', $md);
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', $this->converter->convert(''));
    }

    public function testTokenEstimateIsPositive(): void
    {
        $result = $this->converter->convertWithTokens('<main><p>hello world</p></main>');
        self::assertGreaterThan(0, $result['tokens']);
        self::assertNotEmpty($result['markdown']);
    }

    public function testCollapsesExcessWhitespace(): void
    {
        $html = '<main><p>a</p><p>b</p><p>c</p></main>';
        $md = $this->converter->convert($html);
        self::assertDoesNotMatchRegularExpression('/\n{3,}/', $md);
    }

    public function testHandlesUtf8(): void
    {
        $html = '<main><h2>Über uns</h2><p>Grüß Gott — €5</p></main>';
        $md = $this->converter->convert($html);
        self::assertStringContainsString('Über uns', $md);
        self::assertStringContainsString('€5', $md);
    }
}
