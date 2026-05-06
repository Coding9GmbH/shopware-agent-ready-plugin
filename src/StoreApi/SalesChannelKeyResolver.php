<?php declare(strict_types=1);

namespace Coding9\AgentReady\StoreApi;

/**
 * Resolves the public `sw-access-key` for a sales channel id. Extracted
 * behind a tiny interface so the rest of the executor stays unit-testable
 * without booting Shopware's DAL.
 */
interface SalesChannelKeyResolver
{
    public function resolveAccessKey(?string $salesChannelId): ?string;
}
