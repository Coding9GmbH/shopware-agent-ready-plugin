<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * x402 (HTTP "Payment Required") demo endpoint.
 *
 * x402 is the emerging convention for agentic payments: a server that
 * needs payment to fulfil a request returns HTTP 402 with a JSON body
 * describing supported payment schemes and the price. The agent (or a
 * payment-capable proxy) settles the payment, retries with an
 * `X-PAYMENT` header, and the server fulfils the request.
 *
 * This plugin doesn't process payments — Shopware already has a payment
 * pipeline behind `POST /store-api/handle-payment`. The endpoint exists
 * to:
 *
 *  1. Demonstrate the x402 response shape an agent should expect.
 *  2. Give integrators a turn-key place to wire a real x402 facilitator
 *     (e.g. Coinbase x402, Stripe Agent Toolkit) without touching the
 *     storefront checkout pipeline.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class X402Controller extends AbstractController
{
    public function __construct(private readonly AgentConfig $config)
    {
    }

    #[Route(
        path: '/.well-known/x402',
        name: 'frontend.coding9.agent_ready.x402',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET', 'POST']
    )]
    public function paymentRequired(Request $request): Response
    {
        if (!$this->config->isX402Enabled($this->salesChannelId($request))) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        $payload = [
            'x402Version' => 1,
            'error' => 'payment_required',
            'accepts' => [
                [
                    'scheme' => 'demo',
                    'description' => 'Showcase only. No real settlement happens at this endpoint.',
                    'maxAmountRequired' => '0',
                    'asset' => 'USD',
                    'resource' => $base . '/.well-known/x402',
                    'payTo' => $base,
                ],
            ],
            'documentation' => [
                'spec' => 'https://www.x402.org/',
                'shopwarePayment' => $base . '/store-api/handle-payment',
                'note' => 'Wire a real facilitator (Coinbase x402, Stripe Agent Toolkit, Visa Intelligent Commerce) into this controller to settle agentic payments before calling /store-api/handle-payment.',
            ],
        ];

        $response = new JsonResponse($payload, 402);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        return $response;
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }
}
