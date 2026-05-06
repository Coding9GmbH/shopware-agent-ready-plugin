<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\A2a\A2aServer;
use Coding9\AgentReady\Controller\A2aController;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\StoreApi\StoreApiResponse;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class A2aControllerTest extends TestCase
{
    public function testDispatchesMessageSend(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, '{"total":0,"elements":[]}'));
        $controller = $this->controller(new ArrayConfigReader(), $client);

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'message/send',
            'params' => [
                'message' => [
                    'parts' => [[
                        'kind' => 'data',
                        'data' => ['skill' => 'search-products', 'arguments' => ['query' => 't-shirt']],
                    ]],
                ],
            ],
        ]);
        $response = $controller->dispatch(Request::create('https://shop.example/a2a', 'POST', content: (string) $body));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('message', $payload['result']['kind']);
    }

    public function testDisabledReturnsNotFound(): void
    {
        $controller = $this->controller(new ArrayConfigReader([
            'Coding9AgentReady.config.enableA2aServer' => false,
        ]));
        $response = $controller->dispatch(Request::create('https://shop.example/a2a', 'POST', content: '{}'));
        self::assertSame(404, $response->getStatusCode());
    }

    private function controller(?ArrayConfigReader $reader = null, ?FakeStoreApiClient $client = null): A2aController
    {
        $reader ??= new ArrayConfigReader();
        return new A2aController(
            new AgentConfig($reader),
            new A2aServer(
                new SkillRegistry(),
                new SkillExecutor($client ?? new FakeStoreApiClient(), new StaticSalesChannelKeyResolver()),
            ),
        );
    }
}
