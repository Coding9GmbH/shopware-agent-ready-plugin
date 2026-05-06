# Make your Shopware 6 store agent-ready

AI agents are becoming the new browsers. This open-source extension implements
every recommendation from
[Cloudflare's "Is It Agent Ready?"](https://blog.cloudflare.com/agent-readiness/)
checklist, in a single Shopware 6.7 plugin. Drop it in, click activate, and your
shop starts speaking the protocols agents already understand.

## Highlights

- **Link response headers** (RFC 8288) on the homepage — `api-catalog`,
  `service-doc`, `mcp-server-card`, `a2a-agent-card`, `agent-skills`,
  `oauth-authorization-server`, `oauth-protected-resource`.
- **Markdown for Agents** — when a client sends `Accept: text/markdown`, the
  HTML response is converted to GitHub-flavoured Markdown on the fly, with
  `Content-Type: text/markdown`, `x-markdown-tokens` and proper `Vary: Accept`.
- **Content Signals in `robots.txt`** — declare your AI usage preferences
  (`ai-train`, `search`, `ai-input`) per sales channel.
- **/.well-known/\*** discovery endpoints:
  - `api-catalog` (RFC 9727 linkset+json)
  - `oauth-authorization-server` & `openid-configuration` (RFC 8414 / OIDC)
  - `oauth-protected-resource` (RFC 9728)
  - `mcp/server-card.json` (MCP SEP-1649)
  - `agent-card.json` (A2A)
  - `agent-skills/index.json` with on-demand `SKILL.md` bodies
- **WebMCP** — `navigator.modelContext.provideContext()` is injected on the
  storefront with four shop tools out of the box: `search_products`,
  `open_cart`, `open_account`, `open_checkout`.

## Why

Agents looking for your shop do not (yet) read your product detail page like a
human. They look for `Link:` headers, well-known JSON files, OAuth metadata
and Markdown they can paste into context. This plugin makes sure they find
them — without you touching a single template.

## Configuration

Every feature can be toggled per sales channel in *Extensions → My extensions
→ Agent Ready → Configure*. You decide whether agents may train on your
content, whether your shop offers a Markdown view, and what your A2A agent
card advertises.

## Requirements

- Shopware **6.7** or newer
- PHP **8.2** or newer

## Support & source

- Issues / PRs / docs: <https://github.com/coding9gmbh/shopware-agent-ready>
- Source: MIT-licensed, fully open
