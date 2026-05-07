# 0.1.3

api-catalog: stop advertising endpoints that 404 on Shopware 6.7.

- The `/api` linkset entry no longer carries `service-doc`. Shopware 6.7
  no longer ships a HTML Swagger UI under `/api/_info/swagger.html`;
  hinting it caused crawlers and validators to follow a 404. The
  machine-readable OpenAPI document under `service-desc` is unchanged.
- The `/store-api` linkset entry no longer carries `status`.
  `/store-api/_info/health-check` does not exist on Shopware; the
  Admin-API `status` link still does.

# 0.1.2

robots.txt and service-doc spec fixes.

- `/.well-known/api-catalog` now advertises a real, fetchable service-doc
  (the OpenAPI document for the Store-API) instead of a placeholder URL.
- The plugin's Content-Signal directives are now also injected into
  Shopware's core `robots.txt` response (not only the plugin-served one),
  so they survive when the storefront serves robots.txt directly.
- Adds a cross-scope sub-request whitelist so the agent runtime can call
  Store-API endpoints under `_routeScope: storefront` without scope
  violations.

# 0.1.0

First production-ready release. The plugin moves from "discovery-only
showcase" to a real plug-and-play MCP/A2A server with a hardened skill
surface for AI-agent commerce on Shopware 6.

**MCP / A2A runtime endpoints**

- `POST /mcp` — JSON-RPC 2.0 server implementing `initialize`,
  `tools/list`, `tools/call`, `ping` and notifications. Supports batched
  requests.
- `POST /a2a` — A2A `message/send` runtime sharing the same skill set.
- Both are **plug-and-play** for Claude Desktop, Cursor and the OpenAI
  Agents SDK: configure the URL and the agent host can immediately
  search products, manage carts, log customers in and place orders.

**Skill set (real Store-API proxy, not dispatch instructions)**

- `search-products`, `get-product` (read-only catalog)
- `create-context`, `get-cart`, `manage-cart` (cart sessions)
- `customer-login`, `customer-logout` (auth)
- `place-order` (commits real orders)

Skill execution runs in-process via Symfony `SUB_REQUEST` against
`/store-api/...`, so all standard Shopware middleware (sales-channel
resolution, cart hydration, rate limiting, customer auth) applies.
The `sw-access-key` is resolved automatically from the resolved sales
channel — operators don't have to wire it through.

**Markdown enrichment for product detail pages**

`MarkdownNegotiationSubscriber` now extracts Schema.org `Product`,
`Offer` and `BreadcrumbList` JSON-LD from PDPs and prepends a compact
buying-decision header (name, breadcrumb, SKU, brand, price,
availability, image, description) to the generic HTML→Markdown body.

**Hardening (new admin cards)**

- *Skill toggles* — every skill can be individually disabled. Read-only
  catalog deployments can keep only `search-products` + `get-product`.
- `placeOrderMaxAmount` — server-side cap enforced in `SkillExecutor`.
  Before placing an order the executor fetches the cart, compares
  `totalPrice` to the cap, and returns `403 order_amount_exceeds_limit`
  if exceeded.
- `corsAllowedOrigins` — `/mcp` and `/a2a` no longer emit
  `Access-Control-Allow-Origin: *` unconditionally. Default is empty
  (no CORS header, safest for server-to-server agent hosts). Operators
  add explicit origins (e.g. `https://claude.ai`) for browser-based
  hosts, or `*` for local development only.

**Removed**

- Dynamic Client Registration stub (`POST /api/oauth/register`). Always
  returned 501; brought no value. `registration_endpoint` removed from
  `oauth-authorization-server` metadata.

**Tests**

- 133 PHPUnit tests, decoupled from Shopware via the `StoreApiClient`
  and `SalesChannelKeyResolver` interfaces — runs in milliseconds.
- PHPStan clean.

# 0.0.5

Spec-compliance fixes for robots.txt and MCP server card.

- robots.txt: `Content-Signal` is now placed inside the `User-agent: *`
  group so it associates with that record per
  draft-romm-aipref-contentsignals. Validators (including Cloudflare's)
  ignore an orphan `Content-Signal` line that precedes any user-agent
  group.
- `/.well-known/mcp/server-card.json`: emit a nested `serverInfo`
  `{name, version}` alongside the existing top-level fields, plus an
  empty `capabilities` object, so SEP-1649 validators that expect either
  the discovery shape or the initialize-response shape are satisfied.

# 0.0.4

CI/release pipeline fix.

- Allow `symfony/runtime` Composer plugin (pulled in transitively by
  `shopware/core`); without it, Composer 2.2+ aborts `composer install`
  with a `PluginManager` exception.
- CI workflow now uses `bash -eo pipefail` so that `composer ... | tee`
  pipelines no longer mask non-zero exit codes from earlier commands.

# 0.0.3

Independent code review — substantial corrections.

- A2A agent card now matches a2a-protocol.org spec: top-level `url`,
  `protocolVersion`, `preferredTransport: "JSONRPC"`,
  `defaultInputModes` / `defaultOutputModes`, skill `tags`. `supportedInterfaces`
  was non-standard and has been removed.
- OAuth metadata (RFC 8414) corrected: dropped `authorization_endpoint`,
  `jwks_uri` and `response_types_supported: ['token']` — Shopware does not
  expose an interactive authorization endpoint, JWKS or implicit flow.
- `/.well-known/openid-configuration` removed. Shopware is not an OIDC
  provider; mirroring the OAuth metadata under that URL was misleading.
- MCP server card rewritten to discovery shape per SEP-1649: top-level
  `name` / `version` / `description` / `endpoint` / `protocolVersion` /
  `transport` instead of the nested `serverInfo`+`capabilities`
  initialize-response shape.
- Markdown content negotiation is now restricted to safe storefront
  routes (homepage, navigation, product detail, search, CMS, landing).
  Account, checkout, widgets and AJAX fragments are never rewritten.
- `Content-Length` and `Content-Encoding` are cleared after markdown
  conversion to avoid mismatched / double-encoded responses.
- LinkHeaderSubscriber no longer emits headers on non-2xx responses.
- robots.txt: `Sitemap:` is now an absolute URL; admin-configurable
  Content-Signal values are CR/LF-stripped defensively.
- llms.txt: `siteName` / `siteSummary` are CR/LF and leading-`#` stripped
  to prevent Markdown injection.
- HtmlToMarkdownConverter: input size cap (1.5 MB), URL scheme allow-list
  (http/https/mailto/tel — blocks `javascript:`, `data:`, `vbscript:`),
  link-text escaping for `]` / `[`, narrower header/footer/nav strip
  (only top-level page chrome, no longer drops semantic article headers).
- Twig WebMCP block now resolves the toggle per sales channel
  (`config('...', context.salesChannel.id)`).
- Skill bodies hoisted to a single class constant; SmokeTest reads the
  zip path from `composer.json` (no more hard-coded version).
- Drops dead `RouteScope` annotation import (removed in Shopware 6.5+).
- 62 PHPUnit tests / 328 assertions, phpstan clean,
  shopware-cli extension validate clean.

# 0.0.2

- Add `/llms.txt` and `/llms-full.txt` ([llmstxt.org](https://llmstxt.org/))
  with auto-generated discovery index pointing at every enabled
  /.well-known/* endpoint, sitemap and robots.txt.
- Advertise the new resource via `Link: </llms.txt>; rel="llms-txt"` header.
- New admin config: enable/disable + custom site name / summary.

# 0.0.1

- Initial release.
- RFC 8288 Link response headers on the storefront homepage.
- Markdown for Agents: `Accept: text/markdown` content negotiation with a
  dependency-free HTML to Markdown converter.
- robots.txt with Content-Signal directives (ai-train, search, ai-input).
- /.well-known/* discovery endpoints: api-catalog (RFC 9727),
  oauth-authorization-server (RFC 8414), openid-configuration,
  oauth-protected-resource (RFC 9728), mcp/server-card.json (SEP-1649),
  agent-card.json (A2A), agent-skills/index.json with on-demand SKILL.md.
- WebMCP: navigator.modelContext.provideContext() injection on storefront
  pages with four storefront tools.
- Per-feature toggles in the admin config UI.
- DE + EN snippets and store descriptions.
