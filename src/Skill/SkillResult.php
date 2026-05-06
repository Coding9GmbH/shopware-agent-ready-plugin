<?php declare(strict_types=1);

namespace Coding9\AgentReady\Skill;

final class SkillResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly bool $isError,
        public readonly array $data,
        public readonly int $status,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function success(array $data, int $status = 200): self
    {
        return new self(false, $data, $status);
    }

    public static function error(string $code, string $detail, int $status): self
    {
        return new self(true, ['error' => $code, 'detail' => $detail, 'status' => $status], $status);
    }
}
