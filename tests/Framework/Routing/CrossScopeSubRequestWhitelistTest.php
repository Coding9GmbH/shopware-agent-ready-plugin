<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Framework\Routing;

use Coding9\AgentReady\Framework\Routing\CrossScopeSubRequestWhitelist;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class CrossScopeSubRequestWhitelistTest extends TestCase
{
    public function testAppliesToSubRequestsOriginatingFromMcp(): void
    {
        $stack = $this->stack(
            mainRoute: 'frontend.coding9.agent_ready.mcp',
            subPath: '/store-api/search'
        );
        self::assertTrue((new CrossScopeSubRequestWhitelist($stack))->applies('Acme\\AnyController'));
    }

    public function testAppliesToSubRequestsOriginatingFromA2a(): void
    {
        $stack = $this->stack(
            mainRoute: 'frontend.coding9.agent_ready.a2a',
            subPath: '/store-api/checkout/order'
        );
        self::assertTrue((new CrossScopeSubRequestWhitelist($stack))->applies('Acme\\AnyController'));
    }

    public function testDoesNotApplyOnTheMainRequestItself(): void
    {
        // No sub-request pushed: main request === current request.
        $main = Request::create('/mcp', 'POST');
        $main->attributes->set('_route', 'frontend.coding9.agent_ready.mcp');
        $stack = new RequestStack();
        $stack->push($main);

        self::assertFalse((new CrossScopeSubRequestWhitelist($stack))->applies('Acme\\AnyController'));
    }

    public function testDoesNotApplyForUnrelatedMainRoutes(): void
    {
        $stack = $this->stack(
            mainRoute: 'frontend.home.page',
            subPath: '/store-api/search'
        );
        self::assertFalse((new CrossScopeSubRequestWhitelist($stack))->applies('Acme\\AnyController'));
    }

    public function testDoesNotApplyWhenStackIsEmpty(): void
    {
        self::assertFalse((new CrossScopeSubRequestWhitelist(new RequestStack()))->applies('Acme\\AnyController'));
    }

    private function stack(string $mainRoute, string $subPath): RequestStack
    {
        $stack = new RequestStack();

        $main = Request::create('/mcp', 'POST');
        $main->attributes->set('_route', $mainRoute);
        $stack->push($main);

        $sub = Request::create($subPath, 'POST');
        $stack->push($sub);

        return $stack;
    }
}
