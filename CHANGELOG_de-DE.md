# 0.0.4

Fix für die CI/Release-Pipeline.

- `symfony/runtime` Composer-Plugin in `allow-plugins` aufgenommen
  (wird transitiv über `shopware/core` gezogen). Ohne diesen Eintrag
  bricht Composer 2.2+ jeden `composer install` mit einer
  `PluginManager`-Exception ab.
- CI-Workflow nutzt jetzt `bash -eo pipefail`, damit
  `composer ... | tee`-Pipelines Fehler nicht mehr verschlucken.

# 0.0.3

Unabhängiges Code-Review — substantielle Korrekturen.

- A2A Agent Card folgt jetzt der a2a-protocol.org Spec: top-level `url`,
  `protocolVersion`, `preferredTransport: "JSONRPC"`, `defaultInputModes` /
  `defaultOutputModes`, Skill-`tags`. Das non-standard `supportedInterfaces`
  Feld wurde entfernt.
- OAuth Metadata (RFC 8414) korrigiert: `authorization_endpoint`,
  `jwks_uri` und `response_types_supported: ['token']` entfernt — Shopware
  hat weder einen interaktiven Authorization-Endpoint noch JWKS noch den
  Implicit-Flow.
- `/.well-known/openid-configuration` entfernt. Shopware ist kein OIDC
  Provider; das Spiegeln der OAuth Metadata unter diesem Pfad war
  irreführend.
- MCP Server Card umgebaut auf das SEP-1649 Discovery-Schema: top-level
  `name` / `version` / `description` / `endpoint` / `protocolVersion` /
  `transport` statt der verschachtelten `serverInfo`+`capabilities`
  Initialize-Response-Form.
- Markdown Content Negotiation ist jetzt auf sichere Storefront-Routen
  beschränkt (Home, Navigation, Produktdetail, Suche, CMS, Landing).
  Account, Checkout, Widgets und AJAX-Fragmente werden nie umgewandelt.
- `Content-Length` und `Content-Encoding` werden nach der Markdown-
  Konvertierung entfernt, um falsch/doppelt encodierte Antworten zu
  verhindern.
- LinkHeaderSubscriber emittiert keine Header mehr auf non-2xx Responses.
- robots.txt: `Sitemap:` ist jetzt eine absolute URL; admin-konfigurierbare
  Content-Signal-Werte werden defensiv von CR/LF gesäubert.
- llms.txt: `siteName` / `siteSummary` werden von CR/LF und führenden `#`
  gesäubert um Markdown-Injection zu verhindern.
- HtmlToMarkdownConverter: Input-Größenbegrenzung (1.5 MB), URL-Schema-
  Allowlist (http/https/mailto/tel — blockiert `javascript:`, `data:`,
  `vbscript:`), Link-Text-Escaping für `]` / `[`, präziseres
  Header/Footer/Nav-Stripping (nur Top-Level-Page-Chrome, semantische
  Article-Header bleiben erhalten).
- Twig WebMCP-Block löst den Toggle jetzt pro Sales Channel auf
  (`config('...', context.salesChannel.id)`).
- Skill-Bodies in eine Class-Konstante hochgezogen; SmokeTest liest den
  Zip-Pfad aus `composer.json` (keine hartkodierte Version mehr).
- Toter `RouteScope` Annotation-Import entfernt (in Shopware 6.5+ entfernt).
- 62 PHPUnit Tests / 328 Assertions, phpstan clean,
  shopware-cli extension validate clean.

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
