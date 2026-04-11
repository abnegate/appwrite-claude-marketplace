---
name: utopia-websocket-expert
description: Expert reference for utopia-php/websocket — dependency-free abstraction over Swoole and Workerman WebSocket servers. Consult when building Appwrite realtime, implementing tenant isolation, or adding backpressure for slow clients.
---

# utopia-php/websocket Expert

## Purpose
Dependency-free abstraction layer over Swoole and Workerman WebSocket servers with a lifecycle-callback API.

## Public API
- `Utopia\WebSocket\Server` — facade: wires callbacks, catches `Throwable` into `onError`
- `Utopia\WebSocket\Adapter` — abstract: `start()`, `shutdown()`, `send(connections, message)`, `close(connection, code)`, `getConnections()`, `getNative()`
- `Adapter\Swoole` — Swoole WebSocket server wrapper
- `Adapter\Workerman` — Workerman backend
- **Callback registration**: `onStart`, `onWorkerStart`, `onWorkerStop`, `onOpen`, `onMessage`, `onRequest`, `onClose`, `onError`
- **Config setters**: `setPackageMaxLength(int)`, `setCompressionEnabled(bool)`, `setWorkerNumber(int)`

## Core patterns
- **Thin adapter**: `Server` delegates every call to `Adapter`, wrapping in `try/catch` to funnel errors through `$errorCallbacks`
- **Connection IDs are integers** (Swoole's `$fd`) — use them directly with `send([$id1, $id2], $payload)`
- **`getNative()` escape hatch** returns the underlying Swoole/Workerman server for advanced operations not in the abstract API
- **`onRequest`** lets the same port serve plain HTTP (e.g., health checks) alongside WebSocket upgrades

## Gotchas
- Requires PHP 8.0+ but **in practice needs `ext-swoole` or `workerman/workerman`** to do anything — no pure-PHP adapter
- **No built-in room/channel primitive** — you must track `connectionId => channel` mapping yourself (typically in Redis)
- `onMessage` handler is sync; **long work will block the worker** — always hand off to the queue
- `getConnections()` on Workerman iterates all workers' local state only — **not cluster-wide**

## Appwrite leverage opportunities
- **Fanout strategy**: pair with Redis pub/sub where every realtime worker subscribes to channel patterns and calls `$server->send($localConnections, $payload)` — the WebSocket lib handles only local delivery, scaling horizontally by adding workers
- **Tenant isolation**: track `projectId => [connectionIds]` in a worker-local array populated via `onOpen` (parsing JWT from the upgrade request), drop the mapping in `onClose`. Broadcast via `array_intersect_key` against the tenant's list
- **Backpressure**: wrap `send()` to check per-connection message rate; on overflow, `close($id, 1008)` (policy violation) — prevents slow clients from starving workers
- **Health probes**: use `onRequest` for `/health` so load balancers don't need separate ports — keeps the realtime service single-binary

## Example
```php
use Utopia\WebSocket\Adapter\Swoole;
use Utopia\WebSocket\Server;

$adapter = (new Swoole('0.0.0.0', 3005))
    ->setPackageMaxLength(64_000)
    ->setWorkerNumber(4);

$server = new Server($adapter);
$server->onOpen(fn (int $connection, $request) => print("open $connection\n"));
$server->onMessage(function (int $connection, string $payload) use ($server) {
    $server->send([$connection], "echo: $payload");
});
$server->onClose(fn (int $connection) => print("close $connection\n"));
$server->start();
```
