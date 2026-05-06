<?php declare(strict_types=1);

namespace Coding9\AgentReady\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds RFC 8288 Link response headers to the storefront homepage so that
 * agents can discover well-known resources (api-catalog, service-doc, ...).
 */
class LinkHeaderSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AgentConfig $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->isHomepage($request)) {
            return;
        }

        if (!$this->config->isLinkHeadersEnabled()) {
            return;
        }

        $links = $this->buildLinks();

        // RFC 7230 allows multiple Link headers as well as a single comma-separated header.
        // We send one entry per logical relation as separate values; Symfony will fold them
        // into a comma-separated list, which is RFC 8288 compliant.
        foreach ($links as $link) {
            $response->headers->set('Link', $link, false);
        }
    }

    /**
     * @return string[]
     */
    public function buildLinks(): array
    {
        $links = [];

        if ($this->config->isApiCatalogEnabled()) {
            $links[] = '</.well-known/api-catalog>; rel="api-catalog"';
        }

        $serviceDoc = $this->config->getServiceDocPath();
        if ($serviceDoc !== '') {
            $links[] = '<' . $serviceDoc . '>; rel="service-doc"';
        }

        if ($this->config->isMcpServerCardEnabled()) {
            $links[] = '</.well-known/mcp/server-card.json>; rel="mcp-server-card"';
        }

        if ($this->config->isA2aAgentCardEnabled()) {
            $links[] = '</.well-known/agent-card.json>; rel="a2a-agent-card"';
        }

        if ($this->config->isAgentSkillsIndexEnabled()) {
            $links[] = '</.well-known/agent-skills/index.json>; rel="agent-skills"';
        }

        if ($this->config->isOAuthDiscoveryEnabled()) {
            $links[] = '</.well-known/oauth-authorization-server>; rel="oauth-authorization-server"';
        }

        if ($this->config->isOAuthProtectedResourceEnabled()) {
            $links[] = '</.well-known/oauth-protected-resource>; rel="oauth-protected-resource"';
        }

        return $links;
    }

    private function isHomepage(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if ($route === 'frontend.home.page') {
            return true;
        }

        // fallback for sub-requests / proxied paths
        $path = $request->getPathInfo();
        return $path === '/' || $path === '';
    }
}
