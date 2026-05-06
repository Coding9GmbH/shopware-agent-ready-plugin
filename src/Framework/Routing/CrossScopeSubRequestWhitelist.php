<?php declare(strict_types=1);

namespace Coding9\AgentReady\Framework\Routing;

use Shopware\Core\Framework\Routing\RouteScopeWhitelistInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Skill execution dispatches Store-API sub-requests through the kernel
 * (see {@see \Coding9\AgentReady\StoreApi\KernelStoreApiClient}).
 *
 * Shopware's RouteScopeListener compares the *main* request's path against
 * the *sub* request's `_routeScope`. A storefront-scoped main request
 * (POST /mcp or POST /a2a) calling a store-api scoped sub-request fails
 * that check with `Invalid route scope for route frontend.coding9...`.
 *
 * Whitelisting by controller class doesn't help because the listener fires
 * with the **sub** request's controller (a core Store-API controller). We
 * therefore inspect the request stack and exempt any sub-request whose
 * main request is owned by this plugin.
 */
class CrossScopeSubRequestWhitelist implements RouteScopeWhitelistInterface
{
    private const WHITELISTED_MAIN_ROUTES = [
        'frontend.coding9.agent_ready.mcp',
        'frontend.coding9.agent_ready.a2a',
    ];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function applies(string $controllerClass): bool
    {
        $main = $this->requestStack->getMainRequest();
        if ($main === null) {
            return false;
        }

        $current = $this->requestStack->getCurrentRequest();
        // Only relax the check for sub-requests; the main /mcp or /a2a
        // request itself must continue to pass the regular storefront check.
        if ($current === null || $current === $main) {
            return false;
        }

        $route = $main->attributes->get('_route');
        return is_string($route) && in_array($route, self::WHITELISTED_MAIN_ROUTES, true);
    }
}
