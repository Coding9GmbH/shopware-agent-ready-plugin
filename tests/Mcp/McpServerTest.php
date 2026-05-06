<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Mcp;

use Coding9\AgentReady\Mcp\McpServer;
use Coding9\AgentReady\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class McpServerTest extends TestCase
{
    public function testInitializeReturnsServerInfoAndProtocolVersion(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ]);

        self::assertNotNull($response);
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(1, $response['id']);
        self::assertArrayHasKey('protocolVersion', $response['result']);
        self::assertSame('shopware-storefront', $response['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function testToolsListExposesEverySkill(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        self::assertNotNull($response);
        $tools = $response['result']['tools'];
        $names = array_column($tools, 'name');

        self::assertContains('search-products', $names);
        self::assertContains('manage-cart', $names);
        self::assertContains('place-order', $names);
    }

    public function testToolsCallRunsDispatcherAndReturnsTextContent(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search-products',
                'arguments' => ['query' => 'shoes'],
            ],
        ]);

        self::assertNotNull($response);
        self::assertFalse($response['result']['isError']);
        self::assertSame('text', $response['result']['content'][0]['type']);

        $envelope = $response['result']['structuredContent'];
        self::assertSame('/store-api/search', $envelope['path']);
        self::assertSame('shoes', $envelope['body']['search']);
    }

    public function testToolsCallReturnsInvalidParamsForBadArgs(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'search-products', 'arguments' => []],
        ]);

        self::assertNotNull($response);
        self::assertSame(-32602, $response['error']['code']);
    }

    public function testUnknownToolReturnsMethodNotFound(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'no-such-skill', 'arguments' => []],
        ]);

        self::assertNotNull($response);
        self::assertSame(-32601, $response['error']['code']);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'sampling/createMessage',
        ]);

        self::assertNotNull($response);
        self::assertSame(-32601, $response['error']['code']);
    }

    public function testNotificationProducesNoResponse(): void
    {
        // No `id` => notification per JSON-RPC 2.0
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        self::assertNull($response);
    }

    public function testInvalidRequestMissingMethod(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
        ]);

        self::assertNotNull($response);
        self::assertSame(-32600, $response['error']['code']);
    }

    private function server(): McpServer
    {
        return new McpServer(new SkillRegistry());
    }
}
