<?php declare(strict_types=1);

namespace Coding9\AgentReady\Http;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Production adapter that wraps Symfony's HttpClient (which Shopware ships).
 */
class SymfonyHttpClient implements HttpClient
{
    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public function request(string $method, string $url, array $headers, array|string $body): HttpResponse
    {
        $options = ['headers' => $headers];
        if (is_string($body)) {
            $options['body'] = $body;
        } else {
            $options['json'] = $body;
        }

        try {
            $response = $this->client->request($method, $url, $options);
            return new HttpResponse($response->getStatusCode(), $response->getContent(false));
        } catch (ExceptionInterface $e) {
            return new HttpResponse(0, $e->getMessage());
        }
    }
}
