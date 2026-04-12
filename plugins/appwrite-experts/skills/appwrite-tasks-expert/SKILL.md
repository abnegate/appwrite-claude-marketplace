---
name: appwrite-tasks-expert
description: CLI task system — maintenance, migrations, scheduling, health checks, SDK generation, and the 19 base tasks. Covers cli.php entry point and the ScheduleBase architecture.
---

# Appwrite Tasks Expert

## Entry point

`app/cli.php` — invoked as `php app/cli.php {taskName} [--param=value]`.

Uses `Utopia\CLI` with a Generic adapter. Swoole coroutines enabled for async I/O within tasks.

## Task base class

Same as workers — all tasks extend `Utopia\Platform\Action`:

```php
class MyTask extends Action {
    public static function getName(): string { return 'my-task'; }
    
    public function __construct() {
        $this->desc('What this task does')
            ->param('myParam', 'default', new Text(128), 'Description', true)
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $myParam, Database $dbForPlatform): void {
        // Task logic
    }
}
```

## Task registry

`src/Appwrite/Platform/Services/Tasks.php` — 19 base tasks:

| CLI name | Class | Purpose |
|---|---|---|
| `doctor` | Doctor | Server health diagnostics |
| `install` | Install | Interactive installation wizard |
| `upgrade` | Upgrade | Upgrade existing installation |
| `migrate` | Migrate | Data migration between versions |
| `maintenance` | Maintenance | Scheduled cleanup (retention, certs, cache) |
| `interval` | Interval | Periodic tasks (domain verification, stale execution cleanup) |
| `schedule-functions` | ScheduleFunctions | Cron-based function scheduling |
| `schedule-executions` | ScheduleExecutions | One-time delayed executions |
| `schedule-messages` | ScheduleMessages | Deferred message delivery |
| `stats-resources` | StatsResources | Hourly resource count snapshots |
| `ssl` | SSL | Manual SSL certificate operations |
| `specs` | Specs | Generate OpenAPI specifications |
| `sdks` | SDKs | Generate language SDKs |
| `screenshot` | Screenshot | Site template screenshot capture |
| `version` | Version | Print server version |
| `vars` | Vars | List environment variables |
| `queue-retry` | QueueRetry | Retry failed queue jobs |
| `time-travel` | TimeTravel | Debug: modify document timestamps |

## ScheduleBase architecture

`Tasks/ScheduleBase.php` — abstract base for all schedule-dispatching tasks.

**Timer configuration** (subclasses override):
- `UPDATE_TIMER` — how often to sync schedules from DB (default 10s)
- `ENQUEUE_TIMER` — how often to check and enqueue ready items (default 60s)

**Loop structure**:
1. `collectSchedules()` — loads all active schedules for current region
2. Update timer — every N seconds, syncs DB changes to local cache
3. Enqueue timer — every N seconds, calls `enqueueResources()` in a coroutine
4. Telemetry: records schedule count per tick

**Schedule document**:
```php
[
    '$id' => string,
    'schedule' => string,     // Cron expression or ISO datetime
    'active' => bool,
    'resourceType' => string, // 'function', 'execution', 'message'
    'resourceId' => string,
    'resource' => Document,
    'project' => Document,
    'region' => string,
]
```

**ScheduleFunctions** (cron): parses cron expression → calculates next run → sleeps → enqueues.
**ScheduleExecutions** (one-time): checks `scheduledAt <= now + interval` → sleeps until time → enqueues → deletes schedule.
**ScheduleMessages** (one-time): checks `scheduledAt <= now` → enqueues → deletes schedule.

## Maintenance task

`Tasks/Maintenance.php` — the janitor. Two modes:

- `--type=loop` — runs continuously with `_APP_MAINTENANCE_INTERVAL` sleep (default 86400s)
- `--type=trigger` — runs once and exits

**Operations**:
1. Delete old usage stats (hourly retention: `_APP_MAINTENANCE_RETENTION_USAGE_HOURLY`)
2. Renew expiring SSL certificates (attempts < 5, renewDate <= now)
3. Delete stale cache entries (retention: `_APP_MAINTENANCE_RETENTION_CACHE`)
4. Delete old schedules (retention: `_APP_MAINTENANCE_RETENTION_SCHEDULES`)
5. Delete old CSV exports
6. Delete stale realtime connections (60+ seconds old)

## Interval task

`Tasks/Interval.php` — faster periodic checks:

1. **Domain verification** (every `_APP_INTERVAL_DOMAIN_VERIFICATION` seconds, default 120s):
   - Queries `rules` collection for `RULE_STATUS_CREATED` entries
   - Triggers domain verification jobs for rules created within 3 days
2. **Stale execution cleanup** (every `_APP_INTERVAL_CLEANUP_STALE_EXECUTIONS` seconds, default 300s):
   - Finds executions stuck in `status: 'processing'` for 20+ minutes
   - Updates to `status: 'failed'` with timeout error

## Migration system

`Tasks/Migrate.php` orchestrates version migrations:

1. Loads migration class from `src/Appwrite/Migration/Version/V{version}.php`
2. Disables subquery filters and database validation
3. Iterates all projects, runs `$migration->execute()` per project
4. Processes console project separately

Version mapping in `Migration/Migration.php`:
```
1.0.x → V15, 1.1.x → V16, 1.2.x → V17, ... 1.8.x → V23, 1.9.x → V24
```

## Doctor task

`Tasks/Doctor.php` — comprehensive health check:

| Check category | What it validates |
|---|---|
| Settings | Hostname, CNAME, encryption key, HTTPS, logging |
| Connectivity | Database pools, cache pools, queue brokers, PubSub, antivirus, SMTP |
| Volumes | Read/write permissions on uploads, cache, config, certs |
| Disk | Free space per volume (warns >80% used) |
| Version | Compares running version to latest release |

## SDK/Spec generation

`Tasks/Specs.php` — generates OpenAPI specifications:
- Modes: `normal` (production) or `mocks` (test fixtures)
- Platforms: client, server, console
- Formats: Swagger 2.0, OpenAPI 3.0
- Optional git push to specs repository

`Tasks/SDKs.php` — generates language SDKs from specs:
- Supports 19+ languages (Android, iOS, Flutter, Node, Python, PHP, etc.)
- Modes: `full` (complete SDK) or `examples` (code samples only)
- Optional AI-generated changelogs (OpenAI)
- Git commit, push, and release automation

## Cloud tasks (58 additional)

Cloud extends with `cloud/src/Appwrite/Cloud/Platform/Services/Tasks.php`:

Key categories:
- **Patches** (20+) — one-time data/schema migrations for cloud
- **Deletions** (6) — orphan cleanup (projects, memberships, orgs, users)
- **Migrations** (5) — structured migration operations
- **Sync** (3) — CRM sync (Attio), user list sync, volume sync
- **Admin** — feature flags, resource blocking, project deletion, stats

## DI in tasks

Tasks use the same container as workers but configured differently:
- `authorization` — disabled (tasks run with full access)
- `telemetry` — `NoTelemetry` adapter (tasks don't need tracing)
- Database pools registered with retry logic for long-running operations
- Publishers registered per-queue for event publishing

## Gotchas

- Tasks and workers share the same `Action` base class but are registered in different services (`TYPE_TASK` vs `TYPE_WORKER`)
- The CLI entry point enables Swoole coroutines — tasks can use async I/O
- `maintenance --type=loop` runs forever — it's designed for a Docker container's CMD
- Schedule tasks poll the database — they don't use the queue system for scheduling
- Migration tasks disable database validation — schema changes happen that would fail normal validation
- `queue-retry` requires the queue name without the `v1-` prefix: `--name=audits` not `--name=v1-audits`
- Cloud patches are idempotent — they check if the change was already applied before modifying

## Related skills

- `appwrite-workers-expert` — workers vs tasks (event-driven vs scheduled)
- `appwrite-databases-expert` — migration system modifies database schema
- `appwrite-cloud-expert` — cloud-specific patches and maintenance
