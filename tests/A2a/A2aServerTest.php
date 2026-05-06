<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\A2a;

use Coding9\AgentReady\A2a\A2aServer;
use Coding9\AgentReady\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class A2aServerTest extends TestCase
{
    public function testMessageSendDispatchesSkillAndReturnsAgentMessage(): void
    {
        $server = new A2aServer(new SkillRegistry());

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'a',
            'method' => 'message/send',
            'params' => [
                'message' => [
                    'role' => 'user',
                    'parts' => [
                        [
                            'kind' => 'data',
                            'data' => [
                                'skill' => 'search-products',
                                'arguments' => ['query' => 'shoes'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('a', $response['id']);
        self::assertSame('message', $response['result']['kind']);
        self::assertSame('agent', $response['result']['role']);
        self::assertSame('data', $response['result']['parts'][0]['kind']);
        self::assertSame('/store-api/search', $response['result']['parts'][0]['data']['path']);
    }

    public function testMissingSkillReturnsInvalidParams(): void
    {
        $server = new A2aServer(new SkillRegistry());

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'b',
            'method' => 'message/send',
            'params' => [
                'message' => ['parts' => [['kind' => 'data', 'data' => []]]],
            ],
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function testUnknownMethodErrors(): void
    {
        $server = new A2aServer(new SkillRegistry());

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 'c',
            'method' => 'tasks/cancel',
        ]);

        self::assertArrayHasKey('error', $response);
    }
}
