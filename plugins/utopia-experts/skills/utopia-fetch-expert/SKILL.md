---
name: utopia-fetch-expert
description: Expert reference for utopia-php/fetch — HTTP client with Curl and Swoole adapters, auto-retry on 5xx, and a Response wrapper. Consult when hitting third-party APIs from workers, fixing timeout-in-ms bugs, or enabling Swoole non-blocking I/O.
---

# utopia-php/fetch Expert

## Purpose
HTTP client with pluggable adapters (`Curl`, `Swoole` coroutine) exposing a single `Client::fetch()` entry point with named args, auto-retry on 5xx, and a `Response` wrapper that decodes JSON/text/blob/chunked streams.

## Public API
- `Utopia\Fetch\Client` — fluent builder (`setTimeout` ms, `setConnectTimeout` ms, `setMaxRetries`, `setRetryStatusCodes`, `setRetryDelay`, `setBaseUrl`, `setUserAgent`, `addHeader`, `setJsonEncodeFlags`)
- `Client::fetch(url, method, body, query)` — named-arg call, returns `Response`
- `Utopia\Fetch\Response` — `isOk()`, `getStatusCode()`, `getBody()`, `text()`, `json()`, `blob()`, `getHeaders()`
- `Utopia\Fetch\Adapter` (interface) with `Adapter\Curl` and `Adapter\Swoole` implementations
- `Utopia\Fetch\Chunk` — streamed-response chunk record
- `Utopia\Fetch\Options\{Request, Swoole}` — typed configs (keep-alive, socket buffer, SSL verify)
- `Utopia\Fetch\Exception` — wraps transport errors

## Core patterns
- **Timeouts are milliseconds** — very unusual for PHP HTTP clients and a constant source of bugs when migrating from Guzzle. Defaults are 5s connect / 15s total
- **Headers lower-cased on add** (`addHeader('Content-Type', ...)` stored as `content-type`), so overwrites are case-insensitive
- **Body array auto-encoded**: `application/json` → `jsonEncode()`, `multipart/form-data` → flattened PHP multi-form (supports `CURLFile`), else `http_build_query`. Content-type header drives encoding
- **Retries**: `setMaxRetries(n)` only retries on 500/503 by default — use `setRetryStatusCodes([429, 500, 502, 503, 504])` for production. Delay is fixed, not exponential
- **Swoole adapter** keeps a `$clients[host]` map for connection reuse inside a coroutine — the adapter is itself pool-like per process

## Gotchas
- **Timeouts in ms, not seconds.** `setTimeout(30)` = 30ms, not 30s — will always hang-up on real APIs
- No default `User-Agent`. Many providers (GitHub, Stripe) 403 empty UAs — set one in bootstrap
- `Response::json()` returns `null` on decode failure **and** on `null` body — use `getStatusCode()` to disambiguate
- Multipart with `body[file] = new CURLFile(...)` only works on Curl adapter; Swoole adapter uses its own file upload path and won't accept `CURLFile` objects

## Appwrite leverage opportunities
- **Swoole adapter is already there** — Appwrite workers running under Swoole should default to `new Client(new Swoole())` to gain non-blocking I/O on webhook dispatch, OAuth token exchange, and realtime push. Most Appwrite code uses bare `curl_*` or `\GuzzleHttp\Client`, which blocks the coroutine scheduler
- **Exponential backoff + jitter**: `setRetryDelay()` is constant. Subclass `Client` and override `fetch()` to compute `delay = base * 2^attempt + rand(0, jitter)` — critical for webhook retries where constant delay causes thundering herds
- **Automatic `Accept-Encoding` negotiation**: pair with `utopia-php/compression`'s `Compression::fromAcceptEncoding()` — no library currently decompresses responses on Appwrite function runtimes
- **Idempotency-Key header** helper: outbound payment/webhook calls should inject a stable `Idempotency-Key` per logical request; add `setIdempotent(bool)` that auto-generates from body hash

## Example
```php
use Utopia\Fetch\Client;
use Utopia\Fetch\Adapter\Swoole;

$client = (new Client(new Swoole()))
    ->setBaseUrl('https://api.stripe.com/v1')
    ->setUserAgent('Appwrite/1.9')
    ->setConnectTimeout(2000)
    ->setTimeout(10000)
    ->setMaxRetries(3)
    ->setRetryDelay(250)
    ->setRetryStatusCodes([429, 500, 502, 503, 504])
    ->addHeader('Authorization', 'Bearer ' . getenv('STRIPE_KEY'))
    ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_FORM_URLENCODED);

$response = $client->fetch(
    url: '/charges',
    method: Client::METHOD_POST,
    body: ['amount' => 2000, 'currency' => 'usd', 'source' => 'tok_visa'],
);
if (! $response->isOk()) {
    throw new \RuntimeException('Stripe ' . $response->getStatusCode() . ': ' . $response->text());
}
$charge = $response->json();
```
