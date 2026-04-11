---
name: utopia-cloudevents-expert
description: Expert reference for utopia-php/cloudevents — minimal PHP 8.3 CloudEvents v1.0.2 implementation. Consult when standardizing event envelopes between Appwrite services, Functions, webhooks, and Knative/Dapr consumers.
---

# utopia-php/cloudevents Expert

## Purpose
Minimal, strict PHP 8.3 implementation of the CloudEvents v1.0.2 spec — a single immutable value object with array serialization and validation, used to standardize event envelopes between Appwrite services (functions, workers, webhooks).

## Public API
- `Utopia\CloudEvents\CloudEvent` — single `readonly` class, all fields as constructor-promoted properties
- `new CloudEvent(specversion, type, source, subject, id, time, datacontenttype, data)`
- `CloudEvent::fromArray(array): self`
- `CloudEvent->toArray(): array`
- `CloudEvent->validate(): bool` (throws `InvalidArgumentException`)

## Core patterns
- **Immutable by design** — all properties are `public readonly`, no setters. Mutations require reconstruction. Matches CloudEvents spec intent that events are facts, not state
- **Named constructor arguments** are the intended call style — positional is painful with 8 parameters
- **`fromArray` strictly validates** `specversion === '1.0'` and non-empty `type`; all other fields are optional-ish or defaulted. `subject` is the only nullable field
- **No HTTP/Kafka transport baked in** — this is pure envelope modeling. Pair with your own HTTP client or queue producer
- **Zero runtime dependencies**, PHPStan level 8 — safe to embed anywhere

## Gotchas
- **`fromArray` does NOT default `source`, `id`, or `time`** — it accesses them unconditionally and will raise a PHP warning/error if missing, despite the README calling them "required." Pre-validate or wrap in try/catch
- **`validate()` only checks `specversion` and `type`** — will NOT catch missing `id`, malformed `time` (not RFC3339), or unknown extension attributes. Do your own semantic validation layer
- **No extension attribute support** — the CloudEvents spec allows arbitrary `ce-*` headers, but this library only supports the core set. For `traceparent` for OTel distributed tracing, you must shoehorn it into `data`
- **`data` is `array<string, mixed>`** — binary mode (`data_base64`) from the spec is not modeled. Binary payloads need to be base64-encoded into `data` manually

## Appwrite leverage opportunities
- **Wrap all events published to the event queue** (`project.{id}.collections.{id}.documents.*.create`) in CloudEvent envelopes before they hit Redis/RabbitMQ. Workers decode via `fromArray`, and you get trace correlation via `id` for free
- **Functions runtime** already receives an `APPWRITE_FUNCTION_EVENT` payload — re-encode as CloudEvent so function SDKs across all 11 languages can consume a standard format instead of Appwrite-specific shapes
- **Use `subject` for `projectId` and `type` for the existing dot-namespaced event name** — minimal change, maximum spec compliance. Webhooks can then send `Content-Type: application/cloudevents+json` and be consumed by Knative/Dapr/Kafka tools off-the-shelf
- **For usage/metering events into ClickHouse**, CloudEvent provides a consistent schema header — the ingestion worker knows the shape regardless of producer service

## Example
```php
use Utopia\CloudEvents\CloudEvent;

$event = new CloudEvent(
    type: 'databases.documents.create',
    source: 'appwrite/api',
    subject: $projectId,
    id: ID::unique(),
    time: DateTime::formatTz(DateTime::now()),
    data: ['documentId' => $document->getId(), 'collectionId' => $collection->getId()],
);
$event->validate();
$redis->xadd('events', '*', ['payload' => json_encode($event->toArray())]);

$raw = json_decode($redis->xread(...)[0][1], true);
$received = CloudEvent::fromArray($raw);
```
