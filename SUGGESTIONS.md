# Scan-commits suggestions — week ending 2026-04-29

Reviewed `commits.md` (934 lines, ~30 repos active) against the existing
plugin tree. **All ten changes below are MODIFICATIONS to existing
skills.** No new skills, commands, agents, or hooks were added — every
candidate I considered for net-new content either already had a skill
covering it or the upstream signal wasn't strong enough to clear the
"100% value" bar.

## Themes I saw in the commits

1. **utopia-php library API drift** — `circuit-breaker`, `span`,
   `storage`, `validators`, `database`, `vcs`, `queue`, and `query` all
   shipped public-API changes that make the corresponding expert skill
   wrong or incomplete.
2. **Massive `utopia-php/query` expansion** — went from a serializable
   DSL into a full toolkit (Builder, Schema/Table DDL, wire-protocol
   parsers, AST). The existing skill described only the DSL surface.
3. **Appwrite auth surface expansion** — ephemeral (JWT-encoded)
   project keys, three new OAuth providers, public OAuth2 management
   endpoints, and user impersonation with header + query-param paths
   plus a CSRF-fail-closed guard.
4. **Realtime channel-model evolution** — action-suffixed channels
   (`…documents.{id}.update` and friends) and a `rebindAccountChannels`
   pathway for in-band re-auth.
5. **PHP 8.5 deprecation cleanups** — `curl_close()` removed across
   `utopia-php/{abuse,domains,fetch,migration,vcs}` and
   `appwrite/sdk-generator`/`appwrite/appwrite`. No skill change
   needed — internal hygiene, no public-API impact.
6. **New `appwrite-labs/sidecar-for-*` repos** — six sidecars carved
   out of `appwrite-labs/edge` (database-metrics, index-analysis,
   runtime-build, runtime-init, sql-api, storage-autoscale). All
   internal-cloud-only; rejected as new skills (see below).
7. **Cloud manager APIs, dedicated-tables, ClickHouse audit cleanup,
   realtime logs, deployment retention UI, terraform provider promo,
   Statsig SSR** — internal cloud/website work; not user-facing for
   marketplace consumers.

---

## Modifications

### 1. `utopia-circuit-breaker-expert` — add `trip()`

`utopia-php/circuit-breaker@976f722` (PR #2 "feat: add trip() to force
breaker into open state") added a public `CircuitBreaker::trip()` that
forces the breaker into OPEN out-of-band, idempotent, no extra
`transitions` recorded. Verified against
`src/CircuitBreaker/CircuitBreaker.php` lines 412–417 on `main`. The
skill's Public API list omitted it; the Core patterns/Gotchas section
now describes when to use it (admin-driven take-down) and that
self-healing via the HALF_OPEN probe still applies after `timeout`.

### 2. `utopia-span-expert` — `finish(?string $level, ?Throwable $error)`

`utopia-php/span@4623de8`, `4a50754`, `09f14bd`, `34a63b5` (PR #3
"Move span errors to finish") changed the canonical error path:
`finish()` now takes the throwable directly and stamps a `level`
attribute (`'error'` if an error is present, otherwise the explicit
argument or `'info'`). Verified against `src/Span/Span.php` line 252.
The skill's Public API listed `finish` as zero-arg and the Example
showed `setError($e); throw $e; finally finish();` — that pattern
still works but the new `finish(error: $e)` form is the documented
one. Added a Core-patterns bullet describing the level/error
contract and rewrote the Example.

### 3. `utopia-storage-expert` — out-of-order chunks + assembly race fix

`utopia-php/storage@52d1f89` (#162 "support out-of-order chunked
uploads in Local adapter") and `8a2e3a8` (#163 "handle parallel chunk
upload assembly race in Local and S3 devices"). Verified the patch in
`src/Storage/Device/Local.php`: `joinChunks()` now short-circuits if
the destination already exists and writes assembled output to a
`tempnam`-suffixed file before atomic rename, gracefully unwinding if
it loses the rename race. The skill said chunks had to come in order
and described `move_uploaded_file` as the only finalisation path.
Added a Core-patterns bullet for both behaviours.

### 4. `utopia-validators-expert` — `$allowEmpty` for Domain and URL

`utopia-php/validators@5d7d494` (#8 "Domain `$allowEmpty`") and
`6cce9f7` (#7 "URL `$allowEmpty`"). Verified against
`src/Validator/Domain.php` (`__construct(array $restrictions, bool
$hostnames = true, bool $allowEmpty = false)`) and
`src/Validator/URL.php` (`__construct(array $allowedSchemes = [], bool
$allowEmpty = false)`). The skill's Gotchas section claimed only
`Nullable` could express "optional"; updated to mention the in-built
flag.

### 5. `utopia-database-expert` — Memory adapter

`utopia-php/database@7479a74` (PR #860 "feat-memory-adapter").
Verified `src/Database/Adapter/Memory.php` exists on `main` and ships
the full adapter surface (schemas, collections, attributes, indexes
incl. unique + fulltext + PCRE regex, CRUD, transactions, query
operators, permissions, tenancy, schemaless, relationships) with
spatial/vector throwing intentionally. The skill's Public-API line
listed `MariaDB|MySQL|Postgres|SQLite|Mongo|SQL|Pool` only.

### 6. `utopia-vcs-expert` — GitLab adapter parity

`utopia-php/vcs@a089f3d` (#93 "feat/gitlab-adapter-webhooks"),
`7f9cff9` (#91 "feat/gitlab-adapter-remaining"), `057a076` (#88
"feat/gitlab-adapter-content"). Verified `src/VCS/Adapter/Git/GitLab.php`
on `main` ships `createRepository`, `createBranch`, `createFile`,
`createPullRequest`, `createWebhook`, `createComment`/`updateComment`,
`getPullRequest(Files|FromBranch)`, `listBranches`, `getCommit`/
`getLatestCommit`, `updateCommitStatus`, `getRepositoryTree`/`Content`,
`listRepositoryContents`/`Languages` — i.e. parity with GitHub.
Skill's Public API listed only the GitHub-shaped methods; also added
a Core-patterns bullet on GitLab's OAuth-shaped `initializeVariables`
signature with `accessToken`/`refreshToken` and `setEndpoint()` for
self-hosted GitLab.

### 7. `utopia-queue-expert` — Redis broker reconnect callbacks

`utopia-php/queue@4068a67` (PR #76 "feat(redis): survive transient
Redis outages with bounded reconnects") plus the follow-up commits
that exposed `setReconnectCallback`/`setReconnectSuccessCallback`
(`81b7779`, `1bee005`). Verified
`src/Queue/Broker/Redis.php` on `main` — `consume()` catches connection
errors, sleeps a randomised backoff doubling up to
`RECONNECT_MAX_BACKOFF_MS`, fires the failure callback, resumes, and
fires the success callback after recovery. Skill's Public API entry
for `Broker\Redis` didn't mention either hook; added them and a
Core-patterns bullet on the loop semantics.

### 8. `utopia-query-expert` — full rewrite for Builder/Schema/Compiler

PR #5 (`utopia-php/query@ca96081`) and the long
`feat-builder` series of refactors landed Builder, Schema/Table DDL,
wire-protocol parsers, and AST. Verified `src/Query/{Builder.php,
Builder/, Schema.php, Schema/, AST/, Parser/, Compiler.php,
Method.php, Statement.php… }` on `main`, plus the README v2 listing
the surface. Specifically:

- Plan→Statement, GroupedQueries→ParsedQuery, Blueprint→Table renames
  (`8a34449`, `dc83237`)
- Typed Sequences for MariaDB+PG (`fae406e`)
- Composite primary keys (`f5a3306`)
- ClickHouse MergeTree + TTL (`8545148`)
- PostgreSQL SERIAL types (`d692372`)
- AST walker preserves unchanged children (`672ecb7`)
- Numerous security fixes in `quote()` / `JoinBuilder::on()` /
  `selectWindow` / `extractFirstBsonKey` (`fc94515`, `e677741`,
  `bd13bfc`, `5fb4b25`, `374b77f`)
- README documents `and`/`or` (no underscore suffix)

The previous skill described only the `Query` DSL surface and
referenced `and_`/`or_` plus the loose-array `groupByType`. Rewrote
the file end-to-end and added two new examples (Builder + Schema).
Description bumped to 484 chars (under the 500-char cap).

### 9. `appwrite-auth-expert` — ephemeral keys, OAuth public endpoints, impersonation

Verified against `appwrite/appwrite` on `main`:

- **Ephemeral keys** (`appwrite/appwrite@aca11ed`, PR #12170
  "feat-create-dynamic-keys"; commits `980762f` "rename to ephemeral",
  `8f17616` "re-introduce project JWT endpoint", `b2ce95a` "backwards
  compatibility"). Endpoint: `POST /v1/project/keys/ephemeral`
  (`src/Appwrite/Platform/Modules/Project/Http/Project/Keys/Ephemeral/Create.php`),
  alias `/v1/projects/:projectId/jwts`, scopes + duration body params,
  duration capped at 3600 s (default 900). Constants in
  `app/init/constants.php` (`API_KEY_STANDARD/EPHEMERAL/ORGANIZATION/ACCOUNT`).
  Header form `x-appwrite-key: ephemeral_<jwt>` resolved in
  `app/controllers/general.php`. Project-JWT path was migrated onto
  ephemeral (`ed9b47f`).
- **Public OAuth2 endpoints + new providers** (`appwrite/appwrite@3d3f593`,
  PR #11993 "feat-public-oauth2-endpoints"). New adapters: `Kick`
  (`15f94d9`), `FusionAuth` (`49e6a38`), `Keycloak` (`cb4cff1`), plus
  the existing `Microsoft` got a public-OAuth endpoint update
  (`b28b851`). Verified files present in
  `src/Appwrite/Auth/OAuth2/`. New response models
  `MODEL_OAUTH2_PROVIDER_LIST` / `MODEL_CONSOLE_OAUTH2_PROVIDER_LIST` /
  `MODEL_CONSOLE_OAUTH2_PROVIDER_PARAMETER`.
- **Impersonation** (`appwrite/appwrite@547709a` PR #12167
  "feat/impersonation-query-params" plus the long CSRF-hardening
  series `5465be6`, `46a457b`, `4c989f9`, `9a175c5`, `a3f6cf4`,
  `5afc8f4`, `01b5fa8`, `d73b7a7`, `8f1d73a`, `ed0c7b4`). Verified
  `app/init/resources/request.php` and `app/init/realtime/connection.php`
  read both `X-Appwrite-Impersonate-User-{Id|Email|Phone}` headers and
  the matching `?impersonateUserId` / `impersonateEmail` /
  `impersonatePhone` query params, with the email/phone forms
  intentionally header-only (CSRF surface). Audit attribution and the
  `impersonatorUserId` field on `Account`/`User` response models verified
  in `src/Appwrite/Utopia/Response/Model/{Account,User,Log}.php` and
  `src/Appwrite/Platform/Workers/Audits.php`.

The skill claimed OAuth adapters live under `vendor/utopia-php/auth/`
(they actually live in `appwrite/appwrite` itself), didn't mention
ephemeral keys at all, and lacked the impersonation surface entirely.

### 10. `appwrite-realtime-expert` — action channels + rebindAccountChannels

`appwrite/appwrite@dae9cbc` (PR #12070 "realtime-action-channels") plus
the in-PR follow-ups (`cb8640b`, `ca105ff`, `e6d5c21`, `d25ccb7`,
`78715e4`). Verified
`src/Appwrite/Messaging/Adapter/Realtime.php` on `main`:

- `SUPPORTED_ACTIONS = ['create','update','upsert','delete']` (line 17)
- `RESOURCE_LEAF_NAMES = ['documents','rows','files','executions',
  'functions','account','teams','memberships']` (lines 31–40), with
  `functions` flagged parent-only (silent no-op on bare
  `functions.{action}`)
- `convertChannels($channels, $userId)` rewrites `account` →
  `account.{userId}` and `account.{action}` → `account.{userId}.{action}`,
  strips illegal `account.{otherUserId}` (line 445)
- `rebindAccountChannels($channels, $oldUserId, $newUserId)` for
  guest→user and user→user re-auth (line 493), called from
  `app/realtime.php`

The skill described the bare channel model with no action suffix and
didn't mention the rebind path or the guest-side literal preservation.

---

## Themes considered and rejected

- **`appwrite-labs/sidecar-for-{database-metrics,index-analysis,
  runtime-build,runtime-init,sql-api,storage-autoscale}`** — six new
  repos initially imported from `appwrite-labs/edge` images. Rejected
  as new skills: they are internal cloud sidecars carved out of one
  service for deployment isolation, not user-facing libraries; a
  marketplace user is not asking "how do I configure
  sidecar-for-storage-autoscale". `appwrite-cloud-expert` already
  covers the operator-side concerns at the right altitude.
- **`appwrite-labs/secrets-management`** — new private cloud repo
  (Cloudflared tunnel + DigitalOcean SSH runner). Internal
  infrastructure, not consumed by marketplace users.
- **`open-runtimes/types-for-rust`** — new crate. The
  marketplace-consumer focus is the Appwrite/Utopia PHP backend; we
  don't ship per-runtime-types skills today and the single new crate
  doesn't justify starting a new category.
- **`utopia-php/lock` initial release** — already covered by an
  existing `utopia-lock-expert` skill that is up-to-date with the
  shipped surface (Mutex/Semaphore/File/Distributed). No drift.
- **`utopia-php/cli` boolean coercion fix** (`8686789`,
  `a6ae1eb`) — internal coercion behaviour preserving empty-string
  sentinel; not visible in `utopia-cli-expert`'s described public API
  and not a footgun a user would ask about.
- **`utopia-php/fetch` form-urlencoded fix** (`b988865`) — request-body
  encoding bug fix. The existing skill already mentions form-encoded
  payloads at the right altitude; the fix changes correctness, not
  surface, so no skill update.
- **`utopia-php/http` infrastructure refresh** (PHPUnit 12, PHPStan
  level 7, Rector, PHP 8.3 floor) — internal CI/test hygiene; no
  framework-level public-API change.
- **`utopia-php/migration` storage 2.x bump** (`bf50409`) — dependency
  bump on a downstream library, doesn't change the migration
  resource/group surface the skill describes.
- **`utopia-php/storage` retry on transient S3 errors** (in window
  `7d59…` — actually outside the 7-day window per the section header,
  but visible in the recent log) — not in the scoped commit list.
- **`appwrite/appwrite` migrations module refactor** (`8ab26aa`,
  `3f5dcc8`) — `Migrate` API moved to module style. Internal
  reorganisation, no skill describes the previous file path.
- **Console/website/vibes UI work** — terraform-provider promo, AI
  table-creation flow, deployment retention UI, blocks admin
  dashboard, Statsig SSR, blog content. Not represented in any
  expert skill (we don't have a Console-frontend expert) and the
  marketplace's audience is backend/library-focused.
- **Appwrite SDK version bumps** (Android 24.0.0, Apple 17.0.0, CLI
  19.0.0/19.1.0/19.2.0, Console 11.0.0, Flutter 24.0.0, React Native
  0.29.0, Web 25.0.0) — `appwrite-skills` and `appwrite-experts` are
  scoped to the backend; per-SDK skills aren't part of the
  marketplace's current shape.
- **Cloud-only manager APIs** (`appwrite-labs/cloud@d4454e3`,
  `cc0b929`, etc.) — `appwrite-cloud-expert` is intentionally
  high-level and these endpoints are operator-only; not marketplace
  audience.
- **`appwrite-labs/incidents` reports** — narrative post-mortems, not
  reference content for an expert skill.
