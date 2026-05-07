<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillRegistry;
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
    public function __construct(
        private readonly AgentConfig $config,
        private readonly SkillRegistry $skills,
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

        // Only advertise endpoints that actually exist on a stock Shopware 6.7
        // installation. The Store-API has no health-check route, and the Admin
        // API no longer ships a HTML Swagger UI under /api/_info/swagger.html
        // — for those we drop the rel rather than emit a 404 hint.
        $linkset = [
            [
                'anchor' => $base . '/store-api',
                'service-desc' => [
                    ['href' => 'https://shopware.stoplight.io/api-reference/store-api'],
                ],
                'service-doc' => [
                    ['href' => 'https://shopware.stoplight.io/docs/store-api'],
                ],
            ],
            [
                'anchor' => $base . '/api',
                'service-desc' => [
                    ['href' => $base . '/api/_info/openapi3.json'],
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
     * MCP wire-protocol version and a transport descriptor. We also emit
     * `serverInfo` and an empty `capabilities` object so validators that
     * look for either the discovery shape or the initialize-response shape
     * are satisfied.
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

        $name = 'shopware-storefront';
        $version = '1.0.0';

        $payload = [
            'name' => $name,
            'description' => 'Discovery card for the Shopware 6 storefront. The MCP server is hosted by this plugin at /mcp.',
            'version' => $version,
            'protocolVersion' => '2025-06-18',
            'endpoint' => $endpoint !== '' ? $endpoint : $base . '/mcp',
            'transport' => 'streamable-http',
            // SEP-1649 / MCP initialize compatibility: validators look for
            // either top-level name/version or the nested serverInfo shape.
            'serverInfo' => [
                'name' => $name,
                'version' => $version,
            ],
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
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
            'url' => $base . '/a2a',
            'preferredTransport' => 'JSONRPC',
            'version' => '1.0.0',
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['application/json'],
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'stateTransitionHistory' => false,
            ],
            'skills' => $this->enabledA2aSkills($sc),
        ];

        return $this->jsonWithType($payload, 'application/json');
    }

    /** @return array<int, array{id: string, name: string, description: string, tags: array<int, string>}> */
    private function enabledA2aSkills(?string $sc): array
    {
        $out = [];
        foreach ($this->skills->asA2aSkillList() as $skill) {
            if ($this->config->isSkillEnabled($skill['id'], true, $sc)) {
                $out[] = $skill;
            }
        }
        return $out;
    }

    #[Route(
        path: '/.well-known/agent-skills/index.json',
        name: 'frontend.coding9.agent_ready.agent_skills_index',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function agentSkillsIndex(Request $request): Response
    {
        $sc = $this->salesChannelId($request);
        if (!$this->config->isAgentSkillsIndexEnabled($sc)) {
            return $this->disabled();
        }

        $base = $this->absoluteBase($request);

        $skills = [];
        foreach ($this->skills->all() as $skill) {
            if (!$this->config->isSkillEnabled($skill->id, true, $sc)) {
                continue;
            }
            $skills[] = [
                'name' => $skill->id,
                'type' => 'task',
                'description' => $skill->description,
                'url' => $base . '/.well-known/agent-skills/' . $skill->id . '/SKILL.md',
                'sha256' => hash('sha256', $skill->body),
            ];
        }

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
        $sc = $this->salesChannelId($request);
        if (!$this->config->isAgentSkillsIndexEnabled($sc)) {
            return $this->disabled();
        }

        $skill = $this->skills->get($slug);
        if ($skill === null || !$this->config->isSkillEnabled($skill->id, true, $sc)) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        return new Response($skill->body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
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
