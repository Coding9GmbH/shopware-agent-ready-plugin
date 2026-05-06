<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\WellKnownController;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class WellKnownControllerTest extends TestCase
{
    public function testApiCatalogReturnsLinksetWithKnownAnchors(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->apiCatalog($this->request('https://shop.example/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/linkset+json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('linkset', $payload);
        self::assertCount(2, $payload['linkset']);
        $anchors = array_column($payload['linkset'], 'anchor');
        self::assertContains('https://shop.example/store-api', $anchors);
        self::assertContains('https://shop.example/api', $anchors);

        foreach ($payload['linkset'] as $entry) {
            self::assertArrayHasKey('service-desc', $entry);
            self::assertArrayHasKey('service-doc', $entry);
            self::assertArrayHasKey('status', $entry);
        }
    }

    public function testApiCatalogReturns404WhenDisabled(): void
    {
        $controller = $this->controller(new ArrayConfigReader([
            'Coding9AgentReady.config.enableApiCatalog' => false,
        ]));
        $response = $controller->apiCatalog($this->request());
        self::assertSame(404, $response->getStatusCode());
    }

    public function testOAuthAuthorizationServerHasMandatoryFields(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->oauthAuthorizationServer($this->request('https://shop.example/'));

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://shop.example', $payload['issuer']);
        self::assertSame('https://shop.example/api/oauth/token', $payload['token_endpoint']);
        self::assertContains('client_credentials', $payload['grant_types_supported']);
    }

    public function testOAuthMetadataDoesNotAdvertiseUnsupportedFlows(): void
    {
        // Shopware has no interactive authorization endpoint and no JWKS,
        // and only supports the password / client_credentials / refresh_token
        // grants. Anything else would mislead clients.
        $controller = $this->controller(new ArrayConfigReader());
        $payload = json_decode(
            (string) $controller->oauthAuthorizationServer($this->request('https://shop.example/'))->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        self::assertArrayNotHasKey('authorization_endpoint', $payload);
        self::assertArrayNotHasKey('jwks_uri', $payload);
        self::assertArrayNotHasKey('response_types_supported', $payload);
        self::assertSame(
            ['password', 'client_credentials', 'refresh_token'],
            $payload['grant_types_supported']
        );
    }

    public function testOAuthProtectedResource(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->oauthProtectedResource($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://shop.example/api', $payload['resource']);
        self::assertSame(['https://shop.example'], $payload['authorization_servers']);
    }

    public function testMcpServerCardFollowsDiscoveryShape(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->mcpServerCard($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        // SEP-1649 discovery shape with initialize-response compatibility:
        // both top-level name/version and nested serverInfo.name/version
        // so validators that expect either form succeed.
        self::assertSame('shopware-storefront', $payload['name']);
        self::assertSame('https://shop.example/mcp', $payload['endpoint']);
        self::assertArrayHasKey('protocolVersion', $payload);
        self::assertArrayHasKey('transport', $payload);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('serverInfo', $payload);
        self::assertSame('shopware-storefront', $payload['serverInfo']['name']);
        self::assertSame('1.0.0', $payload['serverInfo']['version']);
        self::assertArrayHasKey('capabilities', $payload);
    }

    public function testMcpServerCardHonoursConfiguredEndpoint(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.mcpServerEndpoint' => 'https://mcp.example/v1',
        ]);
        $controller = $this->controller($reader);
        $response = $controller->mcpServerCard($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://mcp.example/v1', $payload['endpoint']);
    }

    public function testA2aAgentCardMatchesA2aProtocolSpec(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->a2aAgentCard($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        // Mandatory top-level fields per a2a-protocol.org/latest/specification.
        foreach (
            ['protocolVersion', 'name', 'description', 'url', 'preferredTransport',
             'version', 'defaultInputModes', 'defaultOutputModes', 'capabilities', 'skills']
            as $field
        ) {
            self::assertArrayHasKey($field, $payload, "missing required A2A field: $field");
        }

        self::assertSame('https://shop.example/a2a', $payload['url']);
        self::assertSame('JSONRPC', $payload['preferredTransport']);
        self::assertIsArray($payload['defaultInputModes']);
        self::assertIsArray($payload['defaultOutputModes']);
        self::assertNotEmpty($payload['skills']);

        foreach ($payload['skills'] as $skill) {
            self::assertArrayHasKey('id', $skill);
            self::assertArrayHasKey('name', $skill);
            self::assertArrayHasKey('description', $skill);
            self::assertArrayHasKey('tags', $skill, 'A2A skills must declare tags');
            self::assertIsArray($skill['tags']);
            self::assertNotEmpty($skill['tags']);
        }
    }

    public function testAgentSkillsIndexLooksValid(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->agentSkillsIndex($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('$schema', $payload);
        self::assertNotEmpty($payload['skills']);
        foreach ($payload['skills'] as $skill) {
            self::assertArrayHasKey('name', $skill);
            self::assertArrayHasKey('type', $skill);
            self::assertArrayHasKey('description', $skill);
            self::assertArrayHasKey('url', $skill);
            self::assertArrayHasKey('sha256', $skill);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $skill['sha256']);
        }
    }

    public function testAgentSkillMarkdownReturnsContent(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->agentSkillMarkdown($this->request(), 'search-products');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('# Search products', (string) $response->getContent());
    }

    public function testAgentSkillMarkdownReturns404ForUnknownSlug(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->agentSkillMarkdown($this->request(), 'does-not-exist');
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAgentSkillSha256MatchesPublishedBody(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $index = json_decode(
            (string) $controller->agentSkillsIndex($this->request('https://shop.example/'))->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        foreach ($index['skills'] as $skill) {
            $body = (string) $controller->agentSkillMarkdown($this->request(), $skill['name'])->getContent();
            self::assertSame(
                $skill['sha256'],
                hash('sha256', $body),
                'Skill index sha256 must match the body served by /SKILL.md (skill: ' . $skill['name'] . ')'
            );
        }
    }

    public function testRequestSalesChannelIdIsForwardedToConfig(): void
    {
        // Requests carrying sw-sales-channel-id should not break the response,
        // and the well-known endpoint should still work as before.
        $request = Request::create('https://shop.example/');
        $request->attributes->set('sw-sales-channel-id', '01HZW0SCTESTSALESCHANNELID');
        $controller = $this->controller(new ArrayConfigReader());

        self::assertSame(200, $controller->apiCatalog($request)->getStatusCode());
        self::assertSame(200, $controller->mcpServerCard($request)->getStatusCode());
        self::assertSame(200, $controller->a2aAgentCard($request)->getStatusCode());
    }

    private function controller(ArrayConfigReader $reader): WellKnownController
    {
        return new WellKnownController(new AgentConfig($reader), new SkillRegistry());
    }

    private function request(string $base = 'https://shop.example/'): Request
    {
        return Request::create($base);
    }
}
