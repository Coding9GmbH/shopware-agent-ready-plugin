<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

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

class McpControllerTest extends TestCase
{
    public function testReturnsTwoHundredForValidJsonRpcRequest(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $response = $this->controller()->dispatch(
            Request::create('https://shop.example/mcp', 'POST', content: (string) $body)
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['id']);
        self::assertArrayHasKey('serverInfo', $payload['result']);
    }

    public function testHandlesBatchRequests(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ]);
        $response = $this->controller()->dispatch(
            Request::create('https://shop.example/mcp', 'POST', content: (string) $body)
        );

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload);
        self::assertSame(1, $payload[0]['id']);
        self::assertSame(2, $payload[1]['id']);
    }

    public function testReturnsTwoHundredFourForBatchOfNotifications(): void
    {
        $body = json_encode([['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]);
        $response = $this->controller()->dispatch(
            Request::create('https://shop.example/mcp', 'POST', content: (string) $body)
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testReturnsParseErrorOnNonJsonBody(): void
    {
        $response = $this->controller()->dispatch(
            Request::create('https://shop.example/mcp', 'POST', content: 'not-json')
        );
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(-32700, $payload['error']['code']);
    }

    public function testReturnsNotFoundWhenDisabled(): void
    {
        $controller = $this->controller(new ArrayConfigReader([
            'Coding9AgentReady.config.enableMcpServer' => false,
        ]));
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $response = $controller->dispatch(
            Request::create('https://shop.example/mcp', 'POST', content: (string) $body)
        );
        self::assertSame(404, $response->getStatusCode());
    }

    private function controller(?ArrayConfigReader $reader = null): McpController
    {
        $reader ??= new ArrayConfigReader();
        $config = new AgentConfig($reader);
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
