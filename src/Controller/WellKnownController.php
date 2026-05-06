<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves /.well-known/* endpoints used by AI agents, MCP and A2A clients to
 * discover capabilities of the Shopware storefront.
 *
 * Each endpoint can be toggled independently via plugin configuration.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WellKnownController extends AbstractController
{
    /** Single source of truth for the canonical SKILL.md bodies. */
    private const SKILL_BODIES = [
        'search-products' => "# Search products\n\nUse the Store API endpoint `POST /store-api/search` to look up products by keyword. Required input: `search` string.\n",
        'place-order'     => "# Place order\n\n1. Build a cart via `POST /store-api/checkout/cart`.\n2. Add items via `POST /store-api/checkout/cart/line-item`.\n3. Place the order via `POST /store-api/checkout/order`.\n",
        'manage-cart'     => "# Manage cart\n\nUse `/store-api/checkout/cart/line-item` (POST/PATCH/DELETE) to add, update or remove line items in the current cart.\n",
    ];

    public function __construct(private readonly AgentConfig $config)
    {
    }

    #[Route(
        path: '/.well-known/api-catalog',
        name: 'frontend.coding9.agent_ready.api_catalog',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function apiCatalog(Request $request): Response
    {
        if (!$this->config->isApiCatalogEnabled($this->salesChannelId($request))) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);

        $linkset = [
            [
                'anchor' => $base . '/store-api',
                'service-desc' => [
                    ['href' => 'https://shopware.stoplight.io/api-reference/store-api'],
                ],
                'service-doc' => [
                    ['href' => 'https://shopware.stoplight.io/docs/store-api'],
                ],
                'status' => [
                    ['href' => $base . '/store-api/_info/health-check'],
                ],
            ],
            [
                'anchor' => $base . '/api',
                'service-desc' => [
                    ['href' => $base . '/api/_info/openapi3.json'],
                ],
                'service-doc' => [
                    ['href' => $base . '/api/_info/swagger.html'],
                ],
                'status' => [
                    ['href' => $base . '/api/_info/health-check'],
                ],
            ],
        ];

        return $this->jsonWithType(['linkset' => $linkset], 'application/linkset+json');
    }

    /**
     * RFC 8414 — OAuth 2.0 Authorization Server Metadata.
     *
     * Shopware does NOT expose an interactive authorization endpoint. The
     * Admin / Store API uses the password, client_credentials and
     * refresh_token grants directly against /api/oauth/token. We therefore
     * publish only the fields that are actually true for Shopware.
     */
    #[Route(
        path: '/.well-known/oauth-authorization-server',
        name: 'frontend.coding9.agent_ready.oauth_authorization_server',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function oauthAuthorizationServer(Request $request): Response
    {
        if (!$this->config->isOAuthDiscoveryEnabled($this->salesChannelId($request))) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);

        $payload = [
            'issuer' => $base,
            'token_endpoint' => $base . '/api/oauth/token',
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],
            'grant_types_supported' => [
                'password',
                'client_credentials',
                'refresh_token',
            ],
            'scopes_supported' => ['write', 'read'],
            'service_documentation' => 'https://developer.shopware.com/docs/guides/integrations-api/general-concepts/authentication.html',
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    #[Route(
        path: '/.well-known/oauth-protected-resource',
        name: 'frontend.coding9.agent_ready.oauth_protected_resource',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function oauthProtectedResource(Request $request): Response
    {
        if (!$this->config->isOAuthProtectedResourceEnabled($this->salesChannelId($request))) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);

        $payload = [
            'resource' => $base . '/api',
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['write', 'read'],
            'resource_documentation' => 'https://developer.shopware.com/docs/guides/integrations-api/admin-api',
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    /**
     * MCP Server Card discovery document.
     *
     * Shape follows the SEP-1649 server-card discovery proposal:
     * top-level name/description/version, an absolute endpoint URL, the
     * MCP wire-protocol version and a transport descriptor. The MCP
     * `initialize` capabilities object is intentionally NOT served here —
     * that is the response of an MCP session, not a discovery card.
     */
    #[Route(
        path: '/.well-known/mcp/server-card.json',
        name: 'frontend.coding9.agent_ready.mcp_server_card',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function mcpServerCard(Request $request): Response
    {
        $sc = $this->salesChannelId($request);
        if (!$this->config->isMcpServerCardEnabled($sc)) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);
        $endpoint = $this->config->getMcpServerEndpoint($sc);

        $payload = [
            'name' => 'shopware-storefront',
            'description' => 'Discovery card for the Shopware 6 storefront. The MCP transport itself is provided by a separate plugin or external bridge.',
            'version' => '1.0.0',
            'protocolVersion' => '2025-06-18',
            'endpoint' => $endpoint !== '' ? $endpoint : $base . '/mcp',
            'transport' => 'streamable-http',
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    /**
     * A2A Agent Card per a2a-protocol.org/latest/specification.
     *
     * Mandatory: protocolVersion, name, description, url, version,
     * preferredTransport, defaultInputModes, defaultOutputModes,
     * capabilities, skills (each with id, name, description, tags).
     */
    #[Route(
        path: '/.well-known/agent-card.json',
        name: 'frontend.coding9.agent_ready.a2a_agent_card',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function a2aAgentCard(Request $request): Response
    {
        $sc = $this->salesChannelId($request);
        if (!$this->config->isA2aAgentCardEnabled($sc)) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);

        $payload = [
            'protocolVersion' => '0.3.0',
            'name' => $this->config->getA2aAgentName($sc),
            'description' => $this->config->getA2aAgentDescription($sc),
            'url' => $base . '/store-api',
            'preferredTransport' => 'JSONRPC',
            'version' => '1.0.0',
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['application/json'],
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'stateTransitionHistory' => false,
            ],
            'skills' => [
                [
                    'id' => 'search-products',
                    'name' => 'Search products',
                    'description' => 'Search the product catalog by keyword.',
                    'tags' => ['search', 'catalog', 'products'],
                ],
                [
                    'id' => 'get-product',
                    'name' => 'Get product detail',
                    'description' => 'Retrieve detailed information for a single product by id or seo url.',
                    'tags' => ['catalog', 'products'],
                ],
                [
                    'id' => 'add-to-cart',
                    'name' => 'Add product to cart',
                    'description' => 'Add a product line item to the current shopping cart.',
                    'tags' => ['cart', 'checkout'],
                ],
                [
                    'id' => 'place-order',
                    'name' => 'Place order',
                    'description' => 'Place an order for the items in the current cart.',
                    'tags' => ['checkout', 'orders'],
                ],
            ],
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    #[Route(
        path: '/.well-known/agent-skills/index.json',
        name: 'frontend.coding9.agent_ready.agent_skills_index',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function agentSkillsIndex(Request $request): Response
    {
        if (!$this->config->isAgentSkillsIndexEnabled($this->salesChannelId($request))) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);

        $skills = [
            $this->skillEntry(
                'search-products',
                'task',
                'Search the product catalog of the Shopware storefront.',
                $base . '/.well-known/agent-skills/search-products/SKILL.md'
            ),
            $this->skillEntry(
                'place-order',
                'task',
                'Place an order with items from the current cart.',
                $base . '/.well-known/agent-skills/place-order/SKILL.md'
            ),
            $this->skillEntry(
                'manage-cart',
                'task',
                'Add, update or remove items in the shopping cart.',
                $base . '/.well-known/agent-skills/manage-cart/SKILL.md'
            ),
        ];

        $payload = [
            '$schema' => 'https://agentskills.io/schemas/index/v0.2.0.json',
            'skills' => $skills,
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    #[Route(
        path: '/.well-known/agent-skills/{slug}/SKILL.md',
        name: 'frontend.coding9.agent_ready.agent_skill_md',
        requirements: ['slug' => '[a-z0-9-]+'],
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function agentSkillMarkdown(Request $request, string $slug): Response
    {
        if (!$this->config->isAgentSkillsIndexEnabled($this->salesChannelId($request))) {
            return $this->disabled();
        }

        if (!array_key_exists($slug, self::SKILL_BODIES)) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        return new Response(self::SKILL_BODIES[$slug], 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return array{name: string, type: string, description: string, url: string, sha256: string}
     */
    private function skillEntry(string $name, string $type, string $description, string $url): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'description' => $description,
            'url' => $url,
            'sha256' => hash('sha256', self::SKILL_BODIES[$name] ?? ''),
        ];
    }

    private function absoluteBase(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonWithType(array $payload, string $contentType): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }

    private function disabled(): Response
    {
        return new Response('not found', 404, ['Content-Type' => 'text/plain']);
    }
}
