# shopware-agent-ready

Make your Shopware 6.7 store ready for AI agents.

This open-source plugin implements every recommendation from
[Cloudflare's "Is It Agent Ready?"](https://blog.cloudflare.com/agent-readiness/)
checklist for a Shopware storefront. Drop it in, click "Activate", and your
shop starts speaking the protocols agents already understand.

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
| A2A Agent Card | [a2a-protocol.org](https://a2a-protocol.org/latest/specification/) | `/.well-known/agent-card.json` |
| Agent Skills index | [agent-skills-discovery-rfc](https://github.com/cloudflare/agent-skills-discovery-rfc) | `/.well-known/agent-skills/index.json` |
| WebMCP | [webmachinelearning/webmcp](https://webmachinelearning.github.io/webmcp/) | `navigator.modelContext.provideContext()` |

## Requirements

- Shopware **6.7** or newer
- PHP **8.2** or newer

## Installation

### Via composer (recommended)

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
```

Then submit your domain at <https://isitagentready.com> — every check should
turn green.

## License

MIT — see [LICENSE](LICENSE).

## Credits

- Inspired by [Cloudflare's agent readiness post](https://blog.cloudflare.com/agent-readiness/).
- Special thanks to **Martin Weinmayer ([dasistweb](https://www.dasistweb.de/))**
  for the inspiration to make Shopware shops first-class citizens of the
  agentic web. Danke Martin!
- Open-sourced and maintained by [coding9 GmbH](https://coding9.com).

Pull requests welcome.
