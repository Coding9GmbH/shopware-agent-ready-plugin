<?php declare(strict_types=1);

namespace Coding9\AgentReady\X402;

final class X402VerificationResult
{
    /** @param array<string, mixed>|null $payload */
    private function __construct(
        public readonly bool $isValid,
        public readonly ?string $payer,
        public readonly ?string $reason,
        public readonly ?array $payload,
    ) {
    }

    /** @param array<string, mixed>|null $payload */
    public static function success(?string $payer, ?array $payload = null): self
    {
        return new self(true, $payer, null, $payload);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason, null);
    }
}
