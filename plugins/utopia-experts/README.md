# utopia-experts

One Claude Code skill per library in the [utopia-php](https://github.com/utopia-php) ecosystem. Each skill is a dense, opinionated reference that Claude consults on demand: public API surface, core patterns, gotchas the docs don't mention, and an "Appwrite leverage opportunities" section with specific suggestions for extracting more value from the library.

## What's in it

**50 expert skills**, one per library:

### Framework core
- `utopia-http-expert` — minimalist MVC framework; FPM/Swoole/SwooleCoroutine adapters; per-request child containers
- `utopia-di-expert` — PSR-11 container with parent-child scoping and lazy singletons
- `utopia-servers-expert` — shared base for HTTP/CLI/Queue with static hook registries
- `utopia-platform-expert` — Action/Service/Module/Platform OO scaffolding
- `utopia-config-expert` — attribute-driven typed config DTOs with reflection validation

### Data layer
- `utopia-database-expert` — the big one; adapters, queries, permissions, Mirror, filters, transactions
- `utopia-mongo-expert` — Swoole-native wire-protocol Mongo client
- `utopia-query-expert` — standalone serializable query DSL
- `utopia-pools-expert` — generic connection pool with reclaim/retry/telemetry
- `utopia-dsn-expert` — dependency-free connection-string parser

### Storage & I/O
- `utopia-storage-expert` — Device abstraction over local FS and S3-compatible stores
- `utopia-cache-expert` — Redis/Memcached/Hazelcast/Filesystem/Memory/Sharding/Pool cache
- `utopia-fetch-expert` — HTTP client with Curl and Swoole adapters (watch the ms timeouts)
- `utopia-compression-expert` — Brotli/Deflate/GZIP/LZ4/Snappy/XZ/Zstd with Accept-Encoding negotiation
- `utopia-migration-expert` — cross-service resource migration engine

### Auth & security
- `utopia-auth-expert` — password hashing and token/code/phrase proofs (no OAuth2 here — that lives in appwrite/appwrite)
- `utopia-jwt-expert` — static JWT encode/decode (watch: real code lives on `feat-encode-decode` branch)
- `utopia-abuse-expert` — fixed-window rate limiting with Redis/Database/ReCaptcha adapters
- `utopia-waf-expert` — Condition-based firewall rule engine (pairs with abuse for enforcement)
- `utopia-validators-expert` — Text/Range/Domain/Multiple/Nullable/etc. validation primitives

### Runtime & system
- `utopia-cli-expert` — task DSL with DI, optional Swoole worker pool
- `utopia-system-expert` — host CPU/memory/disk/network/IO/arch detection
- `utopia-orchestration-expert` — Docker API and CLI adapters (Kubernetes adapter is an open slot)
- `utopia-preloader-expert` — `opcache.preload` script generator
- `utopia-proxy-expert` — Swoole HTTP/TCP/SMTP proxy with Resolver + optional BPF sockmap

### Observability
- `utopia-logger-expert` — Sentry/AppSignal/Raygun/LogOwl adapters
- `utopia-telemetry-expert` — OpenTelemetry metrics abstraction
- `utopia-audit-expert` — user action log with Database and ClickHouse adapters
- `utopia-analytics-expert` — product analytics fanout (GA/Plausible/Mixpanel/HubSpot/Orbit/ReoDev)
- `utopia-span-expert` — coroutine-safe span tracer with W3C traceparent propagation

### Messaging & async
- `utopia-messaging-expert` — 22+ adapters across Email/SMS/Push/Chat providers
- `utopia-queue-expert` — Redis/AMQP job queue with explicit Commit/NoCommit/Retryable acks
- `utopia-websocket-expert` — Swoole/Workerman WebSocket abstraction
- `utopia-async-expert` — Promise + Parallel with auto-detected adapters
- `utopia-emails-expert` — email parser/classifier with provider canonicalization

### Domain logic
- `utopia-pay-expert` — payments (currently Stripe-only)
- `utopia-vcs-expert` — GitHub/GitLab/Gitea/Gogs/Forgejo adapters (README underclaims coverage)
- `utopia-domains-expert` — PSL-backed domain parser + OpenSRS/NameCom registrar
- `utopia-dns-expert` — PHP 8.3+ authoritative DNS server/client toolkit
- `utopia-locale-expert` — K/V i18n with placeholder interpolation and single-step fallback

### Utilities
- `utopia-ab-expert` — weighted A/B test library (no sticky assignment out of the box)
- `utopia-registry-expert` — lazy DI with named contexts
- `utopia-detector-expert` — project runtime/framework/packager detection for Sites/Functions (NOT a UA detector)
- `utopia-image-expert` — Imagick-backed image manipulation for Storage previews
- `utopia-agents-expert` — provider-agnostic AI agents across OpenAI/Anthropic/Gemini/etc.

### Misc
- `utopia-console-expert` — static CLI helpers and `Console::loop()` with self-correcting GC
- `utopia-cloudevents-expert` — CloudEvents v1.0.2 envelope
- `utopia-clickhouse-expert` — thin HTTP ClickHouse client (warning: addslashes-based escaping)
- `utopia-balancer-expert` — client-side LB with Random/First/Last/RoundRobin
- `utopia-usage-expert` — **STUB** — this library has no source on `main`; rebuild in progress

## How each skill is structured

Every skill follows the same shape so Claude can synthesize across them:

```
## Purpose          — one-sentence summary
## Public API       — 4-8 key classes/interfaces with one-line roles
## Core patterns    — 3-5 idioms that repeat across typical usage
## Gotchas          — 2-4 pitfalls that would otherwise bite you
## Appwrite leverage opportunities
                    — The "ultrathink" section: specific, technical,
                      actionable suggestions for extracting more
                      value from the library in Appwrite-stack work
## Example          — 10-20 lines of compilable idiomatic PHP
```

The leverage-opportunities section is the point of this plugin. It's where research on each library turned up cross-library composition patterns, missing adapters, performance wins, test-double gaps, and observability hooks that aren't wired — the stuff you'd normally only discover by hitting the pain in production.

## Cross-library composition highlights

Several leverage opportunities appear across multiple skills because they form natural pipelines:

1. **Observability wiring** (span + logger + telemetry + audit + analytics): `Span::init` at every request boundary, `span.trace_id` stamped into every log tag, audit `data` map, telemetry metric attribute, and analytics event prop. Makes trace_id the universal join key across all five libraries. Documented in detail in `utopia-span-expert`.

2. **Queue + Messaging** for provider fallback: wrap messaging adapters in a queue worker with per-provider token buckets, automatic fallback chain (Sendgrid → Mailgun → Resend), and dead-letter adapters for exhausted retries.

3. **Cache + Pools + Sharding** for Swoole workers: `Adapter\Sharding([new Pool($poolA), new Pool($poolB)])` gives both connection reuse and horizontal split — the pattern Appwrite Cloud should document as default.

4. **CloudEvents + ClickHouse + Usage** form the future event pipeline: CloudEvents on the producer side, ClickHouse for OLAP storage, `utopia-php/usage` (when it ships) for aggregation semantics.

5. **Domains + DNS + Vcs** for custom-domain onboarding: PSL parse → authoritative DNS server with database-backed resolver → ACME DNS-01 challenge via `_acme-challenge.*` TXT records → Let's Encrypt wildcard issuance.

## Install

Via the marketplace at the repo root:

```
/plugin marketplace add abnegate/appwrite-claude-marketplace
/plugin install utopia-experts@appwrite-claude-marketplace
```

Skills load automatically in any session where Claude detects Appwrite-stack context (composer.json with `utopia-php/*`, directory names matching Appwrite repos, etc.).

## Research methodology

The 50 skills were built via parallel research subagents fanning out across `https://github.com/utopia-php`, with each agent handling ~5 libraries and returning structured findings. Sources: `gh api` for READMEs, `src/` directory listings, `composer.json` dependencies, and sampled source files. Where the README lagged behind the source tree (e.g., `utopia-php/vcs` listing only GitHub when the source has 5 Git adapters), the skills reflect the source.

Findings that surfaced real upstream issues — `utopia-php/jwt` main branch being empty, the RS*/ES* verify path in JWT using `openssl_pkey_get_private` instead of `openssl_pkey_get_public`, the `utopia-php/abuse` circular dependency on `appwrite/appwrite` — are called out in the gotchas sections and in several cases recommended as upstream PR opportunities.
