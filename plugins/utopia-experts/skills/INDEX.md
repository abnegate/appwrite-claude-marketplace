# Utopia Experts — Skill Index

Auto-generated index of the 55 `utopia-*-expert` skills in this plugin.
The `utopia-router` agent reads this file first to decide which 1-3 skills to load
for a given question. Regenerate with `bin/marketplace index` after any skill change.

## How to use

For surgical reference on one library, load the matching skill directly.
For cross-cutting questions, dispatch to the `utopia-router` agent which will
read this index, pick the most relevant skills, and return a synthesised answer.

## Framework core

| Skill | Description |
|---|---|
| `utopia-config-expert` | Expert reference for utopia-php/config — attribute-driven typed configuration loader that parses JSON/YAML/dotenv/env into validated readonly DTOs via reflection. Consult when taming env vars or implementing fail-fast boot validation. |
| `utopia-di-expert` | Expert reference for utopia-php/di — a ~100-line PSR-11 container with parent-child scoping and lazy singletons. Consult when wiring request-scoped services, mocking dependencies in tests, or debugging resolution order bugs. |
| `utopia-http-expert` | Expert reference for utopia-php/http — the minimalist PHP HTTP framework at the root of Appwrite. Consult when wiring routes, hooks, request scopes, or server adapters (FPM/Swoole/SwooleCoroutine) in any Appwrite-stack service. |
| `utopia-platform-expert` | Expert reference for utopia-php/platform — the OO scaffolding that turns routes/tasks/workers into reusable Action/Service/Module/Platform classes. Consult when organizing endpoint catalogues or refactoring closure-style controllers into classes. |
| `utopia-servers-expert` | Expert reference for utopia-php/servers — the shared base for Utopia http/cli/queue front-ends, providing static hook registries, mode flags, and the validation pipeline. Consult when debugging cross-runtime hook leakage or writing Utopia framework code. |

## Data layer

| Skill | Description |
|---|---|
| `utopia-database-expert` | Expert reference for utopia-php/database — the adapter-based CRUD/query/permission layer that backs every Appwrite collection. Consult for queries, attributes, relationships, transactions, the filter chain, cache invalidation, and adapter pitfalls. |
| `utopia-database-proxy-expert` | Expert reference for utopia-php/database-proxy — a Swoole HTTP service that fronts utopia-php/database adapters and exposes one POST /v1/queries endpoint per call. Consult when running Appwrite workers behind a centralized DB pool, wiring x-utopia-* headers (namespace, database, auth, tenant, share-tables, timeouts), or adjusting the secret/method-RPC contract. |
| `utopia-dsn-expert` | Expert reference for utopia-php/dsn — dependency-free DSN parser wrapping parse_url with required-field validation and lazy query-param parsing. Consult when adding connection-string handling or hunting credential-leak-via-logging bugs. |
| `utopia-mongo-expert` | Expert reference for utopia-php/mongo — the Swoole-native wire-protocol MongoDB client. Consult when debugging Mongo adapter issues, sizing pools, implementing replica-set failover, or hunting coroutine-blocking patterns. |
| `utopia-pools-expert` | Expert reference for utopia-php/pools — generic Pool<TResource> with reclaim, retry/reconnect, OpenTelemetry gauges, and Swoole Channel backend. Consult for sizing heuristics, leak hunting, and the Group::use() scoped borrow idiom. |
| `utopia-query-expert` | Expert reference for utopia-php/query — the standalone, backend-agnostic, serializable query DSL extracted from utopia-php/database. Consult when unifying SDK/REST/adapter query shapes or sharing queries across services. |

## Storage & I/O

| Skill | Description |
|---|---|
| `utopia-cache-expert` | Expert reference for utopia-php/cache — unified TTL-aware K/V cache over Redis/Memcached/Hazelcast/filesystem/memory with Sharding and Pool composition. Consult for stampede protection, read-time TTL semantics, and adapter composition. |
| `utopia-cdn-expert` | Expert reference for utopia-php/cdn — provider-agnostic cache purging (Cloudflare, Fastly) and CDN-managed TLS subscriptions (Fastly TLS). Consult when invalidating edge cache, provisioning certs for Appwrite Sites/Functions custom domains, or adding a new CDN backend. Note: `main` is currently a stub; the live API surface lives on the `feat/init-cdn-providers` branch. |
| `utopia-compression-expert` | Expert reference for utopia-php/compression — zero-dependency facade across Brotli/Deflate/GZIP/LZ4/Snappy/XZ/Zstd with HTTP Accept-Encoding negotiation. Consult for response compression middleware or storage-at-rest compression. |
| `utopia-fetch-expert` | Expert reference for utopia-php/fetch — HTTP client with Curl and Swoole adapters, auto-retry on 5xx, and a Response wrapper. Consult when hitting third-party APIs from workers, fixing timeout-in-ms bugs, or enabling Swoole non-blocking I/O. |
| `utopia-migration-expert` | Expert reference for utopia-php/migration — cross-service Resource migration engine (Appwrite/Supabase/NHost/Firebase/CSV/JSON sources and destinations). Consult for resumable migration design, parallel group execution, and source/destination extension. |
| `utopia-storage-expert` | Expert reference for utopia-php/storage — Device abstraction over local filesystem and S3-compatible object storage with chunked upload, cross-device transfer, and telemetry. Consult for bucket-to-bucket moves, multipart uploads, and adapter pitfalls. |

## Auth & security

| Skill | Description |
|---|---|
| `utopia-abuse-expert` | Expert reference for utopia-php/abuse — sliding/fixed-window rate limiting with Redis/RedisCluster/Database/Appwrite/ReCaptcha adapters. Consult for login throttling, sliding-window implementations, and per-tenant dimensioned keys. |
| `utopia-auth-expert` | Expert reference for utopia-php/auth — dependency-free password hashing, token generation, and authentication proof primitives. Consult for hash migration, MFA code generation, session envelope encoding. Note OAuth2 providers live in appwrite/appwrite, not here. |
| `utopia-jwt-expert` | Expert reference for utopia-php/jwt — single-class static JWT encode/decode supporting HS/RS/ES algorithms. Consult for key rotation, Functions runtime tokens, and clock-skew handling. Note main branch is empty; real code lives on feat-encode-decode. |
| `utopia-validators-expert` | Expert reference for utopia-php/validators — the dependency-free validator primitives used across Utopia framework routes and database attributes. Consult when composing validators, debugging the `Text` constructor arg order, or wiring SDK type mapping. |
| `utopia-waf-expert` | Expert reference for utopia-php/waf — dependency-free request rule engine with Condition DSL and Deny/Bypass/Challenge/RateLimit/Redirect actions. Consult when composing dynamic firewall rules from config or pairing with abuse for enforcement. |

## Runtime & system

| Skill | Description |
|---|---|
| `utopia-circuit-breaker-expert` | Expert reference for utopia-php/circuit-breaker — three-state breaker (CLOSED/OPEN/HALF_OPEN) protecting calls to misbehaving downstream deps, with optional shared state via Redis or Swoole\Table and OpenTelemetry counters/gauges/up-down counters via utopia-php/telemetry. Consult when wrapping flaky integrations, sizing thresholds, or wiring shared state across Swoole workers. |
| `utopia-cli-expert` | Expert reference for utopia-php/cli — lightweight CLI framework with task DSL, hooks, DI, and optional Swoole worker pooling. Consult when building Appwrite bin/* tasks, long-running workers, or when adding Swoole coroutines to batch jobs. |
| `utopia-orchestration-expert` | Expert reference for utopia-php/orchestration — thin abstraction over Docker socket API and Docker CLI. Consult when working on the Appwrite Functions executor, debugging container lifecycle, or planning a Kubernetes adapter. |
| `utopia-preloader-expert` | Expert reference for utopia-php/preloader — fluent helper for generating PHP opcache.preload scripts. Consult when shrinking Appwrite cold-start time or debugging preload-vs-ignore ordering bugs. |
| `utopia-proxy-expert` | Expert reference for utopia-php/proxy — high-performance Swoole proxy (HTTP/TCP/SMTP) with Resolver interface and optional BPF sockmap. Consult when replacing Traefik for custom domains, building tenant-aware routing, or adding backend health checks. |
| `utopia-swoole-expert` | Expert reference for utopia-php/swoole — the legacy Swoole adapter for utopia-php/framework v0.x (Request/Response/Files wrappers around Swoole\Http\Server). ARCHIVED upstream — superseded by utopia-php/http's first-party Swoole adapter. Consult only when maintaining services still pinned to `utopia-php/framework: 0.33.*` (e.g. older Appwrite microservices). |
| `utopia-system-expert` | Expert reference for utopia-php/system — zero-dependency static helper for host CPU/memory/disk/network/IO and architecture detection. Consult when wiring health endpoints, runtime tag selection, or container resource feeds to telemetry. |

## Observability

| Skill | Description |
|---|---|
| `utopia-analytics-expert` | Expert reference for utopia-php/analytics — product-analytics client for GA/Plausible/Mixpanel/HubSpot/Orbit/ReoDev. Consult when fixing coroutine-unsafe state, adding batching, or dual-writing events to audit for compliance. |
| `utopia-audit-expert` | Expert reference for utopia-php/audit — user action/audit log store with Database and ClickHouse adapters. Consult when wiring retention, moving audit off MySQL, or adding trace correlation to audit rows. |
| `utopia-logger-expert` | Expert reference for utopia-php/logger — structured error/warning reporting library with Sentry, AppSignal, Raygun, LogOwl adapters. Consult when wiring unified error tracking, adding trace correlation, or escaping synchronous push-per-error. |
| `utopia-span-expert` | Expert reference for utopia-php/span — minimal Swoole-coroutine-safe span tracer with W3C traceparent propagation and per-exporter sampling. Consult when wiring distributed tracing, linking logs/audit/metrics by trace ID, or choosing between Stdout/Pretty/Sentry exporters. |
| `utopia-telemetry-expert` | Expert reference for utopia-php/telemetry — OpenTelemetry metrics abstraction with Counter/UpDown/Histogram/Gauge/ObservableGauge and OTLP + Test + None adapters. Consult when emitting service metrics, wiring exemplars, or replacing Prometheus scraping. |

## Messaging & async

| Skill | Description |
|---|---|
| `utopia-async-expert` | Expert reference for utopia-php/async — Promises/A+ concurrency and true multi-core Parallel execution for PHP 8.1+, auto-selecting Swoole/React/Amp/Parallel/Sync. Consult for concurrent I/O, CPU-bound fanout, and timeout patterns. |
| `utopia-emails-expert` | Expert reference for utopia-php/emails — email parser/classifier with provider-aware canonicalization, free/disposable/corporate detection. Consult for signup gating, account dedupe, and validator composition. |
| `utopia-lock-expert` | Expert reference for utopia-php/lock — one Lock interface, four backends (Mutex, Semaphore, File, Distributed). Coroutine channels for in-process; flock for cross-process; Redis SET-NX-EX with Lua release for cross-host. Consult when serialising work, capping concurrency pools, or coordinating cron/job leases across an Appwrite cluster. |
| `utopia-messaging-expert` | Expert reference for utopia-php/messaging — multi-channel delivery library with 22+ adapters across Email/SMS/Push/Chat. Consult when wiring notification workers, adding provider fallback, or building GEOSMS-style regional routing. |
| `utopia-queue-expert` | Expert reference for utopia-php/queue — Redis/AMQP-backed job queue with Swoole/Workerman workers, DI-driven job handlers, and explicit Commit/NoCommit/Retryable ack semantics. Consult when building Appwrite workers, priority tiers, or dead-letter patterns. |
| `utopia-websocket-expert` | Expert reference for utopia-php/websocket — dependency-free abstraction over Swoole and Workerman WebSocket servers. Consult when building Appwrite realtime, implementing tenant isolation, or adding backpressure for slow clients. |

## Domain logic

| Skill | Description |
|---|---|
| `utopia-dns-expert` | Expert reference for utopia-php/dns — PHP 8.3+ DNS server/client toolkit with Native/Swoole adapters and Memory/Proxy/Cloudflare/Google resolvers. Consult when building authoritative DNS for custom domains, ACME DNS-01 challenges, or geoip routing. |
| `utopia-domains-expert` | Expert reference for utopia-php/domains — zero-dependency domain parser using the Mozilla Public Suffix List plus OpenSRS/NameCom registrar adapters. Consult for custom-domain onboarding, CNAME verification flows, and reseller integration. |
| `utopia-locale-expert` | Expert reference for utopia-php/locale — dependency-free i18n K/V translation library with placeholder interpolation and single-step fallback. Consult when wiring Console translations, debugging static-state leaks in Swoole, or adding plural support. |
| `utopia-pay-expert` | Expert reference for utopia-php/pay — payment provider abstraction (Stripe only, currently). Consult for authorize/capture flows, webhook signature verification, and when planning Paddle/LemonSqueezy adapters for EU VAT. |
| `utopia-vcs-expert` | Expert reference for utopia-php/vcs — webhook-driven Git provider abstraction with GitHub/GitLab/Gitea/Gogs/Forgejo adapters. Consult when wiring Appwrite Functions VCS integration, planning Bitbucket support, or fixing installation token caching. |

## Utilities

| Skill | Description |
|---|---|
| `utopia-ab-expert` | Expert reference for utopia-php/ab — simple server-side A/B test library with weighted variation selection. Consult when building Console/Cloud experiments and be aware it has no sticky assignment or coroutine safety out of the box. |
| `utopia-agents-expert` | Expert reference for utopia-php/agents — provider-agnostic AI agents library supporting OpenAI/Anthropic/Gemini/Deepseek/Perplexity/XAI/OpenRouter. Consult when building "Appwrite Assistant", tool calling, or SSE streaming to Realtime. |
| `utopia-detector-expert` | Expert reference for utopia-php/detector — project environment identification for Appwrite Sites/Functions (runtime, framework, packager, rendering). Consult when auto-configuring Sites builds or Function runtimes. NOT a user-agent detector despite the name. |
| `utopia-image-expert` | Expert reference for utopia-php/image — Imagick-backed image manipulation for the Appwrite Storage preview pipeline. Consult when adding format conversions, tuning quality defaults, or stripping EXIF for privacy. |
| `utopia-registry-expert` | Expert reference for utopia-php/registry — dependency-free lazy DI container with named contexts for isolation. Consult when managing per-coroutine service lifetimes or debugging Swoole cross-request bleed. |
| `utopia-view-expert` | Expert reference for utopia-php/view — minimalist .phtml rendering engine with `setParam`/`print`, registerable filters (escape, nl2p), parent/child composition via `exec()`, and an opt-in whitespace minifier that preserves `<textarea>`/`<pre>`. Consult when wiring server-rendered pages, transactional emails, or the legacy public site templates in Appwrite. |

## Misc

| Skill | Description |
|---|---|
| `utopia-balancer-expert` | Expert reference for utopia-php/balancer — framework-agnostic client-side load balancer with Random/First/Last/RoundRobin algorithms, chained filters, and OTel-instrumented Group failover. Consult when picking executor nodes or wiring storage-adapter failover. |
| `utopia-cloudevents-expert` | Expert reference for utopia-php/cloudevents — minimal PHP 8.3 CloudEvents v1.0.2 implementation. Consult when standardizing event envelopes between Appwrite services, Functions, webhooks, and Knative/Dapr consumers. |
| `utopia-console-expert` | Expert reference for utopia-php/console — static helper for CLI output (colored log levels), prompts, subprocess execution with timeout, and a long-running daemon loop with automatic GC. Consult when building Appwrite bin workers or replacing ad-hoc echo/var_dump. |
| `utopia-usage-expert` | Expert reference for utopia-php/usage — currently a STUB repository with no src. The active rebuild lives on claude/rebuild-analytics-clickhouse-OHWGZ. Consult before depending on this library in production. |

## Composition notes for the router

Some questions naturally span multiple skills. Known pairings:

- **Observability pipeline** — `utopia-span-expert` + `utopia-logger-expert` + `utopia-telemetry-expert` + `utopia-audit-expert` + `utopia-analytics-expert`
- **Swoole pool stack** — `utopia-pools-expert` + `utopia-database-expert` + `utopia-cache-expert` + `utopia-mongo-expert`
- **SDK regen cascade** — `utopia-http-expert` + `utopia-validators-expert` + `utopia-platform-expert`
- **Custom-domain onboarding** — `utopia-domains-expert` + `utopia-dns-expert` + `utopia-vcs-expert`
- **Ingestion pipeline** — `utopia-cloudevents-expert` + `utopia-clickhouse-expert` + `utopia-usage-expert`
- **Rate limiting** — `utopia-abuse-expert` + `utopia-waf-expert` + `utopia-cache-expert`
- **Messaging worker** — `utopia-messaging-expert` + `utopia-queue-expert` + `utopia-async-expert`

When a question matches a pairing, the router should load all relevant skills rather than picking just one.
