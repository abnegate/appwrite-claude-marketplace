---
name: appwrite-messaging-expert
description: Messaging system — providers, topics, subscribers, message delivery via SMS, email, and push. Covers the messaging controller (48 routes) and messaging worker with provider adapters.
---

# Appwrite Messaging Expert

## Route file

`app/controllers/api/messaging.php` — 48 routes covering providers, topics, subscribers, and messages.

Worker: `src/Appwrite/Platform/Workers/Messaging.php` (750+ lines).

## Architecture

```
Route (enqueue) → v1-messaging queue → Messaging Worker → Provider Adapter → External API
```

Messages are **not sent synchronously** from routes. The route creates a message document and enqueues to `v1-messaging`. The worker processes delivery.

## Provider adapters

**SMS:**
| Provider | Adapter class |
|---|---|
| Twilio | `Utopia\Messaging\Adapter\SMS\Twilio` |
| Vonage | `Utopia\Messaging\Adapter\SMS\Vonage` |
| TextMagic | `Utopia\Messaging\Adapter\SMS\TextMagic` |
| Telesign | `Utopia\Messaging\Adapter\SMS\Telesign` |
| Msg91 | `Utopia\Messaging\Adapter\SMS\Msg91` |
| Fast2SMS | `Utopia\Messaging\Adapter\SMS\Fast2SMS` |
| Inforu | `Utopia\Messaging\Adapter\SMS\Inforu` |

**Push:**
| Provider | Adapter class |
|---|---|
| FCM | `Utopia\Messaging\Adapter\Push\FCM` |
| APNS | `Utopia\Messaging\Adapter\Push\APNS` |

**Email:**
| Provider | Adapter class |
|---|---|
| Mailgun | `Utopia\Messaging\Adapter\Email\Mailgun` |
| Sendgrid | `Utopia\Messaging\Adapter\Email\Sendgrid` |
| Resend | `Utopia\Messaging\Adapter\Email\Resend` |
| SMTP | `Utopia\Messaging\Adapter\Email\SMTP` |

Provider factory in `Workers/Messaging.php`:
```php
$adapter = match ($provider->getAttribute('type')) {
    MESSAGE_TYPE_SMS => $this->getSmsAdapter($provider),
    MESSAGE_TYPE_PUSH => $this->getPushAdapter($provider),
    MESSAGE_TYPE_EMAIL => $this->getEmailAdapter($provider),
};
```

## Message types

- `MESSAGE_SEND_TYPE_INTERNAL` — system messages (verification, recovery, MFA)
- `MESSAGE_SEND_TYPE_EXTERNAL` — user-defined messages via the API

## Topic / subscriber model

- **Topics** — named channels (e.g., "promotions", "order-updates")
- **Subscribers** — users subscribed to topics via targets
- **Targets** — delivery endpoints on a user (email address, phone number, push token)

Delivery: message → resolve topic subscribers → resolve targets → batch by provider → send.

## Batch processing

`Workers/Messaging.php` batches by adapter's max messages per request:
```php
$results = batch(array_map(function($chunk) use ($adapter) {
    return function() use ($chunk, $adapter) {
        return $adapter->send($chunk);
    };
}, $chunks));
```

Uses Swoole `batch()` for coroutine-level parallelism within a single worker.

## Scheduled messages

`Tasks/ScheduleMessages.php` handles deferred delivery:
- Polls `schedules` collection for messages with `scheduledAt <= now`
- Enqueues to `v1-messaging` queue
- Deletes schedule after enqueueing

## Error handling

Per-recipient failure tracking:
- Each target gets a `status` in the results array (`success` / `failure`)
- Failed targets logged with error message
- Expired push tokens cleaned up automatically
- Message document updated with delivery stats

## Mails worker (system emails)

Separate from messaging — `Workers/Mails.php` handles SMTP-based system emails:
- Password recovery, email verification, magic URL links
- Template rendering with variables
- SMTP config from env vars or project settings
- Fallback to cloud email provider in cloud deployments

## Gotchas

- The messaging worker and mails worker are separate — messaging handles user API messages, mails handles system templates
- Provider credentials stored on the provider document, not env vars — each project can have different providers
- Push tokens expire — the worker cleans up expired FCM/APNS tokens on delivery failure
- `MESSAGE_SEND_TYPE_INTERNAL` skips topic/subscriber resolution — it sends directly to specified targets
- Batch sizes vary by provider — Sendgrid supports 1000/batch, SMTP is 1/batch
- The `v1-messaging` queue handles both SMS, email, and push — the worker routes by message type

## Related skills

- `appwrite-workers-expert` — queue system and worker patterns
- `appwrite-auth-expert` — system emails for verification/recovery use the Mails worker, not Messaging
