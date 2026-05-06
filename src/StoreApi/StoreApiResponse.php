<?php declare(strict_types=1);

namespace Coding9\AgentReady\StoreApi;

final class StoreApiResponse
{
    /** @param array<string, mixed>|null $json */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $contextToken = null,
        public readonly ?array $json = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed>|null */
    public function decode(): ?array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }
}
