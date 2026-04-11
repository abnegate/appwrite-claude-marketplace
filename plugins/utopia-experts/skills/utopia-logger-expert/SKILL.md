---
name: utopia-logger-expert
description: Expert reference for utopia-php/logger — structured error/warning reporting library with Sentry, AppSignal, Raygun, LogOwl adapters. Consult when wiring unified error tracking, adding trace correlation, or escaping synchronous push-per-error.
---

# utopia-php/logger Expert

## Purpose
Dependency-free structured error/warning reporting library that pushes rich `Log` envelopes (breadcrumbs, tags, user, extras) to third-party error trackers.

## Public API
- `Utopia\Logger\Logger` — facade holding an adapter and optional sample rate
- `Utopia\Logger\Adapter` — abstract base (`push`, `getSupportedTypes`, `validate`)
- `Utopia\Logger\Log` — log envelope with action/namespace/server/type/version/message/user
- `Utopia\Logger\Log\Breadcrumb` — timestamped event crumb (type/category/message)
- `Utopia\Logger\Log\User` — user identity object
- Adapters: `Sentry`, `AppSignal`, `Raygun`, `LogOwl`

## Core patterns
- **Adapter validates envelope** against `getSupportedTypes/Environments/BreadcrumbTypes` before pushing, mapping Utopia's taxonomy onto each provider's enum
- **Synchronous HTTP push per log** (README explicitly notes "future could pool")
- **Client-side sampling** via `setSample(float)` (percentage gate)
- **Fat envelope** carries action, namespace, server, version, user, breadcrumbs, tags and extras so adapter shapes one payload
- Required fields enforced at `addLog()`; throws `Exception` if any of action/environment/message/type/version are empty

## Gotchas
- **Zero async/buffer** — every `addLog` is a blocking curl in-request; wrap in a deferred worker or you pay latency per error
- `samplePercent` uses `rand(1,100) >= percent*100`, so `setSample(1.0)` means always include but edge math is subtle; no deterministic trace-based sampling
- Adapter contract is PHP-level only (no interface), `push()` returns an int HTTP code; failures swallow into `500` in `addLog` unless you check return
- **No correlation ID field** — no built-in hook for trace ID, so cross-library stitching requires abusing `addTag('traceId', …)`

## Appwrite leverage opportunities
- **Wire `Span::current()?->get('span.trace_id')` into every `$log->addTag('trace_id', …)`** at the framework error handler so Sentry issues link back to the span that produced them
- **Replace synchronous `push()` with a Swoole channel-backed queue** flushed from a `Coroutine\run` worker; today each uncaught exception costs a full HTTP round-trip on the request path
- **Add an OTLP-logs adapter** (`open-telemetry/exporter-otlp` is already pulled in by telemetry) so a single OTLP endpoint can receive spans + metrics + logs, eliminating three vendor SDKs
- **Promote the `PROVIDERS` const list to a runtime registry** so Cloud can plug in ClickHouse/Loki adapters without touching the library

## Example
```php
use Utopia\Logger\{Logger, Log};
use Utopia\Logger\Log\{Breadcrumb, User};
use Utopia\Logger\Adapter\Sentry;

$log = (new Log())
    ->setAction('database.deleteDocument')
    ->setEnvironment('production')
    ->setNamespace('api')
    ->setServer(gethostname())
    ->setVersion(APP_VERSION)
    ->setType(Log::TYPE_ERROR)
    ->setMessage($exception->getMessage())
    ->setUser(new User($userId));

$log->addBreadcrumb(new Breadcrumb(Log::TYPE_DEBUG, 'http', 'DELETE /v1/databases/x', microtime(true)));
$log->addTag('trace_id', Span::current()?->get('span.trace_id') ?? '');

(new Logger(new Sentry(getenv('_APP_LOGGING_CONFIG'))))->addLog($log);
```
