<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves /llms.txt and /llms-full.txt — the lightweight, machine-readable
 * Markdown index that LLM clients increasingly look up to learn what a site
 * exposes. See https://llmstxt.org/ for the format.
 *
 * For Shopware shops, /llms.txt points agents at the well-known endpoints,
 * the sitemap and the markdown content negotiation entry point. /llms-full.txt
 * contains the same plus a short canonical example so agents can dispatch
 * Accept: text/markdown requests for any storefront page.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class LlmsTxtController extends AbstractController
{
    public function __construct(private readonly AgentConfig $config)
    {
    }

    #[Route(
        path: '/llms.txt',
        name: 'frontend.coding9.agent_ready.llms_txt',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function llmsTxt(Request $request): Response
    {
        $sc = $this->salesChannelId($request);
        if (!$this->config->isLlmsTxtEnabled($sc)) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        return $this->markdown($this->buildIndex($request, $sc));
    }

    #[Route(
        path: '/llms-full.txt',
        name: 'frontend.coding9.agent_ready.llms_full_txt',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function llmsFullTxt(Request $request): Response
    {
        $sc = $this->salesChannelId($request);
        if (!$this->config->isLlmsTxtEnabled($sc)) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        $index = $this->buildIndex($request, $sc);
        $appendix = <<<MD


## How to fetch the full content of any page

Send `Accept: text/markdown` with any storefront request and the response is
converted from HTML to Markdown on the fly. Example:

```
curl -H 'Accept: text/markdown' {$this->absoluteBase($request)}/
```

The response carries `Content-Type: text/markdown` and an `x-markdown-tokens`
header with a rough token estimate, plus `Vary: Accept` so caches stay correct.
MD;

        return $this->markdown($index . $appendix);
    }

    public function buildIndex(Request $request, ?string $salesChannelId = null): string
    {
        $base = $this->absoluteBase($request);
        $name = $this->config->getSiteName($salesChannelId);
        if ($name === '') {
            $name = $request->getHttpHost() ?: 'Shopware Storefront';
        }
        $summary = $this->config->getSiteSummary($salesChannelId);

        $sections = [];

        $sections[] = "# {$name}";
        $sections[] = '';
        $sections[] = "> {$summary}";
        $sections[] = '';
        $sections[] = 'This shop publishes machine-readable discovery files at `/.well-known/*` '
            . 'and supports `Accept: text/markdown` content negotiation on every page.';
        $sections[] = '';

        $discovery = [];
        if ($this->config->isApiCatalogEnabled($salesChannelId)) {
            $discovery[] = "- [API catalog]({$base}/.well-known/api-catalog): Linkset (RFC 9727) "
                . 'pointing at the Shopware Store-API and Admin-API.';
        }
        if ($this->config->isA2aAgentCardEnabled($salesChannelId)) {
            $discovery[] = "- [A2A agent card]({$base}/.well-known/agent-card.json): "
                . 'Agent metadata for A2A-protocol clients.';
        }
        if ($this->config->isMcpServerCardEnabled($salesChannelId)) {
            $discovery[] = "- [MCP server card]({$base}/.well-known/mcp/server-card.json): "
                . 'MCP discovery card (SEP-1649).';
        }
        if ($this->config->isAgentSkillsIndexEnabled($salesChannelId)) {
            $discovery[] = "- [Agent skills index]({$base}/.well-known/agent-skills/index.json): "
                . 'Skills the storefront advertises (search-products, place-order, manage-cart).';
        }
        if ($this->config->isOAuthDiscoveryEnabled($salesChannelId)) {
            $discovery[] = "- [OAuth metadata]({$base}/.well-known/oauth-authorization-server): "
                . 'OAuth 2.0 / OIDC issuer metadata for authenticating against the Admin-API.';
        }
        if ($this->config->isOAuthProtectedResourceEnabled($salesChannelId)) {
            $discovery[] = "- [Protected resource metadata]({$base}/.well-known/oauth-protected-resource): "
                . 'RFC 9728 metadata describing the protected `/api` resource.';
        }

        if ($discovery) {
            $sections[] = '## Discovery';
            $sections[] = '';
            $sections = array_merge($sections, $discovery);
            $sections[] = '';
        }

        $sections[] = '## Content';
        $sections[] = '';
        $sections[] = "- [Storefront homepage]({$base}/): HTML by default; "
            . '`Accept: text/markdown` returns Markdown for agents.';
        $sections[] = "- [Sitemap]({$base}/sitemap.xml): All public storefront URLs.";
        $sections[] = "- [robots.txt]({$base}/robots.txt): Crawl rules and "
            . '[Content-Signal](https://contentsignals.org/) directives.';
        $sections[] = '';

        $sections[] = '## Notes';
        $sections[] = '';
        $sections[] = '- This file follows the [llms.txt](https://llmstxt.org/) convention.';
        $sections[] = '- Discovery hints are also exposed as RFC 8288 `Link:` response headers '
            . 'on the homepage.';
        $sections[] = '';

        return implode("\n", $sections);
    }

    private function absoluteBase(Request $request): string
    {
        return rtrim($request->getScheme() . '://' . $request->getHttpHost(), '/');
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function markdown(string $body): Response
    {
        return new Response($body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
