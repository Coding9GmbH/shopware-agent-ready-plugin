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
 *
 * Conversion is restricted to public, content-bearing storefront routes
 * (homepage, navigation, product detail, search, CMS pages). Routes that
 * carry transactional state (account, checkout, widgets, AJAX fragments)
 * are never rewritten because turning their HTML into markdown would break
 * the storefront UI.
 */
class MarkdownNegotiationSubscriber implements EventSubscriberInterface
{
    public const MEDIA_TYPE = 'text/markdown';

    /** Storefront routes whose HTML may be converted to markdown. */
    private const SAFE_ROUTE_PREFIXES = [
        'frontend.home.',
        'frontend.navigation.',
        'frontend.detail.',
        'frontend.search.',
        'frontend.cms.',
        'frontend.landing.',
    ];

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

        if (!$this->isConvertibleRoute($request)) {
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
        // The body just changed; let Response::prepare() recompute Content-Length
        // and drop any encoding the original HTML carried.
        $response->headers->remove('Content-Length');
        $response->headers->remove('Content-Encoding');
        $this->appendVary($response, 'Accept');
    }

    /**
     * Markdown is selected only when the client explicitly prefers it over
     * HTML. `*\/*` (browsers) and missing/empty Accept headers always keep
     * HTML.
     */
    public function wantsMarkdown(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');
        if ($accept === '') {
            return false;
        }

        $accepts = $request->getAcceptableContentTypes();
        if (!$accepts) {
            return false;
        }

        $normalised = array_map('strtolower', $accepts);

        $mdRank = $this->rank($normalised, self::MEDIA_TYPE);
        if ($mdRank === null) {
            return false;
        }

        $htmlRank = $this->rank($normalised, 'text/html');
        if ($htmlRank !== null && $htmlRank < $mdRank) {
            return false;
        }

        // If the client only sent text/markdown (no html, no */*) it clearly
        // wants markdown. If both are present at the same q-value, we prefer
        // markdown — that's the explicit opt-in signal.
        return true;
    }

    private function rank(array $list, string $needle): ?int
    {
        foreach ($list as $i => $entry) {
            if ($entry === $needle) {
                return $i;
            }
        }
        return null;
    }

    private function isConvertibleRoute(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '') {
            // Plugin-provided well-known endpoints have explicit routes.
            // For requests without a resolved route we are conservative.
            return false;
        }

        foreach (self::SAFE_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }
        return false;
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
