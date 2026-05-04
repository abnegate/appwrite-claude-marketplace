# Scan-commits suggestions — week of 2026-04-27 → 2026-05-04

Three skill bodies updated. No new skills, commands, agents, or hooks
were added — the deletions and renames in this window were all
internal refactors that the existing catalogue already describes
correctly, and the genuinely new public surfaces all map to skills
that already exist. The bar was "100% value", and this window cleared
it three times.

## Themes seen in the commits

- **utopia-php/database — Redis adapter + SQLite hardening.** PR #872
  ships a brand-new Redis adapter (T20–T56 sub-PRs) mirroring the
  Memory surface — schemas/collections/attributes/indexes incl.
  fulltext + relationships, CRUD/bulk, permissions, tenant-bucketed
  shared-tables keyspace, journal-based rollback. PR #870 lands a
  large SQLite hardening pass: FTS5-backed fulltext indexes, a
  PCRE-backed `REGEXP` UDF, composite UNIQUE constraints, upsert,
  attribute-resize-on-shrink, `BEGIN IMMEDIATE` write-serialisation,
  and a `setEmulateMySQL` flag to share test harnesses.
- **utopia-php/query — Schema builder rewrite.** PR #7 replaces the
  `Blueprint::column($name, $type)` shape with a fluent typed-method
  builder (`->id()`, `->string()`, `->integer()`, …, terminal
  `->create()`/`alter()`/etc.). PR #6 then adds ClickHouse data-
  skipping indexes via `IndexAlgorithm` (BloomFilter, Set,
  NgramBloomFilter, …) and table-level engine `SETTINGS`.
- **appwrite/appwrite — Realtime message-based protocol.** PR #12070
  ("realtime-action-channels") landed earlier; this window's visible
  signal is the public docs + SDK announcement (appwrite/website
  2026-04-29 changelog and `feat/realtime-message-based-sdk`), but
  the server-side WebSocket protocol that backs it — `subscribe` /
  `unsubscribe` / `authentication` / `ping` JSON message types,
  `subscriptionMode` of `'url'` vs `'message'`, bulk-validated
  subscribe/unsubscribe payloads, `Realtime::rebindAccountChannels`
  on auth flips — is the load-bearing piece for any backend question
  about the new SDK behaviour.
- **PHP 8.5 readiness — `curl_close` removal.** Six libraries
  (`utopia-php/abuse`, `fetch`, `migration`, `orchestration`, `vcs`,
  `domains`, `storage`) all drop deprecated `curl_close()` calls in
  the same week. A footgun pattern but transitional; rejected as a
  hook (see below).
- **Rust runtime + SDK + starter template.** New across
  `appwrite/runtimes`, `appwrite/sdk-for-rust` 0.4.0, `appwrite/
  sdk-generator`, `appwrite/templates`, `appwrite/console`,
  `appwrite-labs/edge`, and `appwrite-labs/cloud`. The functions
  expert skill is runtime-agnostic by design and the marketplace has
  no per-SDK skills, so this gets no entry; rejected (see below).
- **Storage out-of-order chunk uploads + idempotent assembly.**
  utopia-php/storage #162 (Local out-of-order) and #163 (parallel
  assembly race in both Local and S3). Already documented in the
  current `utopia-storage-expert` skill — no update needed.
- **OAuth2 + ephemeral keys + impersonation.** Already documented in
  the current `appwrite-auth-expert` skill from the previous scan
  window — no drift, no update needed.

## Changes made

### Updated `plugins/utopia-experts/skills/utopia-database-expert/SKILL.md`

Listed Redis as an adapter alongside the existing SQL/Mongo/Memory
surfaces, added a Redis adapter sub-bullet describing the keyspace
schema (`utopia:{ns}:{db}:doc:{col}:{id}`) and the deliberate gaps
(no `WATCH`/`MULTI`/`EXEC`, no OCC retry, no pessimistic update
locks, journal-based rollback that must use raw `\Redis` commands).
Added a SQLite adapter sub-bullet covering FTS5, the REGEXP UDF
(pattern cache + 512-byte cap), composite UNIQUE, upsert,
attribute-resize-on-shrink, `BEGIN IMMEDIATE`, and `setEmulateMySQL`.
Two new gotchas: Redis transaction-retry surface, and SQLite REGEXP
PDO requirements + FTS5 metadata-driven resolution.

Concrete user: someone wiring a Redis-backed test harness or
extending a SQLite adapter for cloud's lightweight tenants. Question
they would ask: "what guarantees does the Redis adapter give me on
transactions, and what's actually backing SQLite fulltext now?"

Cited commits:
- `utopia-php/database@609ebcd` (Merge #872 Redis adapter)
- `utopia-php/database@bb0cf9c` (tenant-bucketed redis keyspace)
- `utopia-php/database@2f3a158` (transactions + journal-based rollback)
- `utopia-php/database@21310f1` (gate count() fast-path under shared
  tables, drop tx() retry)
- `utopia-php/database@a1bb3e2` (Merge #870 SQLite FTS5)
- `utopia-php/database@60e98bd` (back fulltext indexes with FTS5
  virtual tables)
- `utopia-php/database@75c1a72` (register PHP REGEXP UDF and enable
  PCRE support)
- `utopia-php/database@bd197e5` (enable upserts and composite unique
  constraints)
- `utopia-php/database@e44768f` (gate MariaDB-shape emulation behind
  setEmulateMySQL flag)
- `utopia-php/database@00d502f` (BEGIN IMMEDIATE so writers serialise)

### Updated `plugins/utopia-experts/skills/utopia-query-expert/SKILL.md`

Replaced the stale `Schema\Table` API description (`column`,
`primary(array)`, `index`, `unique`, `fulltext`, …) with the actual
fluent typed-method API now on `main` (`id`, `string`, `integer`, …
columns; `index`, `uniqueIndex`, `fulltextIndex`, `spatialIndex`
table-level), including ClickHouse `engine()`/`settings()` and the
new `IndexAlgorithm` enum for skip indexes. Added a "Schema builder
is fluent-only" gotcha (no `column()` shortcut; mixing column-level
and table-level `primary` throws). Added a ClickHouse-specific
gotcha about `algorithmArgs` rendering verbatim and the `SETTINGS`
key/value charset restrictions. Rewrote the DDL example to use the
new API, dropping the `->column('tenantId', 'String')` shape that
no longer compiles.

Concrete user: someone porting an existing
`Blueprint`/`column()`-style schema definition to the published
library, or building a ClickHouse-backed analytics service that
needs skip indexes. Question they would ask: "what's the actual
column DSL, and how do I declare a BloomFilter skip index?"

Cited commits:
- `utopia-php/query@86bb895` (feat: fluent Schema table builder)
- `utopia-php/query@db26b03` (port schema tests to fluent builder API)
- `utopia-php/query@67669eb` (rewrite README schema examples for
  fluent builder)
- `utopia-php/query@18a77b0` (feat(schema): ClickHouse data-skipping
  indexes and engine SETTINGS)
- `utopia-php/query@bb529d8` (make index granularity nullable)
- `utopia-php/query@b1df44e` (guard ClickHouse index loops against
  non-skip index types)
- `utopia-php/query@fb4f783` (rename SkipIndexAlgorithm to
  IndexAlgorithm)

### Updated `plugins/appwrite-experts/skills/appwrite-realtime-expert/SKILL.md`

Added a "Message-based subscription protocol" section under
"Connection lifecycle" describing the four `onMessage` types
(`ping` / `authentication` / `subscribe` / `unsubscribe`), their
payload shapes, the response envelope (`type: "response", data: { to:
"<original>", success, … }`), and the `subscriptionMode` distinction
between `'url'` (channels in query string) and `'message'`
(channels established post-connect). Documented the `connected`
frame the server sends on `onOpen` for both modes. Documented
`Realtime::rebindAccountChannels` running on `authentication` so
guest-form `account.{action}` and prior-user
`account.{oldUserId}.{action}` subscriptions migrate to the new
user without drop/re-subscribe. Replaced the now-incorrect
"Subscription changes require a new message — you can't dynamically
add/remove channels" gotcha with two new ones: bulk-validation of
subscribe/unsubscribe payloads and the rebind-on-auth contract.

Concrete user: someone reconciling the Web SDK's new shared-socket
realtime API against the server, or debugging "my subscription
disappeared after login". Question they would ask: "what are the
actual JSON frames the SDK and server exchange, and what happens to
my subscriptions across an auth flip?"

Cited commits/files:
- `appwrite/appwrite@dae9cbc` (Merge #12070 Realtime action channels —
  the message-based protocol path lives in `app/realtime.php`'s
  `onMessage` handler at HEAD; the recent merge confirms the action
  channels half is in production)
- `appwrite/website@cf0e7ad` (feat(realtime): introduce message-based
  protocol for subscriptions — public-facing docs that pin the new
  client/server contract)
- `appwrite/website@91eab55` (improvements to realtime docs + announcement)

## Themes considered but rejected

- **New `curl_close` PreToolUse hook.** Six utopia-php repos removed
  `curl_close()` for PHP 8.5. Tempting, but the signal is
  transitional — by the time anyone installs the marketplace into
  fresh code, `curl_close()` is already a known no-op the linter
  catches. Hook would mostly add noise.
- **New Rust SDK / runtime skill.** `appwrite/sdk-for-rust` 0.4.0 +
  `appwrite/runtimes` 0.20 + Rust starter template + console support
  is genuinely new, but the marketplace deliberately doesn't ship
  per-SDK or per-runtime skills (the functions expert is runtime-
  agnostic by design). Adding only Rust would be inconsistent with
  the rest of the catalogue.
- **utopia-storage-expert update for out-of-order chunks / parallel
  assembly race.** Already covered in the current SKILL.md
  ("Chunks may now arrive out-of-order on the `Local` adapter"
  + "Idempotent `joinChunks` finalization — both `Local` and `S3`
  short-circuit if the destination object already exists"). No drift.
- **utopia-validators-expert update for `allowEmpty` flag on URL/
  Domain.** Already covered in the current SKILL.md gotcha section.
- **utopia-circuit-breaker-expert update for `trip()`.** Already
  covered in the current SKILL.md public-API and gotchas sections.
- **appwrite-auth-expert update for OAuth2 endpoints / Keycloak +
  FusionAuth + Kick / ephemeral keys / impersonation query params.**
  All already covered from the previous scan window — no drift.
- **utopia-php/migration storage v2.* bump.** Internal dep
  constraint, not a public-API change.
- **utopia-php/cli boolean coercion fix, utopia-php/query Pint type
  expansion, utopia-php/audit ClickHouse setter rename, utopia-php/
  fetch form-urlencoded fix, utopia-php/agents fetch ^1.1.0,
  utopia-php/queue dropping fetch.** Bug fixes / dep tweaks; no
  user-visible API change worth a skill update.
- **infrastructure / staging cost / docker-base / CI commits.**
  Internal ops noise, no public surface.
- **website / vibes / blog post commits.** Marketing surface, no
  public API.
- **`appwrite/appwrite` `feat-public-oauth2-endpoints`,
  `feat-create-dynamic-keys`, `feat/impersonation-query-params`,
  `migration-refractor`, `slow-queries`,
  `feat-out-of-order-chunk-uploads`.** Already covered or not load-
  bearing for an existing skill — see auth/storage rejections above.
- **appwrite-experts router agent update.** No new service-level
  skill added, so the router stays correct.
