<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Subscriber;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Subscriber\RobotsTxtSubscriber;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class RobotsTxtSubscriberTest extends TestCase
{
    public function testInjectsContentSignalAfterUserAgentWildcardGroup(): void
    {
        $body = <<<TXT
User-agent: *

Allow: /
Disallow: /account/

Sitemap: https://example.test/sitemap.xml
TXT;

        $event = $this->event('/robots.txt', $body);
        $this->subscriber()->onResponse($event);

        $newBody = (string) $event->getResponse()->getContent();
        self::assertStringContainsString(
            'Content-Signal: ai-train=no, search=yes, ai-input=no',
            $newBody
        );

        $userAgentPos = strpos($newBody, 'User-agent: *');
        $signalPos = strpos($newBody, 'Content-Signal:');
        self::assertNotFalse($userAgentPos);
        self::assertNotFalse($signalPos);
        self::assertGreaterThan(
            $userAgentPos,
            $signalPos,
            'Content-Signal must appear inside the User-agent: * group'
        );

        // Pre-existing rules must survive untouched.
        self::assertStringContainsString('Disallow: /account/', $newBody);
        self::assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $newBody);
    }

    public function testIsIdempotentWhenContentSignalAlreadyPresent(): void
    {
        $body = "User-agent: *\nContent-Signal: ai-train=yes, search=yes, ai-input=yes\nAllow: /\n";
        $event = $this->event('/robots.txt', $body);
        $this->subscriber()->onResponse($event);

        $newBody = (string) $event->getResponse()->getContent();
        self::assertSame($body, $newBody);
        self::assertSame(1, substr_count($newBody, 'Content-Signal:'));
    }

    public function testSkipsWhenDisabled(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableContentSignals' => false,
        ]);
        $body = "User-agent: *\nAllow: /\n";
        $event = $this->event('/robots.txt', $body);
        $this->subscriber($reader)->onResponse($event);

        self::assertSame($body, (string) $event->getResponse()->getContent());
    }

    public function testIgnoresOtherPaths(): void
    {
        $body = "User-agent: *\nAllow: /\n";
        $event = $this->event('/some/other/path', $body);
        $this->subscriber()->onResponse($event);

        self::assertSame($body, (string) $event->getResponse()->getContent());
    }

    public function testIgnoresNonSuccessStatus(): void
    {
        $body = "User-agent: *\nAllow: /\n";
        $event = $this->event('/robots.txt', $body, 404);
        $this->subscriber()->onResponse($event);

        self::assertSame($body, (string) $event->getResponse()->getContent());
    }

    public function testIgnoresSubRequests(): void
    {
        $body = "User-agent: *\nAllow: /\n";
        $request = Request::create('/robots.txt');
        $response = new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->subscriber()->onResponse($event);
        self::assertSame($body, (string) $event->getResponse()->getContent());
    }

    public function testInjectsEvenWhenNoWildcardUserAgentExists(): void
    {
        $body = "User-agent: Googlebot\nAllow: /\n";
        $event = $this->event('/robots.txt', $body);
        $this->subscriber()->onResponse($event);

        $newBody = (string) $event->getResponse()->getContent();
        self::assertStringContainsString('User-agent: *', $newBody);
        self::assertStringContainsString('Content-Signal: ai-train=no, search=yes, ai-input=no', $newBody);
        self::assertStringContainsString('User-agent: Googlebot', $newBody);
    }

    public function testRespectsCustomSignals(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.contentSignalAiTrain' => 'yes',
            'Coding9AgentReady.config.contentSignalSearch'  => 'no',
            'Coding9AgentReady.config.contentSignalAiInput' => 'yes',
        ]);
        $body = "User-agent: *\nAllow: /\n";
        $event = $this->event('/robots.txt', $body);
        $this->subscriber($reader)->onResponse($event);

        self::assertStringContainsString(
            'Content-Signal: ai-train=yes, search=no, ai-input=yes',
            (string) $event->getResponse()->getContent()
        );
    }

    public function testIgnoresNonTextPlainResponses(): void
    {
        $body = '<html><body>not robots</body></html>';
        $request = Request::create('/robots.txt');
        $response = new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $event = new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber()->onResponse($event);
        self::assertSame($body, (string) $event->getResponse()->getContent());
    }

    private function subscriber(?ArrayConfigReader $reader = null): RobotsTxtSubscriber
    {
        return new RobotsTxtSubscriber(new AgentConfig($reader ?? new ArrayConfigReader()));
    }

    private function event(string $path, string $body, int $status = 200): ResponseEvent
    {
        $request = Request::create($path);
        $response = new Response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
        return new ResponseEvent(
            $this->kernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
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
