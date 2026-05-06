<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Service;

use Coding9\AgentReady\Service\ProductMarkdownRenderer;
use PHPUnit\Framework\TestCase;

class ProductMarkdownRendererTest extends TestCase
{
    public function testRendersFullProductSummary(): void
    {
        $md = (new ProductMarkdownRenderer())->render([
            'product' => [
                'name' => 'Vintage Sneaker',
                'sku' => 'SW-001',
                'brand' => ['name' => 'Coding9'],
                'url' => 'https://shop.example/sneaker',
                'image' => 'https://shop.example/media/sneaker.jpg',
                'description' => 'A very cool sneaker.',
            ],
            'breadcrumb' => ['Shoes', 'Sneakers'],
            'offers' => [[
                'price' => '99.95',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
            ]],
            'organization' => null,
        ]);

        self::assertStringContainsString('# Vintage Sneaker', $md);
        self::assertStringContainsString('> Shoes › Sneakers', $md);
        self::assertStringContainsString('| SKU | SW-001 |', $md);
        self::assertStringContainsString('| Brand | Coding9 |', $md);
        self::assertStringContainsString('| Price | 99.95 EUR |', $md);
        self::assertStringContainsString('| Availability | in stock |', $md);
        self::assertStringContainsString('![Vintage Sneaker](https://shop.example/media/sneaker.jpg)', $md);
        self::assertStringContainsString('## Description', $md);
        self::assertStringContainsString('A very cool sneaker.', $md);
    }

    public function testReturnsEmptyWhenNoProduct(): void
    {
        $md = (new ProductMarkdownRenderer())->render([
            'product' => null,
            'breadcrumb' => [],
            'offers' => [],
            'organization' => null,
        ]);
        self::assertSame('', $md);
    }

    public function testHandlesPriceRangeAggregateOffer(): void
    {
        $md = (new ProductMarkdownRenderer())->render([
            'product' => ['name' => 'Bundle'],
            'breadcrumb' => [],
            'offers' => [[
                '@type' => 'AggregateOffer',
                'lowPrice' => '10',
                'highPrice' => '50',
                'priceCurrency' => 'USD',
            ]],
            'organization' => null,
        ]);

        self::assertStringContainsString('| Price | 10–50 USD |', $md);
    }

    public function testEscapesPipeInValues(): void
    {
        $md = (new ProductMarkdownRenderer())->render([
            'product' => ['name' => 'X', 'sku' => 'A | B'],
            'breadcrumb' => [],
            'offers' => [],
            'organization' => null,
        ]);

        self::assertStringContainsString('| SKU | A \\| B |', $md);
    }

    public function testTranslatesAvailabilityShortNames(): void
    {
        foreach (
            [
                'https://schema.org/OutOfStock' => 'out of stock',
                'https://schema.org/PreOrder' => 'pre-order',
                'OutOfStock' => 'out of stock',
            ] as $input => $expected
        ) {
            $md = (new ProductMarkdownRenderer())->render([
                'product' => ['name' => 'X'],
                'breadcrumb' => [],
                'offers' => [['availability' => $input]],
                'organization' => null,
            ]);
            self::assertStringContainsString('| Availability | ' . $expected . ' |', $md);
        }
    }
}
