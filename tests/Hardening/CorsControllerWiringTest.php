<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Hardening;

use Coding9\AgentReady\Controller\McpController;
use Coding9\AgentReady\Mcp\McpServer;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CorsControllerWiringTest extends TestCase
{
    public function testNoCorsHeaderByDefault(): void
    {
        $controller = $this->controller([]);
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $request = Request::create('https://shop.example/mcp', 'POST', content: $body);
        $request->headers->set('Origin', 'https://attacker.example');

        $response = $controller->dispatch($request);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testEchoesAllowedOriginOnMatch(): void
    {
        $controller = $this->controller([
            'Coding9AgentReady.config.corsAllowedOrigins' => 'https://claude.ai, https://chatgpt.com',
        ]);
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $request = Request::create('https://shop.example/mcp', 'POST', content: $body);
        $request->headers->set('Origin', 'https://claude.ai');

        $response = $controller->dispatch($request);

        self::assertSame('https://claude.ai', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function testNoHeaderWhenOriginNotInAllowlist(): void
    {
        $controller = $this->controller([
            'Coding9AgentReady.config.corsAllowedOrigins' => 'https://claude.ai',
        ]);
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $request = Request::create('https://shop.example/mcp', 'POST', content: $body);
        $request->headers->set('Origin', 'https://attacker.example');

        $response = $controller->dispatch($request);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @param array<string, mixed> $values */
    private function controller(array $values): McpController
    {
        $config = new AgentConfig(new ArrayConfigReader($values));
        return new McpController(
            $config,
            new McpServer(
                new SkillRegistry(),
                new SkillExecutor(new FakeStoreApiClient(), new StaticSalesChannelKeyResolver(), $config),
                $config,
            ),
        );
    }
}
