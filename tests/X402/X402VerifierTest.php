<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\X402;

use Coding9\AgentReady\Http\HttpResponse;
use Coding9\AgentReady\Tests\Support\FakeHttpClient;
use Coding9\AgentReady\X402\X402Verifier;
use PHPUnit\Framework\TestCase;

class X402VerifierTest extends TestCase
{
    public function testReturnsFailureWhenFacilitatorUrlMissing(): void
    {
        $client = new FakeHttpClient();
        $verifier = new X402Verifier($client);
        $result = $verifier->verify('', $this->validHeader(), $this->requirements());

        self::assertFalse($result->isValid);
        self::assertSame('facilitator_not_configured', $result->reason);
        self::assertSame([], $client->calls);
    }

    public function testReturnsFailureWhenHeaderNotBase64Json(): void
    {
        $client = new FakeHttpClient();
        $verifier = new X402Verifier($client);
        $result = $verifier->verify('https://facilitator.example', '!!!not base64!!!', $this->requirements());

        self::assertFalse($result->isValid);
        self::assertSame('invalid_payment_header', $result->reason);
    }

    public function testPostsToFacilitatorAndPropagatesIsValid(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, json_encode([
            'isValid' => true,
            'payer' => '0xPAYER',
        ]) ?: ''));
        $verifier = new X402Verifier($client);

        $result = $verifier->verify('https://facilitator.example/', $this->validHeader(), $this->requirements());

        self::assertTrue($result->isValid);
        self::assertSame('0xPAYER', $result->payer);

        self::assertCount(1, $client->calls);
        self::assertSame('POST', $client->calls[0]['method']);
        self::assertSame('https://facilitator.example/verify', $client->calls[0]['url']);
        $body = $client->calls[0]['body'];
        self::assertIsArray($body);
        self::assertArrayHasKey('paymentPayload', $body);
        self::assertArrayHasKey('paymentRequirements', $body);
    }

    public function testReturnsFacilitatorReasonOnRejection(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, json_encode([
            'isValid' => false,
            'invalidReason' => 'insufficient_funds',
        ]) ?: ''));
        $verifier = new X402Verifier($client);

        $result = $verifier->verify('https://facilitator.example', $this->validHeader(), $this->requirements());

        self::assertFalse($result->isValid);
        self::assertSame('insufficient_funds', $result->reason);
    }

    public function testReturnsUnreachableWhenStatusZero(): void
    {
        $client = new FakeHttpClient(new HttpResponse(0, 'connection refused'));
        $verifier = new X402Verifier($client);

        $result = $verifier->verify('https://facilitator.example', $this->validHeader(), $this->requirements());

        self::assertFalse($result->isValid);
        self::assertStringStartsWith('facilitator_unreachable', (string) $result->reason);
    }

    public function testRejectsNonJsonBody(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html>oops</html>'));
        $verifier = new X402Verifier($client);

        $result = $verifier->verify('https://facilitator.example', $this->validHeader(), $this->requirements());

        self::assertFalse($result->isValid);
        self::assertSame('facilitator_returned_non_json', $result->reason);
    }

    private function validHeader(): string
    {
        return base64_encode((string) json_encode([
            'x402Version' => 1,
            'scheme' => 'exact',
            'network' => 'base-sepolia',
            'payload' => ['signature' => '0xdeadbeef'],
        ]));
    }

    /** @return array<string, mixed> */
    private function requirements(): array
    {
        return [
            'scheme' => 'exact',
            'network' => 'base-sepolia',
            'maxAmountRequired' => '1000000',
            'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
        ];
    }
}
