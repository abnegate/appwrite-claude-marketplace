---
name: utopia-query-expert
description: Expert reference for utopia-php/query — the standalone, backend-agnostic, serializable query DSL extracted from utopia-php/database. Consult when unifying SDK/REST/adapter query shapes or sharing queries across services.
---

# utopia-php/query Expert

## Purpose
Backend-agnostic, serializable query DSL (filter/order/pagination/spatial/vector) extracted from `utopia-php/database` — one canonical shape that REST controllers, SDKs, and adapters all share.

## Public API
- `Utopia\Query\Query` — fluent static constructors (`equal`, `greaterThan`, `between`, `startsWith`, `contains`, `isNull`, `exists`, `and_`, `or_`, `orderAsc`, `limit`, `offset`, `cursorAfter`, `select`, `distanceLessThan`, `intersects`, `vectorCosine`, etc.) plus `parse`, `parseQueries`, `toString`, `groupByType`, `getByType`, `getMethod`, `getAttribute`, `getValues`
- `Utopia\Query\Exception` — single `QueryException` for parse / validation errors, wrapping `JsonException`
- `TYPE_*` constants (`TYPE_EQUAL`, `TYPE_VECTOR_DOT`, `TYPE_CURSOR_AFTER`, `TYPE_ELEM_MATCH`, …) — the canonical wire names
- `Query::DEFAULT_ALIAS = 'main'`, `ORDER_ASC`, `ORDER_DESC` — constants inlined so `Query` has zero dependencies

## Core patterns
- Every constructor returns a fresh immutable-ish `Query` — chain into arrays, don't mutate. Serialization is JSON: `$query->toString()` emits `{"method":"equal","attribute":"status","values":["active"]}`, `Query::parse($json)` inverts
- **Logical nesting**: `Query::and_([...])` / `Query::or_([...])` wrap child queries; `_` suffix avoids PHP reserved word collision
- **Adapter translation flow**: `Query::groupByType($queries)` splits into `filters / selections / limit / offset / orderAttributes / orderTypes / cursor / cursorDirection` buckets, then translate each independently
- **Zero runtime dependencies** (only PHP 8.4+), `phpstan --level max` clean — safe to import in SDK builders, console code, validators, and workers alike

## Gotchas
- `equal` **always takes an array** of values (`Query::equal('status', ['active'])`), even for a single match. Passing a scalar silently becomes `IN (scalar)` in most adapters but throws on strict ones
- `Query::parse` accepts any valid JSON shape; unknown `method` values fail only at **adapter translation time**, not parse time. Validate with `Validator\Queries` from `utopia-php/database` if the input is untrusted
- There are **two** `Query` classes in the Appwrite stack — `Utopia\Query\Query` (new, standalone) and `Utopia\Database\Query` (legacy, inside `utopia-php/database`). Constants are identical but classes are not interchangeable for type hints. Pick one namespace per service
- `cursorAfter` / `cursorBefore` are exclusive — `groupByType` returns a single `cursor` string, so passing both in the same query silently keeps whichever comes last

## Appwrite leverage opportunities
- **SDK generation**: the `TYPE_*` constants are the only place these method names live as a single source of truth — the Appwrite SDK generators still hand-maintain duplicate enums. Parse `Query.php` in the SDK build to auto-generate `Query` helpers across all 11 SDKs
- **Cross-service query pushdown**: workers currently reconstruct queries from JSON into the legacy `Database\Query`. Migrating realtime/functions/messaging to `utopia-php/query` gives a zero-dependency wire format that can flow through Redis pub/sub without pulling in `ext-pdo`
- **Static validator**: `phpstan --level max` is clean, so a custom PHPStan rule can verify `Query::equal('foo', ...)` references an actual attribute on the target collection at compile time — catch typos in 400+ query call-sites

## Example
```php
use Utopia\Query\Query;

$queries = [
    Query::equal('status', ['active']),
    Query::greaterThan('createdAt', '2025-01-01'),
    Query::orderDesc('score'),
    Query::limit(25),
    Query::cursorAfter('doc_abc'),
    Query::select(['$id', 'title', 'score']),
];

$wire = array_map(fn (Query $q) => $q->toString(), $queries);
$parsed = Query::parseQueries($wire);

$grouped = Query::groupByType($parsed);
$filters = $grouped['filters'];
$cursor = $grouped['cursor'];
$order = array_combine($grouped['orderAttributes'], $grouped['orderTypes']);
```
