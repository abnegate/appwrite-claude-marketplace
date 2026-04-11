---
name: utopia-patterns
description: Cross-cutting cheat sheet for the idioms that repeat across the Utopia PHP ecosystem — routing, DI, Database queries, adapters, pools, validators, events, SDK codegen. Consult when working on any Appwrite-stack repo that imports utopia-php packages. For deep per-library detail, consult the matching `utopia-<library>-expert` skill instead.
---

# Utopia Patterns

Reference for the patterns that repeat across the utopia-php ecosystem.
This isn't a tutorial — it's a cheat sheet for recognizing the idioms
when you see them so edits stay consistent.

> **Want deeper detail on a specific library?** This skill is
> intentionally cross-cutting. For a full reference on any individual
> `utopia-php/*` library — public API surface, gotchas, and Appwrite
> leverage opportunities — consult the matching `utopia-<library>-expert`
> skill (e.g. `utopia-http-expert`, `utopia-database-expert`,
> `utopia-pools-expert`, `utopia-validators-expert`). There are 50 such
> expert skills, one per library in the utopia-php org. Use this
> `utopia-patterns` skill for the big-picture idioms; drop into the
> per-library experts when you need surface area, signatures, or
> library-specific footguns.

## Routing (utopia-php/framework)

*→ Deep dive: `utopia-http-expert` for the full framework API, hooks,
telemetry wiring, and FPM/Swoole adapters.*

Routes are declared on an `App` instance with fluent chaining:

```php
App::get('/v1/collections/:collectionId')
    ->desc('Get collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getCollection')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $collectionId, Response $response, Database $dbForProject) {
        // ...
    });
```

Key points:
- **`->label()`** is how every metadata concern gets attached — scopes,
  SDK codegen hints, auth requirements, rate limits. New metadata is
  added as a label, not a new method.
- **`->inject()`** pulls dependencies from the DI container by name. The
  string must match a `App::setResource()` registration elsewhere.
- **`->param()`** declares the request parameter with a validator. The
  validator is a `Utopia\Validator` subclass.
- **`->action(...)`** is the handler. First-class callable syntax:
  `->action($this->handleRequest(...))` is preferred when the action
  lives on a class.

## Dependency injection (App::setResource)

*→ Deep dive: `utopia-di-expert` for the PSR-11 container, parent-child
scoping, and the lazy-singleton + request-scope pattern.*

```php
App::setResource('dbForProject', function (Document $project, Cache $cache, Connection $connection) {
    $database = new Database(new MySQL($connection), $cache);
    $database->setNamespace('_' . $project->getInternalId());
    return $database;
}, ['project', 'cache', 'dbConnection']);
```

- First arg: resource name (what you `->inject()`).
- Second arg: factory closure. The arguments are the **other** resources
  this one depends on, resolved lazily.
- Third arg: array of dependency names matching the closure's
  parameters in order.
- Resources are resolved per-request (per-coroutine in Swoole) by
  default. Use `setResource(..., fresh: false)` for singletons.

## Database query layer (utopia-php/database)

*→ Deep dive: `utopia-database-expert` for adapters, permissions, the
filter chain, transactions, `Mirror`, and cache invalidation semantics.
For the standalone backend-agnostic query DSL that's been extracted
into its own library, also see `utopia-query-expert`.*

### Queries

```php
$documents = $database->find('collections', [
    Query::equal('status', ['active']),
    Query::greaterThan('createdAt', $cutoff),
    Query::orderDesc('createdAt'),
    Query::limit(25),
]);
```

- All query builders are static factory methods on `Utopia\Database\Query`.
- Multiple filters on the same attribute are ANDed.
- `Query::select([...])` projects columns — use it when you don't need
  the whole document.
- `Query::cursorAfter($lastDoc)` for pagination, not offset. Offsets
  don't scale.

### Attributes

```php
$database->createAttribute(
    collection: 'books',
    id: 'title',
    type: Database::VAR_STRING,
    size: 256,
    required: true,
);
```

- `VAR_STRING`, `VAR_INTEGER`, `VAR_FLOAT`, `VAR_BOOLEAN`, `VAR_DATETIME`,
  `VAR_RELATIONSHIP`. No other types.
- `size` is always an `int`, not a string — even for big integers,
  where some legacy code passes strings. Fix both.
- **Sparse updates**: pass only the changed attributes to
  `$database->updateDocument()`, not the full document. The adapter
  diffs and only writes what moved.

### Adapters

Every adapter extends `Utopia\Database\Adapter` (PostgreSQL, MySQL,
MongoDB, SQLite, MariaDB). The base class defines the public interface;
adapters override the SQL-specific pieces:

- `getSQLSchema()`, `getSQLColumn()`, `getSQLIndex()` — shape the DDL.
- `filter()`, `transform()` — shape the runtime values going in/out.
- **Ignore mode** (`$ignore = true` on `createDocuments`) is
  adapter-specific. MySQL uses `INSERT IGNORE`, Postgres uses
  `ON CONFLICT DO NOTHING`, Mongo passes `ordered: false` with
  duplicate-key error suppression. Each adapter has its own bug surface
  here.

## Pools (utopia-php/pools)

*→ Deep dive: `utopia-pools-expert` for `Pool<TResource>`, reclaim/retry/
reconnect semantics, the `Group::use()` scoped borrow idiom, telemetry
gauges, and sizing heuristics.*

```php
$pool = new Pool('name', size: 64, init: fn () => new Connection(...));
$resource = $pool->pop();
try {
    // use $resource
} finally {
    $pool->push($resource);
}
```

- Pools wrap any resource that's expensive to create: DB connections,
  Redis handles, HTTP clients.
- **Per-worker.** Never share a pool across Swoole workers. Initialize
  in `onWorkerStart`.
- `pop()` blocks if the pool is empty. A starving pool means either
  (a) the size is too small for concurrent load, or (b) code isn't
  pushing resources back (leak).

## Validators (utopia-php/framework)

*→ Deep dive: `utopia-validators-expert` for the full validator
catalogue, composition (`Multiple`, `Nullable`, `AllOf`, `AnyOf`,
`NoneOf`), and the SDK type-mapping gotchas.*

```php
use Utopia\Validator\UID;
use Utopia\Validator\Text;
use Utopia\Validator\Range;

->param('collectionId', '', new UID(), 'Collection ID.')
->param('name', '', new Text(128), 'Name.', optional: true)
->param('limit', 25, new Range(1, 100), 'Limit.', optional: true)
```

- Validators are stateless classes implementing `Utopia\Validator`.
- `Text`, `UID`, `Range`, `Numeric`, `Boolean`, `WhiteList`,
  `ArrayList`, `JSON`, `Email`, `URL`, `Domain`, `IP`, `Host` are the
  standard ones. Custom validators subclass `Validator` and implement
  `isValid($value)`, `getDescription()`, and `getType()`.
- The description string gets embedded in the OpenAPI spec that
  `sdk-generator` consumes — keep it human-readable and action-oriented.

## Events and queues (utopia-php/queue, utopia-php/pubsub)

*→ Deep dive: `utopia-queue-expert` for the Redis/AMQP brokers, the
`Commit`/`NoCommit`/`Retryable` ack semantics, DI-driven job handlers,
and priority-tier patterns. For outbound delivery (SMS/push/email),
see `utopia-messaging-expert`.*

```php
$queue->enqueue(new Message('events-queue', [
    'event' => 'databases.*.collections.*.documents.*.create',
    'project' => $project->getArrayCopy(),
    'payload' => $document->getArrayCopy(),
]));
```

- Messages go into named queues; workers consume them in
  `onMessage` handlers.
- **Event naming** follows the Appwrite event taxonomy:
  `<service>.*.<resource>.*.<action>`. Wildcards at each level allow
  subscribers to match any value.
- Publishers vs Workers: publishers enqueue, workers dequeue. A worker
  can publish new events. The `publisherForStatsResources` pattern
  bundles related resources under a single publisher for throughput.

## SDK codegen (utopia-php/sdk-generator)

*Note: `utopia-php/sdk-generator` is the SDK generation tool itself —
it consumes labels from `utopia-http-expert` routes and validators from
`utopia-validators-expert` to produce client libraries. There's no
standalone `sdk-generator-expert` skill because the tool is usually
driven from outside via `composer update`; if you need to hack on its
templates, clone the repo directly.*

When you change an endpoint:
1. The `->label('sdk.method', ...)` / `->label('sdk.namespace', ...)`
   determine where the generated SDK method goes.
2. The `->param()` validators determine the parameter types.
3. The `->label('sdk.response.model', ...)` determines the return type.
4. Run `composer update utopia-php/sdk-generator` in the consuming repo
   after a generator fix — the generator version is pinned in
   `composer.json` with the `*` wildcard, so an update pulls the latest
   published build.
5. The `*` wildcard means you never edit version strings in
   `composer.json` for SDK bumps — just `composer update`.

## Deep-dive map

When you need more than the cross-cutting view above, jump to the
per-library expert skill. Each one has public API surface, core
patterns, gotchas the docs don't mention, and "Appwrite leverage
opportunities" — specific suggestions for extracting more value.

| Section in this skill      | Deep-dive skill                |
|----------------------------|--------------------------------|
| Routing                    | `utopia-http-expert`           |
| DI / `setResource`         | `utopia-di-expert`             |
| Static hook registries     | `utopia-servers-expert`        |
| Action/Service/Module      | `utopia-platform-expert`       |
| Database query layer       | `utopia-database-expert`       |
| Standalone query DSL       | `utopia-query-expert`          |
| Mongo wire-protocol client | `utopia-mongo-expert`          |
| DSN parsing                | `utopia-dsn-expert`            |
| Pools                      | `utopia-pools-expert`          |
| Cache                      | `utopia-cache-expert`          |
| Storage devices            | `utopia-storage-expert`        |
| Fetch / HTTP client        | `utopia-fetch-expert`          |
| Validators                 | `utopia-validators-expert`     |
| Auth hashes/proofs         | `utopia-auth-expert`           |
| JWT                        | `utopia-jwt-expert`            |
| Abuse / rate limiting      | `utopia-abuse-expert`          |
| WAF rule engine            | `utopia-waf-expert`            |
| CLI tasks                  | `utopia-cli-expert`            |
| Container orchestration    | `utopia-orchestration-expert`  |
| Proxy (HTTP/TCP/SMTP)      | `utopia-proxy-expert`          |
| Logger                     | `utopia-logger-expert`         |
| Telemetry (OTel metrics)   | `utopia-telemetry-expert`      |
| Audit log                  | `utopia-audit-expert`          |
| Distributed tracing        | `utopia-span-expert`           |
| Messaging (Email/SMS/Push) | `utopia-messaging-expert`      |
| Queue / workers            | `utopia-queue-expert`          |
| WebSocket                  | `utopia-websocket-expert`      |
| Async (Promise + Parallel) | `utopia-async-expert`          |
| Emails parser/classifier   | `utopia-emails-expert`         |
| Payments                   | `utopia-pay-expert`            |
| VCS (GitHub/GitLab/…)      | `utopia-vcs-expert`            |
| Domains / registrars       | `utopia-domains-expert`        |
| DNS server toolkit         | `utopia-dns-expert`            |
| Locale / i18n              | `utopia-locale-expert`         |
| A/B testing                | `utopia-ab-expert`             |
| Registry (lazy DI)         | `utopia-registry-expert`       |
| Project env detector       | `utopia-detector-expert`       |
| Image manipulation         | `utopia-image-expert`          |
| AI agents                  | `utopia-agents-expert`         |
| CLI console helpers        | `utopia-console-expert`        |
| CloudEvents envelope       | `utopia-cloudevents-expert`    |
| ClickHouse client          | `utopia-clickhouse-expert`     |
| Load balancer              | `utopia-balancer-expert`       |
| Usage metering (STUB)      | `utopia-usage-expert`          |
| Config DTOs                | `utopia-config-expert`         |
| Compression                | `utopia-compression-expert`    |
| Migration engine           | `utopia-migration-expert`      |
| System metrics             | `utopia-system-expert`         |
| Preloader                  | `utopia-preloader-expert`      |
| Analytics fanout           | `utopia-analytics-expert`      |

For Swoole-specific detail (not a utopia-php library, but the runtime
everything above sits on), consult the `swoole-expert` skill.
