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
