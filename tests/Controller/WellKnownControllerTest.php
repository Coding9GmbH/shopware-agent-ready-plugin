<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\WellKnownController;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

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

    public function testOpenIdConfigurationMirrorsOAuth(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $oauth = json_decode((string) $controller->oauthAuthorizationServer($this->request('https://x.example/'))->getContent(), true);
        $oidc  = json_decode((string) $controller->openIdConfiguration($this->request('https://x.example/'))->getContent(), true);
        self::assertSame($oauth, $oidc);
    }

    public function testOAuthProtectedResource(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->oauthProtectedResource($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://shop.example/api', $payload['resource']);
        self::assertSame(['https://shop.example'], $payload['authorization_servers']);
    }

    public function testMcpServerCardDefaultsEndpointToOrigin(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->mcpServerCard($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('shopware-storefront', $payload['serverInfo']['name']);
        self::assertSame('https://shop.example/mcp', $payload['transport']['endpoint']);
    }

    public function testMcpServerCardHonoursConfiguredEndpoint(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.mcpServerEndpoint' => 'https://mcp.example/v1',
        ]);
        $controller = $this->controller($reader);
        $response = $controller->mcpServerCard($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://mcp.example/v1', $payload['transport']['endpoint']);
    }

    public function testA2aAgentCardHasSkillsAndInterfaces(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->a2aAgentCard($this->request('https://shop.example/'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotEmpty($payload['skills']);
        foreach ($payload['skills'] as $skill) {
            self::assertArrayHasKey('id', $skill);
            self::assertArrayHasKey('name', $skill);
            self::assertArrayHasKey('description', $skill);
        }
        self::assertSame('https://shop.example/store-api', $payload['supportedInterfaces'][0]['url']);
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
        $response = $controller->agentSkillMarkdown('search-products');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('# Search products', (string) $response->getContent());
    }

    public function testAgentSkillMarkdownReturns404ForUnknownSlug(): void
    {
        $controller = $this->controller(new ArrayConfigReader());
        $response = $controller->agentSkillMarkdown('does-not-exist');
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
            $body = (string) $controller->agentSkillMarkdown($skill['name'])->getContent();
            self::assertSame(
                $skill['sha256'],
                hash('sha256', $body),
                'Skill index sha256 must match the body served by /SKILL.md (skill: ' . $skill['name'] . ')'
            );
        }
    }

    private function controller(ArrayConfigReader $reader): WellKnownController
    {
        return new WellKnownController(new AgentConfig($reader), $this->router());
    }

    private function request(string $base = 'https://shop.example/'): Request
    {
        return Request::create($base);
    }

    private function router(): RouterInterface
    {
        return new class implements RouterInterface {
            private RequestContext $context;
            public function __construct() { $this->context = new RequestContext(); }
            public function setContext(RequestContext $context): void { $this->context = $context; }
            public function getContext(): RequestContext { return $this->context; }
            public function getRouteCollection(): RouteCollection { return new RouteCollection(); }
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string { return '/'; }
            public function match(string $pathinfo): array { return []; }
        };
    }
}
