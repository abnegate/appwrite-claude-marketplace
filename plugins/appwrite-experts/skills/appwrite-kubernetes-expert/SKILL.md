---
name: appwrite-kubernetes-expert
description: Kubernetes deployment architecture — Helm charts, pod topology, KEDA autoscaling, Dragonfly cache/queue clusters, NFS storage, ProxySQL database proxying, and production configuration for the Appwrite cloud stack.
---

# Appwrite Kubernetes Expert

## Helm chart

`cloud/deploy/cloud/Chart.yaml` — Helm 3 (apiVersion v2).

Templates: `cloud/deploy/cloud/templates/`
Values: `cloud/deploy/cloud/environments/{staging,production}/*.values.yaml`

Per-region value overrides: `fra1.values.yaml`, `sfo3.values.yaml`, `nyc3.values.yaml`, etc.

## Pod topology

The Appwrite stack deploys as ~30 pods across 5 tiers:

### HTTP tier

| Deployment | Image | Port | Entrypoint | Replicas | HPA |
|---|---|---|---|---|---|
| `appwrite` | appwrite/appwrite | 80 | `php app/http.php` | 3-30 | CPU 70% |
| `appwrite-storage` | appwrite/appwrite | 80 | `php app/http.php` | 1-30 | CPU 70% (optional, separate scaling for storage routes) |
| `realtime` | appwrite/appwrite | 80 | `php app/realtime.php` | 2-5 | CPU 80% |
| `console` | appwrite/console | 80 | nginx | 2 | fixed |

All HTTP pods are stateless. Graceful shutdown: 20s pre-stop sleep.

Startup probe: `GET /v1/health/version` (5s interval, 60 failures = 5min timeout).
Liveness probe: TCP port 80.

### Worker tier

All workers use the same image with different entrypoints (`bin/worker-{name}`). Each consumes from one named queue.

| Worker | Queue | `_APP_WORKERS_NUM` | KEDA trigger | Max replicas |
|---|---|---|---|---|
| `worker-audits` | `v1-audits` | 1 | listLength=1000 | 5 |
| `worker-builds` | `v1-builds` | 4 | disabled | 1 |
| `worker-builds-priority` | `v1-builds-priority` | 4 | disabled | 1 |
| `worker-certificates` | `v1-certificates` | 1 | enabled | 5 |
| `worker-databases` | `v1-databases` | 1 | enabled | 5 |
| `worker-deletes` | `v1-deletes` | 1 | listLength=200 | 5 |
| `worker-executions` | `v1-executions` | 1 | enabled | 5 |
| `worker-functions` | `v1-functions` | 8 | listLength=50 | 5 |
| `worker-functions-schedule` | `v1-functions-schedule` | 1 | enabled | 5 |
| `worker-mails` | `v1-mails` | 1 | enabled | 5 |
| `worker-messaging` | `v1-messaging` | 1 | enabled | 5 |
| `worker-migrations` | `v1-migrations` | 1 | enabled | 5 |
| `worker-webhooks` | `v1-webhooks` | 1 | listLength=200 | 5 |
| `worker-screenshots` | `v1-screenshots` | 4 | enabled | 5 |
| `worker-domains` | `v1-domains` | 1 | enabled | 5 |
| `worker-stats-usage` | `v1-stats-usage` | 2 | listLength=1000 | 5 |
| `worker-stats-resources` | `v1-stats-resources` | 1 | listLength=25000 | 5 |
| `worker-logs` | `v1-logs` | 8 | listLength=1000 | 10 |
| `worker-region-manager` | `v1-region-manager` | 32 | enabled | 10 |
| `worker-growth` | `v1-growth` | 1 | enabled | 5 |
| `worker-edge` | `v1-edge` | 1 | enabled | 5 |
| `worker-threats` | `v1-threats` | 4 | enabled | 5 |
| `worker-billing-project` | `billing-project-aggregation` | 1 | listLength=100000 | 5 |

Default worker resources: 100m CPU request, 256Mi memory request, 512Mi limit.

### Task tier

Long-running scheduled processes (not queue-driven):

| Task | Command | Schedule |
|---|---|---|
| `task-maintenance` | `maintenance --type=loop` | `_APP_MAINTENANCE_INTERVAL` (86400s) |
| `task-interval` | `interval` | Various internal timers |
| `task-schedule-functions` | `schedule-functions` | Polls every 60s |
| `task-schedule-executions` | `schedule-executions` | Polls every 4s |
| `task-schedule-messages` | `schedule-messages` | Polls every 4s |
| `task-stats-resources` | `stats-resources` | `_APP_STATS_RESOURCES_INTERVAL` (3600s) |

### Cache/queue tier (Dragonfly)

Four separate Dragonfly clusters (`dragonflydb.io/v1alpha1` CRD):

| Cluster | Purpose | Replicas | Memory |
|---|---|---|---|
| `cache` | Document/query cache | 3 | 12.5Gi each |
| `queue-dragonfly` | Worker job queues | 3 | 12.5Gi each |
| `queue-usage` | Billing/usage queues | 3 | 12.5Gi each |
| `pubsub-dragonfly` | Realtime PubSub | 3 | 4Gi each |

All on `workload=cloud-cache` or `workload=cloud-pubsub` node selectors.

Queue key format: `utopia-queue.queue.{queueName}` (e.g., `utopia-queue.queue.v1-audits`).

### Database tier

| Component | Technology | Deployment |
|---|---|---|
| Primary DB | MariaDB / MongoDB / PostgreSQL | StatefulSet or external managed |
| ProxySQL | `appwrite/swarm-proxy` | Manages connection pooling + multi-shard routing |
| Vector DB | PostgreSQL | Separate for embeddings |

ProxySQL shard mapping: maps logical DSNs to physical nodes via port-based routing (e.g., port 6033 → console DB at node:10101, port 6034 → shard-17-v2 at node:10119).

## KEDA autoscaling

Workers scale via KEDA `ScaledObject` resources:

```yaml
apiVersion: keda.sh/v1alpha1
kind: ScaledObject
metadata:
  name: worker-audits
spec:
  scaleTargetRef:
    name: worker-audits
  minReplicaCount: 1
  maxReplicaCount: 5
  triggers:
    - type: redis
      metadata:
        address: queue-dragonfly.default:6379
        listName: utopia-queue.queue.v1-audits
        listLength: "1000"
        activationListLength: "1"
```

`listLength` controls sensitivity — lower values mean faster scale-up. Workers that process fast (webhooks, deletes) use 200; high-throughput workers (billing, stats) use 1000-100000.

## Storage

Three storage types supported:

| Type | Config | Use case |
|---|---|---|
| `nfs` | `nfs.server` + `nfs.path` | Production (shared across pods) |
| `pvc` | `pvc.claimName` | Managed K8s storage |
| `emptyDir` | — | Development/ephemeral |

Production NFS example:
```yaml
storage:
  type: nfs
  nfs:
    server: 10.130.0.3
    path: /mnt/cloud_fra1_prod_volume
```

Mount paths:
- `/storage/cache` — API, deletes worker
- `/storage/config` — API, certificates worker (Traefik config)
- `/storage/certificates` — API, certificates worker, deletes worker

Not all workers mount storage — only those that need file access.

## Networking

### Gateway API (Kubernetes Gateway)

```yaml
apiVersion: gateway.networking.k8s.io/v1
kind: Gateway
metadata:
  name: gateway
spec:
  gatewayClassName: traefik
  listeners:
    - name: http
      port: 8000
      protocol: HTTP
    - name: https
      port: 8443
      protocol: HTTPS
      tls:
        certificateRefs:
          - name: gateway  # K8s Secret with TLS cert
```

HTTPRoutes map paths to services:
- `/` → `appwrite:80`
- `/v1/realtime` → `realtime:80`
- `/console` → `console:80`
- `/v1/storage/*` → `appwrite-storage:80` (if enabled)

### Internal service discovery

All services communicate via Kubernetes DNS: `{service}.{namespace}.svc.cluster.local`.

| Source | Destination | Protocol |
|---|---|---|
| API → Dragonfly | `cache.default:6379` | Redis RESP |
| API → DB | `proxy-db:6033` | MySQL/MongoDB |
| Worker → Queue | `queue-dragonfly.default:6379` | Redis RESP |
| Worker → PubSub | `pubsub-dragonfly.default:6379` | Redis RESP |
| Worker → Executor | `_APP_EXECUTOR_HOST` (HTTP) | REST |
| API → Realtime | `pubsub-dragonfly.default:6379` | Redis PubSub |

## Key environment variables for K8s

### Swoole tuning
```
_APP_WORKER_PER_CORE=6       # Swoole workers = CPU_NUM * this
_APP_CPU_NUM=4                # Override detected CPU count
_APP_WORKERS_NUM=1            # Per-queue parallelism in worker pods
```

### Connection pools
```
_APP_CONNECTIONS_DB_CONSOLE=  # Console DB pool size
_APP_CONNECTIONS_DB_PROJECT=  # Project DB pool size
_APP_CONNECTIONS_DB_LOGS=     # Logs DB pool size
_APP_CONNECTIONS_CACHE=       # Cache pool size
_APP_CONNECTIONS_PUBSUB=      # PubSub pool size
_APP_CONNECTIONS_QUEUE=       # Queue pool size
_APP_CONNECTIONS_MAX=         # Global pool limit
_APP_POOL_CLIENTS=            # Named client connections
```

### Database
```
_APP_DB_ADAPTER=mariadb|mongodb|postgresql
_APP_DB_HOST / _APP_DB_PORT / _APP_DB_USER / _APP_DB_PASS / _APP_DB_SCHEMA
APP_DATABASE_TIMEOUT_MILLISECONDS_API=15000     # 15s for API
APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER=300000 # 5m for workers
DATABASE_RECONNECT_SLEEP=2
DATABASE_RECONNECT_MAX_ATTEMPTS=10
```

### Queue
```
_APP_QUEUE_NAME=v1-{worker}   # Queue name (auto-resolved from worker name)
_APP_QUEUE_PREFETCH_COUNT=1000 # Batch fetch size
```

### Maintenance
```
_APP_MAINTENANCE_INTERVAL=86400
_APP_MAINTENANCE_START_TIME=12:00
_APP_MAINTENANCE_RETENTION_EXECUTION=1209600    # 14 days
_APP_MAINTENANCE_RETENTION_CACHE=2592000        # 30 days
_APP_MAINTENANCE_RETENTION_AUDIT=1209600        # 14 days
_APP_MAINTENANCE_RETENTION_USAGE_HOURLY=8640000 # 100 days
```

## Production resource profiles (FRA1)

| Component | CPU request | Memory request | Memory limit |
|---|---|---|---|
| API server | 3500m | 6Gi | 6Gi |
| Realtime | 500m | 1Gi | 1Gi |
| Console | 100m | 64Mi | 128Mi |
| Worker (default) | 100m | 256Mi | 512Mi |
| Dragonfly (cache) | 1000m | 12.5Gi | 12.5Gi |
| Dragonfly (pubsub) | 1000m | 4Gi | 4Gi |

## Health checks

| Endpoint | Purpose | Used by |
|---|---|---|
| `GET /v1/health/version` | Startup probe | K8s startupProbe |
| TCP :80 | Liveness check | K8s livenessProbe |
| `GET /v1/health` | Full health check | Monitoring |
| `GET /v1/health/db` | Database connectivity | Monitoring |
| `GET /v1/health/cache` | Cache connectivity | Monitoring |
| `GET /v1/health/queue` | Queue broker connectivity | Monitoring |
| `GET /v1/health/pubsub` | PubSub connectivity | Monitoring |
| `GET /v1/health/storage/local` | Storage write test | Monitoring |
| `GET /v1/health/anti-virus` | ClamAV connectivity | Monitoring |
| `GET /v1/health/certificate` | SSL cert validity | Monitoring |

## Gotchas

- **Dragonfly, not Redis** — production uses Dragonfly (Redis-compatible but better concurrency). Config is identical to Redis but behavior under load differs.
- **Four separate Dragonfly clusters** — cache, queue, usage-queue, and pubsub are isolated. Don't point queue workers at the cache cluster.
- **NFS latency** — storage mounts are NFS in production. File operations have network latency. The storage-specific deployment (`appwrite-storage`) can scale independently for this reason.
- **ProxySQL sharding** — the database isn't a single instance. ProxySQL routes by port to different physical shards. Connection strings include the proxy port, not the raw DB port.
- **KEDA, not HPA for workers** — API uses standard HPA (CPU-based), but workers use KEDA (queue-depth-based). Different autoscaling behavior and troubleshooting.
- **Worker `_APP_WORKERS_NUM` vs pod replicas** — `_APP_WORKERS_NUM` is Swoole parallelism WITHIN a pod. KEDA scales the number of pods. Total parallelism = pods * `_APP_WORKERS_NUM`.
- **Pre-stop hook** — API pods sleep 20s before shutdown to drain in-flight requests. Don't set `terminationGracePeriodSeconds` lower than 30s.
- **Builds are not autoscaled** — `worker-builds` has fixed replicas (1). Build throughput is bounded by executor capacity, not worker count.
- **Queue key format** — KEDA triggers match on `utopia-queue.queue.{name}`, not just `{name}`. Misconfigured key = no scaling.
- **Separate node pools** — production uses `workload=cloud-cache`, `workload=cloud-pubsub`, etc. as node selectors. Pods won't schedule without matching nodes.
- **SSL is not cert-manager** — Appwrite manages its own certificates via the certificates worker + Let's Encrypt ACME. The Gateway references a pre-populated K8s Secret, not a Certificate CRD.
- **Realtime is PubSub, not queue** — realtime events go through `pubsub-dragonfly`, not `queue-dragonfly`. If pubsub cluster is down, realtime events are lost (fire-and-forget).

## Related skills

- `appwrite-workers-expert` — worker architecture and queue patterns
- `appwrite-tasks-expert` — scheduled tasks that run as long-lived pods
- `appwrite-cloud-expert` — multi-region and cloud-specific infrastructure
