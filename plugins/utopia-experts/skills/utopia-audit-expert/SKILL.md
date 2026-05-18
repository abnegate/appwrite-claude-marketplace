---
name: utopia-audit-expert
description: Expert reference for utopia-php/audit — actor action/audit log store with Database and ClickHouse adapters. Consult when wiring retention, moving audit off MySQL, adding trace correlation, or migrating off the legacy userId column to the actor terminology.
---

# utopia-php/audit Expert

## Purpose
Actor action/audit log store with pluggable adapters; records who (the **actor** — historically the user) did what to which resource with time, IP, user-agent, free-form data, and supports filtered retrieval. Two adapters: row-oriented MySQL/PG (`Database`) and column-oriented ClickHouse over HTTP.

## Public API
- `Utopia\Audit\Audit` — facade (`log(?string $userId, string $event, string $resource, string $userAgent, string $ip, array $data = [])`, `logBatch`, `getLogsByUser`, `getLogsByResource`, `getLogsByUserAndEvents`, `getLogsByResourceAndEvents`, `countLogs*`, `cleanup`, `find($queries)`, `count($queries, ?$max)`, `getLogById`). The `log()` signature no longer accepts `$location` — the column was removed
- `Utopia\Audit\Adapter` — abstract contract with `get*/count*/create*/cleanup/setup` plus `setNamespace`/`setTenant`/`setSharedTables`/`setAsyncCleanup`
- `Utopia\Audit\Log` — entity exposing both legacy and actor accessors: `getId`, `getUserId` (legacy), `getActorId`, `getActorType`, `getActorInternalId`, `getEvent`, `getResource`, etc. Writes still accept `userId` as input; storage and reads use the new `actor*` columns and the adapter remaps in both directions
- `Utopia\Audit\Query` — filter builder; the ClickHouse adapter now supports the full `Query` surface including `Query::select(...)`, between/not-between, contains/not-contains, starts-with/ends-with (and their negations), and regex, in addition to the original equality/range/limit/offset/cursor types
- `Adapter\Database` — default, over `utopia-php/database`
- `Adapter\ClickHouse` — HTTP interface, monthly partitioning, bloom filter indexes, 8123 default. `setAsyncCleanup(bool)` opts into async retention purge (issues `DELETE … SETTINGS mutations_sync = 0`) so `cleanup()` returns immediately and lets ClickHouse process the mutation in the background — pair with the deletes worker async cleanup flag in Appwrite cloud
- `Adapter\SQL` — shared SQL parent for Database adapter

## Core patterns
- **Heavy adapter contract (10 abstract methods)** — every query shape has a paired `countBy*` to avoid full scans
- All retrieval methods take `after/before/limit/offset/ascending` — pagination is structural, not bolted on
- **`logBatch`** takes pre-timestamped events so you can replay from a queue with original timestamps
- **ClickHouse adapter talks HTTP via `utopia-php/fetch`** (no PECL ext required), uses monthly partitioning so retention is a single `DROP PARTITION`
- **`cleanup(\DateTime)`** is the retention primitive — each adapter implements TTL differently

## Gotchas
- Depends on **`utopia-php/database 5.*`**, so pulling audit pulls the whole DB library and ORM — painful for services that only want to emit events
- `log()` is synchronous — a slow audit DB stalls the request; Appwrite historically hides this behind an events worker, but the library itself offers no buffering
- Schema lives in `setup()` — you must remember to call it on first boot
- Data column is a free-form map but **indexing is only by actor/resource/event/time** — searching inside `data` requires adapter-specific escapes
- **`location` column was dropped** in 2.4 (PR #118). If you used to pass `country` / `location` to `log()`, drop the arg — the only callers reading it should now read `ip` and resolve geographically out-of-band
- **Actor terminology rename** (PR #122, 2.4.0): on-disk columns and indexes are now `actorId` / `actorType` / `actorInternalId` (was `userId` / `userType` / `userInternalId`). The adapter remaps `userId → actorId` on writes and `actorId → userId` on reads so existing callers keep working, but the *index name* changed to `idx_actorId_event` (was `idx_userId_event`). Migration on existing ClickHouse tables requires an `ALTER TABLE … RENAME COLUMN`
- **Resource path is N-part, not 6-part** — the ClickHouse adapter no longer asserts the leading 6 segments; arbitrary depth (`database/X/collection/Y/document/Z/sub/W`) flows through unchanged
- **Slim projection always includes `tenant` when `sharedTables` is on** — `Query::select([...])` cannot exclude the tenant filter from the projection; a SELECT without tenant filtering on a shared table is a cross-tenant read. The ClickHouse adapter rewrites projections to enforce this

## Appwrite leverage opportunities
- **Move Cloud's audit emission off the Database adapter onto ClickHouse** and expose the `cleanup` call via a retention worker; Database adapter doesn't scale past ~100M rows, ClickHouse adapter is production-ready today
- **Introduce an `Adapter\Queue`** (Redis Streams or the existing `utopia-php/queue`) that async-forwards to ClickHouse so the request path only does a Redis `XADD`; the existing ClickHouse adapter becomes the consumer
- **Emit a telemetry `Counter('audit.events.total', tags: {event, resource_type})`** inside `Audit::log()` — audit volume becomes first-class observability without double-writing
- **Stamp `Span::current()?->get('span.trace_id')`** into the `data` map at `log()` time so an audit row links back to the trace that produced it, giving one-click "show me the request for this delete"

## Example
```php
use Utopia\Audit\Audit;
use Utopia\Audit\Adapter\ClickHouse;

$audit = new Audit(new ClickHouse(
    host: 'clickhouse',
    database: 'audit',
    username: 'default',
    password: '',
    port: 8123,
    table: 'audit_logs',
));
$audit->setup();

$audit->log(
    userId: $user->getId(),  // stored as actorId; getActorId() reads it back
    event: 'database.document.delete',
    resource: "database/{$databaseId}/document/{$documentId}",
    userAgent: $request->getUserAgent(),
    ip: $request->getIP(),
    data: ['trace_id' => Span::current()?->get('span.trace_id')],
);
```
