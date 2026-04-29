---
name: utopia-cdn-expert
description: Expert reference for utopia-php/cdn — provider-agnostic cache purging (Cloudflare, Fastly) and CDN-managed TLS subscriptions (Fastly TLS). Consult when invalidating edge cache, provisioning certs for Appwrite Sites/Functions custom domains, or adding a new CDN backend. Note: `main` is currently a stub; the live API surface lives on the `feat/init-cdn-providers` branch.
---

# utopia-php/cdn Expert

## Purpose
Provider-agnostic abstraction over CDN cache purging and CDN-issued TLS certificates. Two facades (`Cache`, `Certificates`) wrap pluggable adapters (Cloudflare, Fastly cache; Fastly TLS subscriptions for certs) over `utopia-php/fetch` — the surface Appwrite Sites/Functions reach for when invalidating edge cache or issuing certs for custom domains.

## Public API
- `Utopia\Cdn\Cache` — facade: `purgeUrls(array $urls)`, `purgeKeys(array $keys)`
- `Utopia\Cdn\Cache\Adapter` — interface: `purgeUrls`, `purgeKeys`
- `Utopia\Cdn\Cache\Adapter\Cloudflare(zoneId, apiToken, ?Client, apiBase)` — POSTs `/zones/{zone}/purge_cache`; chunks of 30 URLs per request; throws on `purgeKeys`
- `Utopia\Cdn\Cache\Adapter\Fastly(apiToken, ?serviceId, softPurge, ?Client, apiBase)` — `POST /purge/{url}` per URL; key purge needs `serviceId`; `Fastly-Soft-Purge: 1` header when `softPurge`
- `Utopia\Cdn\Certificates` — facade: `issueCertificate`, `isInstantGeneration`, `getCertificateStatus`, `isRenewRequired`, `deleteCertificate`
- `Utopia\Cdn\Certificates\Provider` (interface) + `Provider\FastlyTls(apiToken, tlsConfigurationId)`
- `Utopia\Cdn\Certificates\Status` — provider-state enum (issued, processing, pending, …)

## Core patterns
- **Cache vs Certificates are separate facades** — they share no client; build one `Cache` and one `Certificates` per provider account
- **`purgeUrls()` takes fully qualified URLs** including scheme — Fastly uses them directly, Cloudflare passes them in the JSON body
- **Cloudflare chunks at 30 URLs**, Fastly serial-fires one HTTP per URL — large invalidations under Fastly amplify rate-limit pressure; batch by surrogate-key and use `purgeKeys` instead
- **`issueCertificate()` returns `?string`** — a renew date when the certificate is already issued/renewing, `null` while Fastly is asynchronously provisioning. Never treat `null` as failure
- **Adapter injects shared `Fetch\Client`** — pass a pre-configured client (timeouts, proxy) into the adapter constructor instead of relying on the per-adapter default

## Gotchas
- The default branch (`main`) currently contains only a placeholder README — the actual classes ship on `feat/init-cdn-providers`. Pin to that branch (or a tag once cut) in `composer.json` until it merges
- `Cloudflare::purgeKeys()` throws `RuntimeException('Cloudflare cache key purging is not supported by this adapter.')` — Cloudflare uses cache tags, not surrogate keys; do not assume cross-provider parity
- `Fastly::purgeKeys()` requires a `serviceId` constructor arg — passing keys without it throws. Construct a separate `Fastly` instance per service
- Errors are surfaced as plain `\RuntimeException` strings stitched from `formatError()` — no typed exception hierarchy. Match on message at your own risk; prefer wrapping at the call site
- `softPurge: true` only sets a stale flag — the URL still serves stale content until origin recheck. Use for cache busts that tolerate a few seconds of staleness; not for legal/PII removal

## Appwrite leverage opportunities
- **Replace ad-hoc cURL purges in `Sites`/`Functions` deployment hooks** with `Cache::purgeUrls([...])` — current code re-implements Cloudflare auth headers in three places. One adapter swap also unlocks Fastly as a deploy target for self-hosters
- **Wire `Certificates` into the existing certificates worker** — Appwrite's worker today only issues via Let's Encrypt + Traefik; for Cloud, `FastlyTls` lets the CDN terminate, eliminating Traefik as a hot path on cert-rotation days
- **Add a `Cache\Adapter\Multi`** that fans `purgeUrls` across both Cloudflare and Fastly in parallel — Appwrite Cloud runs both (Cloudflare for API, Fastly for static), and a single deployment needs to invalidate both surfaces atomically
- **Surface key-purge in the Storage bucket UI**: Fastly surrogate-key purges are O(1); Appwrite tagging uploads with `bucket-{id}` and `file-{id}` keys would replace the current per-URL fan-out for bucket invalidations

## Example
```php
use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Cloudflare;
use Utopia\Cdn\Cache\Adapter\Fastly;
use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Provider\FastlyTls;

$cfCache = new Cache(new Cloudflare(zoneId: $zoneId, apiToken: $cfToken));
$cfCache->purgeUrls([
    "https://{$projectId}.appwrite.network/index.html",
    "https://{$projectId}.appwrite.network/_app.css",
]);

$fastlyCache = new Cache(new Fastly(apiToken: $fastlyToken, serviceId: $serviceId, softPurge: true));
$fastlyCache->purgeKeys(["deployment-{$deploymentId}"]);

$certs = new Certificates(new FastlyTls(apiToken: $fastlyToken, tlsConfigurationId: $tlsId));
$renewAt = $certs->issueCertificate(
    certName: "site-{$projectId}",
    domain: $customDomain,
    domainType: null,
);
if ($renewAt === null && !$certs->isInstantGeneration($customDomain, null)) {
    // Fastly is processing asynchronously — poll getCertificateStatus on a worker
}
```
