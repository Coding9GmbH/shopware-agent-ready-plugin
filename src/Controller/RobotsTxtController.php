<?php declare(strict_types=1);

namespace Coding9\AgentReady\Controller;

use Coding9\AgentReady\Service\AgentConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a Content-Signal aware robots.txt at /robots.txt.
 *
 * NOTE: Symfony route matching only kicks in if no static public/robots.txt
 * exists. Document this clearly to plugin users.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class RobotsTxtController extends AbstractController
{
    public function __construct(private readonly AgentConfig $config)
    {
    }

    #[Route(
        path: '/robots.txt',
        name: 'frontend.coding9.agent_ready.robots_txt',
        defaults: ['auth_required' => false, 'XmlHttpRequest' => true],
        methods: ['GET']
    )]
    public function robotsTxt(Request $request): Response
    {
        $sc = null;
        $value = $request->attributes->get('sw-sales-channel-id');
        if (is_string($value) && $value !== '') {
            $sc = $value;
        }

        $body = $this->build($sc, $request->getSchemeAndHttpHost());
        return new Response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function build(?string $salesChannelId = null, string $baseUrl = ''): string
    {
        $lines = [];

        if ($this->config->isContentSignalsEnabled($salesChannelId)) {
            $signals = $this->config->getContentSignals($salesChannelId);
            $parts = [];
            foreach ($signals as $name => $value) {
                // Defensive: dropdown values today are limited to yes/no, but
                // strip any line-break-based injection just in case the schema
                // is ever loosened.
                $parts[] = $this->oneLine($name) . '=' . $this->oneLine($value);
            }
            $lines[] = '# Content-Signal directives (https://contentsignals.org/)';
            $lines[] = 'Content-Signal: ' . implode(', ', $parts);
            $lines[] = '';
        }

        $lines[] = 'User-agent: *';
        $lines[] = 'Disallow: /account/';
        $lines[] = 'Disallow: /checkout/';
        $lines[] = 'Disallow: /widgets/';
        $lines[] = 'Allow: /';
        $lines[] = '';
        $sitemap = rtrim($baseUrl, '/') . '/sitemap.xml';
        $lines[] = 'Sitemap: ' . ($baseUrl !== '' ? $sitemap : '/sitemap.xml');

        return implode("\n", $lines) . "\n";
    }

    private function oneLine(string $value): string
    {
        return preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    }
}
