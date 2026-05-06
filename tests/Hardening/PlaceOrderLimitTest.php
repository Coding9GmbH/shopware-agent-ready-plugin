<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Hardening;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\StoreApi\StoreApiResponse;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
use PHPUnit\Framework\TestCase;

class PlaceOrderLimitTest extends TestCase
{
    public function testNoLimitMeansOrderProceedsWithoutCartCheck(): void
    {
        // Limit unset → executor must NOT call /store-api/checkout/cart;
        // it should go straight to /store-api/checkout/order.
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, (string) json_encode([
            'orderNumber' => '10001', 'amountTotal' => 999999.0,
        ])));

        $result = $this->executor($client, [])->execute(
            (new SkillRegistry())->get('place-order'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertCount(1, $client->calls);
        self::assertSame('/store-api/checkout/order', $client->calls[0]['path']);
    }

    public function testLimitBlocksOrderWhenCartTotalExceeds(): void
    {
        $client = (new FakeStoreApiClient())
            ->queue(new StoreApiResponse(200, (string) json_encode(['price' => ['totalPrice' => 500.00]])));

        $result = $this->executor(
            $client,
            ['Coding9AgentReady.config.placeOrderMaxAmount' => '250'],
        )->execute(
            (new SkillRegistry())->get('place-order'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('order_amount_exceeds_limit', $result->data['error']);
        self::assertSame(403, $result->status);

        self::assertCount(1, $client->calls);
        self::assertSame('/store-api/checkout/cart', $client->calls[0]['path']);
    }

    public function testLimitAllowsOrderWhenCartTotalUnderCap(): void
    {
        $client = (new FakeStoreApiClient())
            ->queue(new StoreApiResponse(200, (string) json_encode(['price' => ['totalPrice' => 100.00]])))
            ->queue(new StoreApiResponse(200, (string) json_encode(['orderNumber' => '10002'])));

        $result = $this->executor(
            $client,
            ['Coding9AgentReady.config.placeOrderMaxAmount' => '250'],
        )->execute(
            (new SkillRegistry())->get('place-order'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertSame('10002', $result->data['orderNumber']);
        self::assertCount(2, $client->calls);
        self::assertSame('/store-api/checkout/cart', $client->calls[0]['path']);
        self::assertSame('/store-api/checkout/order', $client->calls[1]['path']);
    }

    public function testLimitErrorsCleanlyWhenCartFetchFails(): void
    {
        $client = (new FakeStoreApiClient())
            ->queue(new StoreApiResponse(404, (string) json_encode([
                'errors' => [['title' => 'cart_not_found']],
            ])));

        $result = $this->executor(
            $client,
            ['Coding9AgentReady.config.placeOrderMaxAmount' => '250'],
        )->execute(
            (new SkillRegistry())->get('place-order'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('cart_not_found', $result->data['error']);
    }

    /** @param array<string, mixed> $values */
    private function executor(FakeStoreApiClient $client, array $values): SkillExecutor
    {
        return new SkillExecutor(
            $client,
            new StaticSalesChannelKeyResolver(),
            new AgentConfig(new ArrayConfigReader($values)),
        );
    }
}
