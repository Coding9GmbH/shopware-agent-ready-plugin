<?php declare(strict_types=1);

namespace Coding9\AgentReady\StoreApi;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Production binding for {@see SalesChannelKeyResolver}: looks the sales
 * channel up via the DAL and reads its public access key.
 *
 * The lookup result is cached per request so the executor doesn't hit the
 * DAL once per skill call when an MCP host fires several tools/call in a
 * batched session.
 */
class RepositorySalesChannelKeyResolver implements SalesChannelKeyResolver
{
    /** @var array<string, string|null> */
    private array $cache = [];

    /** @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $repository */
    public function __construct(private readonly EntityRepository $repository)
    {
    }

    public function resolveAccessKey(?string $salesChannelId): ?string
    {
        if ($salesChannelId === null || $salesChannelId === '') {
            return null;
        }

        if (array_key_exists($salesChannelId, $this->cache)) {
            return $this->cache[$salesChannelId];
        }

        $criteria = new Criteria([$salesChannelId]);
        /** @var SalesChannelEntity|null $entity */
        $entity = $this->repository->search($criteria, Context::createDefaultContext())->first();
        $key = $entity?->getAccessKey();

        return $this->cache[$salesChannelId] = is_string($key) && $key !== '' ? $key : null;
    }
}
