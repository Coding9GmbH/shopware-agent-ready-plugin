<?php declare(strict_types=1);

namespace Coding9\AgentReady\StoreApi;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Production binding for {@see StoreApiClient} using a real HTTP loopback.
 *
 * The earlier sub-request implementation did not run Shopware's full
 * kernel.response / kernel.terminate listeners — most notably the
 * CartPersister — so cart and session state never reached the database
 * and got rebuilt as anonymous on every call. A real HTTP request goes
 * through the same lifecycle as a browser hit, which is the only path
 * Shopware's Store-API persistence is reliably wired to.
 *
 * Tradeoffs:
 *   - extra TCP round-trip per skill call (typically <50 ms);
 *   - the host has to be reachable from itself (true for any standard
 *     deployment, including Cloudflare-fronted setups);
 *   - sub-request whitelist hacks are no longer needed for this path,
 *     though we keep them for any other callers Shopware might trigger.
 */
class HttpStoreApiClient implements StoreApiClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function call(
        string $method,
        string $path,
        array $body,
        ?string $accessKey,
        ?string $contextToken,
    ): StoreApiResponse {
        $base = $this->baseUrl();
        if ($base === null) {
            return new StoreApiResponse(500, json_encode([
                'errors' => [['title' => 'no_base_url', 'detail' => 'No active request to derive Store-API base URL from.']],
            ]) ?: '');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($accessKey !== null && $accessKey !== '') {
            $headers['sw-access-key'] = $accessKey;
        }
        if ($contextToken !== null && $contextToken !== '') {
            $headers['sw-context-token'] = $contextToken;
        }

        // Shopware's Store API expects POST/PATCH bodies as JSON objects,
        // never JSON arrays. An empty PHP array would serialize to `[]`,
        // which Shopware rejects — coerce to `{}`.
        $payload = $body === [] ? '{}' : (string) json_encode((object) $body);

        try {
            $response = $this->http->request($method, $base . $path, [
                'headers' => $headers,
                'body' => $payload,
                'timeout' => 30,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            $rawHeaders = $response->getHeaders(false);
        } catch (HttpClientException $e) {
            return new StoreApiResponse(502, json_encode([
                'errors' => [['title' => 'store_api_unreachable', 'detail' => $e->getMessage()]],
            ]) ?: '');
        }

        $token = null;
        foreach ($rawHeaders as $name => $values) {
            if (strtolower($name) === 'sw-context-token' && isset($values[0])) {
                $token = $values[0];
                break;
            }
        }

        return new StoreApiResponse(
            status: $status,
            body: $rawBody,
            contextToken: $token,
        );
    }

    private function baseUrl(): ?string
    {
        $request = $this->requestStack->getMainRequest() ?? $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        return $request->getSchemeAndHttpHost();
    }
}
