---
name: utopia-dsn-expert
description: Expert reference for utopia-php/dsn — dependency-free DSN parser wrapping parse_url with required-field validation and lazy query-param parsing. Consult when adding connection-string handling or hunting credential-leak-via-logging bugs.
---

# utopia-php/dsn Expert

## Purpose
Tiny, dependency-free DSN parser — wraps PHP's `parse_url` with required-field validation, URL-decoded credentials, and lazy query-param parsing. Every Appwrite connection string (`_APP_CONNECTIONS_DB`, `_APP_CONNECTIONS_CACHE`, etc.) flows through this.

## Public API
- `Utopia\DSN\DSN` — single class. Constructor `(string $dsn)`. Getters: `getScheme`, `getUser`, `getPassword`, `getHost`, `getPort`, `getPath`, `getQuery`, `getParam(key, default='')`
- Throws `InvalidArgumentException` on parse failure, missing scheme, or missing host — no custom exception hierarchy

## Core patterns
- **One-shot parse in constructor** — everything except query params is eager. `params` is lazily populated on first `getParam()` via `parse_str`, then cached
- **`path` stripped of leading `/`** (`mysql://h/appwrite` → `path = 'appwrite'`), so it's database-name-ready
- **`user`/`password` auto-decoded** — store `%40` for `@` in passwords safely
- **`getParam` returns `string` with `string` default** (never `null`) — always provide a sensible default to avoid empty-string bugs (`$ssl = $dsn->getParam('ssl', 'false') === 'true'`)

## Gotchas
- `getPort()` returns `?string`, **not** `?int` — cast before passing to PDO/Mongo clients or socket libraries that type-check
- Multi-host DSNs (Mongo replica set syntax `mongo://a,b,c:27017/db`) **fail** `parse_url` — `DSN` only handles single-host URIs. For replica sets, parse hosts manually or use comma-split on `getHost()`
- No `getParams()` to return the whole array — you must call `getParam()` per known key. For dynamic configs, call `parse_str($dsn->getQuery(), $out)` yourself
- `path` is normalized to empty string (not `null`) when absent, but the getter signature is `?string` — callers that check `=== null` miss this

## Appwrite leverage opportunities
- **Typed `DSN\Config` wrapper**: Appwrite re-parses `_APP_CONNECTIONS_*` on every worker start with hand-rolled helpers. Ship a `DSN\Database`, `DSN\Cache`, `DSN\Queue` subclass that validates scheme against a whitelist and exposes typed getters (`getPort(): int`, `getBool('ssl')`, `getInt('timeout')`) — kills a dozen duplicate casts in `Appwrite\Platform\Tasks\*`
- **Multi-host support**: adding `getHosts(): array` with comma/`;` splitting unlocks Mongo replica sets and Redis Sentinel for Appwrite Cloud without a new library
- **Secret masking for logs**: add `__toString()` that redacts password — Appwrite currently logs full DSNs on DB failures via the adapter exception `getMessage()`, leaking credentials to Sentry
- **Pool integration**: the `Pool` `init` callback repeats DSN parsing per pool. Caching a parsed `DSN` on `Group::add` and passing it into init would cut boot time on shard-per-project setups

## Example
```php
use PDO;
use Utopia\DSN\DSN;

$dsn = new DSN(getenv('_APP_CONNECTIONS_DB') ?: 'mariadb://root:pass%40word@mariadb:3306/appwrite?charset=utf8mb4&timeout=3');

$pdo = new PDO(
    sprintf(
        '%s:host=%s;port=%d;dbname=%s;charset=%s',
        $dsn->getScheme() === 'mariadb' ? 'mysql' : $dsn->getScheme(),
        $dsn->getHost(),
        (int) ($dsn->getPort() ?? 3306),
        $dsn->getPath(),
        $dsn->getParam('charset', 'utf8mb4'),
    ),
    $dsn->getUser(),
    $dsn->getPassword(),
    [PDO::ATTR_TIMEOUT => (int) $dsn->getParam('timeout', '3')],
);
```
