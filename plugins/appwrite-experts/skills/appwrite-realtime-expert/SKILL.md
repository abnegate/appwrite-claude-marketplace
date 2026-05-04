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

### Action-suffixed channels

`Realtime::SUPPORTED_ACTIONS = ['create', 'update', 'upsert', 'delete']`. The publisher (`fromPayload`) emits an action-suffixed sibling for every channel whose **last or second-to-last segment** is in `RESOURCE_LEAF_NAMES = ['documents','rows','files','executions','functions','account','teams','memberships']`. So a single document update emits both:

```
databases.db1.collections.col1.documents.doc1
databases.db1.collections.col1.documents.doc1.update
```

…letting clients filter to a specific action with `subscribe('…documents.doc1.update')` instead of `documents.{id}` plus client-side filtering. `functions` is intentionally a **parent-only** entry: the bare `functions.{action}` channel is a silent no-op — only `functions.{functionId}.{action}` is supported.

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
3. Subscriptions are established **either** in the URL query (`?channels[]=…`, legacy `subscriptionMode = 'url'`) **or** dynamically via `subscribe`/`unsubscribe` messages on the open socket (`subscriptionMode = 'message'`, see below)
4. Server registers channels for the connection — `Realtime::convertChannels($channels, $userId)` rewrites the literal `account` → `account.{userId}` and any `account.{action}` → `account.{userId}.{action}` (where `{action}` is in `SUPPORTED_ACTIONS`); illegal `account.{otherUserId}` variants are stripped. Guests keep the literal `account.{action}` form so the action filter still matches the broadcast `account.{action}` channel
5. Events matching subscribed channels are pushed as JSON frames
6. Connection closed on timeout, client disconnect, or server shutdown

### Message-based subscription protocol

`onMessage` (`app/realtime.php`) accepts JSON frames with a top-level `type` and `data`. Four types:

| Type | Payload shape | Server response |
|---|---|---|
| `ping` | none | `{ "type": "pong" }` |
| `authentication` | `{ "session": "<encoded session>" }` | `{ "type": "response", "data": { "to": "authentication", "success": true, "user": <Account model> } }` — re-resolves roles, calls `Realtime::rebindAccountChannels` to migrate guest `account.{action}` and prior-user `account.{oldUserId}[.…]` entries to the new user, then re-subscribes every existing subscription under the new role set |
| `subscribe` | array (must be a list) of `{ subscriptionId?: string, channels: string[], queries?: string[] }` | `{ "type": "response", "data": { "to": "subscribe", "success": true, "subscriptions": [{ subscriptionId, channels, queries }, …] } }` — bulk-validated up front (one bad payload aborts the whole batch); `subscriptionId` is upserted, missing IDs get a fresh `ID::unique()`; queries are parsed via `Realtime::convertQueries` |
| `unsubscribe` | array of `{ subscriptionId: string }` (non-empty) | `{ "type": "response", "data": { "to": "unsubscribe", "success": true, "subscriptions": [{ subscriptionId, removed }, …] } }` — every payload is validated before any removal so a late bad entry can't leave earlier removals half-applied |

The `connected` frame the server sends on `onOpen` advertises the mode: when channels were passed in the URL the payload is `{ "type": "connected", "data": { channels, subscriptions: { index→subscriptionId }, user } }`; when the URL has no channels (message-mode), `channels` and `subscriptions` are empty and the client is expected to follow up with a `subscribe` message. Client SDKs that lean on this share a single WebSocket across `subscribe()`/`unsubscribe()`/`subscription.update()` calls and only tear it down on `realtime.disconnect()`.

Errors during `onMessage` send `{ "type": "error", "data": { "code", "message" } }`; code `1008` (`REALTIME_POLICY_VIOLATION`) closes the connection, everything else stays open.

### `rebindAccountChannels` on auth changes

When a connection re-authenticates (guest → user, or user → different user), `app/realtime.php` calls `Realtime::rebindAccountChannels($channels, $oldUserId, $newUserId)` which:

- Rewrites the literal guest-form `account.{action}` to `account.{newUserId}.{action}`
- Rewrites `account.{oldUserId}` and `account.{oldUserId}.{action}` to the new user
- No-ops on a missing/empty target to avoid producing malformed `account.` strings

This is the only safe way to mutate account-channel subscriptions in place — clients should not assume their subscription string survives a session change unchanged.

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
- The `account` channel receives events for the authenticated user only — not all users; the server rewrites the literal `account` to `account.{userId}` at subscribe time
- **Subscribing to bare `functions.{action}` is a silent no-op** — `functions` is parent-only in `RESOURCE_LEAF_NAMES`. Use `functions.{functionId}.{action}`
- **Action suffixes are restricted to `SUPPORTED_ACTIONS`** (create/update/upsert/delete) — any other suffix is ignored
- **`subscribe`/`unsubscribe` payloads are bulk-validated up front** — a single bad entry rejects the whole batch with `REALTIME_MESSAGE_FORMAT_INVALID`; do not assume earlier entries in the same array were applied. `unsubscribe` requires a non-empty `subscriptionId` per entry
- **Pre-auth channel state survives `authentication`** via `Realtime::rebindAccountChannels` — guest connections that subscribed to `account` (literal) or `account.{action}` will be re-pointed to the new user without losing the subscription. Don't manually unsubscribe and re-subscribe across an auth flip; the protocol does it for you
- The realtime server has its own connection pool for database access (permission checking)
- `reload_async: true` is critical for the realtime server — without it, SIGUSR1 kills WebSocket connections
- Connection cleanup runs periodically via the Interval task (`cleanup_stale_executions` at 5-minute intervals)

## Related skills

- `appwrite-databases-expert` — document events are the most common realtime source
- `appwrite-auth-expert` — session changes propagate via the `account` channel
- `appwrite-workers-expert` — how events flow from routes through the event system
