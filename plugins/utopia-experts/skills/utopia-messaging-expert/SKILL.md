---
name: utopia-messaging-expert
description: Expert reference for utopia-php/messaging — multi-channel delivery library with 22+ adapters across Email/SMS/Push/Chat. Consult when wiring notification workers, adding provider fallback, or building GEOSMS-style regional routing.
---

# utopia-php/messaging Expert

## Purpose
Dependency-free multi-channel message delivery library with 22+ adapters across Email, SMS, Push, and Chat providers.

## Public API
- `Utopia\Messaging\Adapter` (abstract base: `send()`, `getName()`, `getType()`, `getMaxMessagesPerRequest()`)
- `Utopia\Messaging\Message` (marker interface)
- `Utopia\Messaging\Messages\{Email, SMS, Push, Discord}` (typed DTOs)
- `Utopia\Messaging\Priority` (enum: `NORMAL`, `HIGH`)
- `Utopia\Messaging\Response` (normalized `deliveredTo`/`results` shape)
- **Email adapters (5)**: Sendgrid, Mailgun, Resend, SMTP (PHPMailer), Mock
- **SMS adapters (14)**: Twilio, Telesign, Vonage, Msg91, Infobip, Plivo, Sinch, TextMagic, Seven, Inforu, Clickatell, Telnyx, Fast2SMS, GEOSMS (+Mock)
- **Push adapters (2)**: FCM, APNS
- **Chat adapters (1)**: Discord

## Core patterns
- **One abstract `Adapter` per channel type**; `send()` validates message type + `getMaxMessagesPerRequest()` before delegating to `process()`
- **Typed message objects constructed with named args** (`new Email(to: [...], subject: ..., content: ...)`)
- **Built-in `request()` helper** on `Adapter` using raw curl with retry/timeout — no HTTP client dependency
- **`GEOSMS` meta-adapter routes by country code** via nested adapters keyed by calling code — returns per-adapter result array
- **Uniform response shape** `{deliveredTo, type, results[]}` for metrics

## Gotchas
- **`giggsey/libphonenumber-for-php-lite` is required** — pulls a large data file; lock version (`9.0.23`)
- **`FCM` adapter takes the service-account JSON string** (not a file path) and signs its own JWT
- **APNS requires `ext-openssl`** with a `.p8` key; no explicit token refresh — caller must cache
- Each provider hardcodes `getMaxMessagesPerRequest()` — batching above that throws, so fan out upstream

## Appwrite leverage opportunities
- **Wrap adapters in a Messaging Worker with Utopia Queue**: per-provider rate-limit token buckets keyed by `$adapter->getName()`, backed by Redis, with automatic fallback chain (Sendgrid → Mailgun → Resend) on non-2xx from the normalized `results` array
- **Per-tenant delivery quotas**: intercept `send()` via a decorator that checks project-scoped counters before hitting the provider, rejecting with a retryable error that the queue broker requeues with delay
- **Use `Priority::HIGH` as the signal for separate priority queues**: HIGH messages drain ahead of NORMAL, with a dead-letter queue for messages that exhaust `getMaxMessagesPerRequest()` retries
- **GEOSMS pattern is perfect for regional compliance** — wire country-specific carriers (India = Msg91, US = Twilio) behind one adapter so Messaging service stays carrier-agnostic

## Example
```php
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Messages\Email;

$message = new Email(
    to: [['email' => 'team@appwrite.io', 'name' => 'Team']],
    subject: 'Welcome',
    content: '<h1>Hello</h1>',
    fromName: 'Appwrite',
    fromEmail: 'no-reply@appwrite.io',
    html: true,
);

$adapter = new Sendgrid(getenv('SENDGRID_API_KEY') ?: '');
$result = $adapter->send($message);
// $result = ['deliveredTo' => 1, 'type' => 'email', 'results' => [...]]
```
