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
        $response = $controller->robotsTxt();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('User-agent: *', (string) $response->getContent());
    }
}
