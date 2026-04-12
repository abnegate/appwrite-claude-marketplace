---
name: appwrite-realtime-expert
description: Realtime subscriptions, channels, event publishing, and the WebSocket server in Appwrite.
---

# Appwrite Realtime Expert

## Entry point

`app/realtime.php` — Swoole WebSocket server, separate process from the HTTP server.

## Architecture

```
HTTP Route → event label → queueForRealtime → Realtime Server → WebSocket clients
```

The realtime server does NOT use a queue broker — events are published directly via PubSub (Redis).

## Channel model

Clients subscribe to channels that match event patterns:

```
// Subscribe to all documents in a collection
databases.{dbId}.collections.{collectionId}.documents

// Subscribe to a specific document
databases.{dbId}.collections.{collectionId}.documents.{docId}

// Subscribe to all files in a bucket
buckets.{bucketId}.files

// Subscribe to user account changes
account

// Subscribe to team memberships
memberships
```

## Event publishing from routes

Routes declare events via labels:
```php
->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].create')
```

Bracket placeholders (`[param]`) are replaced with actual parameter values at runtime. The resulting event string is published via `queueForRealtime`.

## Event payload

```php
$queueForEvents
    ->setParam('databaseId', $databaseId)
    ->setParam('collectionId', $collectionId)
    ->setParam('documentId', $document->getId())
    ->setPayload($response->output($document, Response::MODEL_DOCUMENT));
```

The payload is the serialized response model — clients receive the same shape as a REST response.

## Permission filtering

The realtime server checks permissions before delivering events:
- Each WebSocket connection is authenticated (session cookie or JWT)
- The user's roles are resolved (user ID, team memberships, labels)
- Events are only delivered if the user has `read` permission on the resource
- Permission checking uses the same `Authorization` class as REST routes

## Connection lifecycle

1. Client opens WebSocket to `wss://{host}/v1/realtime`
2. Authenticates via query param (`project` + session cookie)
3. Sends subscription message with channel list
4. Server registers channels for the connection
5. Events matching subscribed channels are pushed as JSON frames
6. Connection closed on timeout, client disconnect, or server shutdown

## PubSub

Events flow through Redis PubSub (not the queue system):
- HTTP server publishes to a Redis channel
- Realtime server subscribes to the same channel
- Zero-queue delivery — events are real-time

PubSub pool registered in `app/init/resources/` via the `pools` system.

## Event pattern matching

The realtime server matches incoming events against subscriptions using dot-separated pattern matching:

```
Event:        databases.db1.collections.col1.documents.doc1.create
Subscription: databases.db1.collections.col1.documents
→ MATCH (subscription is a prefix of the event)
```

Wildcards are implicit — subscribing to `databases.db1.collections.col1.documents` matches all document events in that collection.

## Gotchas

- Realtime is a separate Swoole process from the HTTP server — they share nothing in memory
- Events are not queued — if the realtime server is down, events are lost (fire-and-forget via PubSub)
- The `account` channel receives events for the authenticated user only — not all users
- Subscription changes require a new message — you can't dynamically add/remove channels on an existing subscription
- The realtime server has its own connection pool for database access (permission checking)
- `reload_async: true` is critical for the realtime server — without it, SIGUSR1 kills WebSocket connections
- Connection cleanup runs periodically via the Interval task (`cleanup_stale_executions` at 5-minute intervals)

## Related skills

- `appwrite-databases-expert` — document events are the most common realtime source
- `appwrite-auth-expert` — session changes propagate via the `account` channel
- `appwrite-workers-expert` — how events flow from routes through the event system
