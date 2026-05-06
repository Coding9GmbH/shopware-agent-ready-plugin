<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Skill;

use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillRegistry;
use Coding9\AgentReady\StoreApi\StoreApiResponse;
use Coding9\AgentReady\Tests\Support\FakeStoreApiClient;
use Coding9\AgentReady\Tests\Support\StaticSalesChannelKeyResolver;
use PHPUnit\Framework\TestCase;

class SkillExecutorTest extends TestCase
{
    public function testSearchProductsProxiesToStoreApiAndSummarizes(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, (string) json_encode([
            'total' => 1,
            'elements' => [[
                'id' => 'abc',
                'translated' => ['name' => 'Vintage Sneaker'],
                'productNumber' => 'SW-001',
                'available' => true,
                'calculatedPrice' => ['unitPrice' => 99.95],
                'seoUrls' => [['pathInfo' => '/p/sneaker']],
                'cover' => ['media' => ['url' => 'https://shop.example/m.jpg']],
            ]],
        ])));

        $result = $this->executor($client)->execute(
            $this->registry()->get('search-products'),
            ['query' => 'shoes'],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertSame(1, $result->data['total']);
        self::assertSame('Vintage Sneaker', $result->data['products'][0]['name']);
        self::assertSame('/p/sneaker', $result->data['products'][0]['url']);

        // Assert the actual store-api call shape:
        self::assertSame('POST', $client->calls[0]['method']);
        self::assertSame('/store-api/search', $client->calls[0]['path']);
        self::assertSame('shoes', $client->calls[0]['body']['search']);
        self::assertSame('TEST_ACCESS_KEY', $client->calls[0]['accessKey']);
    }

    public function testSearchProductsRejectsMissingQuery(): void
    {
        $this->expectException(\Coding9\AgentReady\Skill\SkillInputException::class);
        $this->executor(new FakeStoreApiClient())->execute(
            $this->registry()->get('search-products'),
            [],
            'sc-1',
        );
    }

    public function testSearchProductsPropagatesStoreApiErrors(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(401, (string) json_encode([
            'errors' => [['title' => 'invalid_access_key', 'detail' => 'Sales channel key invalid.']],
        ])));

        $result = $this->executor($client)->execute(
            $this->registry()->get('search-products'),
            ['query' => 'x'],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('invalid_access_key', $result->data['error']);
        self::assertSame(401, $result->status);
    }

    public function testGetProductAcceptsBothUuidFormatsAndStripsDashes(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, (string) json_encode([
            'product' => ['id' => 'abc', 'translated' => ['name' => 'X']],
        ])));

        $dashedUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $this->executor($client)->execute(
            $this->registry()->get('get-product'),
            ['productId' => $dashedUuid],
            'sc-1',
        );

        self::assertSame('/store-api/product/' . str_replace('-', '', $dashedUuid), $client->calls[0]['path']);
    }

    public function testCreateContextReturnsContextToken(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(
            200,
            (string) json_encode(['token' => 'fresh-token']),
            'fresh-token',
        ));

        $result = $this->executor($client)->execute(
            $this->registry()->get('create-context'),
            [],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertSame('fresh-token', $result->data['contextToken']);
        self::assertSame('GET', $client->calls[0]['method']);
        self::assertSame('/store-api/context', $client->calls[0]['path']);
    }

    public function testCreateContextErrorsWhenNoTokenHeader(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, '{}'));

        $result = $this->executor($client)->execute(
            $this->registry()->get('create-context'),
            [],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('context_token_missing', $result->data['error']);
    }

    public function testManageCartAddProxiesWithItemsBody(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, (string) json_encode([
            'lineItems' => [['id' => 'li-1', 'referencedId' => 'p1', 'label' => 'X', 'quantity' => 2, 'price' => ['unitPrice' => 9, 'totalPrice' => 18]]],
            'price' => ['totalPrice' => 18, 'netPrice' => 15, 'positionPrice' => 18],
        ])));

        $result = $this->executor($client)->execute(
            $this->registry()->get('manage-cart'),
            [
                'action' => 'add',
                'contextToken' => 'tok-123',
                'productId' => str_repeat('a', 32),
                'quantity' => 2,
            ],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertSame('POST', $client->calls[0]['method']);
        self::assertSame('/store-api/checkout/cart/line-item', $client->calls[0]['path']);
        self::assertSame('tok-123', $client->calls[0]['contextToken']);
        self::assertSame(2, $client->calls[0]['body']['items'][0]['quantity']);

        self::assertSame(2, $result->data['lineItems'][0]['quantity']);
    }

    public function testManageCartRemoveRequiresLineItemId(): void
    {
        $client = new FakeStoreApiClient();
        $result = $this->executor($client)->execute(
            $this->registry()->get('manage-cart'),
            ['action' => 'remove', 'contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('missing_field', $result->data['error']);
        self::assertSame([], $client->calls, 'no store-api call when validation fails');
    }

    public function testManageCartRemoveSendsDelete(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, '{"lineItems":[]}'));
        $this->executor($client)->execute(
            $this->registry()->get('manage-cart'),
            ['action' => 'remove', 'contextToken' => 'tok-x', 'lineItemId' => str_repeat('b', 32)],
            'sc-1',
        );

        self::assertSame('DELETE', $client->calls[0]['method']);
        self::assertSame([str_repeat('b', 32)], $client->calls[0]['body']['ids']);
    }

    public function testCustomerLoginProxiesAndReturnsRotatedToken(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(
            200,
            (string) json_encode(['contextToken' => 'rotated-token']),
            'rotated-token',
        ));

        $result = $this->executor($client)->execute(
            $this->registry()->get('customer-login'),
            ['contextToken' => 'tok-x', 'username' => 'a@b.com', 'password' => 'pw'],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertSame('rotated-token', $result->data['contextToken']);
        self::assertTrue($result->data['loggedIn']);

        self::assertSame('POST', $client->calls[0]['method']);
        self::assertSame('/store-api/account/login', $client->calls[0]['path']);
        self::assertSame('tok-x', $client->calls[0]['contextToken']);
        self::assertSame('a@b.com', $client->calls[0]['body']['username']);
    }

    public function testCustomerLoginPropagatesAuthErrors(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(401, (string) json_encode([
            'errors' => [['title' => 'invalid_credentials', 'detail' => 'Wrong password.']],
        ])));

        $result = $this->executor($client)->execute(
            $this->registry()->get('customer-login'),
            ['contextToken' => 'tok-x', 'username' => 'a@b.com', 'password' => 'nope'],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('invalid_credentials', $result->data['error']);
    }

    public function testCustomerLogoutSucceedsOn204(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(204, ''));

        $result = $this->executor($client)->execute(
            $this->registry()->get('customer-logout'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertTrue($result->data['loggedOut']);
        self::assertSame('/store-api/account/logout', $client->calls[0]['path']);
    }

    public function testPlaceOrderProxiesAndReturnsOrderSummary(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(200, (string) json_encode([
            'id' => 'order-1',
            'orderNumber' => '10042',
            'amountTotal' => 99.95,
            'currency' => ['isoCode' => 'EUR'],
            'stateMachineState' => ['technicalName' => 'open'],
            'deepLinkCode' => 'abc',
        ])));

        $result = $this->executor($client)->execute(
            $this->registry()->get('place-order'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertFalse($result->isError);
        self::assertSame('10042', $result->data['orderNumber']);
        self::assertSame(99.95, $result->data['amountTotal']);
        self::assertSame('open', $result->data['stateMachineState']);
        self::assertSame('EUR', $result->data['currency']);

        self::assertSame('POST', $client->calls[0]['method']);
        self::assertSame('/store-api/checkout/order', $client->calls[0]['path']);
        self::assertSame(['tos' => true], $client->calls[0]['body']);
    }

    public function testPlaceOrderPropagatesUnauthenticatedError(): void
    {
        $client = (new FakeStoreApiClient())->queue(new StoreApiResponse(403, (string) json_encode([
            'errors' => [['title' => 'CHECKOUT__CUSTOMER_NOT_LOGGED_IN', 'detail' => 'Not logged in.']],
        ])));

        $result = $this->executor($client)->execute(
            $this->registry()->get('place-order'),
            ['contextToken' => 'tok-x'],
            'sc-1',
        );

        self::assertTrue($result->isError);
        self::assertSame('CHECKOUT__CUSTOMER_NOT_LOGGED_IN', $result->data['error']);
        self::assertSame(403, $result->status);
    }

    public function testReturns503WhenSalesChannelKeyMissing(): void
    {
        $executor = new SkillExecutor(
            new FakeStoreApiClient(),
            new StaticSalesChannelKeyResolver(null),
            new \Coding9\AgentReady\Service\AgentConfig(new \Coding9\AgentReady\Tests\Support\ArrayConfigReader()),
        );
        $result = $executor->execute(
            $this->registry()->get('search-products'),
            ['query' => 'x'],
            'sc-unknown',
        );

        self::assertTrue($result->isError);
        self::assertSame('sales_channel_unavailable', $result->data['error']);
        self::assertSame(503, $result->status);
    }

    private function executor(FakeStoreApiClient $client, ?\Coding9\AgentReady\Tests\Support\ArrayConfigReader $reader = null): SkillExecutor
    {
        return new SkillExecutor(
            $client,
            new StaticSalesChannelKeyResolver(),
            new \Coding9\AgentReady\Service\AgentConfig($reader ?? new \Coding9\AgentReady\Tests\Support\ArrayConfigReader()),
        );
    }

    private function registry(): SkillRegistry
    {
        return new SkillRegistry();
    }
}
