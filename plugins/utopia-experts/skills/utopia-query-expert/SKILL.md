---
name: utopia-query-expert
description: Expert reference for utopia-php/query — the standalone query toolkit shared by every Appwrite-stack service. Ships the serializable `Query` value object, a fluent dialect-aware Builder (MySQL/MariaDB/PostgreSQL/SQLite/ClickHouse/MongoDB) emitting parameterised `Statement`s, a Schema/Table DDL builder, and wire-protocol parsers. Consult when unifying SDK/REST/adapter query shapes, generating SQL across dialects, designing portable DDL, or migrating off the legacy Database\Query.
---

# utopia-php/query Expert

## Purpose
Standalone query toolkit. Three independent surfaces share one repository and PHP 8.4+ baseline:

1. **`Query`** — serializable value object representing a single predicate or modifier (filter/order/pagination/spatial/vector/JSON). Same shape REST APIs and SDKs accept.
2. **`Builder`** — dialect-aware fluent SQL/Mongo generator. Every terminal op (`build()`, `insert()`, `update()`, `delete()`) returns a `Statement` (`->query`, `->bindings`, `->readOnly`).
3. **`Schema\Table`** — DDL builder for create/alter, with dialect-specific extensions (PostgreSQL SERIAL, ClickHouse MergeTree + TTL, composite primary keys, generated columns, partitions, foreign keys, views, procedures, triggers).

Plus wire-protocol parsers (`Parser\SQL`, `MySQL`, `PostgreSQL`, `MongoDB`) that turn driver-level traffic back into `Statement`s for proxy/audit use.

## Public API
- **Query DSL** — `Utopia\Query\Query` static constructors: `equal`, `notEqual`, `greaterThan`/`Equal`, `lessThan`/`Equal`, `between`, `notBetween`, `startsWith`, `endsWith`, `search`, `regex`, `containsString`, `containsAny`, `containsAll`, `notContains`, `isNull`, `isNotNull`, `exists`, `notExists`, `createdAfter`/`updatedBetween` (date helpers), `and`/`or`, `orderAsc`/`Desc`/`Random`, `limit`, `offset`, `cursorAfter`/`Before`, `select`, `distanceLessThan`/`GreaterThan`, `intersects`/`overlaps`/`touches`/`crosses`/`covers`/`spatialEquals`, `vectorDot`/`Cosine`/`Euclidean`, `jsonContains`/`NotContains`/`Overlaps`/`Path`, `raw`. Helpers: `parse`, `parseQueries`, `toString`, `groupByType` → `ParsedQuery` DTO, `getByType`, `merge`, `diff`, `validate`, `page`. (`Query::contains` is deprecated — use `containsString`/`containsAny`.)
- **Method enum** — `Utopia\Query\Method` is the canonical wire-name source. The legacy `TYPE_*` constants on `Query` continue to map to it for back-compat.
- **`Statement`** — `(string $query, array $bindings, bool $readOnly)` returned by every Builder terminal call.
- **`ParsedQuery`** — typed DTO from `Query::groupByType($queries)` with `filters`, `selections`, `aggregations`, `groupBy`, `having`, `joins`, `unions`, `limit`, `offset`, `cursor`, `cursorDirection`, `distinct`. Replaces the old loose array shape.
- **Builder** — `Utopia\Query\Builder` (abstract) + `Utopia\Query\Builder\SQL` (MySQL/MariaDB/PostgreSQL/SQLite share this), and concretes `MySQL`, `MariaDB`, `PostgreSQL`, `SQLite`, `ClickHouse`, `MongoDB`. Fluent surface: `select`, `from`, `filter([Query, …])`, `queries([…])` (batch all), `whereRaw`, `whereColumn`, `having`, `groupBy`, `groupByModifiers`, `joins` / `innerJoin` / `leftJoin` / `crossJoin`, `unions`, `cte`, `window`, `case_`, `lock`, `forUpdate`/`Share`, `transaction`, `explain`, `aggregate*` family, conditional aggregates, string aggregates, sequences (`nextVal`/`currVal` MariaDB+PG), composite-PK upsert, `insert`/`update`/`delete`/`upsert`. Hooks via `Hook::*` for observability/rewriting.
- **Schema** — fluent table builder. Obtain a `Table` via `(new Schema\MySQL())->table('users')`, then chain typed column methods (`id`, `string`, `text`, `mediumText`, `longText`, `integer`, `bigInteger`, `serial`/`bigSerial`/`smallSerial`, `float`, `boolean`, `datetime`, `timestamp`, `json`, `binary`, `enum`, `point`, `linestring`, `polygon`, `vector`, `timestamps()`) — each returns a `Column` exposing modifiers (`nullable`, `default`, `unsigned`, `unique`, `primary`, `autoIncrement`, `after`, `comment`, `collation`, `check`, `generatedAs` + `stored`/`virtual`, `ttl` (ClickHouse), `userType` (PostgreSQL)). Table-level fluent ops: `index()`, `uniqueIndex()`, `fulltextIndex()`, `spatialIndex()`, `foreignKey()`, `primary([...])` (composite PKs), `check()`, `partitionByRange/List/Hash()`, `engine()`, `orderBy()`, `ttl()`, `settings()` (ClickHouse). Terminal `create()`/`createIfNotExists()`/`alter()`/`drop()`/`dropIfExists()`/`truncate()`/`rename()` returns a `Statement`. Per-dialect classes: `Schema\MySQL`, `MariaDB`, `PostgreSQL` (incl. SERIAL types, extensions, custom types, sequences, collations), `SQLite`, `ClickHouse` (10 engines, TTL, skip-index algorithms via `IndexAlgorithm` enum, table-level SETTINGS), `MongoDB`.
- **Parsers** — `Utopia\Query\Parser\{SQL,MySQL,PostgreSQL,MongoDB}` ingest wire-format strings/commands and return a `Statement` plus a `ParsedQuery`-shaped tree for inspection or rewriting.
- **AST** — `Utopia\Query\AST\Walker` with original-node-preserving traversal (returns the same node when no children change — keeps memory churn down on hot paths).

## Core patterns
- **`Query` is dialect-free** — same JSON ships from web/SDK to API to adapter. Translation happens in `Builder` or `Database\Adapter`, not in `Query` itself
- **Logical nesting** uses `Query::and([...])` / `Query::or([...])` (no underscore suffix on `main` — older code referenced `and_`/`or_`; current API drops it)
- **Builder produces parameterised SQL** — every value flows through `bindings`, never string-interpolated. `Statement::$readOnly` lets callers route the query to a read replica without re-parsing
- **MariaDB extends MySQL**, adds `RETURNING`, sequences, dialect-specific spatial; PostgreSQL ships first-class SERIAL column types and the `Schema\PostgreSQL` extensions; ClickHouse Schema covers the MergeTree engine family with TTL and aggregate types; MongoDB Builder emits BSON/JSON command docs, not SQL
- **Strict failure surface** — `Builder` raises `ValidationException` early on illegal use (`OFFSET` without `LIMIT`, invalid join shape, locking mode on a Builder that doesn't support it, `whereRaw`/`whereColumn` on MongoDB). `JsonException`-bearing `QueryException` covers parse/validation
- **`reset()` / hook clearing** — Builder reuse across requests must call `reset()` to clear transient build state and registered hooks; the audit fixed this so re-use is safe

## Gotchas
- **Two `Query` classes still exist in the Appwrite stack** — `Utopia\Query\Query` (this lib, the canonical wire format) and the legacy `Utopia\Database\Query`. Method names are identical, classes are not. Pick one namespace per service; `utopia-php/database` is migrating but still imports the legacy one in many call sites
- **Renamed types** — `Plan` → `Statement`, `GroupedQueries` → `ParsedQuery`, `Blueprint` → `Table`. Anything pinning the old names will not autoload after upgrading
- **Schema builder is fluent-only** — there is no `Table::column($name, $type)` shortcut; you must call the typed method (`->string('name')`, `->bigInteger('id')`, …) or `addColumn(name, ColumnType)` for the dynamic case. Mixing column-level `->primary()` with `Table::primary([...])` throws `ValidationException`. `serial`/`bigSerial`/`smallSerial` throw `UnsupportedException` on ClickHouse and MongoDB
- **ClickHouse skip-index algorithm args render verbatim** — `algorithmArgs` for `IndexAlgorithm::Set` / `BloomFilter` / `NgramBloomFilter` / `TokenBloomFilter` are emitted into DDL without parameter binding. Source them from developer-controlled config only. `MinMax` and `Inverted` reject `algorithmArgs` (`ValidationException`). Engine `SETTINGS` keys must match `[A-Za-z_][A-Za-z0-9_]*` and string values are restricted to `[A-Za-z0-9_.\-+/]*`
- **`Query::contains()` is deprecated** — split into `containsString` (LIKE substring) and `containsAny` (array/relation membership). The two were silently doing different things in the legacy Database\Query
- **`equal` always takes an array** for the values argument (`Query::equal('status', ['active'])`), even for one match. Scalar passes through `IN (?)` on most dialects but the strict ones raise
- **`cursorAfter`/`cursorBefore` are mutually exclusive** — `groupByType` keeps whichever appears later. Pass one
- **MongoDB Builder rejects raw/column predicates** — `whereRaw`/`whereColumn` throw `ValidationException`. Stick to typed predicates when you might switch dialects
- **Identifier quoting and bound parameter shapes are dialect-specific** — never copy a `Statement::$query` between drivers; rebuild via the matching `Builder`
- **Security hardening** is in the codebase but not the wire format — `quote()` rejects control bytes, `JoinBuilder::on()` validates identifiers, `selectWindow` arg allowlist tightened, `extractFirstBsonKey` is bounds-checked. Don't try to bypass these by hand-crafting `whereRaw` strings

## Appwrite leverage opportunities
- **Cross-service query pushdown** — workers that today reconstruct queries from JSON into legacy `Database\Query` should migrate to `Utopia\Query\Query` so the wire format flows unchanged through Redis pub/sub and HTTP without dragging `ext-pdo` into the worker bootstrap
- **SDK generator drives off `Method`** — the canonical method names live here, not in the SDK generator. Parse `Method.php` in the SDK build to keep the 11 SDKs aligned without hand-maintaining 11 enums
- **Adopt the Builder for adapters with non-trivial dialects** — the Database adapters today inline SQL templates. Routing through `Utopia\Query\Builder\PostgreSQL` (etc.) gives parameter binding, identifier quoting, and lock/upsert/CTE support for free, plus identical surface across dialects. Composite PKs (`Schema\Table::primary([...])`) and PostgreSQL SERIAL/ClickHouse MergeTree TTL are now first-class — Cloud's analytics + dedicated DB schemas can stop hand-rolling dialect SQL
- **Wire-protocol parsers** unlock proxy and audit use-cases — `Parser\MySQL`/`PostgreSQL` can parse traffic at the proxy layer (utopia-php/proxy or sidecar-for-sql-api) and rewrite via the AST `Walker` before forwarding. The "preserve unchanged children" perf fix makes this viable in the request path
- **Hooks for observability** — register a `Hook` that writes every `Statement` to `utopia-php/span` as an attribute (`db.statement`, `db.bindings_count`) so SQL ends up in the trace without instrumenting every adapter

## Examples

### Serialise + parse a query
```php
use Utopia\Query\Query;

$queries = [
    Query::equal('status', ['active']),
    Query::greaterThan('createdAt', '2026-01-01'),
    Query::orderDesc('score'),
    Query::limit(25),
    Query::cursorAfter('doc_abc'),
    Query::select(['$id', 'title', 'score']),
];

$wire = array_map(fn (Query $q) => $q->toString(), $queries);
$parsed = Query::parseQueries($wire);

$grouped = Query::groupByType($parsed); // ParsedQuery DTO
$filters = $grouped->filters;
$cursor = $grouped->cursor;
```

### Build dialect-aware SQL
```php
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Query;

$stmt = (new Builder())
    ->select(['id', 'email'])
    ->from('users')
    ->filter([
        Query::equal('status', ['active']),
        Query::greaterThan('age', 18),
    ])
    ->sortAsc('email')
    ->limit(25)
    ->build();

$pdo->prepare($stmt->query)->execute($stmt->bindings);
// $stmt->readOnly === true → safe to route to a read replica
```

### DDL with composite primary key + ClickHouse skip-indexes + SETTINGS
```php
use Utopia\Query\Schema\ClickHouse as Schema;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\ClickHouse\IndexAlgorithm;

$schema = new Schema();

$stmt = $schema->table('events')
    ->string('tenantId')
    ->string('eventId')
    ->string('payload')
    ->datetime('createdAt')
    ->primary(['tenantId', 'eventId'])      // composite PK
    ->engine(Engine::MergeTree)
    ->orderBy(['tenantId', 'createdAt'])
    ->ttl('createdAt + INTERVAL 90 DAY')
    // BloomFilter — high-cardinality lookup column
    ->index(['tenantId'], algorithm: IndexAlgorithm::BloomFilter)
    ->settings(['index_granularity' => 8192, 'allow_nullable_key' => true])
    ->create();
// $stmt->query / $stmt->bindings — same Statement contract
```
