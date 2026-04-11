---
name: utopia-dns-expert
description: Expert reference for utopia-php/dns — PHP 8.3+ DNS server/client toolkit with Native/Swoole adapters and Memory/Proxy/Cloudflare/Google resolvers. Consult when building authoritative DNS for custom domains, ACME DNS-01 challenges, or geoip routing.
---

# utopia-php/dns Expert

## Purpose
PHP 8.3+ toolkit for building DNS servers (authoritative or proxy) and clients, with a fully-typed wire-format encoder/decoder and telemetry hooks.

## Public API
- `Utopia\DNS\Server`, `Utopia\DNS\Client`
- `Utopia\DNS\Adapter` (abstract) with **2 adapters** `Native`, `Swoole`
- `Utopia\DNS\Resolver` interface with **4 resolvers** `Memory`, `Proxy`, `Cloudflare`, `Google`
- `Utopia\DNS\Zone`, `Utopia\DNS\Message`, `Message\Question`
- `Message\Record` with `TYPE_A`, `TYPE_AAAA`, `TYPE_CNAME`, `TYPE_SOA`, etc.

## Core patterns
- **Split of concerns**: `Adapter` owns sockets/packets only; `Resolver` owns answer logic. You can swap Swoole↔Native without touching zone code
- **Server automatically listens on both UDP and TCP** on the same port (RFC 5966) — TCP handles truncated responses and zone transfers
- **Telemetry is first-class** via `utopia-php/telemetry`: `dns.queries.total`, `dns.responses.total` counters and `dns.query.duration` histogram emit automatically
- Uses `utopia-php/span` for distributed tracing spans per query
- Depends on `utopia-php/domains` — shares the PSL for validation of query names

## Gotchas
- **PHP 8.3 minimum** and `ext-sockets` required; Swoole adapter adds `ext-swoole`. Will not install on Appwrite images still pinned to 8.2
- **`Memory` resolver is authoritative-only** — it does NOT recurse. Mixing authoritative zones with upstream fallback requires wrapping/chaining resolvers manually (no `Chain` resolver shipped)
- **DNSSEC (RRSIG, DNSKEY, NSEC/NSEC3) is not in the message types** — you cannot serve signed zones. `SOA` is supported but not signing
- **UDP truncation is handled** (TC flag + TCP retry) but EDNS0 / larger-than-512 UDP is not documented; assume 512-byte UDP responses max unless you verify

## Appwrite leverage opportunities
- **Appwrite Sites / custom domains**: run an authoritative DNS server per region backed by a `DatabaseResolver` that reads from Appwrite's `_domains` collection in real time. Every Sites custom domain becomes a dynamic zone entry without zone file reloads
- **ACME DNS-01 challenge resolver**: serves `_acme-challenge.*` TXT records from a Redis-backed map with short TTL. Lets you issue wildcard certs for `*.appwrite.network` and customer vanity domains without a third-party DNS provider
- **Multi-tenant health routing**: wrap `Memory` in a custom resolver that picks A-record IP based on geoip/region, enabling Appwrite Cloud regional steering without Route53 latency-based routing fees
- **Add a `Chain` resolver** to compose `Memory` (authoritative for owned zones) → `Proxy` (recursive fallback via Cloudflare/Google) — essential for hybrid authoritative+forwarding setups

## Example
```php
use Utopia\DNS\Adapter\Swoole;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Resolver\Memory;
use Utopia\DNS\Server;
use Utopia\DNS\Zone;

$zone = new Zone('appwrite.network', [
    new Record('appwrite.network', Record::TYPE_A, '203.0.113.10', 60),
    new Record('*.appwrite.network', Record::TYPE_A, '203.0.113.10', 60),
    new Record('appwrite.network', Record::TYPE_SOA,
        'ns1.appwrite.network hostmaster.appwrite.network 1 7200 1800 1209600 3600', 3600),
]);
$server = new Server(new Swoole('0.0.0.0', 53), new Memory($zone));
$server->setTelemetry($prometheus);
$server->start();
```
