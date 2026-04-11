---
name: utopia-patterns
description: Reference guide for common patterns in the Utopia PHP framework — routing, dependency injection, the Database query layer, and the adapter pattern that runs through every Utopia library. Consult when working on any Appwrite-stack repo that imports utopia-php packages.
---

# Utopia Patterns

Reference for the patterns that repeat across the utopia-php ecosystem.
This isn't a tutorial — it's a cheat sheet for recognizing the idioms
when you see them so edits stay consistent.

## Routing (utopia-php/framework)

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
