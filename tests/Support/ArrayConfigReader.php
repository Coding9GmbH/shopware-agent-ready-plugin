<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Support;

use Coding9\AgentReady\Service\ConfigReader;

class ArrayConfigReader implements ConfigReader
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function get(string $key, ?string $salesChannelId = null): mixed
    {
        return $this->values[$key] ?? null;
    }
}
