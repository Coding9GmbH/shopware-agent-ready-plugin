<?php declare(strict_types=1);

namespace Coding9\AgentReady\Service;

/**
 * Typed accessor for plugin configuration. Reads through a small ConfigReader
 * abstraction so the class stays unit-testable without booting Shopware.
 */
class AgentConfig
{
    private const PREFIX = 'Coding9AgentReady.config.';

    public function __construct(private readonly ConfigReader $reader)
    {
    }

    public function bool(string $key, bool $default, ?string $salesChannelId = null): bool
    {
        $value = $this->reader->get(self::PREFIX . $key, $salesChannelId);
        if ($value === null || $value === '') {
            return $default;
        }
        return (bool) $value;
    }

    public function string(string $key, string $default = '', ?string $salesChannelId = null): string
    {
        $value = $this->reader->get(self::PREFIX . $key, $salesChannelId);
        if ($value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public function isLinkHeadersEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableLinkHeaders', true, $salesChannelId);
    }

    public function getServiceDocPath(?string $salesChannelId = null): string
    {
        return $this->string('serviceDocPath', '/docs/api', $salesChannelId);
    }

    public function isMarkdownNegotiationEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableMarkdownNegotiation', true, $salesChannelId);
    }

    public function isContentSignalsEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableContentSignals', true, $salesChannelId);
    }

    /** @return array{ai-train: string, search: string, ai-input: string} */
    public function getContentSignals(?string $salesChannelId = null): array
    {
        return [
            'ai-train' => $this->string('contentSignalAiTrain', 'no', $salesChannelId),
            'search'   => $this->string('contentSignalSearch', 'yes', $salesChannelId),
            'ai-input' => $this->string('contentSignalAiInput', 'no', $salesChannelId),
        ];
    }

    public function isApiCatalogEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableApiCatalog', true, $salesChannelId);
    }

    public function isOAuthDiscoveryEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableOAuthDiscovery', true, $salesChannelId);
    }

    public function isOAuthProtectedResourceEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableOAuthProtectedResource', true, $salesChannelId);
    }

    public function isMcpServerCardEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableMcpServerCard', true, $salesChannelId);
    }

    public function getMcpServerEndpoint(?string $salesChannelId = null): string
    {
        return $this->string('mcpServerEndpoint', '', $salesChannelId);
    }

    public function isA2aAgentCardEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableA2aAgentCard', true, $salesChannelId);
    }

    public function getA2aAgentName(?string $salesChannelId = null): string
    {
        return $this->string('a2aAgentName', 'Shopware Storefront Agent', $salesChannelId);
    }

    public function getA2aAgentDescription(?string $salesChannelId = null): string
    {
        return $this->string(
            'a2aAgentDescription',
            'Discover products, place orders and manage the shopping cart on this Shopware 6 storefront.',
            $salesChannelId
        );
    }

    public function isAgentSkillsIndexEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableAgentSkillsIndex', true, $salesChannelId);
    }

    public function isLlmsTxtEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableLlmsTxt', true, $salesChannelId);
    }

    public function getSiteName(?string $salesChannelId = null): string
    {
        return $this->string('siteName', '', $salesChannelId);
    }

    public function getSiteSummary(?string $salesChannelId = null): string
    {
        return $this->string(
            'siteSummary',
            'Shopware 6 storefront with AI-agent discovery enabled.',
            $salesChannelId
        );
    }

    public function isWebMcpEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableWebMcp', true, $salesChannelId);
    }

    public function isMcpServerEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableMcpServer', true, $salesChannelId);
    }

    public function isA2aServerEnabled(?string $salesChannelId = null): bool
    {
        return $this->bool('enableA2aServer', true, $salesChannelId);
    }
}
