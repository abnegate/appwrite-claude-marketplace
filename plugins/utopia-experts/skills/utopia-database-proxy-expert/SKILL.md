---
name: utopia-database-proxy-expert
description: Expert reference for utopia-php/database-proxy — a Swoole HTTP service that fronts utopia-php/database adapters and exposes one POST /v1/queries endpoint per call. Consult when running Appwrite workers behind a centralized DB pool, wiring x-utopia-* headers (namespace, database, auth, tenant, share-tables, timeouts), or adjusting the secret/method-RPC contract.
---

# utopia-php/database-proxy Expert

## Purpose
Standalone HTTP/Swoole service that owns one shared `utopia-php/pools` of `Utopia\Database\Adapter` connections (MariaDB out of the box) and exposes the adapter's method surface over `POST /v1/queries`. Lets stateless API/worker pods talk to one fronting pool instead of each holding their own — the pattern Appwrite Cloud uses to decouple compute scaling from MariaDB connection pressure.

## Public API
- `app/http.php` — single Swoole entrypoint (`utopia-php/http` + `utopia-php/cli` + `utopia-php/registry`); not a library, run as a binary
- `POST /v1/queries` (group `api`) — body `query=<methodName>&params=<base64(serialize($args))>`, returns `{"output": ...}`
- `POST /mock/error` (group `api`, `mock`) — dev-only smoke-test endpoint, gated by `Http::isDevelopment()`
- HTTP-level resources injected on every request: `registry`, `logger`, `pool`, `log`, `authorization`, `adapterConnection`, `adapter`
- Required env: `UTOPIA_DATA_API_SECRET` (header check), `UTOPIA_DATA_API_SECRET_CONNECTION` (DSN), `UTOPIA_DATA_API_PORT`, `UTOPIA_DATA_API_ENV`, optional `UTOPIA_DATA_API_LOGGING_PROVIDER` / `_CONFIG`, `UTOPIA_DATA_API_VERSION`
- Per-request headers (read by the `adapter` resource): `x-utopia-secret`, `x-utopia-namespace`, `x-utopia-database`, `x-utopia-timeouts` (JSON map), `x-utopia-auth-roles` (JSON list), `x-utopia-auth-status`, `x-utopia-auth-status-default`, `x-utopia-share-tables`, `x-utopia-tenant`

## Core patterns
- **Method RPC, not query RPC** — the body's `query` is the *method name* on `Utopia\Database\Adapter` (e.g. `find`, `createDocument`); `params` is `base64(serialize($args))` invoked via `call_user_func_array([$adapter, $query], $typedParams)`
- **One pool, one DSN** — `Pool('adapter-pool', poolSize, fn () => new MariaDB($pdo))` lives on the `Registry`; `pool_size` parameter on the DSN tunes capacity (default 255)
- **Per-request adapter prep** — the `adapter` resource pops a connection, applies namespace/database/timeouts/share-tables/tenant from headers, configures `Authorization` (roles + status + statusDefault), then yields the live adapter
- **`shutdown()` and `error()` both reclaim** — every code path must call `Connection::reclaim()` to push the connection back. Errors that bypass shutdown leak connections
- **Secret check is one init hook** on group `api` — header `x-utopia-secret` must equal `UTOPIA_DATA_API_SECRET`; mismatch throws 401 before the pool is touched
- **Hard caps** — `MAX_ARRAY_SIZE = 100_000`, `MAX_STRING_SIZE = 20 MB`, `PAYLOAD_SIZE = 20 MB`; Swoole `package_max_length` and `buffer_output_size` set accordingly
- **Logging is provider-pluggable** — `UTOPIA_DATA_API_LOGGING_PROVIDER` selects sentry/raygun/logowl/appsignal; only triggered for code 500/0, never for 4xx

## Gotchas
- **`unserialize()` on every request body** — params come from `\unserialize(\base64_decode($params))`. Anyone who can hit the proxy with the secret can construct arbitrary objects. Treat the secret as bearer auth, run on a private network, never expose publicly
- **`memory_limit = -1`** — set unconditionally at boot. Run inside a cgroup; one runaway query will OOM the box otherwise
- **Adapter mutation persists across requests** — each request mutates the pooled adapter (`setNamespace`, `setDatabase`, `setShareTables`, `setTenant`, `clearTransformations`). If a request bypasses shutdown without reclaim, the next pop sees the leftover state. Always go through the framework's `error()` hook
- **DSN scheme switch covers `mariadb` only** — adding Postgres/MySQL means extending the `match` in the pool factory; falling through returns `null` and explodes downstream
- **Composer pin uses `dev-feat-framework-v2`** of `utopia-php/database` — proxy is tracking a pre-release. Do not bump database independently without re-validating headers
- **`pool_size=255` per worker × worker_num** — total DB connection count is the multiplication. Easy to accidentally exceed MariaDB `max_connections`; size before deploying

## Appwrite leverage opportunities
- **Move Appwrite API workers behind the proxy** — every `appwrite` API container today owns its own pool, capping cluster-wide concurrency at `pool_size × replicas`. One proxy fleet with proper sizing decouples and lets API replicas scale to zero without dropping warm DB connections
- **Replace `unserialize` with a typed JSON contract** — current shape forces PHP↔PHP. A typed `{method, args}` JSON body would let Functions runtimes (Node/Python) hit the proxy directly, killing the JS-only `appwrite/sdk-for-server-*` round-trip
- **Add per-tenant rate limiting** at the init hook — currently the only auth is the shared secret, so one runaway project can starve others. `x-utopia-tenant` is already in scope; a per-tenant token bucket on the init hook is ten lines
- **Surface pool metrics** — `utopia-php/pools` already has `waitDuration`/`useDuration` histograms. The proxy doesn't wire `Telemetry`. Adding `Http::setTelemetry(...)` plus `pool->setTelemetry($t)` exposes wait-time SLOs ops can alarm on
- **Multi-DSN routing**: extend the registry to hold a `Group` of pools keyed by `x-utopia-shard`; today routing is one DSN, blocking horizontal sharding

## Example
```php
// Calling the proxy from an Appwrite API worker
use Utopia\Fetch\Client;

$body = http_build_query([
    'query'  => 'find',
    'params' => base64_encode(serialize([
        $collectionId,                        // string
        [/* Utopia\Database\Query objects */], // queries
        25,                                    // limit
        0,                                     // offset
    ])),
]);

$response = (new Client())
    ->addHeader('x-utopia-secret', $secret)
    ->addHeader('x-utopia-namespace', "_{$projectInternalId}")
    ->addHeader('x-utopia-database', "appwrite")
    ->addHeader('x-utopia-share-tables', 'true')
    ->addHeader('x-utopia-tenant', (string) $projectInternalId)
    ->addHeader('x-utopia-auth-roles', json_encode(['users', "user:{$userId}"]))
    ->addHeader('x-utopia-auth-status', 'true')
    ->fetch(
        url: 'http://database-proxy/v1/queries',
        method: Client::METHOD_POST,
        body: $body,
    );

$documents = $response->json()['output'];
```
