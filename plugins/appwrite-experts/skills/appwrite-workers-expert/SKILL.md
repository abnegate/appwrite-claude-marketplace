---
name: appwrite-workers-expert
description: Worker architecture, queue system, event publishing, error handling, and the 14 base workers in Appwrite. The cross-cutting async processing layer.
---

# Appwrite Workers Expert

## Entry point

`app/worker.php` — spawned as `php app/worker.php {workerName}`.

Queue name resolution:
```php
if (str_starts_with($workerName, 'databases')) {
    $queueName = System::getEnv('_APP_QUEUE_NAME', 'database_db_main');
} else {
    $queueName = System::getEnv('_APP_QUEUE_NAME', 'v1-' . strtolower($workerName));
}
```

## Worker base class

All workers extend `Utopia\Platform\Action`:

```php
class MyWorker extends Action {
    public function __construct() {
        $this->desc('What this worker does')
            ->inject('message')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(Message $message, Database $dbForProject, Event $queueForEvents): void {
        $payload = $message->getPayload();
        // Process job
    }
}
```

## Worker registry

`src/Appwrite/Platform/Services/Workers.php` registers all base workers:

| Worker | Queue | Purpose |
|---|---|---|
| Audits | `v1-audits` | Batch-aggregate audit logs |
| Certificates | `v1-certificates` | SSL cert generation/renewal |
| Deletes | `v1-deletes` | Cascade resource deletion |
| Executions | `v1-executions` | Persist function execution results |
| Functions | `v1-functions` | Dispatch function executions to executor |
| Mails | `v1-mails` | System email delivery (SMTP) |
| Messaging | `v1-messaging` | User messaging (SMS/email/push) |
| Migrations | `v1-migrations` | Data import/export |
| StatsResources | `v1-stats-resources` | Resource count snapshots |
| StatsUsage | `v1-stats-usage` | Usage metric aggregation |
| Webhooks | `v1-webhooks` | Webhook HTTP delivery |

Module workers:
| Worker | Queue | Module |
|---|---|---|
| Databases | `database_db_main` | Databases |
| Builds | `v1-builds` | Functions |
| Screenshots | `v1-screenshots` | Functions |

## Event system

`src/Appwrite/Event/Event.php` is the base for all event publishing:

```php
// In a route action:
$queueForEvents
    ->setParam('userId', $user->getId())
    ->setPayload($response->output($session, Response::MODEL_SESSION));

// Event is published in the shutdown hook after the response is sent
$queueForEvents->trigger();
```

`trigger()` serializes the payload and enqueues via the broker (Redis/AMQP).

Specialized event classes extend `Event`:
- `Appwrite\Event\Func` — function execution events
- `Appwrite\Event\Delete` — deletion events
- `Appwrite\Event\Certificate` — certificate events
- `Appwrite\Event\Mail` — system email events

### Typed publisher/message migration (in flight, 1.9.x)

A second producer surface lives next to the legacy `queueFor*` Event objects:

- `Appwrite\Event\Publisher\{Func, Database, Delete, Audit, Build, Certificate, Execution, Mail, Messaging, Migration, Screenshot, StatsResources, Usage}` — each `readonly class extends Base`, ctor `(Publisher $publisher, Queue $queue)`, exposes `enqueue(BaseMessage $message, ?Queue $queue = null): string|bool` and `getSize(bool $failed = false, ?Queue $queue = null): int`. `Publisher\Base::publish()` calls `$message->toArray()` and forwards to `Utopia\Queue\Publisher::enqueue()`
- `Appwrite\Event\Message\{Func, Database, Delete, …}` — `final readonly` value objects with promoted constructor properties; each implements `toArray()` so payload shape is no longer reflected ad-hoc through `$event->setParam(...)`. `Func::fromEvent($event, $params, $project, $user, $userId, $payload, $platform)` is the convenience constructor used by event-driven dispatch

The migration is happening per-queue (function publisher landed via `Migrate queueForFunctions to FunctionPublisher and FunctionMessage`; database and deletes followed; region manager queue moved on the cloud side). When adding a new producer, prefer the typed pair over wiring another `Event` subclass — the legacy `queueFor*` injectables and shutdown-hook `trigger()` still exist for compatibility and will be migrated piecewise.

## Queue system

Backend: `utopia-php/queue` with broker adapters (Redis, AMQP, Pool).

Swoole adapter spawns N worker processes per queue:
```php
$adapter = new Swoole($consumer, $workerNum, $queueName);
// $workerNum from _APP_WORKERS_NUM env var (default: 1)
```

## Message container isolation

Each job gets a fresh DI container:
```php
$this->messageContainer = new Container($this->container);
```

Per-job resources registered in `app/init/worker/message.php`:
- `dbForProject` — project-specific database (from message payload's project DSN)
- `dbForPlatform` — console database
- `getProjectDB` — factory for cross-project access
- Queue publishers: `queueForDatabase`, `queueForMessaging`, `queueForMails`, etc.
- Storage devices: `deviceForFiles`, `deviceForFunctions`, `deviceForBuilds`, etc.

## Error handling

**Error hook** (`app/worker.php:113-153`):
```php
$worker->error()
    ->inject('error')
    ->inject('logger')
    ->action(function(Throwable $error, ?Logger $logger) {
        // Log to telemetry service
    });
```

**Retryable errors**: Throw `Utopia\Queue\Error\Retryable` to re-queue instead of dead-letter.

**Result types** for batch workers:
- `Commit` — message processed, acknowledge to broker
- `NoCommit` — keep message for later (used by Audits worker for batching)

## Batch processing pattern

Used by Audits and StatsUsage workers:

```php
public function action(Message $message, ...): Commit|NoCommit {
    $this->buffer[] = $message->getPayload();
    
    if (count($this->buffer) >= $batchSize || $timeSinceLastFlush > $interval) {
        $this->flush();
        return new Commit();
    }
    return new NoCommit();  // Keep accumulating
}
```

## Worker lifecycle hooks

```php
// Global init — runs once per job before action
$worker->init()->action(function() { /* setup */ });

// Global shutdown — runs once per job after action
$worker->shutdown()->action(function() { /* cleanup */ });

// Worker start — runs when process spawns
$worker->workerStart()->action(function() { /* pool init */ });

// Error — runs on exception
$worker->error()->action(function(Throwable $e) { /* log */ });
```

## Deletes worker — the cascade engine

`Workers/Deletes.php` is the largest worker (~800 lines). It handles:

| Delete type | What it cleans up |
|---|---|
| `DELETE_TYPE_DOCUMENT` | Routes by collection: projects, users, teams, buckets, functions, etc. |
| `DELETE_TYPE_TEAM_PROJECTS` | All projects belonging to a team |
| `DELETE_TYPE_EXECUTIONS` | Old executions beyond retention period |
| `DELETE_TYPE_AUDIT` | Old audit logs beyond retention |
| `DELETE_TYPE_USAGE` | Old usage stats beyond retention |
| `DELETE_TYPE_CACHE` | Stale cache entries |
| `DELETE_TYPE_SCHEDULES` | Expired schedules |

Each type cascades: deleting a project deletes all its databases, functions, files, deployments, etc.

## Cloud worker overrides

Cloud extends/overrides several workers in `cloud/src/Appwrite/Cloud/Platform/Workers/`:
- **Activity** — extends Audits, dual-logs to ClickHouse
- **Deletes** — cloud-aware resource deletion
- **Databases** — cloud database provisioning
- **Certificates** — DNS-based validation
- **Mails** — cloud email provider
- **RegionManager** — multi-region orchestration
- **Growth** — growth metrics
- **Threats** — security threat detection

## Gotchas

- Workers run with authorization disabled — they have platform-level access to all data
- Each worker is a separate OS process — they don't share memory with the HTTP server
- The `database_db_main` queue name breaks the `v1-{name}` pattern — special case for databases worker
- Events are published in the HTTP shutdown hook, after the response is sent — not inline in the route action
- `queueForRealtime` is NOT a queue — it publishes directly to Redis PubSub
- Worker process count (`_APP_WORKERS_NUM`) defaults to 1 — scale per queue based on throughput needs
- Pool connections must be initialized per-worker (in workerStart), not at file load — same Swoole rule as HTTP

## Related skills

- `appwrite-tasks-expert` — CLI tasks vs workers (tasks are scheduled, workers are event-driven)
- `appwrite-realtime-expert` — how worker results propagate to WebSocket clients
- `appwrite-databases-expert` — the Databases worker handles schema DDL operations
