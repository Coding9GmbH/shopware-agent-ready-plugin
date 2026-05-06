<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Skill;

use Coding9\AgentReady\Skill\SkillInputException;
use Coding9\AgentReady\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    public function testRegistryExposesFiveCanonicalSkills(): void
    {
        $ids = array_keys((new SkillRegistry())->all());

        self::assertSame(
            ['search-products', 'get-product', 'create-context', 'get-cart', 'manage-cart'],
            $ids
        );
    }

    public function testMcpToolListShape(): void
    {
        foreach ((new SkillRegistry())->asMcpToolList() as $tool) {
            self::assertArrayHasKey('name', $tool);
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function testA2aSkillListShape(): void
    {
        foreach ((new SkillRegistry())->asA2aSkillList() as $skill) {
            self::assertArrayHasKey('id', $skill);
            self::assertArrayHasKey('name', $skill);
            self::assertArrayHasKey('description', $skill);
            self::assertArrayHasKey('tags', $skill);
            self::assertNotEmpty($skill['tags']);
        }
    }

    public function testValidateRejectsMissingRequired(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        $this->expectException(SkillInputException::class);
        $skill->validate([]);
    }

    public function testValidateRejectsLimitOverMaximum(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        $this->expectException(SkillInputException::class);
        $skill->validate(['query' => 'x', 'limit' => 999]);
    }

    public function testValidateRejectsAdditionalProperties(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        $this->expectException(SkillInputException::class);
        $skill->validate(['query' => 'x', 'evil' => true]);
    }

    public function testGetProductAcceptsBothUuidFormats(): void
    {
        $skill = (new SkillRegistry())->get('get-product');
        $skill->validate(['productId' => str_repeat('a', 32)]);
        $skill->validate(['productId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
        $this->expectException(SkillInputException::class);
        $skill->validate(['productId' => 'not-a-uuid']);
    }

    public function testManageCartRejectsInvalidAction(): void
    {
        $skill = (new SkillRegistry())->get('manage-cart');
        $this->expectException(SkillInputException::class);
        $skill->validate(['action' => 'destroy', 'contextToken' => 'tok']);
    }

    public function testSkillBodiesContainOutputSection(): void
    {
        foreach ((new SkillRegistry())->all() as $skill) {
            self::assertStringContainsString(
                '## Output',
                $skill->body,
                'skill ' . $skill->id . ' must document its output shape'
            );
        }
    }
}
