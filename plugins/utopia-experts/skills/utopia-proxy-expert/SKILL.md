---
name: utopia-proxy-expert
description: Expert reference for utopia-php/proxy — high-performance Swoole proxy (HTTP/TCP/SMTP) with Resolver interface and optional BPF sockmap. Consult when replacing Traefik for custom domains, building tenant-aware routing, or adding backend health checks.
---

# utopia-php/proxy Expert

## Purpose
High-performance, protocol-agnostic Swoole proxy (HTTP, TCP, SMTP) that resolves backends through a one-method `Resolver` interface, with optional BPF sockmap kernel zero-copy relay.

## Public API
- `Utopia\Proxy\Resolver` — single-method interface `resolve(string $data): Result`
- `Resolver\Result` — readonly `{endpoint, metadata, timeout}` returned by the resolver
- `Resolver\Fixed` — constant-endpoint resolver for static backends
- `Utopia\Proxy\Adapter` — base class holding a Swoole `Table` router, `onResolve()` override hook, SSRF validation, cache TTL
- `Utopia\Proxy\Protocol` — enum of `TCP | HTTP | SMTP`
- `Utopia\Proxy\ConnectionResult` — `{endpoint, protocol, metadata}` value object
- `Server\HTTP\Swoole` / `Server\TCP\Swoole` / `Server\SMTP\Swoole` — per-protocol Swoole servers
- `Server\{HTTP,TCP,SMTP}\Config` — readonly config objects (host, port, workers, reactor counts, buffers, TLS)
- `Server\TCP\TLS` — TLS termination config (cert, key, CA, `requireClientCert`)

## Core patterns
- **Swoole `Table`** is the shared-memory router cache keyed by resource id → endpoint, TTL configurable via `setCacheTTL`
- **`onResolve($callback)`** wraps the `Resolver` for inline transformations (e.g. parse raw packet → extract database id → look up)
- **SSRF defence**: `IP` + `Range` validators reject resolved endpoints that fall in reserved/private ranges unless `setSkipValidation(true)`
- **TLS offload** on TCP server via `TLSContext` (`ssl_cert_file`, `ssl_key_file`, optional mTLS); backend connection stays plaintext
- **Optional BPF sockmap** (`src/Sockmap`) moves TCP relay into the kernel using libbpf + ext-ffi — bypasses userspace copies once the connection is established

## Gotchas
- Requires **PHP >= 8.4** and **ext-swoole >= 6.0** — bleeding edge, not compatible with current Appwrite main (PHP 8.3/Swoole 5.x)
- **BPF sockmap needs `--cap-add=BPF --cap-add=NET_ADMIN --cap-add=SYS_RESOURCE`** and Linux 4.17+; silently disables if FFI or libbpf is missing
- `Resolver` takes protocol-specific strings — for TCP you get raw packet bytes (may be truncated if less than the first buffer), so startup-message parsing must handle partial reads
- Router cache is a Swoole Table with a **fixed row count** (set at server start) — once full, new resolutions start evicting cached rows; no LRU, FIFO only
- **No built-in circuit breaker or health checks**; an unhealthy backend stays in the cache until TTL or restart

## Appwrite leverage opportunities
- **Replace Traefik + custom router service** with a single `Server\HTTP\Swoole` instance whose resolver queries the `certificates`/`rules` collections directly — drops an entire service from the Cloud stack and makes custom-domain routing a library concern
- **Active backend health checks**: run a Swoole coroutine that writes into the same router Table, marking `Result.metadata.healthy=false` so the next resolve picks a different backend — the library gives you the table, not the probe
- **Per-database TCP routing**: use the TCP adapter's `onResolve` to parse Postgres/MySQL startup packets and route per-database — perfect for multi-tenant DB separation currently handled by Appwrite's per-project `Database::setTenant()` at the application layer
- **Add `getConnections(): array`** on the Adapter so ops can scrape current per-backend connection counts and hook into `utopia-php/telemetry`
- **Pair `Fixed` resolver + sockmap** for the internal Appwrite-to-MariaDB hop on Kubernetes — benchmarks show ~2× throughput over the kube-proxy iptables path

## Example
```php
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Resolver\Exception as ResolverException;
use Utopia\Proxy\Server\HTTP\Config;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

$resolver = new class implements Resolver {
    public function resolve(string $hostname): Result
    {
        $project = $this->projects->getByDomain($hostname)
            ?? throw new ResolverException("Unknown: {$hostname}", 404);
        return new Result(
            endpoint: "appwrite-api-{$project['region']}:80",
            metadata: ['projectId' => $project['$id']],
        );
    }
};

$config = new Config(host: '0.0.0.0', port: 443, workers: swoole_cpu_num() * 2);
(new HTTPServer($resolver, $config))->start();
```
