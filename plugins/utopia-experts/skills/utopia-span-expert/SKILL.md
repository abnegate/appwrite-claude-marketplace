---
name: utopia-span-expert
description: Expert reference for utopia-php/span — minimal Swoole-coroutine-safe span tracer with W3C traceparent propagation and per-exporter sampling. Consult when wiring distributed tracing, linking logs/audit/metrics by trace ID, or choosing between Stdout/Pretty/Sentry exporters.
---

# utopia-php/span Expert

## Purpose
Minimal, Swoole-coroutine-safe span tracer: create a span, attach scalar attributes, finish, export; supports W3C traceparent propagation and pluggable exporters with per-exporter sampling.

## Public API
- `Utopia\Span\Span` — static facade + instance:
  - statics: `setStorage`, `addExporter`, `resetExporters`, `resetStorage`, `reset`, `init`, `current`, `add`, `traceparent`
  - instance: `set`, `get`, `getAttributes`, `getAction`, `setError`, `getError`, `getTraceparent`, `finish(?string $level = null, ?Throwable $error = null)`
- `Utopia\Span\Storage\Storage` — storage interface
- `Utopia\Span\Storage\{Memory, Coroutine, Auto}` — per-runtime context containers
- `Utopia\Span\Exporter\Exporter` — single-method interface (`export(Span $span)`)
- `Utopia\Span\Exporter\{Stdout, Pretty, Sentry, None}`

## Core patterns
- **Single flat attribute map, scalars only** — no nested span tree, one span per operation, IDs make the trace
- **`Storage\Auto`** picks `Coroutine` when Swoole is loaded, `Memory` otherwise — coroutine-safe by default (`Swoole\Coroutine::getContext()`), no manual plumbing
- **Errors funnel through `finish()`** — pass the throwable as the second argument (`$span->finish(level: null, error: $e)`) and finish populates `$this->error` plus the exporter-visible `level` (`'error'` if an error is present, otherwise the explicit `$level` argument or `'info'`). `setError()` is still available for the rare flow where you stamp the error before reaching the finish call site
- **Per-exporter sampler closure** evaluated on `finish()` — errors, slow spans, or enterprise customers can be sampled independently per sink (e.g. Sentry only on errors, Stdout always)
- **W3C Traceparent parsed strictly** (`00-<32 hex>-<16 hex>-<2 hex>`) — valid header sets `trace_id` and `parent_id` automatically
- **Built-in attributes**: `span.trace_id` / `span.id` / `span.started_at` / `span.finished_at` / `span.duration` stamped by the constructor and `finish()`

## Gotchas
- **"Flat span" model is not OpenTelemetry-compatible** — there's no span parent/child tree within a process, only a trace_id link; if you need proper nested spans you'll outgrow this quickly
- **Static state on the `Span` class** (`$storage`, `$exporters`) — tests must call `Span::reset()` or share state leaks across cases
- Sentry exporter skips non-error spans by design — don't rely on it for perf tracing
- Only scalars allowed as attribute values — arrays/objects must be JSON-encoded by the caller; silently no-ops if you violate this after serialization

## Appwrite leverage opportunities
- **Use `Span::init('http.request', $request->getHeader('traceparent'))` at the framework entrypoint** and `Span::traceparent()` on every outbound request (`utopia-php/fetch`, PDO wrapper) to get end-to-end distributed traces across Appwrite's microservices with zero config
- **Add an `Exporter\OTLP`** that POSTs W3C spans to `/v1/traces` on the same collector telemetry already talks to — currently Stdout/Pretty/Sentry are the only sinks, limiting it to dev/error use
- **Bridge to logger + audit**: framework's exception handler calls `$log->addTag('trace_id', Span::current()?->get('span.trace_id'))` and the audit `data` column stamps the same, making trace ID the universal join key across all five observability libraries
- **Replace current stats middleware with a Span-based one** that records `http.method/route/status` and a `duration_ms` attribute, sampled 1% + 100% on errors

## Example
```php
use Utopia\Span\{Span, Storage, Exporter};

Span::setStorage(new Storage\Auto());
Span::addExporter(new Exporter\Stdout());
Span::addExporter(
    new Exporter\Sentry(getenv('_APP_SENTRY_DSN')),
    sampler: fn (Span $s) => $s->getError() !== null || $s->get('span.duration') > 5.0,
);

$span = Span::init('http.request', $request->getHeader('traceparent'));
$span->set('http.method', $request->getMethod());
$span->set('http.route', $route->getPath());
$error = null;
try {
    $response = $app->run();
    $span->set('http.status', $response->getStatusCode());
} catch (Throwable $e) {
    $error = $e;
    throw $e;
} finally {
    // finish(level, error): pass the throwable here so the exporter sees level=error
    $span->finish(error: $error);
}
```

## Cross-library composition (observability pipeline)

The five observability libraries (logger, telemetry, audit, analytics, span) should wire together as one pipeline. Ideal wiring:
1. `Span::init` at every request boundary
2. `span.trace_id` stamped into every `Logger\Log` as a tag
3. Every `Audit\Log`'s `data` map carries the trace_id
4. Every `Telemetry` metric record gets trace_id as an attribute (for exemplars)
5. Outbound `Analytics\Event` props carry it too

Telemetry emits RED metrics at request boundary driven off `span.duration`; audit and analytics decorators emit `Counter('*.events.total{status}')` so all ingestion is observable. Logger, audit, and analytics should all gain buffered/queue adapters so nothing blocks the request path — span's sampler pattern is the model the others should copy.
