<?php declare(strict_types=1);

namespace Coding9\AgentReady\Mcp;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillInputException;
use Coding9\AgentReady\Skill\SkillRegistry;

/**
 * Minimal Model Context Protocol JSON-RPC dispatcher.
 *
 * Implements the subset of MCP an agent host needs to discover and invoke
 * skills:
 *
 *  - initialize          → serverInfo + protocolVersion + capabilities.tools
 *  - tools/list          → declared skills with JSON Schema input
 *  - tools/call          → validates arguments, runs the skill via
 *                          {@see SkillExecutor}, returns a structured
 *                          response with the trimmed Store-API result.
 *  - ping                → health-check
 *  - notifications/*     → accepted, no response
 */
class McpServer
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly SkillExecutor $executor,
        private readonly AgentConfig $config,
        private readonly string $serverName = 'shopware-storefront',
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null Null when the request is a notification.
     */
    public function handle(array $request, ?string $salesChannelId = null): ?array
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
                'tools/list' => $this->toolsList($salesChannelId),
                'tools/call' => $this->toolsCall($params, $salesChannelId),
                'ping' => new \stdClass(),
                'notifications/initialized', 'notifications/cancelled' => null,
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
    private function toolsList(?string $salesChannelId): array
    {
        $allTools = $this->registry->asMcpToolList();
        $filtered = [];
        foreach ($allTools as $tool) {
            if ($this->config->isSkillEnabled((string) $tool['name'], true, $salesChannelId)) {
                $filtered[] = $tool;
            }
        }
        return ['tools' => $filtered];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params, ?string $salesChannelId): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new SkillInputException('missing tool name');
        }

        $skill = $this->registry->get($name);
        if ($skill === null || !$this->config->isSkillEnabled($skill->id, true, $salesChannelId)) {
            throw new McpMethodNotFoundException($name);
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new SkillInputException('arguments must be an object');
        }

        $result = $this->executor->execute($skill, $arguments, $salesChannelId);
        $text = (string) json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $result->data,
            'isError' => $result->isError,
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
