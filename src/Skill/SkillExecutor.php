<?php declare(strict_types=1);

namespace Coding9\AgentReady\Skill;

use Coding9\AgentReady\StoreApi\SalesChannelKeyResolver;
use Coding9\AgentReady\StoreApi\StoreApiClient;
use Coding9\AgentReady\StoreApi\StoreApiResponse;

/**
 * Runs the skills declared in {@see SkillRegistry} against Shopware's
 * Store-API via {@see StoreApiClient}. Produces a compact, MCP/A2A-friendly
 * result envelope:
 *
 *   - on success: a `data` object with the trimmed Store-API response
 *   - on failure: a `data` object with `{error, status, detail}`
 *
 * This is the single place that knows how to translate a declarative skill
 * call into one or more Shopware Store-API requests. Keeping it together
 * means the SkillRegistry stays declarative and McpServer / A2aServer share
 * a single execution path.
 */
class SkillExecutor
{
    public function __construct(
        private readonly StoreApiClient $storeApi,
        private readonly SalesChannelKeyResolver $keys,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return SkillResult
     */
    public function execute(Skill $skill, array $args, ?string $salesChannelId): SkillResult
    {
        $args = $skill->validate($args);

        $accessKey = $this->keys->resolveAccessKey($salesChannelId);
        if ($accessKey === null) {
            return SkillResult::error(
                'sales_channel_unavailable',
                'No public access key resolved for the current sales channel. '
                . 'Make sure the request reaches the storefront via a configured sales-channel domain.',
                503,
            );
        }

        return match ($skill->id) {
            SkillRegistry::ID_SEARCH_PRODUCTS => $this->searchProducts($args, $accessKey),
            SkillRegistry::ID_GET_PRODUCT => $this->getProduct($args, $accessKey),
            SkillRegistry::ID_CREATE_CONTEXT => $this->createContext($accessKey),
            SkillRegistry::ID_GET_CART => $this->getCart($args, $accessKey),
            SkillRegistry::ID_MANAGE_CART => $this->manageCart($args, $accessKey),
            SkillRegistry::ID_CUSTOMER_LOGIN => $this->customerLogin($args, $accessKey),
            SkillRegistry::ID_CUSTOMER_LOGOUT => $this->customerLogout($args, $accessKey),
            SkillRegistry::ID_PLACE_ORDER => $this->placeOrder($args, $accessKey),
            default => SkillResult::error('unknown_skill', 'no executor for skill: ' . $skill->id, 501),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function customerLogin(array $args, string $accessKey): SkillResult
    {
        $token = (string) $args['contextToken'];
        $response = $this->storeApi->call(
            'POST',
            '/store-api/account/login',
            [
                'username' => (string) $args['username'],
                'password' => (string) $args['password'],
            ],
            $accessKey,
            $token,
        );

        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        $body = $response->decode() ?? [];
        // Shopware returns the (possibly rotated) token in the response
        // body and/or the sw-context-token header. Prefer the header.
        $newToken = $response->contextToken
            ?? (isset($body['contextToken']) && is_string($body['contextToken']) ? $body['contextToken'] : $token);

        return SkillResult::success([
            'contextToken' => $newToken,
            'loggedIn' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function customerLogout(array $args, string $accessKey): SkillResult
    {
        $response = $this->storeApi->call(
            'POST',
            '/store-api/account/logout',
            [],
            $accessKey,
            (string) $args['contextToken'],
        );

        // Shopware returns 204 on success; treat any 2xx as logged out.
        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        return SkillResult::success(['loggedOut' => true]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function placeOrder(array $args, string $accessKey): SkillResult
    {
        $body = ['tos' => $args['tos'] ?? true];

        $response = $this->storeApi->call(
            'POST',
            '/store-api/checkout/order',
            $body,
            $accessKey,
            (string) $args['contextToken'],
        );

        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        $payload = $response->decode() ?? [];
        $order = isset($payload['order']) && is_array($payload['order']) ? $payload['order'] : $payload;

        return SkillResult::success(array_filter([
            'orderId' => $order['id'] ?? null,
            'orderNumber' => $order['orderNumber'] ?? null,
            'amountTotal' => $order['amountTotal'] ?? $order['price']['totalPrice'] ?? null,
            'currency' => $order['currency']['isoCode'] ?? null,
            'stateMachineState' => $order['stateMachineState']['technicalName']
                ?? (is_string($order['stateMachineState'] ?? null) ? $order['stateMachineState'] : null),
            'deepLinkCode' => $order['deepLinkCode'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function searchProducts(array $args, string $accessKey): SkillResult
    {
        $response = $this->storeApi->call(
            'POST',
            '/store-api/search',
            [
                'search' => (string) $args['query'],
                'limit' => $args['limit'] ?? 24,
            ],
            $accessKey,
            null,
        );

        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        $body = $response->decode() ?? [];
        $products = [];
        foreach (($body['elements'] ?? []) as $element) {
            if (is_array($element)) {
                $products[] = $this->summarizeProduct($element);
            }
        }

        return SkillResult::success([
            'total' => $body['total'] ?? count($products),
            'products' => $products,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getProduct(array $args, string $accessKey): SkillResult
    {
        $id = $this->normalizeUuid((string) $args['productId']);
        $response = $this->storeApi->call(
            'POST',
            '/store-api/product/' . $id,
            [],
            $accessKey,
            null,
        );

        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        $body = $response->decode() ?? [];
        $product = $body['product'] ?? $body;
        if (!is_array($product)) {
            return SkillResult::error('unexpected_response', 'product field missing in store-api response', 502);
        }

        return SkillResult::success($this->detailedProduct($product));
    }

    private function createContext(string $accessKey): SkillResult
    {
        // Reading /store-api/context with no token causes Shopware to mint
        // a fresh anonymous one; the response header carries it.
        $response = $this->storeApi->call('GET', '/store-api/context', [], $accessKey, null);
        $token = $response->contextToken;

        if ($token === null || $token === '') {
            return SkillResult::error(
                'context_token_missing',
                'Store API did not return a sw-context-token header.',
                $response->isSuccess() ? 502 : $response->status,
            );
        }

        return SkillResult::success(['contextToken' => $token]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function getCart(array $args, string $accessKey): SkillResult
    {
        $response = $this->storeApi->call(
            'GET',
            '/store-api/checkout/cart',
            [],
            $accessKey,
            (string) $args['contextToken'],
        );

        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        $body = $response->decode() ?? [];
        return SkillResult::success($this->summarizeCart($body));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function manageCart(array $args, string $accessKey): SkillResult
    {
        $action = (string) $args['action'];
        $token = (string) $args['contextToken'];

        if ($action === 'remove') {
            $lineItemId = $args['lineItemId'] ?? null;
            if (!is_string($lineItemId) || $lineItemId === '') {
                return SkillResult::error('missing_field', "manage-cart action 'remove' requires lineItemId", 400);
            }
            $response = $this->storeApi->call(
                'DELETE',
                '/store-api/checkout/cart/line-item',
                ['ids' => [$lineItemId]],
                $accessKey,
                $token,
            );
        } else {
            $productId = $args['productId'] ?? null;
            if (!is_string($productId) || $productId === '') {
                return SkillResult::error(
                    'missing_field',
                    "manage-cart action '{$action}' requires productId",
                    400,
                );
            }
            $quantity = $args['quantity'] ?? 1;
            $body = [
                'items' => [[
                    'type' => 'product',
                    'referencedId' => $this->normalizeUuid($productId),
                    'quantity' => $quantity,
                ]],
            ];
            $response = $this->storeApi->call(
                $action === 'update' ? 'PATCH' : 'POST',
                '/store-api/checkout/cart/line-item',
                $body,
                $accessKey,
                $token,
            );
        }

        if (!$response->isSuccess()) {
            return $this->errorFromStoreApi($response);
        }

        return SkillResult::success($this->summarizeCart($response->decode() ?? []));
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function summarizeProduct(array $element): array
    {
        $price = $element['calculatedPrice'] ?? null;
        $cheapest = $element['calculatedCheapestPrice'] ?? null;
        $price = is_array($price) ? $price : (is_array($cheapest) ? $cheapest : null);

        return array_filter([
            'id' => $element['id'] ?? null,
            'name' => $element['translated']['name'] ?? $element['name'] ?? null,
            'productNumber' => $element['productNumber'] ?? null,
            'price' => $price !== null ? [
                'amount' => $price['unitPrice'] ?? $price['totalPrice'] ?? null,
                'currency' => $element['currencyId'] ?? null,
            ] : null,
            'available' => (bool) ($element['available'] ?? false),
            'url' => $this->seoUrl($element),
            'image' => $this->coverUrl($element),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function detailedProduct(array $product): array
    {
        $summary = $this->summarizeProduct($product);
        $description = $product['translated']['description'] ?? $product['description'] ?? null;
        if (is_string($description)) {
            $summary['description'] = trim(strip_tags($description));
        }
        $summary['stock'] = $product['stock'] ?? $product['availableStock'] ?? null;
        return array_filter($summary, static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    private function summarizeCart(array $cart): array
    {
        $lineItems = [];
        foreach (($cart['lineItems'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lineItems[] = array_filter([
                'lineItemId' => $item['id'] ?? null,
                'productId' => $item['referencedId'] ?? null,
                'label' => $item['label'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'unitPrice' => $item['price']['unitPrice'] ?? null,
                'totalPrice' => $item['price']['totalPrice'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        return array_filter([
            'contextToken' => $cart['token'] ?? null,
            'lineItems' => $lineItems,
            'price' => isset($cart['price']) && is_array($cart['price']) ? array_filter([
                'netPrice' => $cart['price']['netPrice'] ?? null,
                'totalPrice' => $cart['price']['totalPrice'] ?? null,
                'positionPrice' => $cart['price']['positionPrice'] ?? null,
            ], static fn ($v) => $v !== null) : null,
            'currency' => $cart['price']['taxStatus'] ?? null,
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** @param array<string, mixed> $element */
    private function seoUrl(array $element): ?string
    {
        $seoUrls = $element['seoUrls'] ?? null;
        if (is_array($seoUrls) && isset($seoUrls[0]['pathInfo']) && is_string($seoUrls[0]['pathInfo'])) {
            return $seoUrls[0]['pathInfo'];
        }
        return null;
    }

    /** @param array<string, mixed> $element */
    private function coverUrl(array $element): ?string
    {
        $cover = $element['cover'] ?? null;
        if (is_array($cover) && isset($cover['media']['url']) && is_string($cover['media']['url'])) {
            return $cover['media']['url'];
        }
        return null;
    }

    private function normalizeUuid(string $id): string
    {
        // Shopware accepts UUIDs as 32-char hex internally. If the agent
        // sent a 36-char dashed form, strip dashes; otherwise keep as-is.
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $id) === 1) {
            return str_replace('-', '', $id);
        }
        return $id;
    }

    private function errorFromStoreApi(StoreApiResponse $response): SkillResult
    {
        $body = $response->decode();
        $title = 'store_api_error';
        $detail = $response->body;
        if (is_array($body) && isset($body['errors'][0]) && is_array($body['errors'][0])) {
            $first = $body['errors'][0];
            $title = (string) ($first['title'] ?? $first['code'] ?? $title);
            $detail = (string) ($first['detail'] ?? $first['title'] ?? $detail);
        }
        return SkillResult::error($title, $detail, $response->status);
    }
}
