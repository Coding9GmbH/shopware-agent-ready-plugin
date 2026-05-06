<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Support;

use Coding9\AgentReady\Http\HttpClient;
use Coding9\AgentReady\Http\HttpResponse;

class FakeHttpClient implements HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: array<string, mixed>|string}> */
    public array $calls = [];

    public function __construct(private HttpResponse $response = new HttpResponse(200, '{}'))
    {
    }

    public function setResponse(HttpResponse $response): void
    {
        $this->response = $response;
    }

    public function request(string $method, string $url, array $headers, array|string $body): HttpResponse
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
        return $this->response;
    }
}
