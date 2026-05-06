<?php declare(strict_types=1);

namespace Coding9\AgentReady\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Service\HtmlToMarkdownConverter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Implements "Markdown for Agents": when the client sends Accept: text/markdown
 * we convert the HTML response to markdown. Browsers (and any other Accept)
 * still receive HTML.
 */
class MarkdownNegotiationSubscriber implements EventSubscriberInterface
{
    public const MEDIA_TYPE = 'text/markdown';

    public function __construct(
        private readonly AgentConfig $config,
        private readonly HtmlToMarkdownConverter $converter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run after Shopware itself but before any compression layers.
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $salesChannelId = $this->salesChannelId($request);

        if (!$this->config->isMarkdownNegotiationEnabled($salesChannelId)) {
            return;
        }

        if (!$this->wantsMarkdown($request)) {
            // Help intermediary caches store both representations.
            $this->appendVary($response, 'Accept');
            return;
        }

        if (!$this->isHtmlResponse($response)) {
            return;
        }

        $html = (string) $response->getContent();
        $result = $this->converter->convertWithTokens($html);

        $response->setContent($result['markdown']);
        $response->headers->set('Content-Type', self::MEDIA_TYPE . '; charset=UTF-8');
        $response->headers->set('x-markdown-tokens', (string) $result['tokens']);
        $this->appendVary($response, 'Accept');
    }

    public function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');
        if ($accept === '') {
            return false;
        }

        // Use Symfony's Accept parser to honour q-values.
        $accepts = $request->getAcceptableContentTypes();
        if (!$accepts) {
            return false;
        }

        // Markdown wins if it appears at least as preferred as text/html.
        $rank = static function (array $list, string $type): int {
            foreach ($list as $i => $entry) {
                if (strtolower($entry) === $type) {
                    return $i;
                }
            }
            return PHP_INT_MAX;
        };

        $mdRank = $rank($accepts, self::MEDIA_TYPE);
        if ($mdRank === PHP_INT_MAX) {
            return false;
        }

        $htmlRank = $rank($accepts, 'text/html');
        return $mdRank <= $htmlRank;
    }

    private function isHtmlResponse(Response $response): bool
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }
        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType === '') {
            return false;
        }
        return str_contains(strtolower($contentType), 'text/html');
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function appendVary(Response $response, string $header): void
    {
        $existing = $response->getVary();
        $normalised = array_map('strtolower', $existing);
        if (!in_array(strtolower($header), $normalised, true)) {
            $existing[] = $header;
            $response->setVary($existing);
        }
    }
}
