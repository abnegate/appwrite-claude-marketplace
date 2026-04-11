---
name: utopia-audit-expert
description: Expert reference for utopia-php/audit — user action/audit log store with Database and ClickHouse adapters. Consult when wiring retention, moving audit off MySQL, or adding trace correlation to audit rows.
---

# utopia-php/audit Expert

## Purpose
User action/audit log store with pluggable adapters; records who did what to which resource with time/IP/user-agent/location/free-form data and supports filtered retrieval.

## Public API
- `Utopia\Audit\Audit` — facade (`log`, `logBatch`, `getLogsByUser`, `getLogsByResource`, `getLogsByUserAndEvents`, `getLogsByResourceAndEvents`, `countLogs*`, `cleanup`)
- `Utopia\Audit\Adapter` — abstract contract with ten `get*/count*/create*/cleanup/setup` methods
- `Utopia\Audit\Log` — log entity
- `Utopia\Audit\Query` — filter builder
- `Adapter\Database` — default, over `utopia-php/database`
- `Adapter\ClickHouse` — HTTP interface, monthly partitioning, bloom filter indexes, 8123 default
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
- Data column is a free-form map but **indexing is only by user/resource/event/time** — searching inside `data` requires adapter-specific escapes

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
    userId: $user->getId(),
    event: 'database.document.delete',
    resource: "database/{$databaseId}/document/{$documentId}",
    userAgent: $request->getUserAgent(),
    ip: $request->getIP(),
    location: $request->getCountry(),
    data: ['trace_id' => Span::current()?->get('span.trace_id')],
);
```
