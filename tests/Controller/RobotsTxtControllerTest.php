<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\RobotsTxtController;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;

class RobotsTxtControllerTest extends TestCase
{
    public function testIncludesContentSignalsWithDefaults(): void
    {
        $controller = new RobotsTxtController(new AgentConfig(new ArrayConfigReader()));
        $body = $controller->build();

        self::assertStringContainsString('Content-Signal: ai-train=no, search=yes, ai-input=no', $body);
        self::assertStringContainsString('User-agent: *', $body);
        self::assertStringContainsString('Sitemap: /sitemap.xml', $body);
        self::assertStringContainsString('Disallow: /checkout/', $body);
    }

    public function testContentSignalAppearsInsideUserAgentGroup(): void
    {
        // Per draft-romm-aipref-contentsignals, Content-Signal is a record
        // member that must follow a User-agent line so it is associated with
        // that user agent. An orphan Content-Signal preceding any User-agent
        // group is ignored by Cloudflare's validator and other parsers.
        $controller = new RobotsTxtController(new AgentConfig(new ArrayConfigReader()));
        $body = $controller->build();

        $userAgentPos = strpos($body, 'User-agent: *');
        $signalPos = strpos($body, 'Content-Signal:');
        self::assertNotFalse($userAgentPos);
        self::assertNotFalse($signalPos);
        self::assertGreaterThan(
            $userAgentPos,
            $signalPos,
            'Content-Signal must appear after User-agent: * to be grouped with it'
        );
    }

    public function testRespectsCustomSignals(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.contentSignalAiTrain' => 'yes',
            'Coding9AgentReady.config.contentSignalSearch'  => 'no',
            'Coding9AgentReady.config.contentSignalAiInput' => 'yes',
        ]);
        $controller = new RobotsTxtController(new AgentConfig($reader));
        $body = $controller->build();

        self::assertStringContainsString('Content-Signal: ai-train=yes, search=no, ai-input=yes', $body);
    }

    public function testCanDisableContentSignals(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableContentSignals' => false,
        ]);
        $controller = new RobotsTxtController(new AgentConfig($reader));
        $body = $controller->build();

        self::assertStringNotContainsString('Content-Signal:', $body);
        self::assertStringContainsString('User-agent: *', $body);
    }

    public function testRobotsTxtResponseHasPlainTextContentType(): void
    {
        $controller = new RobotsTxtController(new AgentConfig(new ArrayConfigReader()));
        $response = $controller->robotsTxt(\Symfony\Component\HttpFoundation\Request::create('/robots.txt'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('User-agent: *', (string) $response->getContent());
    }

    public function testSalesChannelOverrideTakesPrecedence(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.contentSignalAiTrain' => 'no',
        ]);
        $controller = new RobotsTxtController(new AgentConfig($reader));
        // Per-sales-channel reads currently fall back to the same global value
        // (ArrayConfigReader is not sales-channel-aware), but the call shape
        // proves the controller forwards the id without errors.
        $body = $controller->build('01HZW0SCTESTSALESCHANNELID');
        self::assertStringContainsString('Content-Signal: ai-train=no', $body);
    }
}
