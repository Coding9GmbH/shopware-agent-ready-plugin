<?php declare(strict_types=1);

namespace Coding9\AgentReady\StoreApi;

/**
 * Tiny Store API client used by the MCP/A2A skill executor.
 *
 * The plugin does not call the Store API over external HTTP — it issues an
 * in-process sub-request via the Symfony kernel. That keeps the latency
 * minimal, removes the need to know the public hostname (Docker, reverse
 * proxies, multi-tenant setups), and lets the same auth/cookies/sales-channel
 * resolution that powers the real storefront fire on the inner request.
 *
 * Tests bind this to {@see \Coding9\AgentReady\Tests\Support\FakeStoreApiClient}.
 */
interface StoreApiClient
{
    /**
     * Issue a Store API request. The client is responsible for setting the
     * `sw-access-key` header. The optional `sw-context-token` is forwarded
     * verbatim so the same cart/customer session is reused across calls.
     *
     * @param array<string, mixed> $body
     */
    public function call(
        string $method,
        string $path,
        array $body,
        ?string $accessKey,
        ?string $contextToken,
    ): StoreApiResponse;
}
