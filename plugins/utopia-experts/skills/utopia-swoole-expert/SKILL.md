---
name: utopia-swoole-expert
description: Reference for utopia-php/swoole — the ARCHIVED Swoole adapter for the legacy Utopia HTTP framework. The Request/Response/Files classes have been absorbed into utopia-php/http (`Adapter\Swoole\*`). Consult only when maintaining pre-merger services or migrating to the new namespace.
---

# utopia-php/swoole Expert

## Purpose
**Archived.** Originally an extension for the legacy Utopia framework that exposed Swoole-aware `Request`, `Response`, and `Files` classes so PHP services could run on Swoole as an FPM alternative. The functionality moved into `utopia-php/http` as `Utopia\Http\Adapter\Swoole\*` and the standalone repo is no longer maintained.

## Public API (legacy)
- `Utopia\Swoole\Request(\Swoole\Http\Request $req)` — wraps `swoole/http2-request` with the Utopia `Request` interface
- `Utopia\Swoole\Response(\Swoole\Http\Response $res)` — wraps `swoole/http2-response`; chunk-aware `send()`, header-batching
- `Utopia\Swoole\Files` — static-file passthrough; `Files::load(string $directory)`, `Files::isFileLoaded(string $uri)`, `Files::getFileContents/MimeType($uri)`
- Wired by hand to `Swoole\Http\Server` via the `request` event — no `Http::start()` integration

## Patterns
- **Manual server lifecycle** — caller constructs `Swoole\Http\Server`, registers the `request` callback, instantiates `Utopia\Swoole\Request`/`Response`, dispatches into the legacy `App` instance per request
- **Pre-loaded static files** — `Files::load(__DIR__ . '/../public')` reads every file at boot into memory; on each request `isFileLoaded($uri)` checks the in-memory map and short-circuits the framework before any routing runs
- **Cache headers hard-coded by caller** — the README pattern bakes a 2-year `Cache-Control: public, max-age=...` plus `Expires` header into static-file responses; no programmatic TTL helper

## Gotchas
- **REPO IS ARCHIVED** — listed as archived on GitHub (`public, archived` in the org listing). No new fixes; CVEs in transitive deps will not be patched here
- **Replaced by `Utopia\Http\Adapter\Swoole\*`** — `utopia-php/http` ships `Adapter\Swoole\Server`, integrated `Request`/`Response`, and `Utopia\Http\Files`. New code MUST use those; the standalone library predates the merger
- **Targets PHP 8.0** — older than the rest of the modern Utopia stack (PHP 8.3+); composer-installing it next to current `utopia-php/http` will likely conflict on transitive constraints
- **`Files::load` is sync at boot, not lazy** — large `public/` directories balloon the worker's RSS; not a fit for a deploy artefact larger than ~tens of MB
- **No coroutine-aware request scope** — the legacy library predates `Utopia\DI\Container` request scoping; per-request state had to be threaded manually through closures

## Migration to `utopia-php/http`
- `Utopia\Swoole\Request` → `Utopia\Http\Request` (the adapter resolves Swoole vs FPM internally)
- `Utopia\Swoole\Response` → `Utopia\Http\Response`
- `Utopia\Swoole\Files` → `Utopia\Http\Files` (`Files::load` API is preserved)
- Server bootstrap: drop the manual `Swoole\Http\Server` + `request` callback and use `new Utopia\Http\Adapter\Swoole\Server('0.0.0.0', '80')` then `(new Http($adapter, $container, 'UTC'))->start()`
- Static-file cache headers can be wired through an `Http::init()` hook keyed on `Files::isFileLoaded($request->getURI())` instead of bespoke per-request branching

## Composition
- **Active replacement** — see `utopia-http-expert` for the merged `Adapter\Swoole\Server`, request-scope `Container`, and lifecycle hooks
- **Resource pooling under Swoole** — `utopia-pools-expert` (Swoole `Channel` adapter), `utopia-async-expert`
- **Coroutine-safe locking** — `utopia-lock-expert` `Mutex`/`Semaphore` (Swoole 6.0+ required)

## Example (legacy, only for forensic/migration reference)
```php
use Swoole\Http\{Server, Request as SwooleRequest, Response as SwooleResponse};
use Utopia\Swoole\{Request, Response, Files};

$http = new Server('0.0.0.0', 80);
Files::load(__DIR__ . '/../public');

$http->on('request', function (SwooleRequest $req, SwooleResponse $res) {
    $request = new Request($req);
    $response = new Response($res);

    if (Files::isFileLoaded($request->getURI())) {
        $ttl = 60 * 60 * 24 * 365 * 2;
        $response
            ->setContentType(Files::getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age=' . $ttl)
            ->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT')
            ->send(Files::getFileContents($request->getURI()));
        return;
    }

    (new App('UTC'))->run($request, $response);
});

$http->start();
```
