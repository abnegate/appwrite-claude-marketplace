---
name: utopia-swoole-expert
description: Expert reference for utopia-php/swoole — the legacy Swoole adapter for utopia-php/framework v0.x (Request/Response/Files wrappers around Swoole\Http\Server). ARCHIVED upstream — superseded by utopia-php/http's first-party Swoole adapter. Consult only when maintaining services still pinned to `utopia-php/framework: 0.33.*` (e.g. older Appwrite microservices).
---

# utopia-php/swoole Expert (archived)

## Purpose
Legacy bridge between `utopia-php/framework: 0.33.*` and `Swoole\Http\Server`. Provides `Utopia\Swoole\Request`, `Utopia\Swoole\Response`, and `Utopia\Swoole\Files` so the v0.x framework's `App` can be served by Swoole instead of FPM. **Archived upstream** — `utopia-php/http` now ships its own first-party `Adapter\Swoole\Server`, which all new services should use.

## Public API
- `Utopia\Swoole\Request(\Swoole\Http\Request $request)` — extends `Utopia\Request`; `getRawPayload()`, `getServer/setServer`, `setTrustedIpHeaders(array $headers)`, `getIP()`
- `Utopia\Swoole\Response(\Swoole\Http\Response $response)` — extends `Utopia\Response`; full `STATUS_CODE_*` enum (100–511), `send()`/`json()`/`text()` etc. routed to `$swoole->end()`
- `Utopia\Swoole\Files` — static loader: `load(string $directory)`, `isFileLoaded(string $uri): bool`, `getFileContents(string $uri)`, `getFileMimeType(string $uri)`, `getCount()`, plus `addMimeType`/`removeMimeType`/`getMimeTypes`
- `Utopia\Swoole\Files::EXTENSIONS` — built-in MIME map (css/js/svg). Add others via `addMimeType()`

## Core patterns
- **Wrap inside the `request` callback** — `$http->on('request', fn($req, $res) => $app->run(new Request($req), new Response($res)))`. Both wrappers translate Swoole I/O on construction; do not reuse instances across requests
- **Static `Files` registry** — `Files::load(__DIR__ . '/public')` is called once on server boot; per-request you check `Files::isFileLoaded($request->getURI())` and stream via `Files::getFileContents()` with a `Cache-Control` header
- **Trusted-proxy IP extraction** — `setTrustedIpHeaders(['x-forwarded-for', 'x-real-ip'])` whitelists which headers `getIP()` will trust; without it, `getIP()` returns the direct peer (`REMOTE_ADDR`) and never spoofs from headers
- **Composer pin: `php >=8.1`, `ext-swoole: 6.*`, `utopia-php/framework: 0.33.*`** — the pre-`utopia-php/http` framework. Cannot be upgraded in place to the new framework

## Gotchas
- **Repository is archived** — `gh api repos/utopia-php/swoole` returns `archived: true`. PRs are closed; no fixes will land. Treat as frozen and migrate forward when feasible
- **Built on the obsolete `utopia-php/framework` v0.33** — `utopia-php/http` is the modern home for HTTP plus its own Swoole/SwooleCoroutine/FPM adapters. New services should require `utopia-php/http` and import `Utopia\Http\Adapter\Swoole\Server`, not this package
- **`Files` is process-global state** — long-running Swoole workers carry it across requests. `flush()` semantics don't exist; restart the worker to drop a file
- **Trusting `x-forwarded-for` without a proxy is a spoofing footgun** — only call `setTrustedIpHeaders` when the server is reachable solely through a known reverse proxy (e.g. Traefik, nginx)
- **`Response::send()` calls `$swoole->end()`**, which is one-shot — calling `send` twice in one handler raises a Swoole warning and the second body is silently dropped
- **No coroutine-aware request/response** — this lib uses the `Swoole\Http\Server` (worker-process model). For coroutine HTTP servers (`Swoole\Coroutine\Http\Server`), `utopia-php/http`'s `Adapter\SwooleCoroutine\Server` is the only path

## Migration path (for Appwrite services still on this lib)
1. Bump `utopia-php/framework` → `utopia-php/http` (plus DI/router rewrite as needed)
2. Replace `new \Swoole\Http\Server(...)` + `on('request')` with `new Utopia\Http\Adapter\Swoole\Server($host, $port)` and `(new Http(...))->start()`
3. Drop `Utopia\Swoole\Request/Response` — `utopia-php/http` ships native ones
4. Move `Utopia\Swoole\Files::load()` calls into `Http::setResource('files', ...)` or use the new framework's static-file passthrough
5. Re-validate `getIP()` callers — the new `Utopia\Http\Request` exposes a similar API but headers names differ slightly

## Example (legacy, for reference only)
```php
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\Swoole\Files;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;

Files::load(__DIR__ . '/../public');

$http = new Server('0.0.0.0', 80);

$http->on('request', function (SwooleRequest $req, SwooleResponse $res): void {
    $request  = (new Request($req))->setTrustedIpHeaders(['x-forwarded-for']);
    $response = new Response($res);

    if (Files::isFileLoaded($request->getURI())) {
        $maxAge = 60 * 60 * 24 * 30;
        $response
            ->setContentType(Files::getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age=' . $maxAge)
            ->send(Files::getFileContents($request->getURI()));
        return;
    }

    try {
        (new App('UTC'))->run($request, $response);
    } catch (\Throwable $e) {
        $res->status(500);
        $res->end('500: Server Error');
    }
});

$http->start();
```
