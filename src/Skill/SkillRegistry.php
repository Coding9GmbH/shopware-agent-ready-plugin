<?php declare(strict_types=1);

namespace Coding9\AgentReady\Skill;

/**
 * Single source of truth for the agent-callable skills exposed by this plugin.
 *
 * The same registry feeds three surfaces:
 *
 *   1. /.well-known/agent-skills/index.json      (Cloudflare agent-skills RFC)
 *   2. /.well-known/agent-card.json              (A2A specification)
 *   3. /mcp                                      (Model Context Protocol)
 *   4. /a2a                                      (A2A JSON-RPC runtime)
 *
 * Every skill carries:
 *  - a stable id
 *  - a human description
 *  - a JSON-Schema input descriptor
 *  - a Markdown body served at /.well-known/agent-skills/<id>/SKILL.md
 *  - a dispatcher closure that converts validated arguments into a
 *    structured "next action" envelope. The envelope tells the calling
 *    agent which Store API request to issue. We deliberately don't proxy
 *    Store API calls inside the plugin — Shopware already exposes those
 *    routes, and an MCP server that dispatches by description keeps the
 *    plugin small, framework-decoupled and easy to audit.
 */
class SkillRegistry
{
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
                id: 'search-products',
                name: 'Search products',
                description: 'Search the product catalog of the Shopware storefront by keyword.',
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
                            'description' => 'Maximum number of results (default 24, max 100).',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 24,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Search products

                Search the Shopware storefront catalog by free-text keyword.

                ## Input

                ```json
                {
                  "query": "string (required, min 1)",
                  "limit": "integer (1..100, default 24)"
                }
                ```

                ## How to execute

                Issue a Store API request:

                ```http
                POST /store-api/search HTTP/1.1
                Host: <shop-host>
                Content-Type: application/json
                sw-access-key: <SALES_CHANNEL_ACCESS_KEY>

                {"search": "{{query}}", "limit": {{limit}}}
                ```

                ## Authentication

                The Store API requires the sales channel `sw-access-key` header.
                The key is found in the Shopware admin under
                **Sales Channels → <name> → API access**.

                ## Output

                Returns a paginated list of products with `id`, `name`,
                `productNumber`, `calculatedPrice`, `seoUrls`. Use
                `seoUrls[0].pathInfo` to deep-link into the storefront.

                ## Errors

                | HTTP | Meaning |
                | --- | --- |
                | 401 | Missing or invalid `sw-access-key` |
                | 400 | Empty / malformed query body |

                MD,
                dispatch: fn (array $args) => [
                    'kind' => 'http-request',
                    'method' => 'POST',
                    'path' => '/store-api/search',
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'sw-access-key' => '<SALES_CHANNEL_ACCESS_KEY>',
                    ],
                    'body' => [
                        'search' => $args['query'],
                        'limit' => $args['limit'] ?? 24,
                    ],
                ],
            ),
            new Skill(
                id: 'get-product',
                name: 'Get product detail',
                description: 'Fetch detailed information for one product by id.',
                tags: ['catalog', 'products'],
                inputSchema: [
                    'type' => 'object',
                    'required' => ['productId'],
                    'properties' => [
                        'productId' => [
                            'type' => 'string',
                            'description' => 'The product UUID returned by search-products.',
                            'pattern' => '^[a-f0-9]{32}$',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Get product detail

                Retrieve the full product record for one product by its UUID.

                ## Input

                ```json
                {"productId": "32-char hex Shopware UUID"}
                ```

                ## How to execute

                ```http
                POST /store-api/product/{{productId}} HTTP/1.1
                Host: <shop-host>
                Content-Type: application/json
                sw-access-key: <SALES_CHANNEL_ACCESS_KEY>

                {}
                ```

                ## Output

                A `product` object with name, description, calculatedPrice,
                stock, media gallery and the canonical SEO URL.
                MD,
                dispatch: fn (array $args) => [
                    'kind' => 'http-request',
                    'method' => 'POST',
                    'path' => '/store-api/product/' . $args['productId'],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'sw-access-key' => '<SALES_CHANNEL_ACCESS_KEY>',
                    ],
                    'body' => new \stdClass(),
                ],
            ),
            new Skill(
                id: 'manage-cart',
                name: 'Manage cart',
                description: 'Add, update or remove line items in the current shopping cart.',
                tags: ['cart', 'checkout'],
                inputSchema: [
                    'type' => 'object',
                    'required' => ['action', 'productId'],
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['add', 'update', 'remove'],
                            'description' => 'Cart operation to perform.',
                        ],
                        'productId' => [
                            'type' => 'string',
                            'description' => '32-char hex Shopware product UUID.',
                            'pattern' => '^[a-f0-9]{32}$',
                        ],
                        'quantity' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'default' => 1,
                            'description' => 'Required for add/update; ignored for remove.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Manage cart

                Add, update or remove line items in the current cart. The
                Shopware cart is keyed by the `sw-context-token` header — keep
                the same token across requests to operate on one cart.

                ## Input

                ```json
                {
                  "action": "add | update | remove",
                  "productId": "32-char hex Shopware UUID",
                  "quantity": "integer >= 1 (add/update only)"
                }
                ```

                ## How to execute

                | Action | Method | Path |
                | --- | --- | --- |
                | add | POST | /store-api/checkout/cart/line-item |
                | update | PATCH | /store-api/checkout/cart/line-item |
                | remove | DELETE | /store-api/checkout/cart/line-item |

                Body shape for add/update:

                ```json
                {"items":[{"type":"product","referencedId":"{{productId}}","quantity":{{quantity}}}]}
                ```

                Body shape for remove:

                ```json
                {"ids":["{{productId}}"]}
                ```

                ## Authentication

                Requires `sw-access-key` and `sw-context-token` headers. If you
                don't have a context token yet, call
                `POST /store-api/context` first; it returns one.

                MD,
                dispatch: function (array $args) {
                    $action = (string) $args['action'];
                    $productId = $args['productId'];
                    $quantity = $args['quantity'] ?? 1;

                    $method = match ($action) {
                        'add' => 'POST',
                        'update' => 'PATCH',
                        'remove' => 'DELETE',
                        default => throw new \LogicException('action validated upstream: ' . $action),
                    };

                    $body = $action === 'remove'
                        ? ['ids' => [$productId]]
                        : ['items' => [[
                            'type' => 'product',
                            'referencedId' => $productId,
                            'quantity' => $quantity,
                        ]]];

                    return [
                        'kind' => 'http-request',
                        'method' => $method,
                        'path' => '/store-api/checkout/cart/line-item',
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'sw-access-key' => '<SALES_CHANNEL_ACCESS_KEY>',
                            'sw-context-token' => '<CONTEXT_TOKEN>',
                        ],
                        'body' => $body,
                    ];
                },
            ),
            new Skill(
                id: 'place-order',
                name: 'Place order',
                description: 'Place an order for the items currently in the cart.',
                tags: ['checkout', 'orders'],
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'tos' => [
                            'type' => 'boolean',
                            'description' => 'Confirms the customer accepts the terms of service.',
                            'default' => true,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                body: <<<'MD'
                # Place order

                Convert the current cart into an order.

                ## Preconditions

                1. A logged-in customer (call `POST /store-api/account/login`
                   to obtain a `sw-context-token`) **or** a guest checkout
                   established via `POST /store-api/account/register?guest=1`.
                2. A cart with at least one line item.
                3. A selected payment method and shipping method on the
                   sales-channel context.

                ## How to execute

                ```http
                POST /store-api/checkout/order HTTP/1.1
                Host: <shop-host>
                Content-Type: application/json
                sw-access-key: <SALES_CHANNEL_ACCESS_KEY>
                sw-context-token: <CONTEXT_TOKEN>

                {"tos": true}
                ```

                ## Output

                Returns the created `order` with `id`, `orderNumber`,
                `amountTotal`, `stateMachineState`, and the customer-facing
                `deepLinkCode`.

                ## Payment

                Once the order is created, follow up with
                `POST /store-api/handle-payment` to drive the configured
                payment handler. Agentic payment flows (x402, Stripe Agent
                Toolkit, Visa Intelligent Commerce) are not yet wired in
                this plugin — see `/.well-known/x402` for the demo skeleton.
                MD,
                dispatch: fn (array $args) => [
                    'kind' => 'http-request',
                    'method' => 'POST',
                    'path' => '/store-api/checkout/order',
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'sw-access-key' => '<SALES_CHANNEL_ACCESS_KEY>',
                        'sw-context-token' => '<CONTEXT_TOKEN>',
                    ],
                    'body' => [
                        'tos' => $args['tos'] ?? true,
                    ],
                ],
            ),
        ];

        $byId = [];
        foreach ($skills as $skill) {
            $byId[$skill->id] = $skill;
        }
        return $byId;
    }
}
