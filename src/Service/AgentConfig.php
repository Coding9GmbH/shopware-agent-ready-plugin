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

    /**
     * Per-skill enable toggle. Disabled skills are hidden from
     * /.well-known/agent-skills, agent-card.json and MCP tools/list, and
     * tools/call returns method-not-found instead of executing them.
     *
     * The default is the value of the second argument: agent-readiness
     * features default to ON, but operators flip the more sensitive ones
     * (customer-login, place-order, …) off when they're not needed.
     */
    public function isSkillEnabled(string $skillId, bool $default = true, ?string $salesChannelId = null): bool
    {
        $key = 'enableSkill' . str_replace(' ', '', ucwords(str_replace('-', ' ', $skillId)));
        return $this->bool($key, $default, $salesChannelId);
    }

    /**
     * Maximum amount the place-order skill is allowed to commit, in the
     * sales-channel currency's main unit (e.g. 250.0 = 250 EUR). Returns
     * 0.0 when no cap is configured (= unlimited).
     */
    public function getPlaceOrderMaxAmount(?string $salesChannelId = null): float
    {
        $value = $this->string('placeOrderMaxAmount', '', $salesChannelId);
        if ($value === '') {
            return 0.0;
        }
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * CORS origin allowlist for the /mcp and /a2a endpoints. Returns:
     *
     *   - empty list  → no CORS header is emitted (safest default;
     *                   server-side agent hosts don't need it).
     *   - ['*']       → wildcard echoed back; convenient for development
     *                   but lets any browser tab POST credentials.
     *   - ['https://a.example', 'https://b.example']
     *                 → only matching Origin headers get a CORS response.
     *
     * @return array<int, string>
     */
    public function getCorsAllowedOrigins(?string $salesChannelId = null): array
    {
        $raw = $this->string('corsAllowedOrigins', '', $salesChannelId);
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[\s,]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn ($v) => $v !== ''));
    }
}
