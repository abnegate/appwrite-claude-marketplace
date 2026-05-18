---
name: utopia-migration-expert
description: Expert reference for utopia-php/migration — cross-service Resource migration engine (Appwrite/Supabase/NHost/Firebase/CSV/JSON sources and destinations). Consult for resumable migration design, parallel group execution, and source/destination extension.
---

# utopia-php/migration Expert

## Purpose
Cross-service resource migration engine that extracts typed `Resource` objects from a `Source` (Appwrite, Supabase, NHost, Firebase, CSV, JSON) and replays them into a `Destination` (Appwrite, Local, CSV, JSON), scoped by resource group and batched.

## Public API
- `Utopia\Migration\Transfer` — orchestrator; `run(array $groups, callable $callback, string $rootResourceId = '', string $rootResourceType = '')`; group constants (`GROUP_AUTH`, `GROUP_DATABASES`, `GROUP_STORAGE`, `GROUP_FUNCTIONS`, `GROUP_SITES`, `GROUP_MESSAGING`, `GROUP_SETTINGS`), `STORAGE_MAX_CHUNK_SIZE = 5MB`
- `Utopia\Migration\Source` / `Destination` (both extend `Target`) — implement `exportResources()` / `importResources()` per group with batch-size hooks
- `Utopia\Migration\Resource` (abstract) — typed domain objects (`TYPE_USER`, `TYPE_FILE`, `TYPE_DATABASE`, `TYPE_COLLECTION`, `TYPE_ROW`, etc.) with status state machine (`pending/processing/success/error/skipped/warning`)
- `Resources\{Auth,Database,Storage,Functions,Sites,Messaging}\*` — concrete resource types
- `Sources\{Appwrite,Supabase,NHost,Firebase,CSV,JSON}`, `Destinations\{Appwrite,Local,CSV,JSON}`
- `Sources\Appwrite::__construct(string $projectId, string $endpoint, string $key, callable $getDatabasesDB, string $source = SOURCE_API, ?UtopiaDatabase $dbForProject = null, array $queries = [])` — the first arg is `$projectId` (was `$project` pre-PR #154); the source-bound `Appwrite\Services\Project` SDK service is held as `$this->project` and used for platform listing. No more console-key fetch — pass the project API key directly. Supports `Resource::TYPE_PLATFORM` via `exportGroupIntegrations` (`exportPlatforms()` paginates via `Query::cursorAfter($lastId)` over `Project::listPlatforms`, honours `rootResourceId`/`rootResourceType` to subset to a single platform, and `reportIntegrations` uses `buildQueries(resourceType, resourceIds, limit: 1)` so `report()`'s resourceIds filter is honoured for platforms)
- `Destinations\Appwrite::__construct(string $project, string $endpoint, string $key, UtopiaDatabase $dbForProject, callable $getDatabasesDB, array $collectionStructure, UtopiaDatabase $dbForPlatform, string $projectInternalId, OnDuplicate $onDuplicate = OnDuplicate::Fail, ?callable $getDatabaseDSN = null)` — `dbForPlatform` is now constructor-promoted and non-nullable (PR #154 dropped the null-safe operator everywhere); `projectInternalId` is required so the destination can write `_databases` rows scoped to the right project. `getDatabaseDSN` is the opt-in resolver `(Database $resource): string` for the destination's `_databases.database` value (without it, the row is written with `''` and the runtime falls back to the destination project's DSN — never propagate the source DSN)
- `OnDuplicate` enum + `SchemaAction` (Create/Tolerate/UpdateInPlace) — drives the re-migration path on `DestinationAppwrite`. `Skip` always tolerates existing schema; `Upsert` runs a per-resource spec-match guard (`databaseSpecMatches`/`tableSpecMatches`/`attributeSpecMatches`/`indexSpecMatches`) and only updates when the source `updatedAt` is strictly newer; `Fail` (default) lets the library throw `DuplicateException` as before. SDK-reachable changes go through `updateAttribute`/`updateRelationshipAttribute`; non-SDK shape changes (`type`/`array`/`signed`/`format`/`formatOptions`/`filters`, plus relationship structural fields) drop+recreate
- `Cache` — in-memory resource registry shared across source and destination for ID remapping
- `Warning`, `Exception` — non-fatal vs fatal reporting

## Core patterns
- **Pipeline**: `Source->exportResources()` streams batches → `Transfer` callback → `Destination->importResources()`. Batch size is per-group, defaulting to 100
- **Shared `Cache` is the ID remapper** — when a new destination ID is minted for a source User, child resources (Memberships) look up the remapped ID by reference
- **Root resource targeting** — `ROOT_RESOURCES` can be targeted one-at-a-time via `rootResourceId/Type` to resume or subset a migration
- **Databases split** into `GROUP_DATABASES_TABLES_DB`, `GROUP_DATABASES_DOCUMENTS_DB`, `GROUP_DATABASES_VECTOR_DB` for Appwrite's new storage tiering
- **`GROUP_INTEGRATIONS` + `TYPE_PLATFORM`** — platform migration (Project SDK reads the unified `platforms` collection — Web/Apple/Android/etc. shapes share `$id`, `type`, `name`, `key`, `store`, `hostname` plus standard timestamps). Each is wrapped in `Resources\Integrations\Platform` and written through `Destinations\Appwrite` against `dbForPlatform`. Required when migrating projects between Appwrite installations
- Row/Document counts are NOT double-counted: source tracker skips them because destination aggregates per-status

## Gotchas
- **No persistent checkpoint** — `$cache` is in-memory; a crashed transfer restarts from zero unless the destination does idempotent upserts (Appwrite destination does, Local does not)
- `Local` destination is explicitly "testing only" per the README — do not use as a backup solution, it has no integrity check
- Source `previousReport` is used to seed `pending` counts from a prior run, but no code ships to persist it — you're expected to save/load it yourself
- Firebase source uses Firestore REST which aggressively rate-limits; set `getDatabasesBatchSize()` lower than 100 or expect 429s mid-migration
- **Sentry routing** — `Migration\Exception` is now the library's marker for "user-facing migration error". The `import()` per-resource wrap records and re-throws; `create*()` paths convert ~15 user-error sites (duplicates, structure, unsupported defaults) to `setStatus + addError + return false` so they stay in the report and do **not** bubble. The row-flush block catches `DuplicateException`/`StructureException` and re-throws as `Migration\Exception` for the same reason. Workers should route exceptions via `instanceof Migration\Exception` — only library bugs and infra failures should reach Sentry
- **Cross-host destination DSN** — never let the `_databases.database` row inherit the source DSN. Always pass a `getDatabaseDSN` resolver to `DestinationAppwrite` for cross-host or per-database-type setups (e.g. routing `documentsdb` and `vectorsdb` to dedicated DSNs); without one, the empty default is now safe

## Appwrite leverage opportunities
- **Resumable migrations**: serialize `$transfer->getCache()` + `$source->previousReport` to Redis after every batch. On crash, rehydrate before `run()` — avoids re-uploading TBs of storage. The primitive is there (`previousReport`), just no wiring
- **Parallel group execution**: groups are independent (Auth doesn't depend on Storage); in Swoole, spawn one coroutine per group and stream into the same destination. Today `run([AUTH, STORAGE])` is sequential
- **Streaming storage transfer**: the storage group currently reads files into memory per chunk (5MB). Swap the File resource to use `Utopia\Storage\Device::transfer()` directly when source and destination are both S3-compatible — skips the app entirely
- **Two-phase commit**: add a `dryRun` mode that runs extractors, counts, writes a manifest, but doesn't import. Surfaces "you don't have enough storage" before starting
- **Missing sources**: PlanetScale (SQL dump + binlog tail), MongoDB Atlas, PocketBase — Firebase and Supabase are covered, but SQL-native competitors aren't

## Example
```php
use Utopia\Migration\Transfer;
use Utopia\Migration\Sources\Supabase;
use Utopia\Migration\Destinations\Appwrite;

$source = new Supabase(
    endpoint:    'https://xyzcompany.supabase.co',
    key:         getenv('SUPABASE_SERVICE_KEY'),
    host:        'db.xyzcompany.supabase.co',
    databaseName:'postgres',
    username:    'postgres',
    password:    getenv('SUPABASE_DB_PASSWORD'),
);

$destination = new Appwrite(
    project:  'target-project',
    endpoint: 'https://cloud.appwrite.io/v1',
    key:      getenv('APPWRITE_API_KEY'),
);

$transfer = new Transfer($source, $destination);
$transfer->run(
    resources: [Transfer::GROUP_AUTH, Transfer::GROUP_DATABASES, Transfer::GROUP_STORAGE],
    callback:  function (array $resources) use ($transfer) {
        error_log('progress ' . json_encode($transfer->getStatusCounters()));
    },
);
```
