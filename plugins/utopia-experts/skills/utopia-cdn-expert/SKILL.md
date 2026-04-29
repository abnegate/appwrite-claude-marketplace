---
name: utopia-cdn-expert
description: Expert reference for utopia-php/cdn — adapter-based CDN edge control plane with Cloudflare/Fastly cache purging and Fastly TLS subscription certificate management. Consult when wiring purge-on-deploy hooks, cutting cache propagation latency, or managing custom-domain certificate lifecycles.
---

# utopia-php/cdn Expert

## Purpose
Lightweight PHP library for talking to CDN providers from Appwrite-stack services. Two orthogonal facades: `Cache` for URL/key purges, `Certificates` for CDN-managed TLS lifecycle. Built on `utopia-php/fetch`; provider-specific quirks are isolated in adapters so callers stay portable.

## Public API
- `Utopia\Cdn\Cache(Adapter $adapter)` — `purgeUrls(array $urls)`, `purgeKeys(array $keys)`
- `Utopia\Cdn\Cache\Adapter` interface — implemented by `Adapter\Cloudflare(zoneId, apiToken)` and `Adapter\Fastly(apiToken, ?serviceId, softPurge=false)`
- `Utopia\Cdn\Certificates(Provider $provider)` — `issueCertificate(certName, domain, ?domainType): ?string`, `getCertificateStatus`, `isInstantGeneration`, `isRenewRequired`, `deleteCertificate`
- `Utopia\Cdn\Certificates\Provider` interface — `Provider\FastlyTls(apiToken, tlsConfigurationId)` is the only implementation today
- `Utopia\Cdn\Certificates\Status` — `PENDING`, `PROCESSING`, `ISSUED`, `RENEWING`, `FAILED`, `UNKNOWN` string constants

## Patterns
- **Provider-specific URL semantics** — Cloudflare expects fully qualified URLs and chunks them in batches of 30 per `POST /zones/:id/purge_cache`. Fastly issues one `POST /purge/{url}` per URL and treats the URL as the path (no scheme/host substitution)
- **Soft purge toggle** — Fastly `softPurge: true` adds the `Fastly-Soft-Purge: 1` header so cached objects are marked stale instead of evicted; pairs with stale-while-revalidate at the origin
- **Key purges are Fastly-only** — `Cloudflare::purgeKeys()` throws `RuntimeException` immediately. Surrogate keys must be set on the origin response; `serviceId` is required for Fastly key purges and validated lazily
- **Async certificate issuance** — `issueCertificate` returns a renew date string when Fastly already has an issued/renewing cert, and `null` when the cert is still in `pending`/`processing`. Don't treat `null` as failure
- **Auth-via-constructor** — every adapter accepts an injectable `Utopia\Fetch\Client` for testing; default constructs one and adds provider headers (`Authorization: Bearer` for Cloudflare, `Fastly-Key` for Fastly)

## Gotchas
- **`feat/init-cdn-providers` is the live branch** — `main` is essentially empty (`README.md` only). Tag/branch pin `feat/init-cdn-providers` in `composer.json` until it merges, or `composer require` will resolve to nothing useful
- **Fastly `purgeUrls` is N requests** — no batch endpoint, so a 1000-URL purge is 1000 sequential HTTP calls. Wrap in `utopia-php/queue` worker batching, not in a request thread
- **Cloudflare's 30-URL chunk limit is hardcoded** — the Cloudflare Cache API itself accepts 30 per call; bumping the chunk size in caller code will silently get the rest dropped
- **Fastly TLS `getCertificateStatus` returns `Status::UNKNOWN` for cert states the library doesn't model yet** — don't switch on equality with `ISSUED` alone; treat anything not in `FAILED`/`PENDING`/`PROCESSING` as terminal-good
- **No retry/backoff in adapters** — a transient 5xx from Cloudflare bubbles `RuntimeException`. Wrap calls in `utopia-php/circuit-breaker` for production purge paths

## Composition
- **Custom-domain onboarding flow** — `utopia-domains-expert` (parsing/validation) → `utopia-dns-expert` (CNAME setup) → **utopia-cdn-expert** (`issueCertificate`) → poll `getCertificateStatus`/`isRenewRequired` from a worker
- **Edge-cache invalidation on deploy** — `utopia-vcs-expert` deploy webhook → enqueue purge job (`utopia-queue-expert`) → **utopia-cdn-expert** `purgeKeys(['deployment-' . $deploymentId])` for surrogate-key bulk eviction
- **Fault tolerance** — wrap `Cache::purgeUrls` in `utopia-circuit-breaker-expert` so a Cloudflare outage doesn't fail the deploy pipeline; fall back to TTL-driven expiry
- **HTTP transport** — `utopia-fetch-expert` is the underlying client, so Swoole vs cURL behaviour is inherited. Inject a Swoole-aware `Client` for non-blocking purges from coroutine workers

## Example
```php
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Status;

// Purge on deploy — group surrogate keys by deployment ID upstream
$cache = new Cache(new Cloudflare(zoneId: $zoneId, apiToken: $token));
$cache->purgeUrls([
    "https://{$projectId}.appwrite.network/index.html",
    "https://{$projectId}.appwrite.network/manifest.json",
]);

// Issue a managed cert for a freshly-onboarded custom domain
$certs = new Certificates(new FastlyTls(apiToken: $token, tlsConfigurationId: $tlsConfigId));
$renewAt = $certs->issueCertificate('cust-' . $domainId, $domain, domainType: null);

if ($renewAt === null) {
    // Cert is still provisioning — poll Status::ISSUED via getCertificateStatus()
    return 'pending';
}
return $renewAt;
```
