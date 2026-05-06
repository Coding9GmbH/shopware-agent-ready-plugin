<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Service;

use Coding9\AgentReady\Service\JsonLdExtractor;
use PHPUnit\Framework\TestCase;

class JsonLdExtractorTest extends TestCase
{
    public function testExtractsProductAndOfferAndBreadcrumb(): void
    {
        $html = $this->html([
            [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => 'Vintage Sneaker',
                'sku' => 'SW-001',
                'brand' => ['@type' => 'Brand', 'name' => 'Coding9'],
                'image' => 'https://shop.example/media/sneaker.jpg',
                'description' => 'A very cool sneaker.',
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '99.95',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Shoes'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Sneakers'],
                ],
            ],
        ]);

        $data = (new JsonLdExtractor())->extract($html);

        self::assertNotNull($data['product']);
        self::assertSame('Vintage Sneaker', $data['product']['name']);
        self::assertSame(['Shoes', 'Sneakers'], $data['breadcrumb']);
        self::assertCount(1, $data['offers']);
        self::assertSame('99.95', $data['offers'][0]['price']);
    }

    public function testHandlesAtGraphContainer(): void
    {
        $html = $this->html([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', 'name' => 'Coding9 GmbH'],
                ['@type' => 'Product', 'name' => 'Item'],
            ],
        ]);

        $data = (new JsonLdExtractor())->extract($html);
        self::assertSame('Item', $data['product']['name'] ?? null);
        self::assertSame('Coding9 GmbH', $data['organization']['name'] ?? null);
    }

    public function testIgnoresMalformedJsonBlocks(): void
    {
        $html = '<html><head><script type="application/ld+json">{not json</script></head><body></body></html>';

        $data = (new JsonLdExtractor())->extract($html);
        self::assertNull($data['product']);
    }

    public function testReturnsEmptyResultWhenNoBlocks(): void
    {
        $data = (new JsonLdExtractor())->extract('<html><body>nothing here</body></html>');
        self::assertSame([
            'product' => null,
            'breadcrumb' => [],
            'offers' => [],
            'organization' => null,
        ], $data);
    }

    /** @param array<int|string, mixed> $blocks */
    private function html(array $blocks): string
    {
        $blocks = array_is_list($blocks) ? $blocks : [$blocks];
        $scripts = '';
        foreach ($blocks as $block) {
            $scripts .= '<script type="application/ld+json">' . json_encode($block) . '</script>';
        }
        return '<html><head>' . $scripts . '</head><body></body></html>';
    }
}
