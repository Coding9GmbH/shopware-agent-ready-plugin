<?php declare(strict_types=1);

namespace Coding9\AgentReady\Service;

/**
 * Lightweight HTML -> Markdown converter focused on the parts of a Shopware
 * storefront response that are useful to AI agents (main content, headings,
 * lists, links, prices, structured data). Has no external dependencies so the
 * plugin stays slim.
 *
 * The output is deliberately simple: GFM-like markdown with no HTML passthrough.
 */
class HtmlToMarkdownConverter
{
    /**
     * @return array{markdown: string, tokens: int}
     */
    public function convertWithTokens(string $html): array
    {
        $markdown = $this->convert($html);
        // Rough token estimate (words + punctuation), good enough for the
        // x-markdown-tokens response header.
        $tokens = max(1, (int) ceil(str_word_count(strip_tags($markdown)) * 1.3));
        return ['markdown' => $markdown, 'tokens' => $tokens];
    }

    public function convert(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // Force UTF-8 interpretation; Shopware storefront output is UTF-8.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();

        if (!$loaded) {
            return trim(strip_tags($html));
        }

        $xpath = new \DOMXPath($dom);

        $main = $xpath->query('//main')->item(0)
            ?? $xpath->query('//*[@role="main"]')->item(0)
            ?? $xpath->query('//body')->item(0)
            ?? $dom->documentElement;

        if (!$main instanceof \DOMNode) {
            return trim(strip_tags($html));
        }

        // Strip noise that is not useful to an agent.
        $unwanted = $xpath->query(
            './/script | .//style | .//noscript | .//template | .//svg | .//iframe | '
            . './/header | .//footer | .//nav | .//*[@aria-hidden="true"] | '
            . './/*[contains(@class, "cookie")] | .//*[contains(@class, "offcanvas")]',
            $main
        );
        if ($unwanted) {
            foreach (iterator_to_array($unwanted) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // Pull a meaningful title for the document.
        $titleNode = $xpath->query('//title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';

        $body = $this->renderNode($main);
        $body = $this->collapseWhitespace($body);

        if ($title !== '') {
            $body = '# ' . $title . "\n\n" . $body;
        }

        return trim($body);
    }

    private function renderNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/', ' ', $node->nodeValue ?? '') ?? '';
        }

        if (!$node instanceof \DOMElement) {
            return $this->renderChildren($node);
        }

        $tag = strtolower($node->nodeName);

        return match ($tag) {
            'h1' => "\n\n# " . trim($this->renderChildren($node)) . "\n\n",
            'h2' => "\n\n## " . trim($this->renderChildren($node)) . "\n\n",
            'h3' => "\n\n### " . trim($this->renderChildren($node)) . "\n\n",
            'h4' => "\n\n#### " . trim($this->renderChildren($node)) . "\n\n",
            'h5' => "\n\n##### " . trim($this->renderChildren($node)) . "\n\n",
            'h6' => "\n\n###### " . trim($this->renderChildren($node)) . "\n\n",
            'p', 'div', 'section', 'article' => "\n\n" . trim($this->renderChildren($node)) . "\n\n",
            'br' => "  \n",
            'hr' => "\n\n---\n\n",
            'strong', 'b' => '**' . trim($this->renderChildren($node)) . '**',
            'em', 'i' => '*' . trim($this->renderChildren($node)) . '*',
            'code' => '`' . trim($node->textContent) . '`',
            'pre' => "\n\n```\n" . trim($node->textContent) . "\n```\n\n",
            'a' => $this->renderAnchor($node),
            'img' => $this->renderImage($node),
            'ul' => "\n" . $this->renderList($node, false) . "\n",
            'ol' => "\n" . $this->renderList($node, true) . "\n",
            'li' => trim($this->renderChildren($node)),
            'blockquote' => "\n\n> " . trim($this->renderChildren($node)) . "\n\n",
            'table' => $this->renderTable($node),
            default => $this->renderChildren($node),
        };
    }

    private function renderChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderNode($child);
        }
        return $out;
    }

    private function renderAnchor(\DOMElement $node): string
    {
        $text = trim($this->renderChildren($node));
        $href = $node->getAttribute('href');
        if ($href === '' || $text === '') {
            return $text;
        }
        return '[' . $text . '](' . $href . ')';
    }

    private function renderImage(\DOMElement $node): string
    {
        $alt = trim($node->getAttribute('alt'));
        $src = trim($node->getAttribute('src'));
        if ($src === '') {
            return '';
        }
        return '![' . $alt . '](' . $src . ')';
    }

    private function renderList(\DOMElement $node, bool $ordered): string
    {
        $out = '';
        $i = 1;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }
            $marker = $ordered ? ($i . '.') : '-';
            $line = trim($this->renderChildren($child));
            $line = preg_replace('/\s*\n\s*/', ' ', $line) ?? $line;
            $out .= $marker . ' ' . $line . "\n";
            $i++;
        }
        return $out;
    }

    private function renderTable(\DOMElement $node): string
    {
        $xpath = new \DOMXPath($node->ownerDocument);
        $rows = [];
        foreach ($xpath->query('.//tr', $node) as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }
        if (!$rows) {
            return '';
        }
        $width = max(array_map('count', $rows));
        $rows = array_map(static fn (array $r) => array_pad($r, $width, ''), $rows);
        $header = array_shift($rows);
        $out = "\n\n| " . implode(' | ', $header) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, $width, '---')) . " |\n";
        foreach ($rows as $r) {
            $out .= '| ' . implode(' | ', $r) . " |\n";
        }
        return $out . "\n";
    }

    private function collapseWhitespace(string $text): string
    {
        // Normalize Windows newlines, then collapse 3+ blank lines down to two.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return $text;
    }
}
