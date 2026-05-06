<?php declare(strict_types=1);

namespace Coding9\AgentReady\Skill;

/**
 * Single source of truth for the agent-callable skills exposed by this plugin.
 *
 * The same registry feeds:
 *   1. /.well-known/agent-skills/index.json      (Cloudflare agent-skills RFC)
 *   2. /.well-known/agent-card.json              (A2A specification)
 *   3. /mcp                                      (Model Context Protocol)
 *   4. /a2a                                      (A2A JSON-RPC runtime)
 *
 * Execution is delegated to {@see SkillExecutor} — skills here are pure
 * metadata.
 */
class SkillRegistry
{
    public const ID_SEARCH_PRODUCTS = 'search-products';
    public const ID_GET_PRODUCT = 'get-product';
    public const ID_CREATE_CONTEXT = 'create-context';
    public const ID_GET_CART = 'get-cart';
    public const ID_MANAGE_CART = 'manage-cart';

    private const UUID_PATTERN = '^[a-f0-9]{32}$|^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$';

    /** @var array<string, Skill> */
    private array $skills;

    public function __construct()
    {
        $this->skills = self::buildDefaults();
    }

    /** @return array<string, Skill> */
    public function all(): array
    {
        return $this->skills;
    }

    public function get(string $id): ?Skill
    {
        return $this->skills[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->skills[$id]);
    }

    /** @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    public function asMcpToolList(): array
    {
        $out = [];
        foreach ($this->skills as $skill) {
            $out[] = [
                'name' => $skill->id,
                'description' => $skill->description,
                'inputSchema' => $skill->inputSchema,
            ];
        }
        return $out;
    }

    /** @return array<int, array{id: string, name: string, description: string, tags: array<int, string>}> */
    public function asA2aSkillList(): array
    {
        $out = [];
        foreach ($this->skills as $skill) {
            $out[] = [
                'id' => $skill->id,
                'name' => $skill->name,
                'description' => $skill->description,
                'tags' => $skill->tags,
            ];
        }
        return $out;
    }

    /** @return array<string, Skill> */
    private static function buildDefaults(): array
    {
        $skills = [
            new Skill(
                id: self::ID_SEARCH_PRODUCTS,
                name: 'Search products',
                description: 'Search the storefront product catalog by free-text keyword. Returns matching products with name, price, availability and SEO URL.',
                tags: ['search', 'catalog', 'products'],
                inputSchema: [
                    'type' => 'object',
                    'required' => ['query'],
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Free-text search term.',
                            'minLength' => 1,
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results (1..100, default 24).',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 24,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Search products

                Search the storefront catalog by keyword.

                ## Input

                ```json
                {"query": "string (required)", "limit": "integer (1..100, default 24)"}
                ```

                ## Output

                ```json
                {
                  "total": 42,
                  "products": [
                    {
                      "id": "...",
                      "name": "...",
                      "productNumber": "...",
                      "price": {"amount": 99.95, "currency": "EUR"},
                      "available": true,
                      "url": "https://shop.example/p/...",
                      "image": "https://shop.example/media/..."
                    }
                  ]
                }
                ```

                MD,
            ),
            new Skill(
                id: self::ID_GET_PRODUCT,
                name: 'Get product',
                description: 'Fetch detailed information for one product by its UUID.',
                tags: ['catalog', 'products'],
                inputSchema: [
                    'type' => 'object',
                    'required' => ['productId'],
                    'properties' => [
                        'productId' => [
                            'type' => 'string',
                            'description' => 'Shopware product UUID (32-char hex or 36-char dashed).',
                            'pattern' => self::UUID_PATTERN,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Get product

                Retrieve full product detail.

                ## Input

                ```json
                {"productId": "32-char hex or 36-char dashed Shopware UUID"}
                ```

                ## Output

                ```json
                {
                  "id": "...",
                  "name": "...",
                  "productNumber": "...",
                  "description": "...",
                  "price": {"amount": 99.95, "currency": "EUR"},
                  "stock": 42,
                  "available": true,
                  "url": "...",
                  "images": ["..."]
                }
                ```

                MD,
            ),
            new Skill(
                id: self::ID_CREATE_CONTEXT,
                name: 'Create cart session',
                description: 'Create a fresh anonymous Shopware sales-channel context. Returns the contextToken needed by get-cart and manage-cart.',
                tags: ['cart', 'session'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Create cart session

                Mint a fresh anonymous Shopware context. Pass the returned
                `contextToken` to all subsequent cart operations to keep them
                tied to the same cart.

                ## Output

                ```json
                {"contextToken": "..."}
                ```

                MD,
            ),
            new Skill(
                id: self::ID_GET_CART,
                name: 'Get cart',
                description: 'Return the current cart for the supplied contextToken.',
                tags: ['cart'],
                inputSchema: [
                    'type' => 'object',
                    'required' => ['contextToken'],
                    'properties' => [
                        'contextToken' => [
                            'type' => 'string',
                            'description' => 'Token returned by create-context (or sw-context-token header).',
                            'minLength' => 4,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Get cart

                Read the cart owned by the given context token.

                ## Output

                ```json
                {
                  "lineItems": [{"productId": "...", "label": "...", "quantity": 1, "price": 99.95}],
                  "price": {"netPrice": 84.0, "totalPrice": 99.95, "positionPrice": 99.95},
                  "currency": "EUR"
                }
                ```

                MD,
            ),
            new Skill(
                id: self::ID_MANAGE_CART,
                name: 'Manage cart',
                description: 'Add, update or remove a line item in the cart owned by contextToken. Returns the updated cart.',
                tags: ['cart', 'checkout'],
                inputSchema: [
                    'type' => 'object',
                    'required' => ['action', 'contextToken'],
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['add', 'update', 'remove'],
                        ],
                        'contextToken' => [
                            'type' => 'string',
                            'minLength' => 4,
                        ],
                        'productId' => [
                            'type' => 'string',
                            'description' => 'Required for add/update.',
                            'pattern' => self::UUID_PATTERN,
                        ],
                        'lineItemId' => [
                            'type' => 'string',
                            'description' => 'Required for remove (line item identifier from get-cart).',
                            'pattern' => self::UUID_PATTERN,
                        ],
                        'quantity' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 999,
                            'default' => 1,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Manage cart

                Mutate the cart for an existing context token.

                ## Input

                ```json
                {
                  "action": "add | update | remove",
                  "contextToken": "...",
                  "productId": "(add/update only) Shopware UUID",
                  "lineItemId": "(remove only) line item id from get-cart",
                  "quantity": "1..999 (add/update only)"
                }
                ```

                ## Output

                Same shape as get-cart — the updated cart.

                MD,
            ),
        ];

        $byId = [];
        foreach ($skills as $skill) {
            $byId[$skill->id] = $skill;
        }
        return $byId;
    }
}
