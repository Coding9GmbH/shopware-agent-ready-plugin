<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\X402Controller;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class X402ControllerTest extends TestCase
{
    public function testReturns402WithX402Shape(): void
    {
        $controller = new X402Controller(new AgentConfig(new ArrayConfigReader()));
        $request = Request::create('https://shop.example/.well-known/x402');

        $response = $controller->paymentRequired($request);
        self::assertSame(402, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['x402Version']);
        self::assertSame('payment_required', $payload['error']);
        self::assertNotEmpty($payload['accepts']);
        self::assertSame('https://www.x402.org/', $payload['documentation']['spec']);
    }

    public function testDisabledReturnsNotFound(): void
    {
        $reader = new ArrayConfigReader(['Coding9AgentReady.config.enableX402' => false]);
        $controller = new X402Controller(new AgentConfig($reader));
        $request = Request::create('https://shop.example/.well-known/x402');

        self::assertSame(404, $controller->paymentRequired($request)->getStatusCode());
    }
}
