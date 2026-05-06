<?php declare(strict_types=1);

namespace Coding9\AgentReady\Service;

/**
 * Renders a structured Markdown header for product detail pages.
 *
 * Consumes the result of {@see JsonLdExtractor} and emits a compact summary
 * (name, breadcrumb, price, availability, sku, brand, image) ahead of the
 * generic HTML→Markdown body. Agents that only read the head of a response
 * (typical of token-budgeted assistants) get the buying-decision facts
 * without parsing the whole page.
 */
class ProductMarkdownRenderer
{
    /**
     * @param array{
     *   product: array<string, mixed>|null,
     *   breadcrumb: array<int, string>,
     *   offers: array<int, array<string, mixed>>,
     *   organization: array<string, mixed>|null
     * } $data
     */
    public function render(array $data): string
    {
        $product = $data['product'];
        if ($product === null) {
            return '';
        }

        $lines = [];

        $name = $this->stringField($product, 'name');
        if ($name !== null) {
            $lines[] = '# ' . $name;
            $lines[] = '';
        }

        if ($data['breadcrumb'] !== []) {
            $lines[] = '> ' . implode(' › ', $data['breadcrumb']);
            $lines[] = '';
        }

        $facts = [];

        $sku = $this->stringField($product, 'sku') ?? $this->stringField($product, 'productID');
        if ($sku !== null) {
            $facts[] = ['SKU', $sku];
        }

        $brand = $this->extractBrand($product);
        if ($brand !== null) {
            $facts[] = ['Brand', $brand];
        }

        $offer = $this->primaryOffer($data['offers']);
        if ($offer !== null) {
            $price = $this->extractPrice($offer);
            if ($price !== null) {
                $facts[] = ['Price', $price];
            }
            $availability = $this->extractAvailability($offer);
            if ($availability !== null) {
                $facts[] = ['Availability', $availability];
            }
            $sellerName = $this->extractSellerName($offer);
            if ($sellerName !== null) {
                $facts[] = ['Seller', $sellerName];
            }
        }

        $url = $this->stringField($product, 'url');
        if ($url !== null) {
            $facts[] = ['URL', $url];
        }

        if ($facts !== []) {
            $lines[] = '| Field | Value |';
            $lines[] = '| --- | --- |';
            foreach ($facts as [$label, $value]) {
                $lines[] = '| ' . $label . ' | ' . $this->escapeCell($value) . ' |';
            }
            $lines[] = '';
        }

        $image = $this->extractImage($product);
        if ($image !== null) {
            $lines[] = '![' . ($name ?? 'product image') . '](' . $image . ')';
            $lines[] = '';
        }

        $description = $this->stringField($product, 'description');
        if ($description !== null) {
            $lines[] = '## Description';
            $lines[] = '';
            $lines[] = trim($description);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $node */
    private function stringField(array $node, string $key): ?string
    {
        $value = $node[$key] ?? null;
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /** @param array<string, mixed> $product */
    private function extractBrand(array $product): ?string
    {
        $brand = $product['brand'] ?? null;
        if (is_string($brand)) {
            return $brand !== '' ? $brand : null;
        }
        if (is_array($brand)) {
            return $this->stringField($brand, 'name');
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $offers
     * @return array<string, mixed>|null
     */
    private function primaryOffer(array $offers): ?array
    {
        if ($offers === []) {
            return null;
        }
        // Prefer an AggregateOffer if present, otherwise take the first.
        foreach ($offers as $offer) {
            if (($offer['@type'] ?? null) === 'AggregateOffer') {
                return $offer;
            }
        }
        return $offers[0];
    }

    /** @param array<string, mixed> $offer */
    private function extractPrice(array $offer): ?string
    {
        $currency = $this->stringField($offer, 'priceCurrency');

        $price = $this->stringField($offer, 'price');
        if ($price !== null) {
            return $currency !== null ? $price . ' ' . $currency : $price;
        }

        $low = $this->stringField($offer, 'lowPrice');
        $high = $this->stringField($offer, 'highPrice');
        if ($low !== null && $high !== null) {
            return $currency !== null
                ? $low . '–' . $high . ' ' . $currency
                : $low . '–' . $high;
        }
        if ($low !== null) {
            return $currency !== null ? $low . ' ' . $currency : $low;
        }

        if (isset($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
            $spec = $offer['priceSpecification'];
            if (array_is_list($spec) && isset($spec[0]) && is_array($spec[0])) {
                $spec = $spec[0];
            }
            $specPrice = $this->stringField($spec, 'price');
            $specCurrency = $this->stringField($spec, 'priceCurrency');
            if ($specPrice !== null) {
                return $specCurrency !== null ? $specPrice . ' ' . $specCurrency : $specPrice;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $offer */
    private function extractAvailability(array $offer): ?string
    {
        $availability = $this->stringField($offer, 'availability');
        if ($availability === null) {
            return null;
        }
        // Schema.org availability values are namespaced
        // (https://schema.org/InStock). Strip the namespace for readability.
        $short = preg_replace('#^(https?:)?//schema\\.org/#', '', $availability) ?? $availability;
        return match ($short) {
            'InStock' => 'in stock',
            'OutOfStock' => 'out of stock',
            'PreOrder' => 'pre-order',
            'BackOrder' => 'back-order',
            'LimitedAvailability' => 'limited availability',
            'Discontinued' => 'discontinued',
            default => $short,
        };
    }

    /** @param array<string, mixed> $offer */
    private function extractSellerName(array $offer): ?string
    {
        $seller = $offer['seller'] ?? null;
        if (is_array($seller)) {
            return $this->stringField($seller, 'name');
        }
        return null;
    }

    /** @param array<string, mixed> $product */
    private function extractImage(array $product): ?string
    {
        $image = $product['image'] ?? null;
        if (is_string($image) && $image !== '') {
            return $image;
        }
        if (is_array($image)) {
            if (array_is_list($image) && isset($image[0])) {
                return is_string($image[0]) ? $image[0] : null;
            }
            return $this->stringField($image, 'url');
        }
        return null;
    }

    private function escapeCell(string $value): string
    {
        return strtr(trim(preg_replace('/\s+/', ' ', $value) ?? $value), [
            '|' => '\\|',
            "\n" => ' ',
        ]);
    }
}
