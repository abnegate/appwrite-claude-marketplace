# Scan-commits suggestions — week of 2026-05-04 → 2026-05-11

Ten skill bodies updated. No new skills, commands, agents, or hooks.

## Themes seen in this commit window

- **Param aliases** rolled out across the framework stack (`utopia-php/servers` → `http`/`cli`/`queue`/`platform`). One unified mechanism: `Hook::param()` accepts a trailing `array $aliases = []`, and `Servers\Base::prepare()` (plus `Http::getArguments()`) walks aliases against both `$requestParams` and `$values`.
- **utopia-php/http v2.0.0-rc1**: a breaking refactor that splits adapter container access into `resources()` (static) and `context()` (per-request), removes `Http::setResource`/`getResource`/`getResources`, and reshapes the constructor to `(Adapter, string $timezone, Files = new Files())`. `utopia-php/platform` already bumped to `^2.0`.
- **utopia-php/query** schema/builder dialect split: dialect-only methods are removed from base classes, so `dropTrigger`/`upsertSelect`/etc are compile-time errors on dialects that don't support them rather than runtime `UnsupportedException`s. ClickHouse adds `LowCardinality`, `FixedString`, repeatable column-level `CODEC`, and table-level `SAMPLE BY`.
- **utopia-php/system**: new `getCPU(): float` honours cgroup v1/v2 quotas and cpuset pinning so containers report real capacity. `getCPUCores()` deprecated.
- **utopia-php/cache**: filesystem adapter gains a streaming-load mode that returns a file handle instead of slurping the whole file.
- **utopia-php/migration**: regression fix — `DestinationAppwrite` re-introduces the `getDatabaseDSN` resolver so cross-host destinations stop inheriting source DSNs (caused the comuneo-pre-production incident); separately, ~80% of the noise on the migrations Sentry stream is removed by routing user-data errors through `Migration\Exception` instead of generic throws. PR #171 lands `OnDuplicate`/`SchemaAction` for re-migration.
- **utopia-php/database**: `VAR_BIGINT` attribute type lands with adapter capability hooks (`getLimitForBigInt`/`getSupportForUnsignedBigInt`) and bigint-aware default validation.
- Cross-cutting `chore: pin github actions to sha` and `feat/clo-429*-migrate-to-fetch` flowed through ~half a dozen libraries (`audit`, `emails`, `analytics`, `logger`, `pay`, `vcs`). The fetch upgrade is a versioned dependency bump with no surface change.

## Changes made

### Modified `plugins/utopia-experts/skills/utopia-http-expert/SKILL.md`
- Document the v2.0.0-rc1 surface: `Http::resources()` / `context()`, the new constructor (no `Container` arg), removed `setResource`/`getResource`/`getResources` shims, and adapter constructor changes (`Container $resources` is now constructor-promoted on `FPM`/`Swoole`/`SwooleCoroutine`).
- Add the `param()` aliases trailing arg.
- Update the example to drop `setResource` and use the alias trailing arg.
- Cites: `utopia-php/http@3e3b431` (PR #254 — split static resources from per-request context), `utopia-php/http@76be330` (PR #252 — Param aliases).

### Modified `plugins/utopia-experts/skills/utopia-servers-expert/SKILL.md`
- Document the new `array $aliases = []` trailing arg on `Hook::param()` and how `prepare()` walks aliases against both request params and values.
- Cites: `utopia-php/servers@2cec9c7` (Support aliases) and `@24bbd8f` (fix url params).

### Modified `plugins/utopia-experts/skills/utopia-platform-expert/SKILL.md`
- Note `Action::param()` now forwards `aliases`, and add a Compatibility section flagging the bump to `utopia-php/http: ^2.0` (with the `resources()`/`context()` migration) and the drop of PHP 8.1/8.2 support.
- Cites: `utopia-php/platform@36c0a8b` (PR #62 — Bump utopia-php/http to 2.0.0-rc1), `@762efab` and `@393b7ab` (PR #61 — param aliases + 8.1/8.2 drop).

### Modified `plugins/utopia-experts/skills/utopia-cli-expert/SKILL.md`
- Document the `aliases` trailing arg on the task DSL `param()`.
- Cites: `utopia-php/cli@6fc00cc` (PR #53 — Add params alias support).

### Modified `plugins/utopia-experts/skills/utopia-queue-expert/SKILL.md`
- Document the `aliases` trailing arg on `param()` for job payload key fallbacks.
- Cites: `utopia-php/queue@a340c39` (PR #78 — Implement param aliases).

### Modified `plugins/utopia-experts/skills/utopia-query-expert/SKILL.md`
- Add ClickHouse-only column/table modifiers added in this window: `lowCardinality()`, `fixedString($name, $length)`, repeatable `codec(…)`, `sampleBy()`.
- Add a new bullet under Public API explaining the dialect feature interfaces (`Schema/Feature/{Views, ReplaceView, Databases, RenameIndex, AnalyzeTable, Partitioning}`, `Builder/Feature/{Spatial, FullTextSearch, Upsert, InsertOrIgnore, UpsertSelect}`) and that unsupported methods are now compile-time errors on the wrong dialect.
- Add a Gotchas bullet covering ClickHouse-only knobs, SQLite ALTER FK / Spatial / FullTextSearch losses, MongoDB's `Upsert + InsertOrIgnore` only set, and the PostgreSQL hash partitioning DDL change.
- Cites: `utopia-php/query@1499ffc` and `@d408b3e` (PR #8 — feat/clickhouse-schema-extras), `utopia-php/query@9766c4f`, `@1fec919`, `@bff8e07`, `@bc4db3f`, `@2751bb4`, `@ef7ed8c`, `@93687a1`, `@defae92`, `@3450ce5` (PR #9 — refactor/no-throws + dialect splits).

### Modified `plugins/utopia-experts/skills/utopia-system-expert/SKILL.md`
- Replace the `getCPUCores()` Public API bullet with the new `getCPU(): float` (cgroup v1/v2 + cpuset) plus a deprecation note on `getCPUCores()`.
- Cites: `utopia-php/system@34d7cbe` (PR #38 — feat: add getCPU() with cgroup limit support) plus the follow-up fixes `@0a4dbf8`, `@ff48f72`, `@29bbc72`, `@d2392ef`.

### Modified `plugins/utopia-experts/skills/utopia-cache-expert/SKILL.md`
- Document the `Filesystem(string $path, bool $streaming = false)` constructor and what `load()` returns when streaming is enabled (caller must `fclose()`).
- Cites: `utopia-php/cache@580255c` (PR #67 — feat: add filesystem streaming loads).

### Modified `plugins/utopia-experts/skills/utopia-migration-expert/SKILL.md`
- Add `Destinations\Appwrite::__construct(..., ?callable $getDatabaseDSN = null)` to Public API with the cross-host fallback contract.
- Add `OnDuplicate` enum + `SchemaAction` (Create/Tolerate/UpdateInPlace) and the SDK-reachable boundary that drives the re-migration path.
- Add Gotchas bullets explaining Sentry routing via `Migration\Exception` and the cross-host destination DSN rule.
- Cites: `utopia-php/migration@ff3b444` and `@9c9df8f` (PR #180 — destination DSN resolver), `utopia-php/migration@276e7c2`, `@e060244`, `@da8205e` (PR #177 — stop user-data errors leaking to Sentry), `utopia-php/migration@81b608a` (PR #171 — feat/skip-duplicates with `OnDuplicate`/`SchemaAction`).

### Modified `plugins/utopia-experts/skills/utopia-database-expert/SKILL.md`
- Add `Database::VAR_BIGINT` and `BigIntValidator` to Public API with adapter capability hooks (`getLimitForBigInt`/`getSupportForUnsignedBigInt`) and `normalizeBigIntSize` semantics.
- Cites: `utopia-php/database@eb35e68` (PR #847 — Big int) and the validator fix-up commits `@2ae3837`, `@25a3fcd`, `@1968ec4`.

## Themes considered but rejected

- **`utopia-php/audit` / `emails` / `analytics` / `logger` / `pay` / `vcs` fetch 1.1 upgrade** — pure dependency bump with no public surface change; updating skills would just add noise without giving a user a question they couldn't already answer.
- **`appwrite-labs/dns` cache circuit breaker** — a downstream consumer wired `utopia-php/circuit-breaker` around a DNS cache, but no library added/changed an interface; the existing `utopia-circuit-breaker-expert` skill already covers shared-state breakers and OTel telemetry.
- **`appwrite-labs/cluster-configuration-generator`** — net-new repo for an internal cluster-credentials sync cronjob; outside the marketplace's `utopia-php` / `appwrite/*` skill scope and not something a user of these plugins would ask about.
- **`appwrite/sdk-generator` Codex plugin templates** — internal SDK-generation pipeline change; the SDKs themselves get the new behaviour, the generator surface isn't user-facing for marketplace plugin users.
- **`appwrite/agent-skills` 0.2.x SDK bumps** — version churn; the agent-skills repo isn't covered by an expert skill (it's the SDK, not a service), and the bump didn't add features visible to consumers.
- **`appwrite/specs` and per-language SDK regenerations (`sdk-for-{node,php,python,go,kotlin,dart,swift,ruby,rust,dotnet,cli,console}`)** — auto-generated output from the spec change; the meaningful upstream is the spec/server, already covered.
- **`appwrite-labs/cloud` storage cache control / preview cache / build sidecar timeout work** — service-internal feature work that fits inside `appwrite-storage-expert` / `appwrite-cloud-expert` already, not new public surface.
- **`appwrite/console` UI fixes (`fix-deprecated-scopes-selection`, `fix-rule-status-created-ui`, billing dialog tweaks)** — frontend UX, no marketplace-relevant API surface.
- **`utopia-php/database` PR #873 (sum/count without limit optimisation)** — internal SQL shape change with no public-API or default-behaviour shift.
- **Cross-repo `chore: pin github actions to sha`** — repo hygiene; the marketplace already pins its own actions.
- **`open-runtimes/open-runtimes` zstd/symlink fix** — bug fix in the runtime extractor; existing skills don't cover the runtime extractor and one fix doesn't justify a new skill.
- **No new hooks** — none of the recurring footgun patterns in this window (e.g. cross-host DSN inheritance, source-vs-destination spec-match) lend themselves to a static guard a `PreToolUse` hook could enforce; they're API contracts, not edit-time patterns. Documenting them in the migration skill is the right surface.
- **No new commands or agents** — no recurring multi-step workflow showed up across the commits that a slash command would automate, and the existing `utopia-router` / `appwrite-router` agents already cover the routing surface.
