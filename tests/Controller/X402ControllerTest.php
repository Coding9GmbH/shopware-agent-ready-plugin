<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Controller;

use Coding9\AgentReady\Controller\X402Controller;
use Coding9\AgentReady\Http\HttpResponse;
use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use Coding9\AgentReady\Tests\Support\FakeHttpClient;
use Coding9\AgentReady\X402\X402Verifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class X402ControllerTest extends TestCase
{
    public function testReturns402WithRequirementsWhenNoPaymentHeader(): void
    {
        $controller = $this->controller();
        $request = Request::create('https://shop.example/.well-known/x402');

        $response = $controller->paymentRequired($request);
        self::assertSame(402, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['x402Version']);
        self::assertSame('payment_required', $payload['error']);
        self::assertNotEmpty($payload['accepts']);
        self::assertSame('exact', $payload['accepts'][0]['scheme']);
        self::assertSame('base-sepolia', $payload['accepts'][0]['network']);
        self::assertSame('https://shop.example/.well-known/x402', $payload['accepts'][0]['resource']);
    }

    public function testReturnsTwoHundredAndFulfilmentHintOnVerifiedPayment(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, json_encode([
            'isValid' => true,
            'payer' => '0xCAFE',
        ]) ?: ''));
        $controller = $this->controller(
            new ArrayConfigReader([
                'Coding9AgentReady.config.x402FacilitatorUrl' => 'https://facilitator.example',
            ]),
            $client,
        );

        $request = Request::create('https://shop.example/.well-known/x402', 'POST');
        $request->headers->set(
            'X-PAYMENT',
            base64_encode((string) json_encode(['x402Version' => 1, 'scheme' => 'exact']))
        );

        $response = $controller->paymentRequired($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame('0xCAFE', $payload['payer']);
        self::assertSame('/store-api/handle-payment', $payload['fulfilment']['path']);
        self::assertNotEmpty($response->headers->get('X-PAYMENT-RESPONSE'));
    }

    public function testReturns402WithReasonWhenFacilitatorRejects(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, json_encode([
            'isValid' => false,
            'invalidReason' => 'insufficient_funds',
        ]) ?: ''));
        $controller = $this->controller(
            new ArrayConfigReader([
                'Coding9AgentReady.config.x402FacilitatorUrl' => 'https://facilitator.example',
            ]),
            $client,
        );

        $request = Request::create('https://shop.example/.well-known/x402', 'POST');
        $request->headers->set('X-PAYMENT', base64_encode((string) json_encode(['x402Version' => 1])));

        $response = $controller->paymentRequired($request);
        self::assertSame(402, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('insufficient_funds', $payload['error']);
    }

    public function testDisabledReturnsNotFound(): void
    {
        $controller = $this->controller(new ArrayConfigReader([
            'Coding9AgentReady.config.enableX402' => false,
        ]));
        $request = Request::create('https://shop.example/.well-known/x402');

        self::assertSame(404, $controller->paymentRequired($request)->getStatusCode());
    }

    private function controller(?ArrayConfigReader $reader = null, ?FakeHttpClient $client = null): X402Controller
    {
        $reader ??= new ArrayConfigReader();
        $client ??= new FakeHttpClient();
        return new X402Controller(
            new AgentConfig($reader),
            new X402Verifier($client),
        );
    }
}
