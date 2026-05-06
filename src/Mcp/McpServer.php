<?php declare(strict_types=1);

namespace Coding9\AgentReady\Mcp;

use Coding9\AgentReady\Skill\SkillInputException;
use Coding9\AgentReady\Skill\SkillRegistry;

/**
 * Minimal Model Context Protocol JSON-RPC dispatcher.
 *
 * Implements the subset of MCP that an agent host needs to discover and
 * invoke skills:
 *
 *  - initialize          → returns serverInfo + protocolVersion + capabilities
 *  - tools/list          → returns the declared skills with JSON Schema input
 *  - tools/call          → validates arguments, runs the skill dispatcher,
 *                          returns a single text content block carrying the
 *                          structured "next action" envelope as JSON.
 *
 * Notification messages (no `id`) are accepted and acknowledged with no
 * response body. Anything else returns a JSON-RPC method-not-found error.
 *
 * Scope:
 *  - We don't proxy Store API calls. The plugin's job is discovery + dispatch
 *    description; agents (or the upstream MCP host) execute the returned
 *    request envelope themselves. This keeps the plugin small and means
 *    tool authorization/auditing happens where it belongs (the agent host
 *    or Store API access-key policy).
 */
class McpServer
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly string $serverName = 'shopware-storefront',
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    /**
     * Process a single JSON-RPC request envelope.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null  Null when the request is a notification.
     */
    public function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        if ($method === '') {
            return $this->error($id, -32600, 'invalid request: missing method');
        }

        $isNotification = $id === null;

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($params),
                'ping' => new \stdClass(),
                'notifications/initialized' => null,
                default => throw new McpMethodNotFoundException($method),
            };
        } catch (McpMethodNotFoundException $e) {
            if ($isNotification) {
                return null;
            }
            return $this->error($id, -32601, 'method not found: ' . $e->getMessage());
        } catch (SkillInputException $e) {
            if ($isNotification) {
                return null;
            }
            return $this->error($id, -32602, 'invalid params: ' . $e->getMessage());
        } catch (\Throwable $e) {
            if ($isNotification) {
                return null;
            }
            return $this->error($id, -32603, 'internal error: ' . $e->getMessage());
        }

        if ($isNotification) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result ?? new \stdClass(),
        ];
    }

    /** @return array<string, mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function toolsList(): array
    {
        return [
            'tools' => $this->registry->asMcpToolList(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new SkillInputException('missing tool name');
        }

        $skill = $this->registry->get($name);
        if ($skill === null) {
            throw new McpMethodNotFoundException($name);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new SkillInputException('arguments must be an object');
        }

        $envelope = $skill->call($arguments);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'structuredContent' => $envelope,
            'isError' => false,
        ];
    }

    /**
     * @param int|string|null $id
     * @return array<string, mixed>
     */
    private function error(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
