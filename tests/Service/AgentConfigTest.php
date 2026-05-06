<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Service;

use Coding9\AgentReady\Service\AgentConfig;
use Coding9\AgentReady\Tests\Support\ArrayConfigReader;
use PHPUnit\Framework\TestCase;

class AgentConfigTest extends TestCase
{
    public function testBoolReturnsDefaultWhenUnset(): void
    {
        $cfg = new AgentConfig(new ArrayConfigReader());
        self::assertTrue($cfg->isLinkHeadersEnabled());
        self::assertTrue($cfg->isMarkdownNegotiationEnabled());
        self::assertTrue($cfg->isContentSignalsEnabled());
    }

    public function testStringDefaults(): void
    {
        $cfg = new AgentConfig(new ArrayConfigReader());
        self::assertSame('/store-api/_info/openapi3.json', $cfg->getServiceDocPath());
        self::assertSame('Shopware Storefront Agent', $cfg->getA2aAgentName());
        self::assertSame('', $cfg->getMcpServerEndpoint());
    }

    public function testContentSignalDefaults(): void
    {
        $cfg = new AgentConfig(new ArrayConfigReader());
        self::assertSame(
            ['ai-train' => 'no', 'search' => 'yes', 'ai-input' => 'no'],
            $cfg->getContentSignals()
        );
    }

    public function testValuesAreOverridden(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.enableLinkHeaders' => false,
            'Coding9AgentReady.config.serviceDocPath' => '/api-docs',
            'Coding9AgentReady.config.contentSignalAiTrain' => 'yes',
        ]);
        $cfg = new AgentConfig($reader);

        self::assertFalse($cfg->isLinkHeadersEnabled());
        self::assertSame('/api-docs', $cfg->getServiceDocPath());
        self::assertSame('yes', $cfg->getContentSignals()['ai-train']);
    }

    public function testEmptyStringFallsBackToDefault(): void
    {
        $reader = new ArrayConfigReader([
            'Coding9AgentReady.config.serviceDocPath' => '',
        ]);
        $cfg = new AgentConfig($reader);
        self::assertSame('/store-api/_info/openapi3.json', $cfg->getServiceDocPath());
    }
}
