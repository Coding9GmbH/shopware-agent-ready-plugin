# 0.1.3

api-catalog: keine Endpunkte mehr beworben, die unter Shopware 6.7
einen 404 liefern.

- Der `/api`-Linkset-Eintrag enthält kein `service-doc` mehr. Shopware
  6.7 liefert keine HTML-Swagger-UI mehr unter
  `/api/_info/swagger.html`; der Hint führte Crawler und Validatoren
  in einen 404. Das maschinenlesbare OpenAPI-Dokument unter
  `service-desc` bleibt unverändert.
- Der `/store-api`-Linkset-Eintrag enthält kein `status` mehr.
  `/store-api/_info/health-check` existiert in Shopware nicht; der
  `status`-Link für die Admin-API bleibt.

# 0.1.2

Spec-Fixes für robots.txt und Service-Doc.

- `/.well-known/api-catalog` verweist jetzt auf ein echtes, abrufbares
  Service-Doc (das OpenAPI-Dokument der Store-API) statt auf einen
  Platzhalter.
- Die Content-Signal-Direktiven des Plugins werden zusätzlich in die
  von Shopware-Core ausgelieferte `robots.txt` injiziert, damit sie auch
  dann erhalten bleiben, wenn die Storefront robots.txt direkt
  ausliefert.
- Whitelist für cross-scope Sub-Requests, damit die Agent-Runtime
  Store-API-Endpunkte unter `_routeScope: storefront` ohne
  Scope-Verletzung aufrufen kann.

# 0.1.0

Erstes produktionsreifes Release. Das Plugin geht von „Discovery-Showcase"
zu einem plug-and-play-fähigen MCP/A2A-Server mit einer gehärteten
Skill-Surface für KI-Agent-Commerce auf Shopware 6.

**MCP- / A2A-Runtime-Endpunkte**

- `POST /mcp` — JSON-RPC-2.0-Server mit `initialize`, `tools/list`,
  `tools/call`, `ping` und Notifications. Unterstützt Batched Requests.
- `POST /a2a` — A2A-`message/send`-Runtime, teilt das Skill-Set mit MCP.
- Beide sind **plug-and-play** für Claude Desktop, Cursor und das OpenAI
  Agents SDK: URL eintragen, der Agent-Host kann sofort Produkte
  suchen, Warenkörbe verwalten, Kunden einloggen und Bestellungen
  platzieren.

**Skill-Set (echter Store-API-Proxy, keine Dispatch-Anweisungen)**

- `search-products`, `get-product` (Read-only-Katalog)
- `create-context`, `get-cart`, `manage-cart` (Cart-Sessions)
- `customer-login`, `customer-logout` (Auth)
- `place-order` (löst echte Bestellungen aus)

Skill-Ausführung läuft in-process per Symfony-`SUB_REQUEST` gegen
`/store-api/...`. Damit greifen alle Shopware-Standard-Middlewares
(Sales-Channel-Auflösung, Cart-Hydration, Rate-Limiting,
Kunden-Auth). Der `sw-access-key` wird automatisch aus dem aufgelösten
Sales-Channel ermittelt.

**Markdown-Anreicherung für Produktdetailseiten**

Der `MarkdownNegotiationSubscriber` extrahiert jetzt Schema.org
`Product`/`Offer`/`BreadcrumbList` JSON-LD von PDPs und stellt einen
kompakten Kaufentscheidungs-Header (Name, Breadcrumb, SKU, Marke,
Preis, Verfügbarkeit, Bild, Beschreibung) dem generischen HTML→Markdown
voran.

**Härtung (neue Admin-Karten)**

- *Skill-Schalter* — jeder Skill ist einzeln deaktivierbar. Read-only-
  Katalog-Deployments lassen nur `search-products` + `get-product` an.
- `placeOrderMaxAmount` — server-seitiger Bestelllimit-Check im
  `SkillExecutor`. Vor dem Aufruf wird der Cart geladen, `totalPrice`
  geprüft; bei Überschreitung `403 order_amount_exceeds_limit`.
- `corsAllowedOrigins` — `/mcp` und `/a2a` senden den
  `Access-Control-Allow-Origin`-Header nicht mehr per Default als `*`.
  Standard ist leer (kein CORS-Header, sicher für Server-zu-Server-
  Agent-Hosts). Konkrete Origins (z.B. `https://claude.ai`) eintragen
  für Browser-Hosts, `*` nur in der Entwicklung.

**Entfernt**

- DCR-Stub (`POST /api/oauth/register`). Lieferte immer 501, brachte
  keinen Wert. `registration_endpoint` aus
  `oauth-authorization-server`-Metadata entfernt.

**Tests**

- 133 PHPUnit-Tests, von Shopware entkoppelt über die Interfaces
  `StoreApiClient` + `SalesChannelKeyResolver` — läuft in Millisekunden.
- PHPStan clean.

# 0.0.5

Spec-Compliance-Fixes für robots.txt und MCP Server Card.

- robots.txt: `Content-Signal` steht jetzt innerhalb der `User-agent: *`
  Gruppe, damit es gemäß draft-romm-aipref-contentsignals dieser
  Gruppe zugeordnet wird. Validatoren (inkl. Cloudflare) ignorieren
  eine `Content-Signal`-Zeile, die vor jeder User-agent-Gruppe steht.
- `/.well-known/mcp/server-card.json`: liefert jetzt zusätzlich ein
  verschachteltes `serverInfo` `{name, version}` neben den bestehenden
  Top-Level-Feldern sowie ein leeres `capabilities`-Objekt, damit
  SEP-1649-Validatoren sowohl die Discovery-Form als auch die
  Initialize-Response-Form akzeptieren.

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
