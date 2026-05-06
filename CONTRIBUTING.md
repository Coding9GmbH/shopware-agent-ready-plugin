# Contributing

Thank you for considering a contribution!

## Local setup

```bash
git clone https://github.com/coding9gmbh/shopware-agent-ready.git
cd shopware-agent-ready
make install
make test
```

## Coding style

- **PHP 8.2+, strict types** in every file.
- Follow the rules baked into [`CLAUDE.md`](CLAUDE.md): DI everywhere, no raw
  SQL, sales-channel context forwarded into every config call, snippets for
  every user-facing string.
- All new behaviour gets a unit test in `tests/`. Tests must run without
  booting Shopware (use the `ConfigReader` abstraction).

## Recommended Claude Code workflow

This repo plays well with the
[`Coding9GmbH/shopware-agentic-team`](https://github.com/Coding9GmbH/shopware-agentic-team)
skill pack. Before you change anything non-trivial:

1. `@shopware-architect` — sketch the change.
2. `@shopware-plugin-dev` — implement.
3. `@shopware-test-engineer` — add tests.
4. `@shopware-reviewer` — review the diff.
5. `@shopware-store-publisher` (or `/sw-store-prep`) — only on releases.

## Commit messages

Conventional Commits. The first line should fit in 72 chars.

```
feat: add WebMCP support
fix(robots): preserve sitemap entry when content signals are disabled
chore(ci): bump phpunit to 11.6
```

## Pull requests

- One concern per PR.
- Keep `make test phpstan` green.
- Update `CHANGELOG_de-DE.md` and `CHANGELOG_en-GB.md` for user-visible
  changes.

## Releasing

Maintainers run `make zip` and attach the generated zip to a GitHub release
tagged with the same `version` that lives in `composer.json`.
