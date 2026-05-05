# Sync notes for reviewer

## utopia-php drift — no scaffolding warranted

`bin/marketplace sync` flagged one missing skill and one archived upstream.
Resolution below; no skills were added or removed in this PR.

### `utopia-clickhouse-expert` — phantom missing skill, NOT scaffolded

The drift report lists `utopia-php/clickhouse` as upstream-only (missing
locally) with empty description and 0 stars. The repository **does not
exist on GitHub**:

- `gh api repos/utopia-php/clickhouse` → HTTP 404 (Not Found)
- `gh api orgs/utopia-php/repos --paginate` returns 60 names; none match
  `click*`. The 5 blocklist entries reduce that to 55 library repos,
  matching `local_count` exactly. The drift's `upstream_count: 56` is
  off-by-one — likely a transient API blip that briefly surfaced a
  phantom slug at sync time.
- `gh api search/repositories?q=utopia-php+clickhouse` → empty result.

Per the standing rule "do not fabricate APIs", scaffolding a skill with
no public source to ground against is not safe. A previous PR (see git
history of `PR_NOTES.md` at `3cd0419`) already removed a stub skill of
this name for the same reason. Leaving this entry unactioned; the next
sync will re-emit it as missing for as long as the upstream listing
ghost-includes it.

### `utopia-swoole-expert` — archived upstream, KEPT

`utopia-php/swoole` is archived upstream (superseded by `utopia-php/http`'s
first-party Swoole adapter). The local skill already documents this in its
frontmatter description, in a top-of-file `(archived)` heading, and ships
a five-step migration path to `utopia-php/http`. It remains useful for
maintaining services pinned to `utopia-php/framework: 0.33.*`. Keeping
it; the next sync will continue to (correctly) report it under
`utopia.archived`.

## Untracked upstream — not actioned here

Listed for visibility only. The commit-scan workflow
(`.github/workflows/scan-commits.yml`) owns the judgement on whether any
of these should become a tracked skill.

### `appwrite/*` — 12 new or untracked

- `appwrite/integration-for-digitalocean` (20★)
- `appwrite/integration-for-gitpod` (40★)
- `appwrite/mcp-for-api` (68★) — Appwrite's MCP server for backend ops
- `appwrite/mcp-for-docs` (9★) — Appwrite Docs MCP server
- `appwrite/sdk-for-cli` (98★) — official Appwrite CLI
- `appwrite/sdk-for-console` (21★)
- `appwrite/sdk-for-console-php`
- `appwrite/sdk-for-dotnet` (128★)
- `appwrite/sdk-for-react`
- `appwrite/sdk-for-react-native` (4289★)
- `appwrite/sdk-for-rust` (31★)
- `appwrite/sdk-for-svelte` (75★) — pinned to Appwrite 0.9, refactor planned

### `appwrite-labs/*` — 66 visible repos

Mostly cloud infra (`cloud`, `edge`, `infrastructure`, `terraform-modules`),
sidecars (`sidecar-for-runtime-*`, `sidecar-for-sql-api`, `sidecar-for-storage-autoscale`, etc.),
docker images (`docker-mysql`, `docker-postgresql`, `docker-proxysql`, `docker-geo`, `docker-metabase`),
internal SDKs (`sdk-for-manager`, `sdk-for-platform`, `sdk-for-console-imagine`),
and product/ops repos (`growth`, `incidents`, `domain-rankings`, `uptime-monitors`,
`secrets-management`, `helm-charts`, `pwned`, `php-k8s`).

### `open-runtimes/*` — 17 visible repos

- `open-runtimes/.github`, `docker-base`, `examples`, `executor` (34★),
  `open-runtimes` (274★), `orchestrator`, `proxy`
- 10 `types-for-*` repos: `cpp`, `dart`, `dotnet`, `go`, `java`, `kotlin`,
  `node`, `python`, `rust`, `swift`

## Reproduce locally

```
composer install
bin/marketplace sync --regenerate-index=true
vendor/bin/phpunit
```
