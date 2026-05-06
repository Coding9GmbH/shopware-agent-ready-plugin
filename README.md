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
| Dynamic Client Registration | [RFC 7591](https://www.rfc-editor.org/rfc/rfc7591) | `POST /api/oauth/register` (honest stub) |
| MCP Server Card | [SEP-1649](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2127) | `/.well-known/mcp/server-card.json` |
| **MCP server runtime** | [modelcontextprotocol.io](https://modelcontextprotocol.io/) | `POST /mcp` (JSON-RPC: `initialize`, `tools/list`, `tools/call`) |
| A2A Agent Card | [a2a-protocol.org](https://a2a-protocol.org/latest/specification/) | `/.well-known/agent-card.json` |
| **A2A server runtime** | [a2a-protocol.org](https://a2a-protocol.org/latest/specification/) | `POST /a2a` (JSON-RPC: `message/send`) |
| Agent Skills index | [agent-skills-discovery-rfc](https://github.com/cloudflare/agent-skills-discovery-rfc) | `/.well-known/agent-skills/index.json` |
| Agentic payments | [x402.org](https://www.x402.org/) | `/.well-known/x402` (demo) |
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

The plugin hosts a real Model Context Protocol JSON-RPC 2.0 endpoint. It
implements `initialize`, `tools/list` and `tools/call` for four tools whose
input schemas come from `Coding9\AgentReady\Skill\SkillRegistry`:
`search-products`, `get-product`, `manage-cart`, `place-order`.

`tools/call` returns a structured **dispatch envelope** describing the
Store API request the agent (or its host) should issue:

```json
{
  "kind": "http-request",
  "method": "POST",
  "path": "/store-api/search",
  "headers": {"sw-access-key": "<...>"},
  "body": {"search": "shoes", "limit": 24}
}
```

The plugin deliberately does **not** proxy Store API calls — Shopware
already serves them, and keeping authorization and execution out of the
MCP server keeps the surface small, auditable and decoupled from the
Shopware container.

### A2A server runtime (`POST /a2a`)

JSON-RPC `message/send` re-uses the same `SkillRegistry`, so MCP and A2A
clients see identical capabilities. The agent submits a `data` part with
`{skill, arguments}`; the response is an `agent` Message whose part carries
the dispatch envelope.

### Dynamic Client Registration (`POST /api/oauth/register`)

RFC 7591. Shopware doesn't yet support automated client registration;
integrations are created in the admin. The endpoint returns a structured
`501 not_supported` with a doc link, advertises itself via
`registration_endpoint` in the OAuth metadata, and is the natural extension
point once Shopware ships native DCR.

### Agentic payments (`/.well-known/x402`)

Returns an [x402](https://www.x402.org/)-shaped `402 payment_required`
demo body. Wire a real facilitator (Coinbase x402, Stripe Agent Toolkit,
Visa Intelligent Commerce) into `X402Controller` to settle agentic
payments before delegating to `POST /store-api/handle-payment`.

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

# x402:
curl -s -i https://your-shop.example/.well-known/x402
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
