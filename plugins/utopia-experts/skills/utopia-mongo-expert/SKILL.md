---
name: utopia-mongo-expert
description: Expert reference for utopia-php/mongo — the Swoole-native wire-protocol MongoDB client. Consult when debugging Mongo adapter issues, sizing pools, implementing replica-set failover, or hunting coroutine-blocking patterns.
---

# utopia-php/mongo Expert

## Purpose
Non-blocking, Swoole-native MongoDB wire-protocol client (line protocol over raw TCP) — bypasses the libmongoc-based `mongodb/mongodb` driver so coroutines don't block the worker.

## Public API
- `Utopia\Mongo\Client` — the whole library. Constructor `(database, host, port, user, password, useCoroutine)`. Methods: `connect`, `insert`, `find`, `update`, `delete`, `count`, `aggregate`, `distinct`, `createCollection`, `dropCollection`, `createIndexes`, `listDatabaseNames`, `startSession`, `commitTransaction`, `abortTransaction`, `query` (raw command)
- `Utopia\Mongo\Auth` — SCRAM-SHA-1/256 handshake; `encodeCredentials(user, password)`
- `Utopia\Mongo\Exception` — single exception class mapping Mongo error codes
- `COMMAND_*` constants for every wire command; `READ_CONCERN_*`, `READ_PREFERENCE_*`, `TRANSACTION_*` state machine constants

## Core patterns
- **Socket class picked at runtime** — `CoroutineClient` if `useCoroutine=true` **and** `Coroutine::getCid() > 0`, else `SwooleClient`. Both use `SWOOLE_SOCK_TCP | SWOOLE_KEEP` with 30s timeout + TCP keepalive
- **Results as `stdClass` trees** mirroring BSON — access via `$result->cursor->firstBatch`, not array notation. BSON `ObjectId`/`Int64`/`Document` are the `mongodb/mongodb` PHP types (ext still required for BSON encode/decode)
- **Sessions + transactions hand-managed** via `$client->sessions` array with explicit state (`TRANSACTION_STARTING` → `IN_PROGRESS` → `COMMITTED|ABORTED`); retryable errors classified by label (`TransientTransactionError`, `UnknownTransactionCommitResult`)
- **`defaultMaxTimeMS = 30000`** applied to every command except `getMore`/`killCursors`. Pass `maxTimeMS` explicitly on long-running aggregations

## Gotchas
- `useCoroutine=true` in a non-coroutine context **silently downgrades** to sync — tests initialized at bootstrap before Swoole server start pin sync sockets for the whole worker lifetime. Always construct inside the request scope
- **No connection pooling.** The `id` (`uniqid('utopia.mongo.client')`) is per-instance, so holding one Client across coroutines serializes all commands through one socket. Wrap in `utopia-php/pools` with `size = coroutine concurrency`
- All constructor args are `non-empty` — missing user/password throws `InvalidArgumentException`. There is no "no auth" mode; pass dummy credentials for local dev Mongo without `--auth`
- Cluster time / operation time are tracked for causal consistency but **not** exposed; cross-client causal reads need raw `query()` with `$clusterTime` injected

## Appwrite leverage opportunities
- **Pool integration is DIY**: `utopia-php/database`'s `Mongo` adapter holds a single `Client` — Appwrite Cloud's Mongo-backed projects serialize all queries per worker. Wire `Adapter\Pool` to a `Pool<Client>` with size = Swoole `worker_num * 4` for immediate concurrency
- **Missing observability**: no hook between `query()` send and recv — add a `setCommandListener(callable)` so the Appwrite platform can emit span-per-command to OpenTelemetry without forking
- **Replica set discovery**: `Client` only speaks to the supplied host. A `ReplicaSet` class that speaks `isMaster` / `hello` and rotates hosts on `NotPrimary` would let Appwrite Cloud survive primary elections without HAProxy
- **Change streams**: wire-protocol supports `getMore` on capped cursors but no change-stream helper. Adding one would let Appwrite realtime push Mongo updates without polling `_metadata`

## Example
```php
use MongoDB\BSON\ObjectId;
use Utopia\Mongo\Client;

$client = new Client(
    database: 'appwrite',
    host: 'mongo',
    port: 27017,
    user: 'root',
    password: 'password',
    useCoroutine: true,
);
$client->connect();

$inserted = $client->insert('projects', [
    'name' => 'Acme',
    'region' => 'fra',
]);
$id = (string) $inserted['_id'];

$project = $client->find('projects', ['_id' => new ObjectId($id)])
    ->cursor->firstBatch[0] ?? null;

$client->update('projects', ['_id' => new ObjectId($id)], ['$set' => ['region' => 'nyc']]);
$client->delete('projects', ['_id' => new ObjectId($id)]);
```
