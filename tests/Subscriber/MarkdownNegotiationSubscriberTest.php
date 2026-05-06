<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Service\HtmlToMarkdownConverter;
use Coding9\AgentReady\Subscriber\MarkdownNegotiationSubscriber;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MarkdownNegotiationSubscriberTest extends TestCase
{
    public function testReturnsMarkdownWhenAcceptIsTextMarkdown(): void
    {
        $event = $this->event('text/markdown', '<html><body><main><h1>Hi</h1><p>x</p></main></body></html>');
        $this->subscriber()->onResponse($event);

        $response = $event->getResponse();
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('# Hi', (string) $response->getContent());
        self::assertNotEmpty($response->headers->get('x-markdown-tokens'));
        self::assertContains('Accept', $response->getVary());
    }

    public function testKeepsHtmlWhenBrowserAccept(): void
    {
        $event = $this->event(
            'text/html,application/xhtml+xml',
            '<html><body><main><p>still html</p></main></body></html>'
        );
        $this->subscriber()->onResponse($event);

        $response = $event->getResponse();
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('<p>still html</p>', (string) $response->getContent());
        self::assertContains('Accept', $response->getVary());
    }

    public function testHtmlIsKeptWhenMarkdownLowQValue(): void
    {
        $event = $this->event('text/html, text/markdown;q=0.1', '<html><body><main><p>ok</p></main></body></html>');
        $this->subscriber()->onResponse($event);
        self::assertStringStartsWith('text/html', (string) $event->getResponse()->headers->get('Content-Type'));
    }

    public function testIgnoresNonHtmlResponses(): void
    {
        $request = Request::create('/foo');
        $request->headers->set('Accept', 'text/markdown');
        $response = new Response('{"a":1}', 200, ['Content-Type' => 'application/json']);

        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->subscriber()->onResponse($event);

        self::assertSame('{"a":1}', $event->getResponse()->getContent());
        self::assertSame('application/json', $event->getResponse()->headers->get('Content-Type'));
    }

    public function testSkipsWhenDisabled(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableMarkdownNegotiation' => false,
        ]);
        $event = $this->event('text/markdown', '<html><body><main><h1>x</h1></main></body></html>');
        $this->subscriberWith($reader)->onResponse($event);
        self::assertStringStartsWith('text/html', (string) $event->getResponse()->headers->get('Content-Type'));
    }

    public function testSubRequestIsIgnored(): void
    {
        $request = Request::create('/');
        $request->headers->set('Accept', 'text/markdown');
        $response = new Response('<html><body><main><p>x</p></main></body></html>', 200, ['Content-Type' => 'text/html']);
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::SUB_REQUEST, $response);
        $this->subscriber()->onResponse($event);
        self::assertStringStartsWith('text/html', (string) $event->getResponse()->headers->get('Content-Type'));
    }

    private function subscriber(): MarkdownNegotiationSubscriber
    {
        return $this->subscriberWith(new ArrayConfigReader());
    }

    private function subscriberWith(ArrayConfigReader $reader): MarkdownNegotiationSubscriber
    {
        return new MarkdownNegotiationSubscriber(new AgentConfig($reader), new HtmlToMarkdownConverter());
    }

    private function event(string $accept, string $body): ResponseEvent
    {
        $request = Request::create('/');
        $request->headers->set('Accept', $accept);
        $response = new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        return new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
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
