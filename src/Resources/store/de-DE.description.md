# Macht Ihren Shopware 6 Shop bereit für AI-Agenten

KI-Agenten sind die neuen Browser. Dieses quelloffene Plugin setzt sämtliche
Empfehlungen aus
[Cloudflare's "Is It Agent Ready?"](https://blog.cloudflare.com/agent-readiness/)
in einem Shopware 6.7 Plugin um. Installieren, aktivieren — fertig.

## Highlights

- **Link Response-Header** (RFC 8288) auf der Startseite — `api-catalog`,
  `service-doc`, `mcp-server-card`, `a2a-agent-card`, `agent-skills`,
  `oauth-authorization-server`, `oauth-protected-resource`.
- **Markdown für Agenten** — sobald ein Client `Accept: text/markdown` schickt,
  wird die HTML-Antwort on-the-fly zu GitHub-flavoured Markdown konvertiert
  (`Content-Type: text/markdown`, `x-markdown-tokens`, korrektes `Vary: Accept`).
- **Content Signals in `robots.txt`** — Ihre Präferenzen für KI-Training,
  Suche und KI-Input deklarieren (`ai-train`, `search`, `ai-input`),
  konfigurierbar pro Sales Channel.
- **/.well-known/\*** Discovery-Endpunkte:
  - `api-catalog` (RFC 9727 linkset+json)
  - `oauth-authorization-server` & `openid-configuration` (RFC 8414 / OIDC)
  - `oauth-protected-resource` (RFC 9728)
  - `mcp/server-card.json` (MCP SEP-1649)
  - `agent-card.json` (A2A)
  - `agent-skills/index.json` mit on-demand `SKILL.md` Inhalten
- **WebMCP** — `navigator.modelContext.provideContext()` wird im Storefront
  eingebunden, mit vier Shop-Tools: `search_products`, `open_cart`,
  `open_account`, `open_checkout`.

## Warum

Agenten lesen Ihren Shop (noch) nicht wie ein Mensch. Sie suchen nach
`Link:`-Headern, well-known JSON-Dateien, OAuth-Metadaten und Markdown, das
sie in einen Kontext kopieren können. Das Plugin sorgt dafür, dass Agenten
fündig werden — ohne dass Sie ein einziges Template anfassen müssen.

## Konfiguration

Jedes Feature kann pro Sales Channel umgeschaltet werden, unter
*Erweiterungen → Meine Erweiterungen → Agent Ready → Konfigurieren*.
Sie entscheiden, ob Agenten auf Ihren Inhalten trainieren dürfen, ob Ihr Shop
Markdown ausliefert und was Ihre A2A-Agent-Card kommuniziert.

## Anforderungen

- Shopware **6.7** oder höher
- PHP **8.2** oder höher

## Support & Quellcode

- Issues / PRs / Doku: <https://github.com/coding9gmbh/shopware-agent-ready>
- MIT-Lizenz, vollständig open-source
