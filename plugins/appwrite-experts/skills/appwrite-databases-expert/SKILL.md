---
name: appwrite-databases-expert
description: Databases module covering DocumentsDB and TablesDB — collections/tables, attributes/columns, documents/rows, queries, indexes, relationships, and the permission model. The largest Appwrite domain (228 actions).
---

# Appwrite Databases Expert

## Module structure

`src/Appwrite/Platform/Modules/Databases/` — 3 services, 228 HTTP actions.

Key files:
- `Services/Http.php` — route registration (all database HTTP actions)
- `Workers/Databases.php` — async schema operations (attribute/index creation)
- `Http/` — action classes organized by entity

## Database types

| Type | Entity names | API style | Status |
|---|---|---|---|
| `documentsdb` | collection / attribute / document | NoSQL-like, flexible schema | Stable |
| `tablesdb` | table / column / row | SQL-like, strict schema | Stable |
| `vectorsdb` | — | Vector search | Not yet implemented |
| `dedicateddb` | table / column / row | Managed external DB | Cloud only |

The polymorph API uses `useDatabaseSdk()` and `useTerminology()` to abstract the type. Routes are shared — the database type determines behavior.

## Route anatomy

All database routes live under `/v1/databases/{databaseId}/...`. The typical pattern:

```php
Http::post('/v1/databases/{databaseId}/collections')
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].create')
    ->label('audits.resource', 'database/{request.databaseId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'collections',
        name: 'createCollection',
        // ...
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new CustomId(), 'Unique ID.')
    ->param('name', '', new Text(128), 'Collection name.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'Permissions array.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (...) { /* ... */ });
```

## Schema operations (async)

Attribute and index creation is async — the route enqueues a job, the Databases worker processes it:

1. Route creates attribute/index document with `status: 'processing'`
2. Enqueues to `v1-databases` (or `database_db_main`) queue
3. `Workers/Databases.php` picks it up, runs the DDL
4. Updates status to `available` or `failed`
5. Fires realtime event for status change

Worker type router (`Workers/Databases.php:80-88`):
- `DATABASE_TYPE_CREATE_ATTRIBUTE` / `DELETE_ATTRIBUTE`
- `DATABASE_TYPE_CREATE_INDEX` / `DELETE_INDEX`
- `DATABASE_TYPE_DELETE_COLLECTION` / `DELETE_DATABASE`

## Query system

Queries use the `utopia-php/database` Query class:

```php
$documents = $dbForProject->find('collection_id', [
    Query::equal('status', ['active']),
    Query::greaterThan('score', 100),
    Query::orderDesc('createdAt'),
    Query::limit(25),
    Query::offset(0),
]);
```

Available query methods: `equal`, `notEqual`, `greaterThan`, `greaterThanEqual`, `lessThan`, `lessThanEqual`, `between`, `isNull`, `isNotNull`, `contains`, `search`, `startsWith`, `endsWith`, `select`, `orderAsc`, `orderDesc`, `limit`, `offset`, `cursorAfter`, `cursorBefore`, `or`, `and`.

## Permission model

Every document has a `$permissions` array:

```php
[
    Permission::read(Role::any()),           // Anyone can read
    Permission::read(Role::user('abc')),     // Specific user
    Permission::create(Role::team('xyz')),   // Team members can create
    Permission::update(Role::team('xyz', 'admin')),  // Team admins
    Permission::delete(Role::user('abc')),
]
```

Permission inheritance: collection-level permissions gate access; document-level permissions refine it. `documentSecurity` flag on collection controls whether document permissions are checked.

## Relationships

Relationship types: `oneToOne`, `oneToMany`, `manyToOne`, `manyToMany`.

```php
->param('type', '', new WhiteList(['oneToOne', 'oneToMany', 'manyToOne', 'manyToMany']))
->param('onDelete', 'restrict', new WhiteList(['restrict', 'cascade', 'setNull']))
```

Relationships create virtual attributes on both sides. Cascade deletes handled by the Deletes worker.

## Database access pattern

```php
// Inject in route
->inject('dbForProject')

// CRUD
$doc = $dbForProject->createDocument('collection_id', new Document([...]));
$doc = $dbForProject->getDocument('collection_id', $docId);
$doc = $dbForProject->updateDocument('collection_id', $docId, $doc);
$dbForProject->deleteDocument('collection_id', $docId);

// Auth-skipped access (admin operations)
$doc = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));
```

## Cache layer

The database library (`utopia-php/database`) has built-in cache-aside:
- `getDocument` checks cache first
- `createDocument`/`updateDocument`/`deleteDocument` purge cache
- `purgeCachedDocument('collection', 'docId')` for manual invalidation
- Cache keyed by `{collection}:{documentId}`

**Gotcha**: adapter-level methods that bypass the cache layer are the most common source of stale data bugs. When adding a new query path, verify it participates in the cache layer.

## Gotchas

- Attribute creation is async — don't query an attribute immediately after creating it; wait for `status: 'available'`
- `$permissions` is the only field prefixed with `$` — all user attributes are flat
- `documentSecurity: false` means only collection-level permissions apply; document `$permissions` are ignored
- The `size` parameter on string attributes is in bytes, not characters (matters for UTF-8)
- Indexes have a compound limit — check `_APP_DB_MAX_INDEXES` env var
- `cursorAfter` and `cursorBefore` are mutually exclusive — using both throws
- Relationship depth is limited to prevent N+1; use `select` to control which relations load
- The Databases worker queue name is `database_db_main`, not `v1-databases` — different from the pattern

## Related skills

- `appwrite-workers-expert` — how the Databases worker processes schema operations
- `appwrite-realtime-expert` — how document changes propagate to subscriptions
- `appwrite-cloud-expert` — dedicated database provisioning in cloud
