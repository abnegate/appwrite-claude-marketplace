---
name: utopia-http-expert
description: Expert reference for utopia-php/http — the minimalist PHP HTTP framework at the root of Appwrite. Consult when wiring routes, hooks, request scopes, or server adapters (FPM/Swoole/SwooleCoroutine) in any Appwrite-stack service.
---

# utopia-php/http Expert

## Purpose
Utopia's minimalist MVC framework for building HTTP services with pluggable server adapters (FPM, Swoole, SwooleCoroutine) and built-in OpenTelemetry metrics. The root of every Appwrite HTTP service.

## Public API
- `Utopia\Http\Http` — framework entrypoint; static route registration (`get`/`post`/etc), lifecycle hooks, telemetry wiring
- `Utopia\Http\Route` — per-route definition extending `Hook`; path, method, params, aliases, `hook(false)` to skip init/shutdown
- `Utopia\Http\Router` — path-to-route matching with static route registry plus wildcard fallback
- `Utopia\Http\Request` / `Response` — HTTP primitives abstracted over the server adapter
- `Utopia\Http\Adapter` — base server adapter (`FPM\Server`, `Swoole\Server`, `SwooleCoroutine\Server`)
- `Utopia\Http\Hook` — extends `Servers\Hook`; adds HTTP-specific param semantics (route/query/body)
- `Utopia\Http\Files` — static-file passthrough registered on the container

## Core patterns
- **DI-injected route actions** — `->inject('name')->action(fn(...) => ...)`, params resolved from `Container` per request scope
- **Inline route params** — `->param('id', '', new UID(), 'desc', false)` with validator, default, description, and optional flag
- **Grouped lifecycle hooks** — `init`/`shutdown`/`error`/`options` run only when the hook's group matches the route's groups
- **Mode flags** — `Http::MODE_TYPE_PRODUCTION` gates debugging; `Http::setMode()` at boot
- **OpenTelemetry histograms/counters** — `http.server.request.duration`, `active_requests`, body sizes via `setTelemetry()`

## Gotchas
- Routes, hooks, and mode are **static state** on `Http`/`Base` — carries across requests in long-running Swoole workers. `Http::reset()` is a test-only escape hatch
- Swoole adapter opens a real coroutine server; under SwooleCoroutine `ext-swoole` is still a hard composer requirement even for FPM deployments
- `hook(false)` on a route disables **all** init/shutdown hooks, not just one group — easy footgun for health/metrics endpoints
- Request scope is a **child** `Container` built per request via `prepare()`; factories set on the parent cache globally — stateful services on the root container leak between requests

## Appwrite leverage opportunities
- Appwrite re-implements its own OpenTelemetry exporter wiring in `app/init.php` — register a `Telemetry` adapter through `Http::setTelemetry()` once and drop dozens of lines of manual histogram creation, inheriting semantic-conv-correct names for free
- The per-request child `Container` is the ideal place to attach a request-id, `$dbForProject`, and auth principal. Moving those from global mutation in init hooks to `Dependency` factories on the request scope makes them mockable in PHPUnit without touching globals
- `Router`'s static registry defeats hot-reloading under Swoole coroutine workers — a `CachedRouter` that serializes the compiled route table to APCu at boot would cut cold-start route compilation from O(routes) to O(1)
- No `Adapter\RoadRunner` exists; building one would give Appwrite a third long-running runtime option without Swoole's coroutine quirks (filesystem, `pcntl`, third-party extensions)

## Example
```php
use Utopia\DI\{Container, Dependency};
use Utopia\Http\Http;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\{Request, Response};
use Utopia\Validator\UID;

$container = new Container();
$container->set((new Dependency())
    ->setName('project')
    ->inject('request')
    ->setCallback(fn (Request $request) => $request->getHeader('x-appwrite-project', '')));

Http::init()->groups(['api'])->inject('project')->action(
    fn (string $project) => $project === '' ? throw new \Exception('Project required', 401) : null
);

Http::get('/v1/users/:userId')
    ->groups(['api'])
    ->param('userId', '', new UID(), 'User ID')
    ->inject('response')
    ->action(fn (string $userId, Response $response) => $response->json(['id' => $userId]));

Http::setMode(Http::MODE_TYPE_PRODUCTION);
(new Http(new Server('0.0.0.0', '80'), $container, 'UTC'))->start();
```
