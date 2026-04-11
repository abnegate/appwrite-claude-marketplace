---
name: utopia-analytics-expert
description: Expert reference for utopia-php/analytics — product-analytics client for GA/Plausible/Mixpanel/HubSpot/Orbit/ReoDev. Consult when fixing coroutine-unsafe state, adding batching, or dual-writing events to audit for compliance.
---

# utopia-php/analytics Expert

## Purpose
Thin product-analytics client that fans user events/pageviews out to external SaaS analytics providers (GA, Plausible, Mixpanel, HubSpot, Orbit, Reo.dev).

## Public API
- `Utopia\Analytics\Adapter` — abstract base (`send`, `validate`, `createEvent`, `enable`/`disable`, `setClientIP`, `setUserAgent`)
- `Utopia\Analytics\Event` — type/url/name/value/props payload
- Adapters: `GoogleAnalytics`, `Plausible`, `Mixpanel`, `HubSpot`, `Orbit`, `ReoDev`
- `enable()` / `disable()` toggle per-adapter (for opt-out / DNT handling)

## Core patterns
- **Adapter owns its own HTTP transport** — each adapter has its own `send`; no shared fetch abstraction, so headers/auth are inlined per provider
- **`Event` is a mutable builder** (`setType`, `setUrl`, `setName`, `setValue`, fluent), and `$props` is a loose assoc array the adapter flattens to provider-specific params
- **`validate()` is a live-fire test**: sends a real event and confirms acceptance — useful for health checks, costly in hot paths
- **Client IP / User-Agent are adapter state, not per-event**, so you must `setClientIP` per request before `createEvent`
- **`enabled` flag** allows cheap runtime gating without tearing down the adapter

## Gotchas
- No dependencies at all — each adapter implements its own curl/fetch, so error handling is inconsistent across providers
- `Event` has a single `setValue` string and an untyped `$props` array — no currency/revenue/item fields, no SemConv mapping; fine for pageview/event, not for ecommerce
- **Synchronous send** — every `createEvent` is a network call; for HubSpot/Mixpanel you'll want to batch
- **`clientIP` is instance state**, which is a footgun in Swoole where adapters are often cached across coroutines — you WILL leak another user's IP into events

## Appwrite leverage opportunities
- **Replace instance state (`clientIP`, `userAgent`) with per-call parameters on `createEvent`** to make the adapter coroutine-safe; file a PR before Cloud hits a cross-user IP leak
- **Dual-write analytics events into `utopia-php/audit`** via a decorator adapter: `class AuditingAnalytics implements Adapter { … send(Event $e) { $this->audit->log(…); return $this->inner->send($e); } }` — gives you local source of truth for compliance even if Mixpanel goes down
- **Add a buffering adapter** that writes to a Swoole channel and batch-flushes (Mixpanel `/track` accepts arrays up to 2000 events, HubSpot batch API is 100) to amortise HTTPS handshakes
- **Emit `telemetry.Counter('analytics.events.total{provider,status}')`** from a wrapping adapter so you can Grafana-alert when Plausible silently drops events

## Example
```php
use Utopia\Analytics\Event;
use Utopia\Analytics\Adapter\Plausible;

$analytics = new Plausible('appwrite.io', 'api-key', 'secret');
$analytics->setClientIP($request->getIP())
          ->setUserAgent($request->getUserAgent());

$event = (new Event())
    ->setType('pageview')
    ->setUrl('https://appwrite.io/docs/installation')
    ->setName('docs.installation');

$analytics->createEvent($event);
```
