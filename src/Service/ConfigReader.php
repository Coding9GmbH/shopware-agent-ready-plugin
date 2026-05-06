<?php declare(strict_types=1);

namespace Coding9\AgentReady\Service;

/**
 * Tiny abstraction over Shopware's SystemConfigService. Keeps the rest of the
 * plugin testable without booting the full Shopware container.
 */
interface ConfigReader
{
    public function get(string $key, ?string $salesChannelId = null): mixed;
}
