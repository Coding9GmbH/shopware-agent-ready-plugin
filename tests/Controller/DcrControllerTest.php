<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\DcrController;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class DcrControllerTest extends TestCase
{
    public function testReturnsHonest501WithDocumentationLink(): void
    {
        $controller = new DcrController(new AgentConfig(new ArrayConfigReader()));
        $request = Request::create(
            'https://shop.example/api/oauth/register',
            'POST',
            content: json_encode(['client_name' => 'agent']) ?: ''
        );

        $response = $controller->register($request);
        self::assertSame(501, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('not_supported', $payload['error']);
        self::assertStringContainsString('developer.shopware.com', $payload['documentation']);
    }

    public function testRejectsNonJsonBody(): void
    {
        $controller = new DcrController(new AgentConfig(new ArrayConfigReader()));
        $request = Request::create('https://shop.example/api/oauth/register', 'POST', content: 'not-json');

        self::assertSame(400, $controller->register($request)->getStatusCode());
    }

    public function testReturnsNotFoundWhenDisabled(): void
    {
        $reader = new ArrayConfigReader(['Coding9AgentReady.config.enableDcr' => false]);
        $controller = new DcrController(new AgentConfig($reader));
        $request = Request::create('https://shop.example/api/oauth/register', 'POST', content: '{}');

        self::assertSame(404, $controller->register($request)->getStatusCode());
    }
}
