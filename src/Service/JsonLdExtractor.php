<?php declare(strict_types=1);

namespace Coding9\AgentReady\Service;

/**
 * Pulls Schema.org structured data from `<script type="application/ld+json">`
 * blocks in a Shopware storefront HTML response.
 *
 * Shopware 6 emits a Product node out of the box on product detail pages and
 * a BreadcrumbList on category/PDP pages. We deliberately don't synthesize
 * data — if the storefront didn't render JSON-LD, the extractor returns
 * empty arrays and the markdown subscriber falls back to plain HTML→MD.
 *
 * The extractor is forgiving:
 *  - tolerates one or many top-level JSON-LD blocks
 *  - tolerates `@graph` containers
 *  - tolerates HTML-escaped JSON (Shopware's twig encodes the `&` in URLs)
 */
class JsonLdExtractor
{
    /**
     * @return array{
     *   product: array<string, mixed>|null,
     *   breadcrumb: array<int, string>,
     *   offers: array<int, array<string, mixed>>,
     *   organization: array<string, mixed>|null
     * }
     */
    public function extract(string $html): array
    {
        $blocks = $this->collectBlocks($html);

        $result = [
            'product' => null,
            'breadcrumb' => [],
            'offers' => [],
            'organization' => null,
        ];

        foreach ($blocks as $block) {
            $this->ingest($block, $result);
        }

        return $result;
    }

    /** @return array<int, mixed> */
    private function collectBlocks(string $html): array
    {
        if ($html === '' || !str_contains($html, 'application/ld+json')) {
            return [];
        }

        // Greedy script-block match, case-insensitive on the type attribute.
        $count = preg_match_all(
            '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        );
        if (!$count) {
            return [];
        }

        $blocks = [];
        foreach ($matches[1] as $raw) {
            $json = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $json = trim($json);
            if ($json === '') {
                continue;
            }
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $blocks[] = $decoded;
        }
        return $blocks;
    }

    /**
     * @param mixed                                                      $block
     * @param array{
     *   product: array<string, mixed>|null,
     *   breadcrumb: array<int, string>,
     *   offers: array<int, array<string, mixed>>,
     *   organization: array<string, mixed>|null
     * } $result
     */
    private function ingest(mixed $block, array &$result): void
    {
        if (!is_array($block)) {
            return;
        }

        // Unwrap `@graph` containers and arrays of nodes.
        if (isset($block['@graph']) && is_array($block['@graph'])) {
            foreach ($block['@graph'] as $node) {
                $this->ingest($node, $result);
            }
            return;
        }
        if (array_is_list($block)) {
            foreach ($block as $node) {
                $this->ingest($node, $result);
            }
            return;
        }

        $type = $this->normalizeType($block['@type'] ?? null);
        if ($type === null) {
            return;
        }

        if ($type === 'Product') {
            $result['product'] ??= $block;
            $offers = $block['offers'] ?? null;
            if (is_array($offers)) {
                if (array_is_list($offers)) {
                    foreach ($offers as $offer) {
                        if (is_array($offer)) {
                            $result['offers'][] = $offer;
                        }
                    }
                } else {
                    $result['offers'][] = $offers;
                }
            }
            return;
        }

        if ($type === 'BreadcrumbList' && isset($block['itemListElement']) && is_array($block['itemListElement'])) {
            $crumbs = [];
            foreach ($block['itemListElement'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = $item['name'] ?? ($item['item']['name'] ?? null);
                if (is_string($name) && $name !== '') {
                    $crumbs[] = $name;
                }
            }
            if ($crumbs) {
                $result['breadcrumb'] = $crumbs;
            }
            return;
        }

        if ($type === 'Organization') {
            $result['organization'] ??= $block;
            return;
        }

        if ($type === 'Offer') {
            $result['offers'][] = $block;
        }
    }

    private function normalizeType(mixed $type): ?string
    {
        if (is_string($type)) {
            return $type;
        }
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}
