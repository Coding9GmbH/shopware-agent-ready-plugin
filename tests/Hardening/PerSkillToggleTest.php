<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Hardening;

use Coding9\AgentReady\Mcp\McpServer;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
use PHPUnit\Framework\TestCase;

class PerSkillToggleTest extends TestCase
{
    public function testDisabledSkillIsHiddenFromToolsList(): void
    {
        $config = $this->config(['Coding9AgentReady.config.enableSkill_place_order' => false]);
        $server = $this->server($config);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $names = array_column($response['result']['tools'], 'name');
        self::assertNotContains('place-order', $names);
        self::assertContains('search-products', $names);
    }

    public function testDisabledSkillReturnsMethodNotFoundOnToolsCall(): void
    {
        $config = $this->config(['Coding9AgentReady.config.enableSkill_customer_login' => false]);
        $server = $this->server($config);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'customer-login', 'arguments' => [
                'contextToken' => 'tok-x',
                'username' => 'a@b.com',
                'password' => 'pw',
            ]],
        ]);

        self::assertSame(-32601, $response['error']['code']);
    }

    private function config(array $values): AgentConfig
    {
        return new AgentConfig(new ArrayConfigReader($values));
    }

    private function server(AgentConfig $config): McpServer
    {
        return new McpServer(
            new SkillRegistry(),
            new SkillExecutor(new FakeStoreApiClient(), new StaticSalesChannelKeyResolver(), $config),
            $config,
        );
    }
}
