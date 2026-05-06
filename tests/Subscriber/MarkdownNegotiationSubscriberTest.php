<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Service\HtmlToMarkdownConverter;
use Coding9\AgentReady\Service\JsonLdExtractor;
use Coding9\AgentReady\Service\ProductMarkdownRenderer;
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
        $request->attributes->set('_route', 'frontend.home.page');
        $response = new Response('<html><body><main><p>x</p></main></body></html>', 200, ['Content-Type' => 'text/html']);
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::SUB_REQUEST, $response);
        $this->subscriber()->onResponse($event);
        self::assertStringStartsWith('text/html', (string) $event->getResponse()->headers->get('Content-Type'));
    }

    public function testTransactionalRoutesAreNotConverted(): void
    {
        // /account, /checkout, /widgets must keep HTML even if an automated
        // tool sends Accept: text/markdown — converting them would break
        // the storefront UI.
        foreach (['frontend.account.home.page', 'frontend.checkout.confirm.page', 'widgets.checkout.info'] as $route) {
            $event = $this->event('text/markdown', '<html><body><main><p>ok</p></main></body></html>', $route);
            $this->subscriber()->onResponse($event);
            self::assertStringStartsWith(
                'text/html',
                (string) $event->getResponse()->headers->get('Content-Type'),
                "$route should not be converted"
            );
        }
    }

    public function testStarStarAcceptKeepsHtml(): void
    {
        // Browsers and curl send `*/*`. Markdown is selected only on an explicit
        // text/markdown opt-in.
        $event = $this->event('*/*', '<html><body><main><p>x</p></main></body></html>');
        $this->subscriber()->onResponse($event);
        self::assertStringStartsWith('text/html', (string) $event->getResponse()->headers->get('Content-Type'));
    }

    public function testProductDetailPagePrependsStructuredHeader(): void
    {
        $html = '<html><head>'
            . '<script type="application/ld+json">'
            . json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => 'Vintage Sneaker',
                'sku' => 'SW-001',
                'offers' => [
                    'price' => '99.95',
                    'priceCurrency' => 'EUR',
                    'availability' => 'https://schema.org/InStock',
                ],
            ])
            . '</script>'
            . '</head><body><main><p>marketing copy</p></main></body></html>';

        $event = $this->event('text/markdown', $html, 'frontend.detail.page');
        $this->subscriber()->onResponse($event);

        $body = (string) $event->getResponse()->getContent();
        self::assertStringContainsString('# Vintage Sneaker', $body);
        self::assertStringContainsString('| Price | 99.95 EUR |', $body);
        self::assertStringContainsString('| Availability | in stock |', $body);
        self::assertStringContainsString('marketing copy', $body, 'generic body must follow the structured header');
    }

    public function testHomepageDoesNotGetProductHeader(): void
    {
        $html = '<html><head>'
            . '<script type="application/ld+json">'
            . json_encode(['@type' => 'Product', 'name' => 'Should not show'])
            . '</script>'
            . '</head><body><main><p>home</p></main></body></html>';
        $event = $this->event('text/markdown', $html, 'frontend.home.page');
        $this->subscriber()->onResponse($event);

        $body = (string) $event->getResponse()->getContent();
        self::assertStringNotContainsString('Should not show', $body);
    }

    public function testStaleContentLengthAndEncodingAreRemoved(): void
    {
        $request = Request::create('/');
        $request->headers->set('Accept', 'text/markdown');
        $request->attributes->set('_route', 'frontend.home.page');
        $response = new Response(
            '<html><body><main><h1>X</h1></main></body></html>',
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Length' => '999',
                'Content-Encoding' => 'gzip',
            ]
        );
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->subscriber()->onResponse($event);

        self::assertNull($event->getResponse()->headers->get('Content-Length'));
        self::assertNull($event->getResponse()->headers->get('Content-Encoding'));
    }

    private function subscriber(): MarkdownNegotiationSubscriber
    {
        return $this->subscriberWith(new ArrayConfigReader());
    }

    private function subscriberWith(ArrayConfigReader $reader): MarkdownNegotiationSubscriber
    {
        return new MarkdownNegotiationSubscriber(
            new AgentConfig($reader),
            new HtmlToMarkdownConverter(),
            new JsonLdExtractor(),
            new ProductMarkdownRenderer(),
        );
    }

    private function event(string $accept, string $body, string $route = 'frontend.home.page'): ResponseEvent
    {
        $request = Request::create('/');
        $request->headers->set('Accept', $accept);
        $request->attributes->set('_route', $route);
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
