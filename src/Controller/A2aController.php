<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\A2a\A2aServer;
use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class A2aController extends AbstractController
{
    public function __construct(
        private readonly AgentConfig $config,
        private readonly A2aServer $server,
    ) {
    }

    #[Route(
        path: '/a2a',
        name: 'frontend.coding9.agent_ready.a2a',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function dispatch(Request $request): Response
    {
        if (!$this->config->isA2aServerEnabled($this->salesChannelId($request))) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'parse error'],
            ]);
        }

        $response = new JsonResponse($this->server->handle($payload));
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
