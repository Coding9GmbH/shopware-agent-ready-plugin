<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Subscriber\LinkHeaderSubscriber;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class LinkHeaderSubscriberTest extends TestCase
{
    public function testAddsExpectedLinkHeadersOnHomepage(): void
    {
        $sub = $this->subscriber(new ArrayConfigReader());

        $event = $this->event('/', 'frontend.home.page');
        $sub->onResponse($event);

        $headers = $event->getResponse()->headers->all('link');
        self::assertNotEmpty($headers);
        $combined = implode(', ', $headers);

        self::assertStringContainsString('</.well-known/api-catalog>; rel="api-catalog"', $combined);
        self::assertStringContainsString('</docs/api>; rel="service-doc"', $combined);
        self::assertStringContainsString('</.well-known/mcp/server-card.json>; rel="mcp-server-card"', $combined);
        self::assertStringContainsString('</.well-known/agent-card.json>; rel="a2a-agent-card"', $combined);
        self::assertStringContainsString('</.well-known/agent-skills/index.json>; rel="agent-skills"', $combined);
        self::assertStringContainsString('</.well-known/oauth-authorization-server>; rel="oauth-authorization-server"', $combined);
        self::assertStringContainsString('</.well-known/oauth-protected-resource>; rel="oauth-protected-resource"', $combined);
        self::assertStringContainsString('</llms.txt>; rel="llms-txt"', $combined);
    }

    public function testDoesNotAddHeadersWhenDisabled(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableLinkHeaders' => false,
        ]);
        $sub = $this->subscriber($reader);
        $event = $this->event('/', 'frontend.home.page');
        $sub->onResponse($event);
        self::assertSame([], $event->getResponse()->headers->all('link'));
    }

    public function testDoesNotAddHeadersOutsideHomepage(): void
    {
        $sub = $this->subscriber(new ArrayConfigReader());
        $event = $this->event('/some/category', 'frontend.navigation.page');
        $sub->onResponse($event);
        self::assertSame([], $event->getResponse()->headers->all('link'));
    }

    public function testSkipsNonSuccessResponses(): void
    {
        $sub = $this->subscriber(new ArrayConfigReader());
        $request = Request::create('/');
        $request->attributes->set('_route', 'frontend.home.page');

        foreach ([301, 302, 404, 500] as $status) {
            $event = new ResponseEvent(
                $this->kernel(),
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                new Response('', $status)
            );
            $sub->onResponse($event);
            self::assertSame([], $event->getResponse()->headers->all('link'),
                "Link header must not be emitted on HTTP $status");
        }
    }

    public function testIgnoresSubRequests(): void
    {
        $sub = $this->subscriber(new ArrayConfigReader());

        $event = new ResponseEvent(
            $this->kernel(),
            Request::create('/'),
            HttpKernelInterface::SUB_REQUEST,
            new Response('ok')
        );
        $sub->onResponse($event);
        self::assertSame([], $event->getResponse()->headers->all('link'));
    }

    public function testServiceDocCanBeOmitted(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.serviceDocPath' => '',
        ]);
        // service doc empty -> default kicks in. Test fully empty by using sentinel value.
        $sub = $this->subscriber($reader);
        $event = $this->event('/', 'frontend.home.page');
        $sub->onResponse($event);
        $combined = implode(', ', $event->getResponse()->headers->all('link'));
        self::assertStringContainsString('</docs/api>; rel="service-doc"', $combined);
    }

    public function testIndividualEndpointsCanBeDisabled(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableApiCatalog'    => false,
            'Coding9AgentReady.config.enableMcpServerCard' => false,
            'Coding9AgentReady.config.enableA2aAgentCard'  => false,
        ]);
        $sub = $this->subscriber($reader);
        $event = $this->event('/', 'frontend.home.page');
        $sub->onResponse($event);

        $combined = implode(', ', $event->getResponse()->headers->all('link'));
        self::assertStringNotContainsString('rel="api-catalog"', $combined);
        self::assertStringNotContainsString('rel="mcp-server-card"', $combined);
        self::assertStringNotContainsString('rel="a2a-agent-card"', $combined);
        self::assertStringContainsString('rel="agent-skills"', $combined);
    }

    private function subscriber(ArrayConfigReader $reader): LinkHeaderSubscriber
    {
        return new LinkHeaderSubscriber(new AgentConfig($reader));
    }

    private function event(string $path, ?string $route): ResponseEvent
    {
        $request = Request::create($path);
        if ($route) {
            $request->attributes->set('_route', $route);
        }
        return new ResponseEvent(
            $this->kernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('ok')
        );
    }

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
