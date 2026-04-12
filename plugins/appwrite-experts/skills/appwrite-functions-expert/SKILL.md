---
name: appwrite-functions-expert
description: Serverless functions — deployments, builds, executions, runtimes, variables, and the executor service. Covers the Functions module (30 actions) plus builds and executions workers.
---

# Appwrite Functions Expert

## Module structure

`src/Appwrite/Platform/Modules/Functions/` — 2 services (Http, Workers).

Key files:
- `Services/Http.php` — HTTP action registration
- `Http/` — action classes (functions CRUD, deployments, executions, variables)
- `Workers/Builds.php` — compiles deployments into runnable artifacts
- `Workers/Screenshots.php` — captures site deployment previews

Workers (core):
- `src/Appwrite/Platform/Workers/Functions.php` — dispatches executions to the executor
- `src/Appwrite/Platform/Workers/Executions.php` — persists execution results

## Function lifecycle

1. **Create function** — `POST /v1/functions` with runtime, entrypoint, commands, timeout, schedule
2. **Create deployment** — `POST /v1/functions/{id}/deployments` (upload code or link VCS)
3. **Build** — Builds worker clones/extracts code, runs build commands, uploads artifact
4. **Activate** — Set deployment as active: `PATCH /v1/functions/{id}/deployments/{deploymentId}`
5. **Execute** — `POST /v1/functions/{id}/executions` or triggered by event/schedule/HTTP

## Build pipeline

`Workers/Builds.php` handles two build types:
- `BUILD_TYPE_DEPLOYMENT` — standard build from uploaded code or VCS
- `BUILD_TYPE_RETRY` — retry a previously failed build

Build flow:
1. Clone from VCS (GitHub adapter) or extract uploaded archive
2. Detect runtime from function config
3. Call Executor service to build (installs dependencies, compiles)
4. Upload build artifact to storage device (`deviceForBuilds`)
5. Update deployment status: `ready` or `failed`
6. Fire realtime event + webhook

VCS integration: `src/Appwrite/Platform/Modules/VCS/` handles GitHub installations, repository webhooks, and automatic deployments on push.

## Execution flow

`Workers/Functions.php`:
1. Resolve user context (if userId provided)
2. Generate JWT for function runtime access
3. Call Executor with: runtime, code path, entrypoint, timeout, env vars, request body
4. Executor returns: status code, response body, headers, duration, logs
5. Persist execution document via `Workers/Executions.php`
6. Fire event via bus: `ExecutionCompleted`

Executor service (`inject('executor')`) is the bridge to `open-runtimes` — it manages runtime containers, cold starts, and execution isolation.

## Scheduling

Two scheduling paths:

**Cron functions** — `Tasks/ScheduleFunctions.php`:
- Reads `schedules` collection for active function schedules
- Parses cron expression, calculates next run
- Groups by delay, spawns coroutines that sleep until execution time
- Enqueues to `v1-functions` queue

**One-time executions** — `Tasks/ScheduleExecutions.php`:
- For delayed executions (scheduledAt in the future)
- Shorter polling interval (3s update, 4s enqueue)
- Deletes schedule after enqueueing

## Variables

Function variables stored as documents in `variables` collection:
- Scoped to function (not deployment)
- Injected as environment variables at execution time
- Can be overridden per-execution via request body

System variables injected automatically:
- `APPWRITE_FUNCTION_ID`, `APPWRITE_FUNCTION_NAME`
- `APPWRITE_FUNCTION_DEPLOYMENT`, `APPWRITE_FUNCTION_PROJECT_ID`
- `APPWRITE_FUNCTION_RUNTIME_NAME`, `APPWRITE_FUNCTION_RUNTIME_VERSION`

## Event-triggered functions

Functions can subscribe to events via `events` attribute:
```
databases.*.collections.*.documents.*.create
users.*.create
storage.*.buckets.*.files.*.create
```

The Functions worker matches event patterns against function subscriptions and enqueues execution if matched.

## Resource blocking (cloud)

The `isResourceBlocked` callable checks if a function is blocked before execution:
- Project-wide blocks
- Function-specific blocks
- Expiration-based temporary blocks

## Gotchas

- Build is async — creating a deployment returns immediately with `status: 'processing'`
- The active deployment is a property on the function document, not the deployment
- Cold starts: first execution after deploy or idle timeout is slower
- Execution timeout is per-execution, not cumulative — a function that times out on one request doesn't affect the next
- Logs are captured from stdout/stderr of the runtime container — `console.log` (Node), `print` (Python), `echo` (PHP)
- The executor connection is HTTP-based — `_APP_EXECUTOR_HOST` env var points to the executor service
- VCS deployments auto-build on push — the webhook handler in the VCS module triggers the build worker
- Function size limit controlled by `_APP_FUNCTIONS_SIZE_LIMIT` env var

## Related skills

- `appwrite-workers-expert` — queue system and worker lifecycle
- `appwrite-tasks-expert` — scheduling system (ScheduleBase)
- `appwrite-cloud-expert` — cloud-specific compute features
