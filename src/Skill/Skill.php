<?php declare(strict_types=1);

namespace Coding9\AgentReady\Skill;

/**
 * One agent-callable skill exposed via MCP, A2A and the agent-skills index.
 *
 * The dispatcher returns a structured "next action" envelope rather than
 * proxying the Store API itself: the plugin's job is to make Shopware
 * agent-discoverable, not to re-implement the Store API. Agents (or an
 * upstream MCP host) execute the returned http-request envelope themselves.
 */
final class Skill
{
    /**
     * @param array<string, mixed>             $inputSchema JSON Schema (draft 2020-12) for arguments.
     * @param array<int, string>               $tags        A2A skill tags.
     * @param \Closure(array<string, mixed>): array<string, mixed> $dispatch
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly array $tags,
        public readonly array $inputSchema,
        public readonly string $body,
        public readonly \Closure $dispatch,
    ) {
    }

    /**
     * Validate input against the declared schema and run the dispatcher.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     * @throws SkillInputException
     */
    public function call(array $args): array
    {
        $this->validate($args);
        return ($this->dispatch)($args);
    }

    /**
     * @param array<string, mixed> $args
     * @throws SkillInputException
     */
    private function validate(array $args): void
    {
        $required = $this->inputSchema['required'] ?? [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $args)) {
                throw new SkillInputException("missing required field: {$key}");
            }
        }

        $properties = $this->inputSchema['properties'] ?? [];
        $additional = $this->inputSchema['additionalProperties'] ?? true;

        foreach ($args as $key => $value) {
            if (!isset($properties[$key])) {
                if ($additional === false) {
                    throw new SkillInputException("unknown field: {$key}");
                }
                continue;
            }
            $this->validateValue((string) $key, $value, $properties[$key]);
        }
    }

    /** @param array<string, mixed> $schema */
    private function validateValue(string $key, mixed $value, array $schema): void
    {
        $type = $schema['type'] ?? null;

        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) || is_object($value),
            'array' => is_array($value),
            null => true,
            default => true,
        };
        if (!$ok) {
            throw new SkillInputException("field '{$key}' must be of type {$type}");
        }

        if ($type === 'string' && is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                throw new SkillInputException("field '{$key}' is too short");
            }
            if (isset($schema['pattern']) && !preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/', $value)) {
                throw new SkillInputException("field '{$key}' does not match pattern");
            }
            if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
                $allowed = implode(', ', $schema['enum']);
                throw new SkillInputException("field '{$key}' must be one of: {$allowed}");
            }
        }

        if ($type === 'integer' && is_int($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new SkillInputException("field '{$key}' must be >= {$schema['minimum']}");
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new SkillInputException("field '{$key}' must be <= {$schema['maximum']}");
            }
        }
    }
}
