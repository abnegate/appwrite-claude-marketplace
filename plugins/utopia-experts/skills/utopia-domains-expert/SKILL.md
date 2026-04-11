---
name: utopia-domains-expert
description: Expert reference for utopia-php/domains — zero-dependency domain parser using the Mozilla Public Suffix List plus OpenSRS/NameCom registrar adapters. Consult for custom-domain onboarding, CNAME verification flows, and reseller integration.
---

# utopia-php/domains Expert

## Purpose
Zero-dependency domain name parser using the Mozilla Public Suffix List, plus a registrar abstraction for buying/renewing/transferring domains.

## Public API
- `Utopia\Domains\Domain` — value object with `getTLD()`, `getSuffix()`, `getRegisterable()`, `getName()`, `getSub()`, `isKnown()`, `isICANN()`, `isPrivate()`, `isTest()`
- `Utopia\Domains\Registrar` — facade
- `Utopia\Domains\Registrar\Contact`, `Price`, `Renewal`, `TransferStatus`, `UpdateDetails` — typed DTOs
- `Registrar\Adapter\OpenSRS`, `NameCom` — **2 registrar adapters**

## Core patterns
- **Public Suffix List is bundled as generated PHP** (`data/import.php` regenerates from publicsuffix.org) — no runtime HTTP calls, parsing is pure in-memory
- **`Domain` is a value object** constructed from a hostname; all accessors derived lazily from PSL lookup
- **Registrar adapters return typed DTOs** (`Registration`, `Renewal`, `TransferStatus`) — more structured than `Pay`'s raw arrays
- **`suggest()` supports price bounds** (`$priceMin`, `$priceMax`) and TLD filtering — built for domain marketplace UIs
- **`isTest()` hardcodes `localhost` and `test` TLDs** — convenient for dev-env branching without PSL maintenance

## Gotchas
- **Bundled PSL goes stale.** New gTLDs (e.g., `.zip`, `.app`, `.dev`) added post-release will `isKnown() === false` until you run `php ./data/import.php` and bump the composer version
- **`Domain` expects a host only, not a URL** — passing `https://foo.com/bar` silently breaks parsing. Always `parse_url($url, PHP_URL_HOST)` first
- **`$domain->get()` returns the full input** (including subdomain), not the registerable domain — easy to confuse with `getRegisterable()`
- **IDN/punycode is not auto-converted** — `münchen.de` must be fed as `xn--mnchen-3ya.de` or the PSL lookup misses

## Appwrite leverage opportunities
- **Appwrite custom-domain onboarding**: use `Domain::isKnown()` + `isPrivate()` to reject `*.vercel.app`/`*.netlify.app` (private suffixes the user doesn't own) before issuing Let's Encrypt certificates. Prevents vanity-domain hijacking
- **Cloud domain reseller**: wire `Registrar` with OpenSRS (cheapest wholesale) behind an Appwrite collection for orders. Use `suggest()` with price floor to upsell premium TLDs during project creation
- **Scheduled PSL refresh**: add a Cron that runs `data/import.php` weekly and auto-PRs the diff — prevents new-gTLD drift
- **CNAME verification flow**: `getRegisterable()` + `getSub()` lets you distinguish `app.customer.com` (apex CNAME disallowed) from `customer.com` (requires ALIAS/ANAME). Drive DNS instructions in console UI

## Example
```php
use Utopia\Domains\Domain;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\OpenSRS;
use Utopia\Domains\Registrar\Contact;

$host = parse_url('https://blog.example.co.uk/post', PHP_URL_HOST);
$domain = new Domain($host);
if (!$domain->isKnown() || $domain->isPrivate()) {
    throw new \DomainException('Unsupported suffix');
}
$registerable = $domain->getRegisterable(); // 'example.co.uk'

$registrar = new Registrar(new OpenSRS($key, $user, $pass, ['ns1.appwrite.io', 'ns2.appwrite.io']));
if ($registrar->available($registerable)) {
    $registrar->purchase($registerable, [$contact], periodYears: 1);
}
```
