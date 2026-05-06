<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Mcp\McpServer;
use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP transport for the MCP server.
 *
 * Accepts a JSON-RPC 2.0 request body at POST /mcp and returns the
 * dispatcher result. Batched requests (JSON arrays) are supported per
 * the JSON-RPC 2.0 spec — handy for agent hosts that pipeline
 * `initialize` + `tools/list` in a single round-trip.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class McpController extends AbstractController
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly McpServer $server,
    ) {
    }

    #[Route(
        path: '/mcp',
        name: 'frontend.coding9.agent_ready.mcp',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function dispatch(Request $request): Response
    {
        if (!$this->config->isMcpServerEnabled($this->salesChannelId($request))) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->jsonRpcError(null, -32700, 'parse error');
        }

        if (array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $entry) {
                if (!is_array($entry)) {
                    $responses[] = $this->errorBody(null, -32600, 'invalid request');
                    continue;
                }
                $r = $this->server->handle($entry);
                if ($r !== null) {
                    $responses[] = $r;
                }
            }
            if ($responses === []) {
                return new Response('', 204);
            }
            return $this->jsonResponse($responses);
        }

        $response = $this->server->handle($payload);
        if ($response === null) {
            return new Response('', 204);
        }
        return $this->jsonResponse($response);
    }

    /** @param array<int|string, mixed> $payload */
    private function jsonResponse(array $payload): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }

    private function jsonRpcError(int|string|null $id, int $code, string $message): JsonResponse
    {
        return $this->jsonResponse($this->errorBody($id, $code, $message));
    }

    /** @return array<string, mixed> */
    private function errorBody(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }
}
