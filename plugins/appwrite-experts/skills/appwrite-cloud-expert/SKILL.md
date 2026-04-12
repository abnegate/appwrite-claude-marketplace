---
name: appwrite-cloud-expert
description: Cloud-specific concerns — multi-region, edge, billing, patches, dedicated databases, resource blocking, and the 18 cloud workers and 58 cloud tasks.
---

# Appwrite Cloud Expert

## Repository

`~/Local/cloud` — extends the base Appwrite CE (`~/Local/appwrite`) via Composer.

Cloud loads CE init first, then layers on top:
```php
// cloud/app/init.php
include_once APP_VENDOR_CE_DIR . '/app/init.php';
```

## Key differences from CE

| Concern | CE | Cloud |
|---|---|---|
| Memory limit | 512M | 2048M |
| Redis | Single instance | Redis Cluster with failover |
| Database | Single DSN | Per-project DSN, dedicated databases |
| Workers | 11 base | 11 base + 18 cloud-specific |
| Tasks | 19 base | 19 base + 58 cloud-specific |
| Regions | Single | Multi-region (fra, nyc, syd, sfo, sgp, tor) |
| Edge | None | Fastly CDN integration |
| Billing | None | Stripe + plan-based quotas |

## Multi-region architecture

Each region runs independently with its own:
- Swoole HTTP server
- Worker processes
- Database cluster
- Cache cluster
- Queue broker

Cross-region coordination via:
- `RegionManager` worker — orchestrates operations across regions
- `Edge` worker — CDN routing and invalidation
- Shared console database for project metadata

Region-aware queries filter by `region` attribute on projects and schedules.

## Resource blocking

`cloud/app/init.php` defines `isResourceBlocked()`:

```php
// Checks project.blocks array for:
// 1. Project-wide blocks (resourceType = 'projects')
// 2. Type-wide blocks (all functions, all sites)
// 3. Resource-specific blocks (specific function ID)
// 4. Expiration dates via expiredAt
// Enterprise teams are exempt
```

Used by Functions worker before execution and in route middleware.

## Billing / plan system

Plans define quotas injected as `array $plan`:
- `executionsRetentionCount` — max execution history
- `storageQuota` — storage limit in bytes
- `requestsQuota` — API requests per period
- `httpLogs.enabled` — whether HTTP logging is active
- Member limits per plan tier

Plan enforcement in routes and workers via quota checks before operations.

## HTTP logging

Cloud-specific middleware captures request telemetry:
```php
$queueForHttpLogs
    ->setMethod($request->getMethod())
    ->setPath($request->getURI())
    ->setHostname($request->getHostname())
    ->setIp($request->getIP())
    ->setCountry($geodb->country($ip))
    ->setRequestSize($requestSize)
    ->setResponseSize($responseSize);
```

Controlled by plan setting + project-level toggle.

## Cloud workers (18 additional)

| Worker | Purpose |
|---|---|
| Activity | Dual-logs audits to ClickHouse for analytics |
| Databases (cloud) | Dedicated database provisioning |
| Deletes (cloud) | Cloud-aware cascade deletion |
| Certificates (cloud) | DNS-based SSL validation |
| Mails (cloud) | Cloud email provider integration |
| Domains | Custom domain provisioning and validation |
| Edge | CDN routing and cache invalidation |
| RegionManager | Cross-region orchestration |
| Growth | Growth metrics and analytics |
| Threats | Security threat detection |
| Logs | Centralized log aggregation |
| StatsEdge | Edge location statistics |
| StatsUsage (cloud) | Cloud usage tracking with ClickHouse |
| Patches | System patch application |
| MigrationsCloud | Cloud-enabled data migrations |
| MigrationsCloudValidation | Pre-migration validation |
| FunctionsSchedule | Scheduled function execution |
| Builds (compute) | Dedicated database builds |
| CrossRegionHealthMonitor | Cross-region health checks |

## Cloud tasks (58 additional)

Key categories:

**Patches** (~20 tasks): One-time data/schema migrations. Naming: `patch-{description}`. All extend a base `Patch` class with apply/validate/rollback lifecycle. Idempotent by design.

**Deletions** (~6 tasks): Cleanup orphaned resources:
- `delete-console-users` — users with no project memberships
- `delete-detached-projects` — projects without team
- `delete-orphaned-memberships` — empty team memberships
- `delete-orphaned-orgs` — teams with no members

**Admin** tasks:
- `flags list|add|remove` — feature flag management per user/team/project
- `manage-blocks` — block/unblock resources
- `enrichment` — AI-powered team/company data enrichment

**Sync** tasks:
- `sync-attio` — CRM synchronization
- `sync-userlist` — user directory sync
- `volume-sync` — storage volume sync

## Dedicated databases (cloud)

Cloud supports dedicated per-project databases:
- Project document has a custom DSN attribute
- `getDatabasesDB` factory resolves to dedicated connection
- Separate pool management for dedicated connections
- The Databases worker (cloud override) handles provisioning

## Cloud SDK extensions

`cloud/src/Appwrite/Cloud/SDK/Method.php` — extends the base Method class for cloud-specific API documentation and auth types.

## Cloud document classes

Custom document types in `cloud/src/Appwrite/Cloud/Utopia/Database/Documents/`:
- `Project` — extended project document with billing, blocks, regions
- `Team` — extended team document with organization features

## Event publishers (cloud-specific)

```php
// cloud/app/controllers/general.php
Appwrite\Cloud\Event\Edge       // Edge computing events
Appwrite\Cloud\Event\Growth     // Growth/billing events
Appwrite\Cloud\Event\HttpLogs   // HTTP request logging
Appwrite\Cloud\Event\RegionManager  // Multi-region events
```

## Gotchas

- Cloud `init.php` must be loaded AFTER CE `init.php` — order matters
- Redis Cluster requires `redis-cluster://` DSN scheme — different from single Redis
- Enterprise teams bypass resource blocking — check team flags before assuming blocks apply
- Patches are meant to run once — running twice is safe (idempotent) but wasteful
- The cloud overrides CE workers by registering with the same name — last-registered wins
- Region filtering is critical — a task that processes all projects without filtering by region will process projects it shouldn't
- Dedicated database connections have separate pool management — don't mix with shared pools

## Related skills

- `appwrite-workers-expert` — base worker patterns that cloud extends
- `appwrite-tasks-expert` — base task patterns that cloud extends
- `appwrite-databases-expert` — dedicated database provisioning
