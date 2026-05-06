<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Support;

use Coding9\AgentReady\StoreApi\StoreApiClient;
use Coding9\AgentReady\StoreApi\StoreApiResponse;

/**
 * In-memory test double for {@see StoreApiClient}.
 *
 * Use {@see queue()} to push canned responses; consecutive calls receive
 * them in FIFO order. {@see $calls} captures every request for assertions.
 */
class FakeStoreApiClient implements StoreApiClient
{
    /** @var array<int, array{method: string, path: string, body: array<string, mixed>, accessKey: ?string, contextToken: ?string}> */
    public array $calls = [];

    /** @var array<int, StoreApiResponse> */
    private array $queue = [];

    private StoreApiResponse $default;

    public function __construct()
    {
        $this->default = new StoreApiResponse(200, '{}');
    }

    public function queue(StoreApiResponse $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function setDefault(StoreApiResponse $response): self
    {
        $this->default = $response;
        return $this;
    }

    public function call(string $method, string $path, array $body, ?string $accessKey, ?string $contextToken): StoreApiResponse
    {
        $this->calls[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'accessKey' => $accessKey,
            'contextToken' => $contextToken,
        ];
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        return $this->default;
    }
}
