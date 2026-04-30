---
name: utopia-queue-expert
description: Expert reference for utopia-php/queue — Redis/AMQP-backed job queue with Swoole/Workerman workers, DI-driven job handlers, and explicit Commit/NoCommit/Retryable ack semantics. Consult when building Appwrite workers, priority tiers, or dead-letter patterns.
---

# utopia-php/queue Expert

## Purpose
Redis/AMQP-backed job queue with a Swoole/Workerman worker server, DI-driven job handlers, and telemetry hooks.

## Public API
- `Utopia\Queue\Server` — worker runtime; `job()`, `error()`, `workerStart()`, `init()`, `shutdown()`, `setResource()`, `setTelemetry()`
- `Utopia\Queue\Adapter` — abstract: owns `Queue`, `workerNum`, `namespace`; Swoole/Workerman concrete
- `Utopia\Queue\Publisher` — interface: `enqueue()`, `retry()`, `getQueueSize()`
- `Utopia\Queue\Consumer` — interface: `consume(Queue, messageCallback, successCallback, errorCallback)`
- `Utopia\Queue\Broker\{Redis, AMQP, Pool}` — implement both `Publisher` + `Consumer`. `Broker\Redis` exposes `setReconnectCallback(?callable)` and `setReconnectSuccessCallback(?callable)` for transient-outage telemetry/logging
- `Utopia\Queue\Connection\{Redis, RedisCluster}` — low-level
- `Utopia\Queue\{Job, Message, Queue}` — DTOs
- `Utopia\Queue\Error\Retryable` + `Result\{Commit, NoCommit}` — explicit ack semantics

## Core patterns
- **Router-style hook registration**: `$server->job()->inject('message')->action(fn(Message $m) => ...)` backed by `utopia-php/di` container
- **Dual-role brokers** — `Redis` and `AMQP` both implement `Publisher` + `Consumer`, so the same class enqueues and dequeues
- **`Broker\Pool`** wraps multiple brokers for connection pooling via `utopia-php/pools`
- **Redis broker uses namespaced keys**: `{ns}.queue.{name}`, `{ns}.jobs.{name}.{pid}`, `{ns}.processing.{name}`, `{ns}.stats.{name}.*` with a `jobTtl` lease
- **Telemetry baked in**: `messaging.process.wait.duration` + `process.duration` histograms via `utopia-php/telemetry`
- **Bounded reconnects survive transient Redis outages** — `Broker\Redis::consume()` catches connection errors, sleeps a randomized backoff that doubles each attempt up to `RECONNECT_MAX_BACKOFF_MS`, and resumes the consume loop without crashing the worker. Wire `setReconnectCallback(fn($queue, $err, $attempt, $sleepMs) => …)` for retry telemetry and `setReconnectSuccessCallback(fn($queue, $attempts) => …)` to track recovery — counters from these are how you tell a healthy reconnect storm from a flapping cluster

## Gotchas
- Job handler must **return `Commit`/`NoCommit`** (or throw `Retryable`) when using AMQP — throwing `Retryable` triggers redelivery, anything else acks
- **Redis broker's `POP_TIMEOUT = 2s`** is hardcoded; long BRPOP waits aren't configurable
- `Queue\Adapter` constructor signature mixes concerns (`workerNum`, `queue`, `namespace`) — all Swoole workers share a single queue name per adapter instance
- **PHP 8.3+ required**; AMQP broker pulls in `php-amqplib/php-amqplib ^3.7`

## Appwrite leverage opportunities
- **Priority tiers**: run two `Server` instances with different `Queue` names (`jobs-high`, `jobs-normal`) and a lightweight scheduler that moves stale `jobs-high` entries to `jobs-normal` — cheaper than implementing priority inside a single queue
- **Dead-letter adapter**: decorate `Broker\Redis` so after N `Retryable` throws it pushes to `{ns}.dlq.{name}` and fires a telemetry counter — combine with Appwrite's audit service for post-mortem
- **Idempotency**: use `Message->getPid()` as the dedupe key in a Redis `SETNX` before invoking the handler; AMQP message IDs already map to this via the broker's commit flow
- **`Broker\Pool` + `utopia-php/pools`** is the right primitive for multi-region fanout — one logical queue, N regional Redis clusters, weighted round-robin enqueue

## Example
```php
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\{Adapter\Swoole, Message, Queue, Server};

$broker = new RedisBroker(new RedisConnection('redis', 6379));
$adapter = new Swoole($broker, workerNum: 8, queue: 'emails');

$server = new Server($adapter);
$server->job()
    ->inject('message')
    ->action(function (Message $message) {
        // handle; throw Utopia\Queue\Error\Retryable to requeue
    });
$server->start();
```
