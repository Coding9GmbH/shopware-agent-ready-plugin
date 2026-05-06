# shopware-agent-ready

Make your Shopware 6.7 store ready for AI agents.

This open-source plugin implements every recommendation from
[Cloudflare's "Is It Agent Ready?"](https://blog.cloudflare.com/agent-readiness/)
checklist for a Shopware storefront. Drop it in, click "Activate", and your
shop starts speaking the protocols agents already understand.

> ⚠️ **Disclaimer — showcase project**
>
> This plugin was built as a **demonstration / showcase** of what an
> agent-ready Shopware 6 store can look like. It is provided **as-is, without
> warranty of any kind**, and there is **no commercial support and no SLA**.
> **Use at your own risk.** Review the code, test on a staging environment,
> and decide for yourself whether to ship it to production.
>
> *Dieses Plugin ist ein Showcase-Projekt. Nutzung auf eigene Gefahr — keine
> Gewährleistung, kein Support, keine Garantie.*

| Capability | Standard / Spec | Endpoint / Mechanism |
| --- | --- | --- |
| Link response headers | [RFC 8288](https://www.rfc-editor.org/rfc/rfc8288) | `Link:` header on `/` |
| Markdown for Agents | [Cloudflare](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/) | `Accept: text/markdown` content negotiation |
| Content Signals | [contentsignals.org](https://contentsignals.org/) | `robots.txt` |
| API catalog | [RFC 9727](https://www.rfc-editor.org/rfc/rfc9727) | `/.well-known/api-catalog` |
| OAuth 2.0 discovery | [RFC 8414](https://www.rfc-editor.org/rfc/rfc8414) | `/.well-known/oauth-authorization-server` |
| OpenID Connect discovery | [OIDC](https://openid.net/specs/openid-connect-discovery-1_0.html) | `/.well-known/openid-configuration` |
| Protected resource metadata | [RFC 9728](https://www.rfc-editor.org/rfc/rfc9728) | `/.well-known/oauth-protected-resource` |
| MCP Server Card | [SEP-1649](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2127) | `/.well-known/mcp/server-card.json` |
| **MCP server runtime** | [modelcontextprotocol.io](https://modelcontextprotocol.io/) | `POST /mcp` (JSON-RPC: `initialize`, `tools/list`, `tools/call`) |
| A2A Agent Card | [a2a-protocol.org](https://a2a-protocol.org/latest/specification/) | `/.well-known/agent-card.json` |
| **A2A server runtime** | [a2a-protocol.org](https://a2a-protocol.org/latest/specification/) | `POST /a2a` (JSON-RPC: `message/send`) |
| Agent Skills index | [agent-skills-discovery-rfc](https://github.com/cloudflare/agent-skills-discovery-rfc) | `/.well-known/agent-skills/index.json` |
| WebMCP | [webmachinelearning/webmcp](https://webmachinelearning.github.io/webmcp/) | `navigator.modelContext.provideContext()` |
| llms.txt | [llmstxt.org](https://llmstxt.org/) | `/llms.txt`, `/llms-full.txt` |

## Requirements

- Shopware **6.7** or newer
- PHP **8.2** or newer

## Installation

### Via the pre-built zip (easiest)

Grab **`Coding9AgentReady-<version>.zip`** from the
[GitHub Releases page](https://github.com/coding9gmbh/shopware-agent-ready/releases/latest)
and upload it via *Extensions → My extensions → Upload extension*.
Each release is built by the [tag-driven pipeline](.github/workflows/release.yml).

> ⚠️ Use the release **asset** named `Coding9AgentReady-<version>.zip`, not the
> auto-generated *"Source code (zip)"* link. The Shopware Plugin Manager
> rejects source archives because (a) their inner directory is `shopware-agent-ready`
> instead of `Coding9AgentReady`, and (b) when downloaded on macOS they get
> `__MACOSX/._*` resource-fork files that the Plugin Manager refuses for
> security reasons. The release asset is a clean Shopware-Store-compliant
> bundle without those.

### Via composer

```bash
composer require coding9/shopware-agent-ready
bin/console plugin:refresh
bin/console plugin:install --activate Coding9AgentReady
bin/console cache:clear
```

### From source

```bash
cd custom/plugins
git clone https://github.com/coding9gmbh/shopware-agent-ready.git Coding9AgentReady
cd ../..
bin/console plugin:refresh
bin/console plugin:install --activate Coding9AgentReady
bin/console cache:clear
```

### Build a release zip locally

```bash
make install   # composer install
make release   # test + phpstan + shopware-cli validate + zip
ls .build/     # Coding9AgentReady-<version>.zip
```

## Configuration

Open the Shopware admin → *Extensions → My extensions → Agent Ready → Configure*.
Every feature can be toggled individually per sales channel:

- **Link headers (RFC 8288)** — toggle, configure `service-doc` path
- **Markdown for Agents** — toggle content negotiation
- **Content Signals (robots.txt)** — toggle and choose `ai-train`, `search`,
  `ai-input` values
- **Well-Known endpoints** — toggle each endpoint, set MCP transport URL,
  customize A2A agent name and description
- **WebMCP** — toggle `navigator.modelContext.provideContext()` injection

> If your hoster ships a static `public/robots.txt`, remove it (or merge it
> with the dynamic version) so the plugin's Content-Signal directives can be
> served.

## How it works

### Link headers

`LinkHeaderSubscriber` listens to `kernel.response` and only fires on the
storefront homepage (`frontend.home.page`). It emits one `Link:` header value
per enabled relation. Sample output:

```http
Link: </.well-known/api-catalog>; rel="api-catalog"
Link: </docs/api>; rel="service-doc"
Link: </.well-known/mcp/server-card.json>; rel="mcp-server-card"
Link: </.well-known/agent-card.json>; rel="a2a-agent-card"
Link: </.well-known/agent-skills/index.json>; rel="agent-skills"
Link: </.well-known/oauth-authorization-server>; rel="oauth-authorization-server"
Link: </.well-known/oauth-protected-resource>; rel="oauth-protected-resource"
```

### Markdown for Agents

`MarkdownNegotiationSubscriber` inspects the `Accept` header. When
`text/markdown` is requested with a q-value at least equal to `text/html`,
the HTML body is converted to GitHub-flavoured Markdown:

```http
Content-Type: text/markdown; charset=UTF-8
x-markdown-tokens: 184
Vary: Accept
```

Browsers (which prefer `text/html`) get the original HTML untouched. The
conversion is performed by the dependency-free `HtmlToMarkdownConverter`,
which also strips noise (`<script>`, `<nav>`, `<footer>`, cookie banners…).

#### Structured product header on PDPs

On `frontend.detail.*` routes the subscriber additionally extracts the
Schema.org `Product` and `BreadcrumbList` JSON-LD blocks Shopware emits
out of the box and prepends a compact buying-decision summary:

```markdown
# Vintage Sneaker

> Shoes › Sneakers

| Field | Value |
| --- | --- |
| SKU | SW-001 |
| Brand | Coding9 |
| Price | 99.95 EUR |
| Availability | in stock |
| URL | https://shop.example/sneaker |

![Vintage Sneaker](https://shop.example/media/sneaker.jpg)

## Description

A very cool sneaker.
```

Token-budgeted assistants can stop at the table; the generic body still
follows for agents that want the full marketing copy.

### `robots.txt`

`RobotsTxtController` serves a robots file with Content-Signal directives:

```text
# Content-Signal directives (https://contentsignals.org/)
Content-Signal: ai-train=no, search=yes, ai-input=no

User-agent: *
Disallow: /account/
Disallow: /checkout/
Disallow: /widgets/
Allow: /

Sitemap: /sitemap.xml
```

### `/.well-known/*`

`WellKnownController` exposes:

- `api-catalog` (RFC 9727, `application/linkset+json`) — points at both
  `/store-api` and `/api`
- `oauth-authorization-server` & `openid-configuration` — Shopware's
  password / client_credentials / refresh_token grants
- `oauth-protected-resource` — `/api` resource metadata
- `mcp/server-card.json` — MCP transport + `serverInfo`
- `agent-card.json` — A2A agent description with skills
- `agent-skills/index.json` — index pointing at `/SKILL.md` documents whose
  SHA-256 digests are computed at response time, so they always match

### MCP server runtime (`POST /mcp`)

The plugin hosts a real Model Context Protocol JSON-RPC 2.0 endpoint that
agent hosts (Claude Desktop, Cursor, OpenAI Agents SDK, …) can plug in to
directly. It implements:

  - `initialize` — `serverInfo`, protocolVersion, `capabilities.tools`
  - `tools/list` — eight tools described below
  - `tools/call` — validates arguments and runs the matching skill against
    Shopware's Store-API in-process, returning the trimmed result
  - `ping`, `notifications/*` — accepted, no-op

| Tool | Purpose | Output |
| --- | --- | --- |
| `search-products` | search the catalog by keyword | `{total, products: [{id, name, price, available, url, image}]}` |
| `get-product` | full product detail | `{id, name, description, price, stock, available, url, image}` |
| `create-context` | mint a fresh anonymous cart session | `{contextToken}` |
| `get-cart` | read the cart owned by `contextToken` | `{lineItems, price, currency}` |
| `manage-cart` | `add` / `update` / `remove` line items | updated cart |
| `customer-login` | authenticate the cart session as an existing customer | `{contextToken, loggedIn}` |
| `customer-logout` | drop customer auth, invalidate the token | `{loggedOut}` |
| `place-order` | convert the (authenticated) cart into an order | `{orderId, orderNumber, amountTotal, stateMachineState, deepLinkCode}` |

#### End-to-end purchase flow an agent can run

```
1. create-context        → contextToken
2. search-products       → product UUIDs
3. manage-cart (add)     → updated cart
4. customer-login        → contextToken (authenticated)
5. place-order           → orderNumber
6. (out-of-skill) POST /store-api/handle-payment to drive the configured payment handler
```

Skill execution is in-process: the plugin issues a Symfony `SUB_REQUEST`
through the kernel against `/store-api/...`, so all standard Shopware
middleware (sales-channel resolution, cart hydration, customer
authentication, rate limiting…) applies. The `sw-access-key` is resolved
automatically from the sales channel that handled the inbound `/mcp`
request.

> ⚠️ **Security note on `customer-login`**
>
> Passwords flow through the agent host. Only point trusted MCP/A2A
> clients at this endpoint, and prefer dedicated "agent buyer" customer
> accounts (with order limits configured in the Shopware admin) over a
> human customer's primary credentials.

#### Hardening knobs (Plugin admin → *Hardening* + *Skill toggles*)

The plugin ships safe defaults for development and exposes three knobs
operators can tighten before going live:

  * **Per-skill toggles** — every skill (`search-products`, `get-product`,
    `create-context`, `get-cart`, `manage-cart`, `customer-login`,
    `customer-logout`, `place-order`) can be individually disabled. A
    disabled skill is hidden from `tools/list`, the agent-card.json and
    the agent-skills index, and `tools/call` returns method-not-found
    instead of executing it. Read-only catalog deployments can switch
    everything except `search-products` + `get-product` off.
  * **`placeOrderMaxAmount`** — server-side cap, enforced in
    `SkillExecutor`. Before placing an order the executor fetches
    `/store-api/checkout/cart`, compares `price.totalPrice` to the cap,
    and refuses with `403 order_amount_exceeds_limit` if exceeded. Use
    this even when you trust the customer account — it's the cheapest
    safety net for an out-of-control agent.
  * **`corsAllowedOrigins`** — the `/mcp` and `/a2a` endpoints emit
    `Access-Control-Allow-Origin` only when the request's `Origin` is on
    this allowlist. **The default is empty**, so no browser tab can
    cross-origin POST credentials to your shop. Server-to-server agent
    hosts (Claude Desktop, Cursor, OpenAI Agents SDK) don't send
    `Origin` and don't need CORS. Add `https://claude.ai` or similar
    explicitly if you do want browser hosts; use `*` only for local
    development.

### A2A server runtime (`POST /a2a`)

JSON-RPC `message/send` shares the same `SkillExecutor` as the MCP server,
so both protocols expose identical capabilities. The agent submits a
`data` part with `{skill, arguments}`; the response is an `agent` Message
whose part carries the trimmed Store-API result.

### WebMCP

The plugin extends `@Storefront/storefront/base.html.twig` and injects a
`navigator.modelContext.provideContext()` call with four storefront actions:
`search_products`, `open_cart`, `open_account`, `open_checkout`. The block
renders only when WebMCP is enabled, no-ops on browsers without
`navigator.modelContext`.

## Testing

```bash
composer install
composer test         # PHPUnit
composer phpstan      # static analysis (optional)
```

The unit tests are decoupled from Shopware via the `ConfigReader` interface
(see `src/Service/ConfigReader.php`), so they run in milliseconds without
booting the framework.

```text
tests/
├── Controller/
│   ├── RobotsTxtControllerTest.php
│   └── WellKnownControllerTest.php
├── Service/
│   ├── AgentConfigTest.php
│   └── HtmlToMarkdownConverterTest.php
├── Subscriber/
│   ├── LinkHeaderSubscriberTest.php
│   └── MarkdownNegotiationSubscriberTest.php
└── Support/
    └── ArrayConfigReader.php
```

## Verifying against isitagentready.com

After activation, run a quick check:

```bash
curl -sI https://your-shop.example/ | grep -i '^link:'
curl -s -H 'Accept: text/markdown' https://your-shop.example/ | head
curl -s https://your-shop.example/robots.txt
curl -s https://your-shop.example/.well-known/api-catalog | jq
curl -s https://your-shop.example/.well-known/agent-card.json | jq
curl -s https://your-shop.example/.well-known/mcp/server-card.json | jq
curl -s https://your-shop.example/.well-known/agent-skills/index.json | jq

# Hit the MCP server end-to-end:
curl -s https://your-shop.example/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq

curl -s https://your-shop.example/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search-products","arguments":{"query":"shoes"}}}' | jq

# A2A:
curl -s https://your-shop.example/a2a \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"message/send","params":{"message":{"parts":[{"kind":"data","data":{"skill":"search-products","arguments":{"query":"shoes"}}}]}}}' | jq
```

Then submit your domain at <https://isitagentready.com> — every check should
turn green.

## License

MIT — see [LICENSE](LICENSE).

## Releasing

The release pipeline is open-source and tag-driven. Push a semver tag and
[`.github/workflows/release.yml`](.github/workflows/release.yml) does the rest:

```bash
# bump composer.json version, edit CHANGELOG_*.md, then:
git tag -a v0.0.1 -m "v0.0.1"
git push origin v0.0.1
```

The pipeline:

1. checks out the tagged commit,
2. verifies the tag matches `composer.json` `version`,
3. runs `composer install`, `phpunit`, `phpstan`,
4. runs `shopware-cli extension validate`,
5. builds `.build/Coding9AgentReady-<version>.zip` (vendor-free, ~28 KB),
6. extracts release notes from `CHANGELOG_en-GB.md`,
7. publishes a public GitHub Release with the zip attached and links back to
   the README, CHANGELOG and the Cloudflare post that started it all.

## Credits

- Inspired by
  [Cloudflare's agent readiness post](https://blog.cloudflare.com/agent-readiness/).
- Special thanks to **Martin Weinmayer ([dasistweb](https://www.dasistweb.de/))**
  for the inspiration to make Shopware shops first-class citizens of the
  agentic web. Danke Martin!
- Built with the help of the
  [`Coding9GmbH/shopware-agentic-team`](https://github.com/Coding9GmbH/shopware-agentic-team)
  Claude Code skill pack — `shopware-plugin-dev`, `shopware-reviewer` and
  `shopware-store-publisher` shaped large parts of the conventions used here.
- Open-sourced and maintained by [coding9 GmbH](https://coding9.de).

Pull requests welcome.
