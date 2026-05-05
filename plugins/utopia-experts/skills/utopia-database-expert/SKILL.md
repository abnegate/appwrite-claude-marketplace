---
name: utopia-database-expert
description: Expert reference for utopia-php/database — the adapter-based CRUD/query/permission layer that backs every Appwrite collection. Consult for queries, attributes, relationships, transactions, the filter chain, cache invalidation, and adapter pitfalls.
---

# utopia-php/database Expert

## Purpose
Adapter-based CRUD/query abstraction over SQL (MariaDB/MySQL/Postgres/SQLite), MongoDB, and Redis with permissions, relationships, typed attributes, caching, filters, and transactions — the backbone of every Appwrite collection operation.

## Public API
- `Utopia\Database\Database` — main facade. `createDocument`, `find`, `findOne`, `getDocument`, `updateDocument`, `updateDocuments`, `increaseDocumentAttribute`, `createIndex`, `withTransaction`, `purgeCachedDocument`, `setDocumentType`, `createRelationship`
- `Utopia\Database\Adapter` — abstract base; `MariaDB`, `MySQL`, `Postgres`, `SQLite`, `Mongo`, `Memory`, `Redis`, `SQL`, `Pool` concretions live in `src/Database/Adapter/`. `Memory` is an in-process drop-in covering schemas/collections/attributes/indexes (incl. unique + fulltext + PCRE regex), CRUD, transactions, permissions, tenancy/shared-tables, object/nested attributes, schemaless mode, and relationships — intended for tests and ephemeral workloads (spatial types and vector search throw `DatabaseException`)
- `Adapter\Redis` — Redis-backed adapter mirroring the Memory surface (schemas/collections/attributes/indexes incl. fulltext + relationships, CRUD/bulk, permissions, tenancy/shared-tables). Keys are namespaced as `utopia:{ns}:{db}:doc:{col}:{id}` etc.; `tx()` is a single-shot journal-based rollback (no `WATCH`/`MULTI`/`EXEC`, no OCC retry, no pessimistic update locks); storage-typed inherited tests are skipped
- `Adapter\SQLite` — full-feature SQLite3 adapter with FTS5 virtual tables backing fulltext indexes (one vtable per attribute set, tenant-scoped, hashed name), a `preg_match`-backed `REGEXP` UDF (FIFO pattern cache, 512-byte pattern cap, 256 entries), composite UNIQUE constraints, upserts, attribute-resize-on-shrink, and `BEGIN IMMEDIATE` transaction lock to serialise writers. Call `setEmulateMySQL(true)` to emit MariaDB-shape DDL when sharing test harnesses
- `Utopia\Database\Document` — typed wrapper around `array<string,mixed>`; `$id/$createdAt/$updatedAt/$permissions/$collection` reserved
- `Utopia\Database\Query` — filter/order/pagination DSL; 40+ `TYPE_*` constants including spatial (`distanceLessThan`, `intersects`) and vector (`vectorCosine`, `vectorDot`, `vectorEuclidean`)
- `Utopia\Database\Mirror` — dual-write wrapper; writes to source + optional destination with filters + error callbacks (zero-downtime migrations)
- `Utopia\Database\Validator\Authorization` — role-based ACL input; `addRole()`, `skip()`, reset
- `Utopia\Database\Helpers\{ID,Permission,Role}` — ID generation (`ID::unique`, `ID::custom`), permission string builders (`Permission::read(Role::any())`)
- `Utopia\Database\Change` / `Operator` — change tracking and atomic update operators

## Core patterns
- **Permission strings** follow `action("role")`: `Permission::read(Role::user($id))`. `documentSecurity=true` makes per-document ACLs win over collection ACLs
- **Writes go through `Structure`/`PartialStructure` validators** then attribute `filters` (encode on write, decode on read). Register with `Database::addFilter($name, $encode, $decode)` — instance filters override static
- **`Query::groupByType` / `getByType`** bucket queries into `filters/selections/limit/offset/orderAttributes/cursor` — adapters translate each bucket independently
- **`withTransaction(callable)`** retries on deadlock/conflict; exceptions derived from `Utopia\Database\Exception` are classified (`Duplicate`, `Conflict`, `Timeout`, `NotFound`, `Restricted`, `Limit`, `Structure`, `Dependency`, `Index`, `Order`, `Type`, `Relationship`, `Authorization`) — catch the specific subclass, never the base
- **Cache is write-through on `getDocument`**; after side-channel writes call `purgeCachedDocument($collection, $id)` or the next read serves stale data

## Gotchas
- MongoDB adapter depends on `utopia-php/mongo` (custom wire client) **plus** `ext-mongodb`/`mongodb/mongodb` for BSON types
- Sparse updates: `updateDocument` accepts only changed attributes, but `Mirror` + authorization re-validate the **full** merged document, so missing required attributes resurface as `StructureException` on mirror writes. Pass a full doc through `Mirror`
- Relationship depth is hard-capped at `RELATION_MAX_DEPTH = 3` and chunked at `RELATION_QUERY_CHUNK_SIZE = 5000`; deeper graphs silently truncate to an array of `$id` strings
- `Database::find` returns `[]` on missing permissions (not an exception) — always check authorization explicitly when the caller expects "not found" vs "forbidden"
- **Redis adapter has no transaction retries** — `getSupportForTransactionRetries()` is `false`, so the OCC-shape tests are skipped and the caller must not rely on `withTransaction()` to retry on conflict. Schema/CRUD/relationship ops route through a journal so partial failures roll back, but the rollback path uses raw `\Redis` commands — never call public adapter methods from inside `rollbackJournal()` or you recurse infinitely
- **SQLite REGEXP requires `sqliteCreateFunction` access** — Pool/Proxy PDO wrappers may not expose it. Check `$adapter->getSupportForRegex()` before composing queries that rely on `Query::regex`. FTS5 indexes are resolved by id via collection metadata, not by name — drop/rename paths walk the metadata, so out-of-band schema edits will desync

## Appwrite leverage opportunities
- **Cache stampede**: `purgeCachedDocument` is a blunt delete, not SWR. For hot collections (`_metadata`, `projects`, `teams`) wrap the `Cache` adapter to support `getStaleWhileRevalidate` so one worker refreshes and the rest serve stale — halves P99 on `getDocument` during schema migrations
- **Telemetry hooks**: `Database` triggers named events (`EVENT_DATABASE_CREATE` etc.) but no built-in instrumentation. Attach an OpenTelemetry span-starter via `Adapter::trigger` to get adapter-level SQL timings without patching every call-site
- **Sparse updates on relationships**: `updateDocuments` re-selects the full collection before the UPDATE to populate the cache. For attribute-only batch updates (e.g. `$updatedAt` refresh) add a `skipFetch: true` fast-path
- **Mirror for migrations**: `Mirror` is under-used — Appwrite still hand-rolls dual-writes for schema upgrades. Wire `Mirror` into `Appwrite\Platform\Tasks\Migrate` with `writeFilters` per attribute type to get free idempotent backfill + per-collection error sinks

## Example
```php
use PDO;
use Utopia\Cache\{Cache, Adapter\Memory};
use Utopia\Database\{Database, Document, Query};
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Helpers\{ID, Permission, Role};
use Utopia\Database\Validator\Authorization;

$pdo = new PDO('mysql:host=mariadb;dbname=appwrite;charset=utf8mb4', 'root', 'password', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_PERSISTENT => true,
]);
$database = new Database(new MariaDB($pdo), new Cache(new Memory()));
$database->setNamespace('_project_abc')->setDatabase('appwrite');

$authorization = new Authorization();
$authorization->addRole(Role::user('user_123')->toString());

$database->withTransaction(function () use ($database) {
    $database->createDocument('posts', new Document([
        '$id' => ID::unique(),
        'title' => 'Hello',
        '$permissions' => [Permission::read(Role::any())],
    ]));
    return $database->find('posts', [Query::equal('title', ['Hello']), Query::limit(10)]);
});
```
