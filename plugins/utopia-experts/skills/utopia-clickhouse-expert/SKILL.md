---
name: utopia-clickhouse-expert
description: Expert reference for utopia-php/clickhouse — thin HTTP-interface ClickHouse client tailored for Appwrite audit/usage analytics. Consult when batching inserts, running multi-tenant shared-table ingestion, or debugging addslashes-based parameter escaping.
---

# utopia-php/clickhouse Expert

## Purpose
Thin HTTP-interface ClickHouse client built on `utopia-php/fetch`, tailored for Appwrite audit/usage analytics workloads — provides table/database lifecycle, parameterized queries, namespaced and tenant-scoped reads, and TabSeparated row parsing via a self-managed `_metadata` table.

## Public API
- `Utopia\ClickHouse\ClickHouse` — constructor `(host, username, password, port=8123, secure=false)`
- `create(name)`, `drop(name)`, `exists(name): bool`, `setDatabase(name)`
- `createTable(name, columns, indexes, engine='MergeTree()', orderBy='id', partitionBy=null)`
- `getRow(table, id): ?array`, `find(table, filters, orderBy, limit, offset): array`
- Multi-tenancy toggles: `setNamespace()`, `setTenant()`, `setSharedTables()`, `isSharedTables()`
- Private `query(sql, params, applyTenantFilter=true)` — parameter interpolation + tenant WHERE injection

## Core patterns
- **HTTP over port 8123** using `X-ClickHouse-User/Key/Database` headers — no TCP native protocol, no persistent connection. Every `query()` creates a fresh `Fetch\Client`
- **Parameter binding is string interpolation** (`str_replace(":key", escaped_value, $sql)`) — not real server-side parameters. Escaping is `addslashes()` which handles `'` and `\` but not SQL-level edge cases
- **Self-hosted `_metadata` table in every database** storing column ordering, indexes, shared flag, and `_tenant` — because `system.columns` is shared across tenants in shared-table mode. Column order is read from here when parsing TSV rows
- **Automatic tenant WHERE clause injection** via `applyTenantFilter()` — detects existing WHERE (case-insensitive) and ANDs the condition; strips and re-appends any trailing `FORMAT ...` clause
- **Meta columns (`_id`, `_createdAt`, `_updatedAt`, optionally `_tenant`)** are auto-added to every `createTable` call

## Gotchas
- **`addslashes()` is the only escaping layer** — vulnerable to injection via UTF-8 multibyte edge cases and ClickHouse-specific escape sequences. Never pass user-supplied table/column names through; only pass values via `:param` placeholders
- **`setDatabase()` must be called before any operation that isn't `create(dbName)`** — `$this->database` defaults to empty string and the HTTP client sends an empty `X-ClickHouse-Database` header which ClickHouse rejects
- **`find()` returns a hardcoded audit-schema column list** (`_id as id, userId, event, resource, userAgent, ip, location, time, data`) — the library name says "ClickHouse client" but the SELECT was extracted from the Appwrite audit adapter. Unusable for non-audit tables as-is
- **30-second fetch timeout hardcoded**; no connection pooling, no retries. High-volume ingestion needs batching at the caller level
- No TLS cert verification toggle exposed — `secure: true` uses `fetch` defaults which typically verify

## Appwrite leverage opportunities
- **Replace MariaDB-backed `audits` table with ClickHouse** via this adapter — audit logs are write-heavy, rarely updated, analytical-queried (by userId/resource/time range). ClickHouse MergeTree with time partitioning gives 10-100× storage compression
- **Batch usage metric writes**: aggregate per-minute counters in a Redis hash, then `INSERT ... VALUES (...),(...),(...)` at window close — ClickHouse is built for bulk inserts, single-row inserts are an anti-pattern. Write a `batchInsert(table, rows)` helper on top
- **Use `_tenant` shared-table mode with `setSharedTables(true)`** for Appwrite Cloud's multi-tenant project isolation — one physical table per metric type, tenant filter auto-applied. Saves schema management across 100k+ projects
- **`find()` needs a rewrite**: remove the audit-column hardcode so usage/realtime/function-logs can all share the same client

## Example
```php
use Utopia\ClickHouse\ClickHouse;

$clickhouse = new ClickHouse(host: 'clickhouse', username: 'appwrite', password: $password);
$clickhouse->setDatabase('appwrite')->setNamespace('audit')->setTenant($projectIntId)->setSharedTables(true);

if (!$clickhouse->exists('appwrite')) {
    $clickhouse->create('appwrite');
}
$clickhouse->createTable(
    name: 'logs',
    columns: ['userId' => 'String', 'event' => 'String', 'time' => 'DateTime64(3)', 'data' => 'String'],
    indexes: ['idx_user' => ['userId']],
    engine: 'MergeTree()',
    orderBy: 'time',
    partitionBy: 'toYYYYMM(time)',
);
$rows = $clickhouse->find('logs', filters: ['userId' => $userId], orderBy: ['time' => 'DESC'], limit: 50);
```
