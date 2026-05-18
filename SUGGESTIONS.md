# Scan-commits suggestions — week of 2026-05-11 → 2026-05-18

Eleven skill bodies updated. No new skills, commands, agents, or hooks.

## Themes seen in this commit window

- **utopia-php/cache 3.0** — major release. New `Adapter\Redis\Multiplexing` adapter funnels every Swoole coroutine through a single Redis TCP connection (FIFO `Lock` + reader-coroutine + `Channel` dispatch + `cache.redis_multiplexing.pending.depth` gauge). Retry and telemetry are extracted into `Feature\Retryable` and `Feature\Telemetry` capability interfaces; the base `Adapter` contract gains `touch()`. Reads/writes go through `Adapter\Redis\Envelope` so the stored value carries write-time mtime. `utopia-php/database`, `dns`, `domains`, `vcs`, and `appwrite/appwrite` all bumped to `^3.0` in lockstep.
- **utopia-php/span 3.0** — exporter API refactor. `addExporter`/`resetExporters`/`reset` are gone; `setExporters(Exporter ...)` replaces all in one shot, and each `Exporter` now owns its sampler closure via constructor (and is required to implement `sample()`). `Sentry` validates DSN components at construct time and throws `InvalidArgumentException` for malformed DSNs. `Stdout` and `Pretty` print `level` before `action`.
- **utopia-php/audit 2.4 — actor terminology + ClickHouse capability** — columns/indexes renamed `userId`/`userType`/`userInternalId` → `actorId`/`actorType`/`actorInternalId`; `location` column dropped; resource path is N-part (no leading-6 assertion); `setAsyncCleanup(bool)` on the ClickHouse adapter; `Query::select`/between/contains/regex now supported on ClickHouse; slim projection always includes `tenant` when `sharedTables` is on.
- **utopia-php/validators** — new `Contains(array $patterns, bool $strict = false)` validator used by `appwrite/appwrite` for `[skip ci]` deployment skipping (and `VCS_DEPLOYMENT_SKIP_PATTERNS` constant).
- **utopia-php/vcs** — `createBranch` now abstract on `Adapter\Git` with a real GitHub implementation; `listBranches` is no longer an internal loop — single-page fetch with explicit `page`/`perPage` clamped to `[1, 100]`. Cache bumped to `^3.0`.
- **utopia-php/queue** — `messaging.queue.depth` gauge added on the worker `Server`; sampled per job by calling `Publisher::getQueueSize()`. Errors swallowed so a flaky Redis doesn't crash the worker.
- **utopia-php/circuit-breaker** — constructor option renamed `cacheKey` → `key` (PR #3) for consistency with the cache feature interfaces.
- **utopia-php/migration — `feat-platform-db-access`** (PR #154). `Sources\Appwrite` ctor first arg renamed `project` → `projectId`; the source-bound `Project` SDK service is now held as `$this->project`. `exportPlatforms()` paginates the `platforms` collection via `Project::listPlatforms` + `Query::cursorAfter`, honours `rootResourceId`/`rootResourceType`, and `reportIntegrations` uses `buildQueries(resourceIds, limit: 1)`. `Destinations\Appwrite` ctor now requires `dbForPlatform: UtopiaDatabase` (non-nullable, constructor-promoted) and `projectInternalId: string`. Console-key fetch removed — use the project API key directly. New resource type `TYPE_PLATFORM` under `GROUP_INTEGRATIONS`.
- **utopia-php/usage** — no longer a stub. `Usage` facade ships with two-table (`events` + `gauges`) architecture, in-memory buffer (threshold + interval gated), `collect`/`flush`/`shouldFlush`, query-time aggregation (`getTimeSeries(1h|1d)`, `find`, `count`, `sum`, daily MV via `findDaily`/`sumDailyBatch`), and ClickHouse + Database adapters. `setNamespace`/`setTenant`/`setSharedTables` moved out of abstract `Adapter` onto concrete adapters; tables re-keyed on `(tenant, metric, time, id)` for time-range scan efficiency. `utopia-php/fetch ^1.1` is the HTTP transport.
- **utopia-php/storage** — chunked upload split into three explicit phases on `Device`: `prepareUpload` / `uploadChunk` (does **not** finalise) / `finalizeUpload`. Legacy `upload()` is kept as a wrapper for backwards compat.
- **`appwrite/appwrite` typed Publisher/Message migration** — `Appwrite\Event\Publisher\{Func, Database, Delete, ...}` (readonly classes wrapping `Utopia\Queue\Publisher`) and `Appwrite\Event\Message\*` (final readonly DTOs with `toArray()`) are landing alongside the legacy `queueFor*` `Event` objects. Function, database, and delete queues migrated this window; cloud's region-manager queue followed.
- **Cross-repo themes that did NOT warrant skill changes**: cache `^3.0` constraint propagation across utopia-php/database / dns / domains / vcs (pure constraint bump, the cache surface change is documented in `utopia-cache-expert`); `utopia-php/dns` and `utopia-php/domains` `Support domains 2.x` (downstream lib bump only); `utopia-php/platform` relax-constraints chore (no API change); console / blog / specs / SDK regens; cloud / edge GH-action SHA pins; cloud queue typed-publisher migrations (covered via `appwrite-workers-expert`); appwrite-labs/uptime-monitors Bun rewrite (internal service); appwrite/sdk-for-react TanStack/SSR work (per-language SDK, out of marketplace scope per prior policy).

## Changes made

### Modified `plugins/utopia-experts/skills/utopia-cache-expert/SKILL.md`
- Document `Cache::touch()`, the `cache.operation.duration` histogram tagging, and the cascade rule for telemetry into a single inner adapter.
- Document `Adapter\Redis\Multiplexing` (constructor args, `cache.redis_multiplexing.pending.depth` gauge, FIFO `Lock`/reader-coroutine design, when to choose it over `Pool`, the head-of-line blocking trade-off).
- Document `Feature\Retryable` and `Feature\Telemetry` capability interfaces and the fact that `Sharding` no longer propagates telemetry into its leaves.
- Document `Adapter\Redis\Envelope` write-time mtime envelope, `Adapter\CircuitBreaker` composite, and the `^3.0` composer bump that propagated across utopia-php/database/dns/domains/vcs and appwrite/appwrite.
- Cites: `utopia-php/cache@d2e1025` (Redis\Multiplexing), `@940da16` (Envelope codec), `@d13eb23` (Retryable feature), `@b0cc209` (telemetry-aware adapters), `@5933249` (stop propagating telemetry through sharding), `@ef52a04` (PR #68 — cache touch); downstream bumps `utopia-php/database@05b8a1f`, `utopia-php/dns@5437a60`, `utopia-php/domains@1b1fea8`, `utopia-php/vcs@6bb7a16`, `appwrite/appwrite@1b945bd`.

### Modified `plugins/utopia-experts/skills/utopia-span-expert/SKILL.md`
- Replace `addExporter`/`resetExporters`/`reset` with `setExporters(Exporter ...)`; each exporter constructor now takes its sampler closure directly, and `Exporter` is a two-method interface (`export` + `sample`).
- Document `Sentry`'s mandatory DSN validation (throws on missing/invalid public key/host/project ID) and the new `Stdout`/`Pretty` field ordering (`level` before `action`).
- Update the example to use `setExporters` + per-exporter sampler.
- Cites: `utopia-php/span@7531a2d` (PR — drop addExporter/reset helpers), `@cad25b8` (PR #5 — deprecate addExporter, add setExporters), `@cea1fda` (validate Sentry DSN components on construction), `@114a062` (PR #6 — level before action in Stdout/Pretty).

### Modified `plugins/utopia-experts/skills/utopia-audit-expert/SKILL.md`
- Replace "user" terminology with "actor"; document `Log::getActorId/getActorType/getActorInternalId` getters next to the legacy `getUserId` and the bidirectional remap inside the adapter.
- Document `Adapter\ClickHouse::setAsyncCleanup(bool)`, the dropped `location` column, the N-part resource path, the new `idx_actorId_event` index, the `Query::select`/between/contains/regex coverage, and the slim-projection-always-includes-tenant rule under sharedTables.
- Drop `location` from the `log()` example.
- Update description (still <= 500 chars).
- Cites: `utopia-php/audit@b3b663c` (PR #122 — add getActorId/getActorType/getActorInternalId getters), `@490b2d6` (rename actor columns and indexes), `@33383ff` (PR #118 — drop location column and N-part resource paths), `@a889e2e` (PR #117 — async cleanup), `@c1b85ad` (PR #116 — tenant in slim projection), `@a3f683f` (Query::select + remaining query types on ClickHouse), and the downstream bump `appwrite/appwrite@6116d11` (refresh utopia-php/audit to 2.3.2 → 2.4.x in this scan window via `appwrite-labs/cloud@fa3b8c9`).

### Modified `plugins/utopia-experts/skills/utopia-validators-expert/SKILL.md`
- Add `Contains(array $patterns, bool $strict = false)` under Strings/format with constructor semantics (empty patterns throws, default case-insensitive), and the `[skip ci]` use-case cite for `appwrite/appwrite`.
- Cites: `utopia-php/validators@3e0e0c5` (PR #9 — Add contains validator), `@01adf80` (empty patterns array), and the consumer commits `appwrite/appwrite@e375567` (extend Contains, keep only `[skip ci]` pattern) and `@b5f0ebb` (VCS_DEPLOYMENT_SKIP_PATTERNS constant).

### Modified `plugins/utopia-experts/skills/utopia-vcs-expert/SKILL.md`
- Document `createBranch` now abstract on `Adapter\Git` with a real GitHub implementation, and `listBranches` signature change (single-page, explicit `page`/`perPage`, GitHub clamps to `[1, 100]`).
- Add gotcha for non-auto-paginating `listBranches` and the cache `^3.0` constraint bump.
- Cites: `utopia-php/vcs@a413e42` (PR #98 — Implement createBranch, add page param), `@78507b1` (Fix GitHub branch pagination), `@0fdd494` (use assertEqualsCanonicalizing for order independence), `@6bb7a16` (PR #104 — cache 3.0 bump).

### Modified `plugins/utopia-experts/skills/utopia-queue-expert/SKILL.md`
- Add `messaging.queue.depth` gauge (sampled per job via `Publisher::getQueueSize`, tagged with destination name + namespace, failures swallowed) to the telemetry bullet.
- Cites: `utopia-php/queue@1977bc3` (Add queue depth telemetry gauge), `@025f1e4` (surface queue depth telemetry errors), `@7e6b977` (simplify queue depth error telemetry).

### Modified `plugins/utopia-experts/skills/utopia-circuit-breaker-expert/SKILL.md`
- Rename the `cacheKey` constructor arg to `key` across signature, gotchas, leverage opportunities, and the runnable example.
- Document the `InvalidArgumentException` thrown when a cache adapter is wired with an empty `key`.
- Cites: `utopia-php/circuit-breaker@a084d62` (PR #3 — Rename cacheKey option to key).

### Modified `plugins/utopia-experts/skills/utopia-migration-expert/SKILL.md`
- Document `Sources\Appwrite` ctor first arg rename `project` → `projectId`, `Project` SDK service held as `$this->project`, `exportPlatforms` pagination via `Project::listPlatforms` + `Query::cursorAfter`, `rootResourceId`/`rootResourceType` guard, `reportIntegrations` use of `buildQueries(resourceType, resourceIds, limit: 1)`, and the removal of the console-key fetch.
- Document `Destinations\Appwrite` ctor now requiring `dbForPlatform` (non-nullable, constructor-promoted) and `projectInternalId` (string).
- Add a `GROUP_INTEGRATIONS` + `TYPE_PLATFORM` core-pattern bullet.
- Cites: `utopia-php/migration@3ee6e12` (PR #154 merge — feat-platform-db-access), `@0713d21` (rename source project to projectId; hold Project SDK service as project), `@d7b5175` (rootResourceId guard in exportPlatforms), `@4fc5fc3` (buildQueries in reportIntegrations), `@c0a7d01` (drop null-safe operator on dbForPlatform), `@052013a` (paginate exportPlatforms via Project SDK), `@01ae076` (pass dbForPlatform + projectInternalId in destination), `@18a8f88` (remove console-key fetch).

### Modified `plugins/utopia-experts/skills/utopia-usage-expert/SKILL.md`
- Full rewrite: the library is no longer a stub. Document the `Usage` facade (constants, lifecycle, capture, flush, direct writes, reads, multi-tenancy), `Adapter\ClickHouse` knobs (timeout/compression/keepAlive/maxRetries/retryDelay/asyncInserts/queryLogging) and `Adapter\Database`, the `(tenant, metric, time, id)` keying, two-table aggregation rules (SUM for events, argMax for gauges), the daily SummingMergeTree MV, the buffer flush semantics, and gotchas (negative value rejection, md5(tags) hashing, partial flush retention, async insert ack-before-commit, Database-as-dev-only).
- Add an Appwrite leverage section keyed off replacing `bin/worker-usage` / `app/tasks/usage.php` and pairing with `cloudevents` / `span` / `audit`.
- New description reflects the populated surface (still <= 500 chars).
- Cites: `utopia-php/usage@9bff4b7` (PR #3 merge — rebuild-analytics-clickhouse-OHWGZ), `@cdea198` (move setNamespace/setTenant/setSharedTables out of abstract Adapter), `@68a4b6f` (re-key event/gauge tables on (tenant, metric, time, id)), `@b2cbdbc` (DB adapter query coverage + null-type cross-type fallback), `@b90879a` (bump utopia-php/fetch to ^1.1).

### Modified `plugins/utopia-experts/skills/utopia-storage-expert/SKILL.md`
- Document the three-phase chunked-upload contract on `Device`: `prepareUpload` / `uploadChunk` (no auto-finalise) / `finalizeUpload`, plus the legacy `upload()` wrapper.
- Rewrite the chunked-upload core pattern to reflect the phased contract and rename "joinChunks" → "finalizeUpload".
- Update the example to drive the phased API explicitly.
- Cites: `utopia-php/storage@37129cf` (PR #165 — Split chunked upload phases).

### Modified `plugins/appwrite-experts/skills/appwrite-workers-expert/SKILL.md`
- Add a "Typed publisher/message migration (in flight, 1.9.x)" subsection under Event system: describe `Appwrite\Event\Publisher\*` readonly classes (ctor `(Publisher, Queue)`, `enqueue(BaseMessage, ?Queue)`, `getSize`) and `Appwrite\Event\Message\*` final-readonly DTOs (promoted properties, `toArray()`, `Func::fromEvent()` convenience). Note that the legacy `queueFor*` injectables and shutdown-hook `trigger()` still exist for compatibility and are being migrated piecewise.
- Cites: `appwrite/appwrite@85e2cf7` (migrate queueForFunctions to FunctionPublisher and FunctionMessage), `@ad88b82` (refactor database queue publisher), `@65d1e58` (migrate delete queue publisher), `@8f481d6` (keep delete publisher in shared container); cloud-side reinforcement `appwrite-labs/cloud@5defb63` (region manager queue → typed publisher), `@bc7a501` (database queue publisher), `@313e4ce` (delete queue publisher), `@a0e3fcd` (functions queue → FunctionPublisher).

## Themes considered but rejected

- **`utopia-php/cache: ^3.0` propagation across utopia-php/database / dns / domains / vcs** — pure composer constraint bump. The cache 3.0 surface change is documented once on `utopia-cache-expert`; replicating "we bumped cache" eleven times would only add churn.
- **`utopia-php/dns` Support domains 2.x (#45) and `utopia-php/domains` cache 3.0 bump** — downstream library version bumps with no public surface change in dns / domains.
- **`utopia-php/platform` relax-version-constraints chore** — composer constraint hygiene, no API change.
- **`utopia-php/pools` and `utopia-php/proxy` "checkpoint local"** commits — `chore: checkpoint local rector config` / `chore: checkpoint local changes`, work-in-progress that did not land features.
- **`open-runtimes/executor` base-image / phpunit migration** — internal CI/runtime hygiene (`php 8.5`, `swoole 6.2`, `utopia-php/orchestration 0.19.2`, PHPUnit 13 `#[DataProvider]`). No user-visible surface change in the executor's API.
- **`open-runtimes/open-runtimes` Bun glibc/pnpm pin** — bug fixes inside the Bun runtime extractor; no skill currently covers individual runtime extractors and one fix doesn't justify one.
- **`appwrite/sdk-for-react` 0.x package work** — TanStack Start adapter, SSR, OAuth callback validation, rename to `@appwrite.io/react`. Per the prior week's policy ("auto-generated SDKs are out of marketplace scope"), and the React SDK still being pre-1.0 / changeset-driven, a new skill isn't justified yet.
- **`appwrite/sdk-for-cli` 21.0.0 / `appwrite/sdk-for-console` 13.0.0 / `appwrite/sdk-for-php` 23.1.1 / `homebrew-appwrite`** — version-bump churn; the meaningful upstream is the spec/server which is already covered, and the per-language SDKs are auto-generated.
- **`appwrite/sdk-generator` bigint + swift Codable enum + cover-image + CLI Windows signature** — internal generator pipeline change; the generated SDKs get the new behaviour, the generator itself isn't user-facing for marketplace consumers.
- **`appwrite/console` 8.3.x UI fixes (impersonated resource URLs, signup email security, variable pagination, relationships-GA labelling, perf optimisations)** — frontend UX, no marketplace-relevant API surface.
- **`appwrite/vibes` weekly UX refactors** — frontend churn in the design-system staging repo; covered (if anywhere) by `appwrite-conventions` frontend priors which weren't invalidated this week.
- **`appwrite/website` blog/docs commits** — content updates only.
- **`appwrite/codex-plugin` (new repo) and `appwrite/sdk-generator` codex plugin fixes** — a Codex marketplace plugin for the Appwrite SDK generator. Not in the Claude marketplace's covered surface (Claude Code plugins for the Appwrite/Utopia stack), so it doesn't need a skill.
- **`appwrite-labs/cloud` region manager publisher, deletes-worker async audit cleanup, build queue env config, autoscaling tweaks, queue publisher migrations** — service-internal feature work covered by the typed-publisher addition to `appwrite-workers-expert`. Individual cloud-only refactors don't warrant their own dedicated bullet.
- **`appwrite-labs/edge` deprecated `curl_close` cleanup, manager SDK 3.0 bump, span 3.0 migration, php 8.5/swoole 6.2 image, atomic archive download, compaction logic** — base-image/dep churn plus internal compaction; the span 3.0 user-facing change is documented on `utopia-span-expert`.
- **`appwrite-labs/growth`, `helm-charts`, `infrastructure`, `telemetry`, `uptime-monitors`, `databases`, `clickhouse`, `sdk-for-manager`** — service-internal infrastructure / cluster / cloudflare / dragonfly / k8s assets / Bun migration / SDK bump. None of these are in the per-library expert scope.
- **`appwrite-labs/incidents` and `appwrite/incidents`** — incident reports (historical narratives), not API/contract changes.
- **`appwrite/terraform-provider-appwrite` bigint column support** — Go SDK 3.1.0 bump + bigint parsing. The marketplace doesn't ship a Terraform-provider expert, and bigint is already documented in `utopia-database-expert` from the prior scan window.
- **`utopia-php/abuse` and `utopia-php/messaging` "feat/sdk-23" merges** — SDK constraint bumps with no public surface change in those libraries.
- **`utopia-php/database` PR #876/#879 cache bump** — pure constraint bump.
- **No new hooks** — the recurring footguns this week (queue-publisher rename collisions, removed `addExporter`, `cacheKey` → `key` rename, `createBranch` on the wrong adapter) are API-contract surprises rather than edit-time patterns. A `PreToolUse` static guard can't catch them; documenting the breakages in the relevant expert skill is the right surface.
- **No new commands or agents** — no recurring multi-step workflow showed up across the commits that a slash command would automate, and the existing `utopia-router` / `appwrite-router` agents already cover the routing surface.
