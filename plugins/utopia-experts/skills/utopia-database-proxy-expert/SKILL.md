---
name: utopia-database-proxy-expert
description: Expert reference for utopia-php/database-proxy — Swoole HTTP server that fronts utopia-php/database adapters as a remote RPC endpoint, marshalling method calls over `POST /v1/queries`. Consult when sharding Appwrite databases behind a proxy, debugging the secret-header auth, or extending the proxy with new endpoints.
---

# utopia-php/database-proxy Expert

## Purpose
Standalone Swoole HTTP server that exposes a `utopia-php/database` adapter (today: `MariaDB`) as a remote RPC. Every call becomes `POST /v1/queries` with the method name + base64-of-serialize-of-args. Lets Appwrite Cloud put the database adapter on a different host than the API workers.

## Public API
The proxy is an application, not a library — its surface is HTTP, not PHP classes:
- `POST /v1/queries` — `query` form param = adapter method name; `params` = `base64(serialize($args))`. Returns `{ "output": <return value> }`
- Per-request headers configure the borrowed adapter:
  - `x-utopia-secret` — shared secret (matched against `UTOPIA_DATA_API_SECRET`)
  - `x-utopia-database` — `setDatabase()`
  - `x-utopia-namespace` — `setNamespace()`
  - `x-utopia-share-tables` — `setShareTables(bool)`
  - `x-utopia-tenant` — `setTenant(int)`
  - `x-utopia-timeouts` — JSON `{ "<event>": <seconds> }` → repeated `setTimeout`
  - `x-utopia-auth-roles` — JSON array → `Authorization::addRole(...)`
  - `x-utopia-auth-status`, `x-utopia-auth-status-default` — `enable()`/`disable()` and `setDefaultStatus`
- `GET /mock/error` — dev-only 500 generator (gated on `Http::isDevelopment()`)
- Env: `UTOPIA_DATA_API_ENV`, `UTOPIA_DATA_API_PORT`, `UTOPIA_DATA_API_SECRET`, `UTOPIA_DATA_API_SECRET_CONNECTION` (DSN), `UTOPIA_DATA_API_LOGGING_PROVIDER` (`sentry`/`raygun`/`logowl`/`appsignal`), `UTOPIA_DATA_API_LOGGING_CONFIG`, `UTOPIA_DATA_API_VERSION`

## Patterns
- **Pool-per-worker, adapter-per-request** — `pool` resource borrows one PDO+`MariaDB` adapter from `Utopia\Pools\Pool` per request; the request scope's `adapter` resource tags it with namespace/database/tenant/auth roles, then the `shutdown` hook reclaims it. Adapter mutation is intentional and isolated by Swoole worker affinity
- **Authorization is per-request, not per-connection** — `x-utopia-auth-roles` is reset on every request via `cleanRoles()` then `addRole('any')` then the supplied roles. There is no caller identity beyond the shared secret
- **`unserialize($base64)` of caller-supplied bytes** — only safe because of the secret check; treat that secret as a high-trust credential equivalent to DB root
- **20 MB payload ceiling** — `package_max_length` and `buffer_output_size` capped at `PAYLOAD_SIZE = 20MB`; `MAX_ARRAY_SIZE = 100000`, `MAX_STRING_SIZE = 20MB` constants gate caller serialization on the client side
- **Single supported scheme: `mariadb://`** — the pool factory's `switch ($dsnScheme)` falls through silently for anything else, returning `null` from the factory and crashing on first `pop()`. There is no MySQL/Postgres adapter today

## Gotchas
- **`memory_limit = -1`** — set explicitly in `app/http.php`. The proxy assumes container-level memory limits, not PHP-level ones
- **`UTOPIA_DATA_API_SECRET` is the only auth** — anyone who can hit the port and present the header can run arbitrary `MariaDB` adapter methods, including DDL and `find('users', ...)`. Never expose this proxy outside the cluster network
- **Adapter mutation leaks across requests if `setDatabase`/`setShareTables`/`setTenant` headers are omitted** — the code only resets these when the corresponding header is present and non-empty. A header-less request gets the previous request's namespace. Always send the full envelope from the client
- **`setShareTables` accepts the literal string `'false'`** — header parsing checks `=== 'false'`; any other non-empty value (including `'0'`) flips share-tables ON. Quirk worth pinning in client SDKs
- **5xx handler always logs** — `httpError` action pushes to `Logger` for code 500 OR 0; transport-level fetch failures get logged twice (once at proxy, once at caller)
- **30 retry attempts × 1 s sleep** — pool retry config is hardcoded; under sustained MariaDB outage the worker stalls 30 s before returning. Pair with a circuit breaker upstream

## Composition
- **DB sharding topology** — front N database-proxy instances behind `utopia-balancer-expert`; the API workers in the Appwrite container instantiate a `utopia-php/database` `Proxy` adapter pointing at the balancer
- **Pool tuning** — `pool_size` query param on `UTOPIA_DATA_API_SECRET_CONNECTION` DSN drives `utopia-pools-expert`; defaults to 255. See `utopia-pools-expert` for sizing heuristics
- **Resilience wrapper** — caller wraps every proxy invocation in `utopia-circuit-breaker-expert` keyed on the proxy host so a single hot proxy doesn't poison the whole API tier
- **Auth hand-off** — `utopia-database-expert` `Authorization` is what the headers reconstruct; the same role set is what the API tier sends

## Example
```php
// Caller side — what the Appwrite API would do via a Database\Proxy adapter
$payload = http_build_query([
    'query' => 'getDocument',
    'params' => base64_encode(serialize(['users', 'user_abc'])),
]);

$ch = curl_init('http://database-proxy.internal/v1/queries');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-utopia-secret: ' . getenv('PROXY_SECRET'),
        'x-utopia-database: appwrite',
        'x-utopia-namespace: _project_abc',
        'x-utopia-share-tables: false',
        'x-utopia-tenant: 0',
        'x-utopia-auth-roles: ' . json_encode(['any', 'users:user_abc']),
        'x-utopia-auth-status: true',
        'x-utopia-timeouts: ' . json_encode(['read' => 5, 'write' => 15]),
    ],
]);
$body = json_decode((string) curl_exec($ch), true);
$document = $body['output']; // already deserialised on the proxy side
```
