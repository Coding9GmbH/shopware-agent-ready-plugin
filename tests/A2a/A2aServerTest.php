<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\A2a;

use Coding9\AgentReady\A2a\A2aServer;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\StoreApi\StoreApiResponse;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
use PHPUnit\Framework\TestCase;

class A2aServerTest extends TestCase
{
    public function testMessageSendDispatchesSkillAndReturnsAgentMessage(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, '{"total":0,"elements":[]}'));
        $server = $this->server($client);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'a',
            'method' => 'message/send',
            'params' => [
                'message' => [
                    'role' => 'user',
                    'parts' => [[
                        'kind' => 'data',
                        'data' => ['skill' => 'search-products', 'arguments' => ['query' => 'shoes']],
                    ]],
                ],
            ],
        ], 'sc-1');

        self::assertSame('a', $response['id']);
        self::assertSame('message', $response['result']['kind']);
        self::assertSame('agent', $response['result']['role']);
        self::assertSame(0, $response['result']['parts'][0]['data']['total']);
    }

    public function testMissingSkillReturnsInvalidParams(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 'b',
            'method' => 'message/send',
            'params' => ['message' => ['parts' => [['kind' => 'data', 'data' => []]]]],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function testUnknownMethodErrors(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'id' => 'c',
            'method' => 'tasks/cancel',
        ]);
        self::assertArrayHasKey('error', $response);
    }

    private function server(?FakeStoreApiClient $client = null): A2aServer
    {
        $config = new \Coding9\AgentReady\Service\AgentConfig(new \Coding9\AgentReady\Tests\Support\ArrayConfigReader());
        return new A2aServer(
            new SkillRegistry(),
            new SkillExecutor($client ?? new FakeStoreApiClient(), new StaticSalesChannelKeyResolver(), $config),
            $config,
        );
    }
}
