<?php declare(strict_types=1);

namespace Coding9\AgentReady\A2a;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Skill\SkillExecutor;
use Coding9\AgentReady\Skill\SkillInputException;
use Coding9\AgentReady\Skill\SkillRegistry;

/**
 * Minimal A2A JSON-RPC dispatcher.
 *
 * Implements `message/send` per the A2A specification declared in
 * /.well-known/agent-card.json. Skill execution is shared with the MCP
 * server through {@see SkillExecutor}.
 *
 * The plugin is stateless from A2A's point of view — `tasks/*` methods are
 * not supported, and the agent-card.json correctly advertises
 * `stateTransitionHistory: false` so clients know not to expect them.
 */
class A2aServer
{
    public const PROTOCOL_VERSION = '0.3.0';

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly SkillExecutor $executor,
        private readonly AgentConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request, ?string $salesChannelId = null): array
    {
        $id = $request['id'] ?? null;
        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        try {
            $result = match ($method) {
                'message/send' => $this->messageSend($params, $salesChannelId),
                default => throw new \RuntimeException('method not found: ' . $method),
            };
        } catch (SkillInputException $e) {
            return $this->error($id, -32602, 'invalid params: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($id, -32601, $e->getMessage());
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function messageSend(array $params, ?string $salesChannelId): array
    {
        $message = $params['message'] ?? null;
        if (!is_array($message)) {
            throw new SkillInputException('message is required');
        }

        $parts = $message['parts'] ?? [];
        if (!is_array($parts) || $parts === []) {
            throw new SkillInputException('message.parts must be a non-empty array');
        }

        $skillId = null;
        $arguments = [];

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $kind = $part['kind'] ?? $part['type'] ?? null;
            if ($kind === 'data' && isset($part['data']) && is_array($part['data'])) {
                $skillId = $part['data']['skill'] ?? $skillId;
                $args = $part['data']['arguments'] ?? null;
                if (is_array($args)) {
                    $arguments = $args;
                }
            }
        }

        if (!is_string($skillId) || $skillId === '') {
            throw new SkillInputException('parts[].data.skill is required (string)');
        }

        $skill = $this->registry->get($skillId);
        if ($skill === null || !$this->config->isSkillEnabled($skill->id, true, $salesChannelId)) {
            throw new SkillInputException('unknown or disabled skill: ' . $skillId);
        }

        $result = $this->executor->execute($skill, $arguments, $salesChannelId);

        return [
            'kind' => 'message',
            'role' => 'agent',
            'messageId' => bin2hex(random_bytes(8)),
            'parts' => [
                [
                    'kind' => 'data',
                    'data' => $result->data,
                ],
            ],
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
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
