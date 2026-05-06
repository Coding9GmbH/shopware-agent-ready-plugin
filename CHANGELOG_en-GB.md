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
