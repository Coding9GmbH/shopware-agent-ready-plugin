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
 * All endpoints honour the plugin configuration, so a shop owner can disable
 * any single endpoint independently.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WellKnownController extends AbstractController
{
    public function __construct(
        private readonly AgentConfig $config,
    ) {
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
                    ['href' => 'https://github.com/shopware/shopware/blob/trunk/src/Core/Framework/Api/ApiDefinition/StoreApiDefinition.php'],
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
            'authorization_endpoint' => $base . '/api/oauth/authorize',
            'token_endpoint' => $base . '/api/oauth/token',
            'jwks_uri' => $base . '/api/oauth/jwks',
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],
            'grant_types_supported' => [
                'password',
                'client_credentials',
                'refresh_token',
            ],
            'response_types_supported' => ['token'],
            'scopes_supported' => ['write', 'read'],
            'service_documentation' => 'https://developer.shopware.com/docs/guides/integrations-api/general-concepts/authentication.html',
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    #[Route(
        path: '/.well-known/openid-configuration',
        name: 'frontend.coding9.agent_ready.openid_configuration',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function openIdConfiguration(Request $request): Response
    {
        // Mirrors the OAuth metadata so agents that look up either path succeed.
        return $this->oauthAuthorizationServer($request);
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
            'serverInfo' => [
                'name' => 'shopware-storefront',
                'version' => '1.0.0',
            ],
            'transport' => [
                'type' => 'http',
                'endpoint' => $endpoint !== '' ? $endpoint : $base . '/mcp',
            ],
            'capabilities' => [
                'tools' => (object) [],
                'resources' => (object) [],
                'prompts' => (object) [],
            ],
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

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
            'name' => $this->config->getA2aAgentName($sc),
            'version' => '1.0.0',
            'description' => $this->config->getA2aAgentDescription($sc),
            'supportedInterfaces' => [
                [
                    'url' => $base . '/store-api',
                    'transport' => 'jsonrpc-2.0',
                    'protocol' => 'https://a2a-protocol.org/',
                ],
            ],
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
                ],
                [
                    'id' => 'get-product',
                    'name' => 'Get product detail',
                    'description' => 'Retrieve detailed information for a single product by id or seo url.',
                ],
                [
                    'id' => 'add-to-cart',
                    'name' => 'Add product to cart',
                    'description' => 'Add a product line item to the current shopping cart.',
                ],
                [
                    'id' => 'place-order',
                    'name' => 'Place order',
                    'description' => 'Place an order for the items in the current cart.',
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

        $skills = [
            'search-products' => "# Search products\n\nUse the Store API endpoint `POST /store-api/search` to look up products by keyword. Required input: `search` string.\n",
            'place-order'     => "# Place order\n\n1. Build a cart via `POST /store-api/checkout/cart`.\n2. Add items via `POST /store-api/checkout/cart/line-item`.\n3. Place the order via `POST /store-api/checkout/order`.\n",
            'manage-cart'     => "# Manage cart\n\nUse `/store-api/checkout/cart/line-item` (POST/PATCH/DELETE) to add, update or remove line items in the current cart.\n",
        ];

        if (!array_key_exists($slug, $skills)) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        return new Response($skills[$slug], 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return array{name: string, type: string, description: string, url: string, sha256: string}
     */
    private function skillEntry(string $name, string $type, string $description, string $url): array
    {
        // The hash references the canonical body the controller serves. Since
        // we generate it from a constant string per skill, the hash stays
        // stable as long as the body does.
        $bodies = [
            'search-products' => "# Search products\n\nUse the Store API endpoint `POST /store-api/search` to look up products by keyword. Required input: `search` string.\n",
            'place-order'     => "# Place order\n\n1. Build a cart via `POST /store-api/checkout/cart`.\n2. Add items via `POST /store-api/checkout/cart/line-item`.\n3. Place the order via `POST /store-api/checkout/order`.\n",
            'manage-cart'     => "# Manage cart\n\nUse `/store-api/checkout/cart/line-item` (POST/PATCH/DELETE) to add, update or remove line items in the current cart.\n",
        ];
        $body = $bodies[$name] ?? '';

        return [
            'name' => $name,
            'type' => $type,
            'description' => $description,
            'url' => $url,
            'sha256' => hash('sha256', $body),
        ];
    }

    private function absoluteBase(Request $request): string
    {
        $scheme = $request->getScheme();
        $host = $request->getHttpHost();
        return rtrim($scheme . '://' . $host, '/');
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
