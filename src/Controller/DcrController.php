<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RFC 7591 — OAuth 2.0 Dynamic Client Registration.
 *
 * Shopware's Admin API does not natively support DCR; integration clients
 * are created in the admin UI under *Settings → System → Integrations*. This
 * endpoint advertises the gap honestly: agents that POST a registration
 * request receive a structured 501 with a pointer to the manual flow rather
 * than a misleading 404. It exists because OAuth Authorization Server
 * Metadata (RFC 8414) advertises this URL via `registration_endpoint` once
 * DCR is enabled in the admin.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class DcrController extends AbstractController
{
    public function __construct(private readonly AgentConfig $config)
    {
    }

    #[Route(
        path: '/api/oauth/register',
        name: 'frontend.coding9.agent_ready.dcr',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function register(Request $request): Response
    {
        if (!$this->config->isDcrEnabled($this->salesChannelId($request))) {
            return new Response('not found', 404, ['Content-Type' => 'text/plain']);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error(400, 'invalid_client_metadata', 'request body must be JSON');
        }

        return $this->error(
            501,
            'not_supported',
            'Shopware does not yet support automated client registration. '
            . 'Create an Integration manually in the Shopware admin '
            . '(Settings → System → Integrations); the resulting access_key + '
            . 'secret_access_key act as client_id + client_secret against '
            . '/api/oauth/token.'
        );
    }

    private function error(int $status, string $code, string $description): JsonResponse
    {
        $response = new JsonResponse([
            'error' => $code,
            'error_description' => $description,
            'documentation' => 'https://developer.shopware.com/docs/guides/integrations-api/general-concepts/authentication.html',
        ], $status);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    private function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get('sw-sales-channel-id');
        return is_string($value) && $value !== '' ? $value : null;
    }
}
