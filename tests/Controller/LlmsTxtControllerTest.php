<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\LlmsTxtController;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class LlmsTxtControllerTest extends TestCase
{
    public function testLlmsTxtFollowsTheLlmstxtFormat(): void
    {
        $controller = new LlmsTxtController(new AgentConfig(new ArrayConfigReader()));
        $response = $controller->llmsTxt($this->request('https://shop.example/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        self::assertStringStartsWith('# ', $body, 'llms.txt must start with a single H1');
        self::assertStringContainsString('> ', $body, 'llms.txt should have a blockquote summary');
        self::assertStringContainsString('## Discovery', $body);
        self::assertStringContainsString('## Content', $body);
    }

    public function testLlmsTxtListsEveryEnabledWellKnownEndpoint(): void
    {
        $controller = new LlmsTxtController(new AgentConfig(new ArrayConfigReader()));
        $body = (string) $controller->llmsTxt($this->request('https://shop.example/'))->getContent();

        self::assertStringContainsString('https://shop.example/.well-known/api-catalog', $body);
        self::assertStringContainsString('https://shop.example/.well-known/agent-card.json', $body);
        self::assertStringContainsString('https://shop.example/.well-known/mcp/server-card.json', $body);
        self::assertStringContainsString('https://shop.example/.well-known/agent-skills/index.json', $body);
        self::assertStringContainsString('https://shop.example/.well-known/oauth-authorization-server', $body);
        self::assertStringContainsString('https://shop.example/.well-known/oauth-protected-resource', $body);
        self::assertStringContainsString('https://shop.example/sitemap.xml', $body);
        self::assertStringContainsString('https://shop.example/robots.txt', $body);
    }

    public function testDisabledEndpointsAreOmittedFromIndex(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableApiCatalog' => false,
            'Coding9AgentReady.config.enableMcpServerCard' => false,
        ]);
        $controller = new LlmsTxtController(new AgentConfig($reader));
        $body = (string) $controller->llmsTxt($this->request('https://shop.example/'))->getContent();

        self::assertStringNotContainsString('/.well-known/api-catalog', $body);
        self::assertStringNotContainsString('/.well-known/mcp/server-card.json', $body);
        self::assertStringContainsString('/.well-known/agent-card.json', $body);
    }

    public function testCustomSiteNameAndSummaryAreUsed(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.siteName' => 'Acme AI Shop',
            'Coding9AgentReady.config.siteSummary' => 'Custom one-line description.',
        ]);
        $controller = new LlmsTxtController(new AgentConfig($reader));
        $body = (string) $controller->llmsTxt($this->request())->getContent();

        self::assertStringContainsString('# Acme AI Shop', $body);
        self::assertStringContainsString('> Custom one-line description.', $body);
    }

    public function testFallsBackToHostHeaderForSiteName(): void
    {
        $controller = new LlmsTxtController(new AgentConfig(new ArrayConfigReader()));
        $body = (string) $controller->llmsTxt($this->request('https://my.shop.example/'))->getContent();

        self::assertStringContainsString('# my.shop.example', $body);
    }

    public function testLlmsTxtCanBeDisabled(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableLlmsTxt' => false,
        ]);
        $controller = new LlmsTxtController(new AgentConfig($reader));

        self::assertSame(404, $controller->llmsTxt($this->request())->getStatusCode());
        self::assertSame(404, $controller->llmsFullTxt($this->request())->getStatusCode());
    }

    public function testLlmsFullTxtIncludesIndexAndAcceptInstructions(): void
    {
        $controller = new LlmsTxtController(new AgentConfig(new ArrayConfigReader()));
        $response = $controller->llmsFullTxt($this->request('https://shop.example/'));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();

        self::assertStringContainsString('## Discovery', $body);
        self::assertStringContainsString('## How to fetch the full content', $body);
        self::assertStringContainsString("Accept: text/markdown", $body);
        self::assertStringContainsString('https://shop.example/', $body);
    }

    public function testResponsesCarryCorsAndCacheHeaders(): void
    {
        $controller = new LlmsTxtController(new AgentConfig(new ArrayConfigReader()));
        $response = $controller->llmsTxt($this->request());

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('max-age=', (string) $response->headers->get('Cache-Control'));
    }

    private function request(string $base = 'https://shop.example/'): Request
    {
        return Request::create($base);
    }
}
