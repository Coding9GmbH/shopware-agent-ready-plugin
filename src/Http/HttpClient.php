<?php declare(strict_types=1);

namespace Coding9\AgentReady\Http;

/**
 * Tiny HTTP client abstraction used by the x402 facilitator integration.
 *
 * The plugin doesn't pull in Symfony's HttpClient as a hard dependency at
 * runtime — Shopware ships one and we wire it through this interface in
 * services.xml. Tests replace it with {@see FakeHttpClient}.
 */
interface HttpClient
{
    /**
     * @param array<string, string>      $headers
     * @param array<string, mixed>|string $body
     * @return HttpResponse
     */
    public function request(string $method, string $url, array $headers, array|string $body): HttpResponse;
}
