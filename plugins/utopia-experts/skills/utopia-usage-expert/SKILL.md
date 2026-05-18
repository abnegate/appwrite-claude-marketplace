---
name: utopia-usage-expert
description: Expert reference for utopia-php/usage — two-table (events + gauges) metering library with ClickHouse and Database adapters, in-memory buffering, query-time aggregation, and a daily SummingMergeTree materialised view. Consult when wiring per-project usage capture, sizing flush thresholds, querying daily/hourly time series, or composing with utopia-php/query.
---

# utopia-php/usage Expert

## Purpose
Lightweight metering library for capturing application usage. Two tables: **events** (additive metrics — bandwidth, requests — summed at query time) and **gauges** (point-in-time snapshots — storage size, user count — last-write-wins, argMax at query time). Pluggable adapters; production target is ClickHouse with a daily SummingMergeTree materialised view, with a Database adapter for development. Multi-tenant via `(tenant, metric, time, id)` keying so range scans stay cheap.

## Public API
- `Utopia\Usage\Usage` — facade:
  - constants: `TYPE_EVENT = 'event'`, `TYPE_GAUGE = 'gauge'`
  - lifecycle: `__construct(Adapter $adapter)`, `setup()`, `healthCheck()`
  - capture: `collect(string $metric, int $value, string $type, array $tags = [])` — buffers in memory; events are summed by `metric:type:md5(tags)` key, gauges are last-write-wins. Negative `value` and unknown `$type` throw `InvalidArgumentException`
  - flush: `flush()`, `shouldFlush()`, `setFlushThreshold(int)`, `setFlushInterval(int)`, `getFlushThreshold`/`getFlushInterval`/`getBufferCount`/`getBufferSize`. Default thresholds: 10 000 entries or 20 seconds
  - direct writes: `addBatch(array $metrics, string $type, int $batchSize = 1000)` for callers that already buffer elsewhere
  - reads: `getTimeSeries(metrics, '1h'|'1d', startDate, endDate, queries, zeroFill = true, ?type)`, `getTotal`, `getTotalBatch`, `find($queries, ?$type)`, `count`, `sum($queries, $attribute = 'value', $type = TYPE_EVENT)`, `findDaily`, `sumDaily`, `sumDailyBatch`, `purge`
  - multi-tenancy: `setNamespace(string)`, `setTenant(?string)`, `setSharedTables(bool)` — declared on `Usage` but lives on the concrete adapter (refactored out of the abstract `Adapter` base, so test adapters and the Database adapter no longer have to no-op these)
- `Utopia\Usage\Adapter` — abstract; declares `getName`, `healthCheck`, `setup`, `addBatch`, `getTimeSeries`, `getTotal`, `getTotalBatch`, `purge`, `find`, `count`, `sum`, `findDaily`, `sumDaily`, `sumDailyBatch`
- `Utopia\Usage\Adapter\ClickHouse(host, username, password, port = 8123, secure = false)` — production. Knobs: `setTimeout(ms)`, `setCompression(bool)`, `setKeepAlive(bool)`, `setMaxRetries(int)`, `setRetryDelay(ms)`, `setAsyncInserts(bool, $waitForConfirmation = true)`, `enableQueryLogging(bool)` (use `getQueryLog/clearQueryLog` for tests). `LowCardinality(String)` for `country`; bloom filter indexes on all event columns; daily SummingMergeTree materialised view for fast billing queries
- `Utopia\Usage\Adapter\Database` — Utopia\Database-backed dev/testing adapter (same surface, missing the ClickHouse-only query knobs)
- `Utopia\Usage\Adapter\SQL` — shared parent for the Database adapter
- `Utopia\Usage\Metric` — value object
- `Utopia\Usage\UsageQuery` — light wrapper around `Utopia\Query\Query` for the read methods

## Core patterns
- **Two tables, one library** — additive metrics go to `events` (request-level, with dedicated columns for `path`/`method`/`status`/`resource`/`country`/`userAgent` extracted out of `tags` so they're indexable); snapshots go to `gauges`. Aggregation rules are baked in: events SUM, gauges argMax
- **Query-time aggregation** — there is no write-time period fan-out. `getTimeSeries` groups by `1h`/`1d` at query time and `zeroFill` synthesises empty intervals so charts have continuous x-axes
- **Per-tenant time-range scans are fast** because the table is keyed `(tenant, metric, time, id)`; without `tenant` you'd full-scan a global metric history per query
- **Buffer is mtime + count gated** — `shouldFlush()` returns true when either threshold trips. Workers should call `flush()` themselves; the buffer never auto-flushes on `collect()`
- **Daily MV is the billing path** — `findDaily`/`sumDaily`/`sumDailyBatch` hit the pre-aggregated `*_daily` SummingMergeTree, ~100× cheaper than the same query against `events`
- **Adapters take `Utopia\Query\Query[]`** for filters — the same Query DTOs that flow through utopia-php/database and utopia-php/audit, so a single Query builder can target all three observability stores

## Gotchas
- **`collect()` validates value ≥ 0** — for decrements (e.g. user offboarding), record a separate `users.deleted` event and subtract at query time
- **`tags` are hashed via `md5(json_encode($tags))`** — non-deterministic key ordering will fragment your buffer. Pass associative arrays with stable ordering (or sort keys) before calling `collect`
- **Partial flush leaves the buffer non-empty** — `flush()` keeps unwritten entries on `addBatch` failure for retry, but if one batch type (events) flushes and the other (gauges) throws, the events are gone and the exception still propagates. Catch + log at the caller
- **ClickHouse async inserts ack before commit** — `setAsyncInserts(true, waitForConfirmation: false)` returns to the caller before the row is durable. Acceptable for analytics, not for billing
- **Database adapter is dev-only** at scale — schema and queries work, but MariaDB/MySQL chokes on `(tenant, metric, time, id)` range scans past ~10M rows. The `INSERT_BATCH_SIZE` default (1000) is chosen for ClickHouse, not InnoDB
- **`utopia-php/fetch ^1.1`** is the HTTP transport for the ClickHouse adapter — no PECL ClickHouse extension required, just curl

## Appwrite leverage opportunities
- **Replace `bin/worker-usage` + `app/tasks/usage.php`** in Appwrite: per-request `Usage::collect('network.requests', 1, TYPE_EVENT, ['projectId' => …, 'region' => …])` from a shutdown hook, daily rollup served from the MV. Keeps the request hot-path doing one in-memory write
- **Storage gauges via cron**: `Usage::collect('storage.bytes', $size, TYPE_GAUGE, ['projectId' => …])` from the Deletes worker after retention sweeps; `findDaily`/`sumDailyBatch` then drives the console "storage" pane without a re-aggregation pass
- **Pair with `utopia-php/cloudevents`** — wrap each `collect()` call in a CloudEvent envelope before it leaves the producer (HTTP API, webhook hop) so the same row carries a stable producer-side identity end-to-end
- **Cross-correlate with audit and span**: stamp `Span::current()?->get('span.trace_id')` into `tags` so a usage spike has a one-click path to the trace that produced it
- **`enableQueryLogging`** in CI fixtures so a regression in `getTimeSeries` shows up as a diff on the captured query, not as a silent perf cliff

## Example
```php
use Utopia\Usage\Usage;
use Utopia\Usage\Adapter\ClickHouse;
use Utopia\Query\Query;

$adapter = new ClickHouse(host: 'clickhouse', username: 'default', password: '');
$adapter->setNamespace('appwrite');
$adapter->setSharedTables(true);
$adapter->setTenant('project_abc');

$usage = new Usage($adapter);
$usage->setup();
$usage->setFlushThreshold(5_000);
$usage->setFlushInterval(10);

// Hot path — buffered.
$usage->collect('network.requests', 1, Usage::TYPE_EVENT, [
    'path' => '/v1/databases/.../documents',
    'method' => 'POST',
    'status' => 201,
    'country' => 'DE',
]);

$usage->collect('storage.bytes', $totalSize, Usage::TYPE_GAUGE, [
    'bucket' => $bucketId,
]);

if ($usage->shouldFlush()) {
    $usage->flush();
}

// Billing query — uses the daily SummingMergeTree MV.
$daily = $usage->sumDailyBatch(
    metrics: ['network.requests'],
    queries: [
        Query::greaterThanEqual('time', '2026-05-01 00:00:00'),
        Query::lessThan('time', '2026-06-01 00:00:00'),
    ],
);
```
