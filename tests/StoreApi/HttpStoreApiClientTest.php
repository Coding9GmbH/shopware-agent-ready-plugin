<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\StoreApi;

use Coding9\AgentReady\StoreApi\HttpStoreApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class HttpStoreApiClientTest extends TestCase
{
    public function testCallEmitsAccessKeyAndContextTokenHeaders(): void
    {
        $captured = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse(
                json_encode(['ok' => true]) ?: '',
                ['response_headers' => ['sw-context-token' => 'NEW_TOKEN_42']],
            );
        });

        $stack = new RequestStack();
        $stack->push(Request::create('https://shop.example/mcp'));

        $client = new HttpStoreApiClient($http, $stack);
        $response = $client->call(
            'POST',
            '/store-api/checkout/cart/line-item',
            ['items' => [['type' => 'product']]],
            'SWSCAGENT123',
            'OLD_TOKEN_1',
        );

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://shop.example/store-api/checkout/cart/line-item', $captured['url']);

        $headers = $this->normalizeHeaders($captured['options']['headers'] ?? []);
        self::assertSame('SWSCAGENT123', $headers['sw-access-key']);
        self::assertSame('OLD_TOKEN_1', $headers['sw-context-token']);
        self::assertSame('application/json', $headers['content-type']);

        self::assertSame(200, $response->status);
        self::assertSame('NEW_TOKEN_42', $response->contextToken);
        self::assertSame(['ok' => true], $response->decode());
    }

    public function testEmptyBodyIsSerializedAsEmptyObject(): void
    {
        $captured = '';
        $http = new MockHttpClient(function ($method, $url, $options) use (&$captured): MockResponse {
            $captured = $options['body'] ?? '';
            return new MockResponse('{}');
        });

        $stack = new RequestStack();
        $stack->push(Request::create('https://shop.example/'));

        $client = new HttpStoreApiClient($http, $stack);
        $client->call('POST', '/store-api/account/logout', [], 'SWSCAGENT123', 'TOKEN');

        self::assertSame('{}', $captured);
    }

    public function testReturns502WhenTransportFails(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'boom']));

        $stack = new RequestStack();
        $stack->push(Request::create('https://shop.example/'));

        $client = new HttpStoreApiClient($http, $stack);
        $response = $client->call('GET', '/store-api/checkout/cart', [], 'SWSCAGENT123', 'TOKEN');

        self::assertSame(502, $response->status);
        self::assertNotNull($response->decode());
    }

    public function testReturns500WhenNoActiveRequest(): void
    {
        $client = new HttpStoreApiClient(new MockHttpClient(), new RequestStack());
        $response = $client->call('GET', '/store-api/context', [], 'SWSCAGENT123', null);

        self::assertSame(500, $response->status);
    }

    /**
     * @param array<int|string, mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_int($key) && is_string($value) && str_contains($value, ':')) {
                [$name, $val] = explode(':', $value, 2);
                $out[strtolower(trim($name))] = trim($val);
            } elseif (is_string($key) && is_string($value)) {
                $out[strtolower($key)] = $value;
            }
        }
        return $out;
    }
}
