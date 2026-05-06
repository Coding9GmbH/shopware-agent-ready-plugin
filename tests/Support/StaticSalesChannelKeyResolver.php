<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Support;

use Coding9\AgentReady\StoreApi\SalesChannelKeyResolver;

class StaticSalesChannelKeyResolver implements SalesChannelKeyResolver
{
    public function __construct(private readonly ?string $key = 'TEST_ACCESS_KEY')
    {
    }

    public function resolveAccessKey(?string $salesChannelId): ?string
    {
        return $this->key;
    }
}
