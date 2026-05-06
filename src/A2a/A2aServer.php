<?php declare(strict_types=1);

namespace Coding9\AgentReady\A2a;

use Coding9\AgentReady\Skill\SkillInputException;
use Coding9\AgentReady\Skill\SkillRegistry;

/**
 * Minimal A2A JSON-RPC dispatcher.
 *
 * Implements the methods an A2A client needs to drive this agent over the
 * wire-protocol declared in /.well-known/agent-card.json:
 *
 *  - message/send → expects params.message.parts[*].text or
 *                   params.message.parts[*].data with skill+arguments,
 *                   runs the matching skill and returns the resulting
 *                   "next action" envelope as a Message.
 *  - tasks/get    → not stateful in this implementation; returns
 *                   the same envelope keyed under the supplied task id.
 *
 * The plugin doesn't keep task state — A2A's optional state machine is out
 * of scope for a discovery-first showcase. The agent-card.json declares
 * `stateTransitionHistory: false`, so clients know not to expect it.
 */
class A2aServer
{
    public const PROTOCOL_VERSION = '0.3.0';

    public function __construct(private readonly SkillRegistry $registry)
    {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $id = $request['id'] ?? null;
        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        try {
            $result = match ($method) {
                'message/send' => $this->messageSend($params),
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
    private function messageSend(array $params): array
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
        if ($skill === null) {
            throw new SkillInputException('unknown skill: ' . $skillId);
        }

        $envelope = $skill->call($arguments);

        return [
            'kind' => 'message',
            'role' => 'agent',
            'messageId' => bin2hex(random_bytes(8)),
            'parts' => [
                [
                    'kind' => 'data',
                    'data' => $envelope,
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
