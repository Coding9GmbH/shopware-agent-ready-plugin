<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Mcp;

use Coding9\AgentReady\Mcp\McpServer;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\StoreApi\StoreApiResponse;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
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

        $names = array_column($response['result']['tools'], 'name');
        self::assertContains('search-products', $names);
        self::assertContains('get-product', $names);
        self::assertContains('manage-cart', $names);
        self::assertContains('create-context', $names);
        self::assertContains('get-cart', $names);
    }

    public function testToolsCallRunsExecutorAndReturnsRealStoreApiResult(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, (string) json_encode([
            'total' => 0,
            'elements' => [],
        ])));
        $response = $this->server($client)->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'search-products', 'arguments' => ['query' => 'shoes']],
        ], 'sc-1');

        self::assertFalse($response['result']['isError']);
        self::assertSame('text', $response['result']['content'][0]['type']);
        self::assertSame(0, $response['result']['structuredContent']['total']);
        self::assertSame('sc-1', $client->calls[0]['accessKey'] !== null ? 'sc-1' : null, 'sales channel id propagated');
    }

    public function testToolsCallReturnsIsErrorTrueOnStoreApiFailure(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(500, (string) json_encode([
            'errors' => [['title' => 'kaboom', 'detail' => 'something broke']],
        ])));
        $response = $this->server($client)->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'search-products', 'arguments' => ['query' => 'x']],
        ], 'sc-1');

        self::assertTrue($response['result']['isError']);
        self::assertSame('kaboom', $response['result']['structuredContent']['error']);
    }

    public function testToolsCallReturnsInvalidParamsForBadArgs(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'search-products', 'arguments' => []],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function testUnknownToolReturnsMethodNotFound(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'no-such-skill', 'arguments' => []],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function testUnknownMethodReturnsMethodNotFound(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'sampling/createMessage',
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    public function testNotificationProducesNoResponse(): void
    {
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
            'id' => 8,
        ]);
        self::assertSame(-32600, $response['error']['code']);
    }

    private function server(?FakeStoreApiClient $client = null): McpServer
    {
        return new McpServer(
            new SkillRegistry(),
            new SkillExecutor($client ?? new FakeStoreApiClient(), new StaticSalesChannelKeyResolver()),
        );
    }
}
