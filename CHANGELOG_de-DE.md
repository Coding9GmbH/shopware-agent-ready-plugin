# 0.0.2

- `/llms.txt` und `/llms-full.txt` ([llmstxt.org](https://llmstxt.org/))
  mit automatisch generiertem Discovery-Index, der auf alle aktivierten
  /.well-known/* Endpunkte, Sitemap und robots.txt verweist.
- Neue Ressource per `Link: </llms.txt>; rel="llms-txt"` Header beworben.
- Admin-Config: Aktivieren/Deaktivieren + eigener Shop-Name / Kurzbeschreibung.

# 0.0.1

- Erstveröffentlichung.
- RFC 8288 Link Response-Header auf der Storefront-Startseite.
- Markdown für Agenten: Content Negotiation mit `Accept: text/markdown` und
  einem dependency-freien HTML-zu-Markdown-Konverter.
- robots.txt mit Content-Signal Direktiven (ai-train, search, ai-input).
- /.well-known/* Discovery-Endpunkte: api-catalog (RFC 9727),
  oauth-authorization-server (RFC 8414), openid-configuration,
  oauth-protected-resource (RFC 9728), mcp/server-card.json (SEP-1649),
  agent-card.json (A2A), agent-skills/index.json mit on-demand SKILL.md.
- WebMCP: navigator.modelContext.provideContext() Einbindung im Storefront
  mit vier Shop-Tools.
- Toggles pro Feature in der Admin-Konfiguration.
- DE + EN Snippets und Store-Beschreibungen.
