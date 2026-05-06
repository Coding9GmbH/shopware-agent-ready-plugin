<?php declare(strict_types=1);

namespace Coding9\AgentReady\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class SystemConfigReader implements ConfigReader
{
    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function get(string $key, ?string $salesChannelId = null): mixed
    {
        return $this->systemConfig->get($key, $salesChannelId);
    }
}
