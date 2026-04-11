---
name: utopia-telemetry-expert
description: Expert reference for utopia-php/telemetry — OpenTelemetry metrics abstraction with Counter/UpDown/Histogram/Gauge/ObservableGauge and OTLP + Test + None adapters. Consult when emitting service metrics, wiring exemplars, or replacing Prometheus scraping.
---

# utopia-php/telemetry Expert

## Purpose
OpenTelemetry metrics abstraction (Counter/UpDownCounter/Histogram/Gauge/ObservableGauge) with a pluggable `Adapter` interface for emission and periodic `collect()` export.

## Public API
- `Utopia\Telemetry\Adapter` — interface with `createCounter/Histogram/Gauge/UpDownCounter/ObservableGauge` and `collect()`
- `Utopia\Telemetry\Counter`, `UpDownCounter`, `Histogram`, `Gauge`, `ObservableGauge` — instrument interfaces (`add`/`record`/`observe`)
- `Utopia\Telemetry\Adapter\OpenTelemetry` — real OTLP HTTP/protobuf emitter built on `open-telemetry/sdk`
- `Utopia\Telemetry\Adapter\None` — no-op
- `Utopia\Telemetry\Adapter\Test` — in-memory capture for assertions
- `Utopia\Telemetry\Exception`

## Core patterns
- **Adapter interface mirrors OpenTelemetry's Meter API 1:1** so the real adapter is a thin proxy over `MeterProvider`
- **Internal `$meterStorage` keyed by instrument class + name** acts as a per-process instrument cache — don't recreate counters in hot paths, instantiate once
- **`collect()` is caller-driven** (README shows `Swoole\Timer::tick(60_000, …)`) — no built-in push loop
- **Resource attributes** (`service.namespace`, `service.name`, `service.instance.id`) bound at adapter construction and stamp every exported metric
- `advisory` array plumbed through every `create*` call (histogram bucket hints, etc.)

## Gotchas
- Requires PECL **`ext-opentelemetry`** + **`ext-protobuf`** — not pure PHP deps, will break `composer install` on stock CI images
- `Sdk::builder()->buildAndRegisterGlobal()` fires in the OTel adapter constructor — constructing two instances races global SDK registration; use a shared container binding
- **Synchronous OTLP transport** via `symfony/http-client`; in FPM `collect()` is a blocking HTTP round-trip — only safe to call on Swoole Timer, CLI, or worker
- **No built-in exemplar/trace linkage** — emitted histograms have no span context unless you attach trace_id as an attribute yourself

## Appwrite leverage opportunities
- **Make telemetry the Prometheus/OTLP replacement** for the current stats collector: expose request duration as histogram + active requests as up-down counter, emitted from a single Swoole middleware so every service gets RED metrics for free
- **Attach `span.trace_id` to every metric record call** (`['trace_id' => Span::current()?->get('span.trace_id')]`) to enable exemplars in Tempo/Grafana for slow-request drill-down
- **Ship an `Adapter\Prometheus`** that buffers in APCu and exposes a `/metrics` endpoint — many Appwrite deployments already scrape Prometheus and don't want the OTLP collector
- **Bridge `utopia-php/audit` event counts to a Counter** (`audit.events.total{event,resource_type}`) so audit volume is observable without querying the audit DB

## Example
```php
use Utopia\Telemetry\Adapter\OpenTelemetry;

$telemetry = new OpenTelemetry(
    endpoint: 'http://otel-collector:4318/v1/metrics',
    serviceNamespace: 'appwrite',
    serviceName: 'api',
    serviceInstanceId: gethostname(),
);

$requests = $telemetry->createCounter('http.server.requests', '{request}');
$duration = $telemetry->createHistogram('http.server.request.duration', 'ms');

$requests->add(1, ['method' => $method, 'route' => $route, 'status' => $status]);
$duration->record($elapsedMs, ['method' => $method, 'route' => $route]);

Swoole\Timer::tick(30_000, fn () => $telemetry->collect());
```
