<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\McpController;
use Coding9\AgentReady\Mcp\McpServer;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class McpControllerTest extends TestCase
{
    public function testReturnsTwoHundredForValidJsonRpcRequest(): void
    {
        $controller = $this->controller();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]);
        $request = Request::create('https://shop.example/mcp', 'POST', content: (string) $body);

        $response = $controller->dispatch($request);
        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['id']);
        self::assertArrayHasKey('serverInfo', $payload['result']);
    }

    public function testHandlesBatchRequests(): void
    {
        $controller = $this->controller();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ]);
        $request = Request::create('https://shop.example/mcp', 'POST', content: (string) $body);

        $response = $controller->dispatch($request);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(2, $payload);
        self::assertSame(1, $payload[0]['id']);
        self::assertSame(2, $payload[1]['id']);
    }

    public function testReturnsTwoHundredFourForBatchOfNotifications(): void
    {
        $controller = $this->controller();
        $body = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ]);
        $request = Request::create('https://shop.example/mcp', 'POST', content: (string) $body);

        $response = $controller->dispatch($request);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testReturnsParseErrorOnNonJsonBody(): void
    {
        $controller = $this->controller();
        $request = Request::create('https://shop.example/mcp', 'POST', content: 'not-json');

        $response = $controller->dispatch($request);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(-32700, $payload['error']['code']);
    }

    public function testReturnsNotFoundWhenDisabled(): void
    {
        $controller = $this->controller(new ArrayConfigReader([
            'Coding9AgentReady.config.enableMcpServer' => false,
        ]));
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $request = Request::create('https://shop.example/mcp', 'POST', content: (string) $body);

        $response = $controller->dispatch($request);
        self::assertSame(404, $response->getStatusCode());
    }

    private function controller(?ArrayConfigReader $reader = null): McpController
    {
        $reader ??= new ArrayConfigReader();
        return new McpController(
            new AgentConfig($reader),
            new McpServer(new SkillRegistry()),
        );
    }
}
