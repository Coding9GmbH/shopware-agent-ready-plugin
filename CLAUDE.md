# Repo guide for Claude Code

This is the source for the **Coding9 Agent Ready** Shopware 6.7 plugin —
the open-source counterpart of every Cloudflare *Is It Agent Ready?*
recommendation.

## Layout

```
src/
├── Coding9AgentReady.php          # Plugin entry
├── Controller/
│   ├── RobotsTxtController.php    # /robots.txt with Content-Signal
│   └── WellKnownController.php    # /.well-known/* discovery endpoints
├── Service/
│   ├── AgentConfig.php            # typed config accessor
│   ├── ConfigReader.php           # decouples tests from Shopware
│   ├── HtmlToMarkdownConverter.php
│   └── SystemConfigReader.php
├── Subscriber/
│   ├── LinkHeaderSubscriber.php   # RFC 8288 Link headers on /
│   └── MarkdownNegotiationSubscriber.php
├── Resources/
│   ├── config/{services,routes,config}.xml
│   ├── config/plugin.png          # 40x40 admin icon
│   ├── snippet/{de_DE,en_GB}/     # required snippets
│   ├── store/                     # store submission assets
│   └── views/storefront/base.html.twig  # WebMCP injection
tests/                              # 45 PHPUnit tests
```

## Conventions

- **Strict types** in every PHP file (`declare(strict_types=1);`).
- **DI over container statics** — services are registered in
  `src/Resources/config/services.xml`.
- **Routes carry `_routeScope: storefront`** and follow the
  `frontend.coding9.agent_ready.<feature>` naming convention from the
  Shopware Plugin Engineer playbook.
- **Sales-channel context** is read from
  `Request::attributes->get('sw-sales-channel-id')` and forwarded into
  every config call.
- **No raw SQL.** This plugin has no schema, no migrations.
- **Tests are decoupled from Shopware** via `ConfigReader` so they boot
  in milliseconds and run without a database.

## Local commands

```bash
make install        # composer install
make test           # PHPUnit (45 tests)
make phpstan        # static analysis
make validate       # shopware-cli extension validate
make build          # shopware-cli extension build (compiled assets)
make zip            # produces .build/Coding9AgentReady-<version>.zip
make sandbox        # spins up a throwaway Shopware sandbox via shopware-cli
make smoke          # installs the zip into the sandbox and curls every endpoint
```

## Working with the agentic team

This repo is designed to be opened alongside the
[`Coding9GmbH/shopware-agentic-team`](https://github.com/Coding9GmbH/shopware-agentic-team)
Claude Code skill pack. To enable it locally:

```bash
git clone https://github.com/Coding9GmbH/shopware-agentic-team.git ~/.claude-shopware
ln -s ~/.claude-shopware/.claude .claude
ln -s ~/.claude-shopware/knowledge knowledge
ln -s ~/.claude-shopware/CLAUDE.md AGENTS.md
```

Then in any Claude Code session you can run:

- `@shopware-architect` — plan structural changes before coding
- `@shopware-plugin-dev` — PHP plugin work
- `@shopware-storefront-dev` — Twig/SCSS work in `Resources/views/storefront`
- `@shopware-test-engineer` — write more tests
- `@shopware-reviewer` — independent code review before commit
- `@shopware-store-publisher` — keep `.shopware-extension.yml` and store
  assets aligned, run `/sw-store-prep` before each release
- `/sw-validate`, `/sw-test`, `/sw-build`, `/sw-cache` — slash commands

The plugin already follows the team's hard rules. New contributions should
keep doing so.

## Release checklist

Before tagging a release:

```bash
make test phpstan validate build
```

Then bump `version` in `composer.json`, append to `CHANGELOG_*.md`, run
`make zip`, and upload `.build/Coding9AgentReady-<version>.zip` either to the
Shopware Store or attach it to a GitHub release.
