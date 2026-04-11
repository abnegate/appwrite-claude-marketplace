---
name: utopia-pay-expert
description: Expert reference for utopia-php/pay — payment provider abstraction (Stripe only, currently). Consult for authorize/capture flows, webhook signature verification, and when planning Paddle/LemonSqueezy adapters for EU VAT.
---

# utopia-php/pay Expert

## Purpose
Dependency-free payment provider abstraction supporting direct purchases, authorization/capture flows, customers, payment methods, and refunds.

## Public API
- `Utopia\Pay\Pay` — facade
- `Utopia\Pay\Adapter` — abstract base
- `Utopia\Pay\Adapter\Stripe` — **1 adapter total** (Stripe)
- Key methods: `purchase()`, `authorize()`, `capture()`, `cancelAuthorization()`, `refund()`, `createCustomer()`, `createPaymentMethod()`, `listPaymentMethods()`, `setCurrency()`, `setTestMode()`

## Core patterns
- **Single-adapter library (Stripe only)** despite the adapter pattern — extensible but currently no multi-provider failover
- **Amounts are integer minor units** (e.g., `5000` = $50.00). Currency is stateful on the adapter via `setCurrency()`, not per-call
- **Two-phase commit flow**: `authorize()` → provider work → `capture()` on success or `cancelAuthorization()` on failure. Designed for scenarios like domain registration where you must secure funds before acquiring an external resource
- **Returns `array<mixed>` blobs** from provider APIs rather than typed DTOs — callers must know Stripe response shapes
- **`testMode`** is a boolean toggle on the adapter; no sandbox-specific endpoint routing, relies on test API keys

## Gotchas
- **No webhook signature verification helper** — you must implement Stripe webhook validation (`Stripe-Signature` header, HMAC-SHA256 over raw body) yourself
- **No 3DS/SCA/PSD2 flow helpers** — `confirmPaymentIntent`, `next_action` handling, and `return_url` redirects are raw passthroughs via `additionalParams`
- **Currency is mutable global state** on the adapter — racy if you share a `Pay` instance across requests handling different currencies. Always `setCurrency()` before each call
- **No idempotency key support built-in** — must pass `Idempotency-Key` header via `additionalParams` if the adapter forwards it

## Appwrite leverage opportunities
- **Cloud billing**: use `authorize()` → provision resource (domain, dedicated instance, function invocations credit pack) → `capture()` pattern for all paid self-serve provisioning. On provisioning failure, `cancelAuthorization()` releases the hold without customer friction
- **Build a `PaddleAdapter` and `LemonSqueezyAdapter`** for EU VAT/MOSS compliance — Stripe Tax is expensive and Paddle acts as merchant of record, reducing Appwrite Cloud's tax burden across 160+ jurisdictions
- **Idempotency middleware** that auto-derives keys from `(projectId, invoiceId, retryAttempt)` so function-worker retries on webhook processing don't double-charge
- **Wrap webhook signature verification** in an Appwrite route using raw request body (critical: Swoole must not have parsed the body) and reject replays via cache TTL keyed on Stripe event ID

## Example
```php
use Utopia\Pay\Pay;
use Utopia\Pay\Adapter\Stripe;

$pay = new Pay(new Stripe(publishableKey: $pub, secretKey: $secret));
$pay->setCurrency('USD');
$pay->setTestMode(false);

$customer = $pay->createCustomer('Jane Doe', 'jane@example.com');
$auth = $pay->authorize(
    amount: 1999,
    customerId: $customer['id'],
    paymentMethodId: $pmId,
    additionalParams: ['metadata' => ['invoiceId' => $invoiceId]],
);
try {
    provisionResource($invoiceId);
    $pay->capture($auth['id']);
} catch (\Throwable $e) {
    $pay->cancelAuthorization($auth['id']);
    throw $e;
}
```
