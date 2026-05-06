<?php declare(strict_types=1);

namespace Coding9\AgentReady\X402;

use Coding9\AgentReady\Http\HttpClient;

/**
 * Verifies an X-PAYMENT header against an x402 facilitator.
 *
 * The x402 protocol (https://www.x402.org/) defines a two-step flow:
 *
 *  1. The resource server returns 402 with `accepts[]` describing
 *     accepted payment requirements.
 *  2. The agent re-issues the request with `X-PAYMENT: <base64 payload>`.
 *     The resource server posts {paymentPayload, paymentRequirements} to
 *     the facilitator's `/verify` endpoint and, on success, fulfils the
 *     request. Settlement happens out-of-band via `/settle`.
 *
 * This class doesn't sign or settle — it just verifies. Settlement is left
 * to whichever facilitator the operator configures (Coinbase x402,
 * Stripe Agent Toolkit, …); the verification step is enough to prove the
 * agent is willing+able to pay before we commit storefront resources.
 */
class X402Verifier
{
    public function __construct(private readonly HttpClient $client)
    {
    }

    /**
     * @param array<string, mixed> $requirements The `accepts[]` entry the
     *                                            request is being verified
     *                                            against.
     * @return X402VerificationResult
     */
    public function verify(string $facilitatorUrl, string $xPaymentHeader, array $requirements): X402VerificationResult
    {
        $facilitatorUrl = rtrim($facilitatorUrl, '/');
        if ($facilitatorUrl === '') {
            return X402VerificationResult::failure('facilitator_not_configured');
        }

        $payload = $this->decodePayment($xPaymentHeader);
        if ($payload === null) {
            return X402VerificationResult::failure('invalid_payment_header');
        }

        $response = $this->client->request(
            'POST',
            $facilitatorUrl . '/verify',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            [
                'paymentPayload' => $payload,
                'paymentRequirements' => $requirements,
            ],
        );

        if ($response->status === 0) {
            return X402VerificationResult::failure('facilitator_unreachable: ' . $response->body);
        }

        $body = $response->json();
        if ($body === null) {
            return X402VerificationResult::failure('facilitator_returned_non_json');
        }

        $isValid = (bool) ($body['isValid'] ?? false);
        if (!$isValid) {
            $reason = is_string($body['invalidReason'] ?? null)
                ? $body['invalidReason']
                : 'rejected_by_facilitator';
            return X402VerificationResult::failure($reason);
        }

        $payer = is_string($body['payer'] ?? null) ? $body['payer'] : null;
        return X402VerificationResult::success($payer, $payload);
    }

    /** @return array<string, mixed>|null */
    private function decodePayment(string $header): ?array
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        $decoded = base64_decode($header, true);
        if ($decoded === false) {
            return null;
        }
        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($payload) ? $payload : null;
    }
}
