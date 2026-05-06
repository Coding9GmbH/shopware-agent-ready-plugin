<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\X402\X402Verifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * x402 ("HTTP Payment Required") agentic-payment endpoint.
 *
 * On a request without a valid `X-PAYMENT` header we return 402 with
 * `accepts[]` describing the payment requirements (default: 1 USDC on the
 * `base-sepolia` testnet — change via plugin config to flip to mainnet
 * USDC, BTC-Lightning, etc.).
 *
 * On a request with `X-PAYMENT` we delegate verification to the configured
 * facilitator URL via {@see X402Verifier}. On success we return 200 with
 * an `X-PAYMENT-RESPONSE` header echoing the payer + a structured
 * "fulfilment hint" — concretely: a Shopware Store API instruction the
 * agent can immediately follow up with `/store-api/handle-payment`.
 *
 * Settlement is intentionally left to the facilitator. The plugin does
 * not custody funds.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class X402Controller extends AbstractController
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly X402Verifier $verifier,
    ) {
    }

    #[Route(
        path: '/.well-known/x402',
        name: 'frontend.coding9.agent_ready.x402',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['GET', 'POST']
    )]
    public function paymentRequired(Request $request): Response
    {
        $sc = $this->salesChannelId($request);
        if (!$this->config->isX402Enabled($sc)) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $requirements = $this->buildRequirements($base, $sc);

        $paymentHeader = (string) $request->headers->get('X-PAYMENT', '');
        if ($paymentHeader === '') {
            return $this->paymentRequiredResponse($requirements);
        }

        $result = $this->verifier->verify(
            $this->config->getX402FacilitatorUrl($sc),
            $paymentHeader,
            $requirements,
        );

        if (!$result->isValid) {
            return $this->paymentRequiredResponse($requirements, $result->reason);
        }

        $body = [
            'success' => true,
            'payer' => $result->payer,
            'fulfilment' => [
                'kind' => 'http-request',
                'method' => 'POST',
                'path' => '/store-api/handle-payment',
                'headers' => [
                    'sw-access-key' => '<SALES_CHANNEL_ACCESS_KEY>',
                    'sw-context-token' => '<CONTEXT_TOKEN>',
                ],
                'note' => 'Payment verified by the configured x402 facilitator. '
                    . 'Use /store-api/handle-payment to resolve the open order, '
                    . 'or settle out-of-band via the facilitator /settle endpoint.',
            ],
        ];

        $response = new JsonResponse($body, 200);
        $response->headers->set('X-PAYMENT-RESPONSE', $this->encodePaymentResponse($result->payer, $requirements));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }

    /**
     * @param array<string, mixed> $requirements
     */
    private function paymentRequiredResponse(array $requirements, ?string $invalidReason = null): JsonResponse
    {
        $payload = [
            'x402Version' => 1,
            'error' => $invalidReason ?? 'payment_required',
            'accepts' => [$requirements],
        ];

        $response = new JsonResponse($payload, 402);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequirements(string $base, ?string $sc): array
    {
        return [
            'scheme' => $this->config->getX402Scheme($sc),
            'network' => $this->config->getX402Network($sc),
            'maxAmountRequired' => $this->config->getX402MaxAmount($sc),
            'asset' => $this->config->getX402Asset($sc),
            'payTo' => $this->config->getX402PayTo($sc),
            'resource' => $base . '/.well-known/x402',
            'description' => 'Demo agentic-commerce gate for the Shopware storefront. '
                . 'Successful verification returns a Store API fulfilment hint.',
            'mimeType' => 'application/json',
            'maxTimeoutSeconds' => 60,
        ];
    }

    /**
     * The x402 spec defines `X-PAYMENT-RESPONSE` as base64-encoded JSON the
     * client can verify after settlement. We emit the canonical shape so
     * agents can record proof of the transaction.
     *
     * @param array<string, mixed> $requirements
     */
    private function encodePaymentResponse(?string $payer, array $requirements): string
    {
        $body = [
            'success' => true,
            'payer' => $payer,
            'network' => $requirements['network'] ?? null,
            'asset' => $requirements['asset'] ?? null,
        ];
        return base64_encode((string) json_encode($body));
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }
}
