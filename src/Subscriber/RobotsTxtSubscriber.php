<?php declare(strict_types=1);

namespace Coding9\AgentReady\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects Content-Signal directives into the /robots.txt response body
 * regardless of which controller produced it.
 *
 * Shopware 6.7's Storefront ships its own /robots.txt route
 * (frontend.sitemap.robots-txt) that wins route resolution against the
 * plugin's RobotsTxtController. Augmenting the response after the fact lets
 * us add Content-Signal (draft-romm-aipref-contentsignals) without fighting
 * over route precedence and without breaking existing rules merchants rely on.
 */
class RobotsTxtSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AgentConfig $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Run late so we modify the final body after other listeners (cache, etc.)
        return [
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/robots.txt') {
            return;
        }

        $response = $event->getResponse();
        if ($response->getStatusCode() !== 200) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_starts_with($contentType, 'text/plain')) {
            return;
        }

        $salesChannelId = $this->salesChannelId($request);
        if (!$this->config->isContentSignalsEnabled($salesChannelId)) {
            return;
        }

        $body = (string) $response->getContent();
        if ($body === '' || str_contains($body, 'Content-Signal:')) {
            return;
        }

        $signalLine = $this->buildSignalLine($salesChannelId);
        $newBody = $this->injectIntoUserAgentGroup($body, $signalLine);

        if ($newBody !== $body) {
            $response->setContent($newBody);
        }
    }

    private function buildSignalLine(?string $salesChannelId): string
    {
        $signals = $this->config->getContentSignals($salesChannelId);
        $parts = [];
        foreach ($signals as $name => $value) {
            $parts[] = $this->oneLine($name) . '=' . $this->oneLine($value);
        }
        return 'Content-Signal: ' . implode(', ', $parts);
    }

    /**
     * Insert the Content-Signal line right after the first `User-agent: *`
     * line, so it is grouped with that user agent per
     * draft-romm-aipref-contentsignals §3. Falls back to prepending a fresh
     * `User-agent: *` group when no wildcard group exists.
     */
    private function injectIntoUserAgentGroup(string $body, string $signalLine): string
    {
        $comment = '# Content-Signal directives (https://contentsignals.org/)';
        $injection = "\n" . $comment . "\n" . $signalLine;

        $replaced = preg_replace(
            '/^(User-agent:\s*\*[^\r\n]*)$/m',
            '$1' . $injection,
            $body,
            1,
            $count
        );

        if (is_string($replaced) && $count > 0) {
            return $replaced;
        }

        return "User-agent: *" . $injection . "\n\n" . $body;
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function oneLine(string $value): string
    {
        return preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    }
}
