# Sync notes for reviewer

## utopia-php drift resolved

**Added 6 skills** scaffolded from upstream READMEs and source via `gh api`:

| Skill | Repo | Category |
| --- | --- | --- |
| `utopia-cdn-expert` | `utopia-php/cdn` | `storage-io` |
| `utopia-circuit-breaker-expert` | `utopia-php/circuit-breaker` | `runtime` |
| `utopia-database-proxy-expert` | `utopia-php/database-proxy` | `data` |
| `utopia-lock-expert` | `utopia-php/lock` | `messaging-async` |
| `utopia-swoole-expert` | `utopia-php/swoole` (archived) | `runtime` |
| `utopia-view-expert` | `utopia-php/view` | `utilities` |

Notes on individual scaffolds:

- **`utopia-cdn-expert`** — `main` is currently a stub (README only). The actual `Cache` / `Certificates` API surface (Cloudflare + Fastly adapters, FastlyTls provider) lives on `feat/init-cdn-providers`. The skill is grounded against that branch and flags the `main`-vs-feature gap explicitly.
- **`utopia-swoole-expert`** — repo is archived upstream; superseded by `utopia-php/http`'s first-party Swoole adapter. Skill is scaffolded with a clear "ARCHIVED" header and a migration path so it remains useful for legacy services pinned to `utopia-php/framework: 0.33.*`. After scaffolding, the next `bin/marketplace sync` will (correctly) report it under `utopia.archived` — that is intentional, not a regression.

**Deleted 1 orphan**:

- **`utopia-clickhouse-expert`** — the upstream repo `utopia-php/clickhouse` does not exist (HTTP 404; nothing close in the org either — only `analytics` and `usage` are remotely adjacent). The skill described an API surface with no public source to ground against, which is a hallucination risk on every consult. Removed from `plugins/utopia-experts/skills/` and from `Catalogue::all()` (`misc` category).

`Catalogue::all()` and the two count assertions in `tests/Index/CatalogueTest.php` / `tests/Index/GeneratorTest.php` were updated from `50` to `55`. `bin/marketplace index`, `bin/marketplace validate`, and `vendor/bin/phpunit` all green.

## Untracked upstream — not actioned here

These are listed for visibility only — the **commit-scan workflow** (`.github/workflows/scan-commits.yml`) owns the judgement on whether any of them should become a tracked skill. No scaffolding was done here.

### `appwrite/*` — 11 new or untracked

- `appwrite/integration-for-digitalocean` (20★)
- `appwrite/integration-for-gitpod` (40★)
- `appwrite/mcp-for-api` (66★) — Appwrite's MCP server for backend operations
- `appwrite/mcp-for-docs` (9★) — Appwrite's MCP server for browsing docs
- `appwrite/sdk-for-cli` (98★) — official Appwrite CLI
- `appwrite/sdk-for-console` (21★)
- `appwrite/sdk-for-console-php`
- `appwrite/sdk-for-dotnet` (128★)
- `appwrite/sdk-for-react-native` (4292★)
- `appwrite/sdk-for-rust` (31★)
- `appwrite/sdk-for-svelte` (75★) — pinned to Appwrite 0.9, refactor planned

### `appwrite-labs/*` — 2 visible repos

- `appwrite-labs/.github`
- `appwrite-labs/php-k8s` — unofficial PHP Kubernetes client

### `open-runtimes/*` — 16 visible repos

- `open-runtimes/.github`, `examples`, `executor` (34★), `open-runtimes` (274★), `orchestrator`, `proxy`
- 10 `types-for-*` repos: `cpp`, `dart`, `dotnet`, `go`, `java`, `kotlin`, `node`, `python`, `rust`, `swift`

## Reproduce locally

```
composer install
bin/marketplace sync --regenerate-index=true
vendor/bin/phpunit
```
