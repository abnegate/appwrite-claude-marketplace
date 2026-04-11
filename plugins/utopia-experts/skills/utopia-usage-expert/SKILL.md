---
name: utopia-usage-expert
description: Expert reference for utopia-php/usage — currently a STUB repository with no src. The active rebuild lives on claude/rebuild-analytics-clickhouse-OHWGZ. Consult before depending on this library in production.
---

# utopia-php/usage Expert

## Purpose
**Stub repository.** Nominally intended as a lightweight usage/metering library (composer description: "Light and Fast Usage library"), but as of the current `main` branch there is **no source code** — only `composer.json`, `composer.lock`, and `.gitignore`. No `src/` directory, no classes, no tags, no published Packagist version.

## Public API
**None exists yet.** Declared dependencies give the intent:
- `utopia-php/fetch ^0.4.2` — implies it will POST metric events over HTTP (likely to ClickHouse or a stats collector)
- `utopia-php/database ^4.3` — implies it will read/write aggregated counters via the Utopia database adapter, probably to persist daily/hourly rollups

An in-progress branch `claude/rebuild-analytics-clickhouse-OHWGZ` suggests a ClickHouse-backed analytics rewrite is underway.

## Core patterns (inferred from deps + branch name)
- Combines hot-path ingestion (fetch → ClickHouse) with cold-path aggregation (database adapter → MariaDB/Postgres for the UI)
- Likely mirrors the pattern in Appwrite's existing `app/tasks/usage.php` — bucketed counters keyed by `projectId + metric + period`
- Expect the rebuild to expose something like a `Collector` or `Aggregator` class with `add(metric, value)` and `flush()` semantics

## Gotchas
- **Do not depend on this package in production.** No tags, no Packagist releases, empty `main`. Pinning `utopia-php/usage: *` in another repo's composer.json will pull HEAD of `main` which is currently just metadata
- The dependency versions are very old (`database ^4.3` vs Appwrite's current line) — this manifest was likely scaffolded long ago and abandoned until the current rebuild
- The `dev` branch exists separately from `main` — check both before assuming "nothing here."

## Appwrite leverage opportunities
- **Watch the rebuild branch.** Once `claude/rebuild-analytics-clickhouse-OHWGZ` lands, this is the library slot Appwrite will use to replace the in-tree `app/controllers/shared/api.php` stats collector and `bin/worker-usage`. Plan for it by decoupling usage recording from controllers now (use a `Stats` service interface that can be swapped)
- **The ClickHouse direction is consistent with `utopia-php/clickhouse`** — the two packages are designed to compose: `Usage` ingests events, writes TSV batches to `ClickHouse` via `Fetch`, and exposes aggregation queries backed by MergeTree
- **Pair with `utopia-php/cloudevents`**: wrap each recorded metric in a CloudEvent envelope, standardizing the producer side. CloudEvents + ClickHouse + (future) usage library form a natural pipeline
- **Until then**: do not attempt to use this package. Keep the existing in-tree usage code, but structure new metric additions so the migration path is mechanical (a single `Stats::record($metric, $value, $dimensions)` call site)

## Example
**Not applicable** — no public API exists. When the rebuild lands, expect something along these lines (speculative only, do not copy into real code):
```php
// SPECULATIVE — library has no source yet
use Utopia\Usage\Collector;

$collector = new Collector($clickhouse, $database);
$collector->record('network.requests', 1, ['projectId' => $projectId, 'region' => 'fra']);
$collector->record('storage.bytes', $fileSize, ['projectId' => $projectId]);
$collector->flush();
```
