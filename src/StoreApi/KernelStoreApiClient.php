<?php declare(strict_types=1);

namespace Coding9\AgentReady\StoreApi;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Production binding for {@see StoreApiClient}.
 *
 * Builds a fresh sub-request with the supplied method, path, body and
 * Store-API headers, dispatches it through the Symfony kernel and
 * captures the response. The sub-request flows through Shopware's
 * standard Store-API stack so all middleware (sales-channel resolution,
 * cart hydration, customer authentication, rate limiting…) applies.
 */
class KernelStoreApiClient implements StoreApiClient
{
    public function __construct(private readonly HttpKernelInterface $kernel)
    {
    }

    public function call(
        string $method,
        string $path,
        array $body,
        ?string $accessKey,
        ?string $contextToken,
    ): StoreApiResponse {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($accessKey !== null && $accessKey !== '') {
            $server['HTTP_SW_ACCESS_KEY'] = $accessKey;
        }
        if ($contextToken !== null && $contextToken !== '') {
            $server['HTTP_SW_CONTEXT_TOKEN'] = $contextToken;
        }

        // Shopware's Store API expects POST/PATCH bodies as JSON objects,
        // never JSON arrays. An empty PHP array would serialize to `[]`,
        // which Shopware rejects — coerce to `{}`.
        $payload = $body === [] ? '{}' : (string) json_encode((object) $body);

        $sub = Request::create(
            uri: $path,
            method: $method,
            server: $server,
            content: $payload,
        );

        try {
            $response = $this->kernel->handle($sub, HttpKernelInterface::SUB_REQUEST, false);
        } catch (\Throwable $e) {
            return new StoreApiResponse(500, json_encode([
                'errors' => [['title' => 'sub_request_failed', 'detail' => $e->getMessage()]],
            ]) ?: '');
        }

        $token = $response->headers->get('sw-context-token');

        return new StoreApiResponse(
            status: $response->getStatusCode(),
            body: (string) $response->getContent(),
            contextToken: is_string($token) ? $token : null,
        );
    }
}
