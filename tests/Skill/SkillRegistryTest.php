<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests\Skill;

use Coding9\AgentReady\Skill\SkillInputException;
use Coding9\AgentReady\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    public function testRegistryExposesFourCanonicalSkills(): void
    {
        $registry = new SkillRegistry();
        $ids = array_keys($registry->all());

        self::assertSame(
            ['search-products', 'get-product', 'manage-cart', 'place-order'],
            $ids
        );
    }

    public function testMcpToolListShape(): void
    {
        $tools = (new SkillRegistry())->asMcpToolList();

        self::assertNotEmpty($tools);
        foreach ($tools as $tool) {
            self::assertArrayHasKey('name', $tool);
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function testA2aSkillListShape(): void
    {
        $skills = (new SkillRegistry())->asA2aSkillList();

        foreach ($skills as $skill) {
            self::assertArrayHasKey('id', $skill);
            self::assertArrayHasKey('name', $skill);
            self::assertArrayHasKey('description', $skill);
            self::assertArrayHasKey('tags', $skill);
            self::assertNotEmpty($skill['tags']);
        }
    }

    public function testSearchProductsDispatchProducesStoreApiEnvelope(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        self::assertNotNull($skill);

        $envelope = $skill->call(['query' => 'shoes', 'limit' => 10]);

        self::assertSame('http-request', $envelope['kind']);
        self::assertSame('POST', $envelope['method']);
        self::assertSame('/store-api/search', $envelope['path']);
        self::assertSame(['search' => 'shoes', 'limit' => 10], $envelope['body']);
        self::assertArrayHasKey('sw-access-key', $envelope['headers']);
    }

    public function testSearchProductsRejectsMissingQuery(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        self::assertNotNull($skill);

        $this->expectException(SkillInputException::class);
        $skill->call([]);
    }

    public function testSearchProductsRejectsLimitOverMaximum(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        self::assertNotNull($skill);

        $this->expectException(SkillInputException::class);
        $skill->call(['query' => 'x', 'limit' => 999]);
    }

    public function testGetProductRejectsInvalidUuid(): void
    {
        $skill = (new SkillRegistry())->get('get-product');
        self::assertNotNull($skill);

        $this->expectException(SkillInputException::class);
        $skill->call(['productId' => 'not-a-uuid']);
    }

    public function testGetProductAcceptsValidUuid(): void
    {
        $skill = (new SkillRegistry())->get('get-product');
        self::assertNotNull($skill);

        $envelope = $skill->call(['productId' => str_repeat('a', 32)]);
        self::assertSame('/store-api/product/' . str_repeat('a', 32), $envelope['path']);
    }

    public function testManageCartDispatchSwitchesMethodAndBodyShape(): void
    {
        $skill = (new SkillRegistry())->get('manage-cart');
        self::assertNotNull($skill);

        $add = $skill->call([
            'action' => 'add',
            'productId' => str_repeat('b', 32),
            'quantity' => 2,
        ]);
        self::assertSame('POST', $add['method']);
        self::assertSame('/store-api/checkout/cart/line-item', $add['path']);
        self::assertSame(2, $add['body']['items'][0]['quantity']);

        $remove = $skill->call([
            'action' => 'remove',
            'productId' => str_repeat('b', 32),
        ]);
        self::assertSame('DELETE', $remove['method']);
        self::assertSame([str_repeat('b', 32)], $remove['body']['ids']);
    }

    public function testManageCartRejectsInvalidAction(): void
    {
        $skill = (new SkillRegistry())->get('manage-cart');
        self::assertNotNull($skill);

        $this->expectException(SkillInputException::class);
        $skill->call(['action' => 'destroy', 'productId' => str_repeat('c', 32)]);
    }

    public function testAdditionalPropertiesAreRejected(): void
    {
        $skill = (new SkillRegistry())->get('search-products');
        self::assertNotNull($skill);

        $this->expectException(SkillInputException::class);
        $skill->call(['query' => 'ok', 'evil' => true]);
    }

    public function testSkillBodiesContainHowToExecuteSection(): void
    {
        foreach ((new SkillRegistry())->all() as $skill) {
            self::assertStringContainsString(
                '## How to execute',
                $skill->body,
                'skill ' . $skill->id . ' must document how to execute itself'
            );
        }
    }
}
