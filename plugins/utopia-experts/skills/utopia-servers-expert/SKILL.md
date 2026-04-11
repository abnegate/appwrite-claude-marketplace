---
name: utopia-servers-expert
description: Expert reference for utopia-php/servers — the shared base for Utopia http/cli/queue front-ends, providing static hook registries, mode flags, and the validation pipeline. Consult when debugging cross-runtime hook leakage or writing Utopia framework code.
---

# utopia-php/servers Expert

## Purpose
Shared base class providing static lifecycle registries (init/shutdown/error/start/end hooks), mode flags, and dependency-injected validation for Utopia's `http`, `cli`, and `queue` framework front-ends.

## Public API
- `Utopia\Servers\Base` — abstract parent of `Http\Http`, `CLI\CLI`, `Queue\Server`; holds `$init`/`$shutdown`/`$errors`/`$start`/`$end` Hook registries and a `Container` reference
- `Utopia\Servers\Hook` — fluent hook/route descriptor: `param`, `inject`, `label`, `groups`, `desc`, `action`
- `Utopia\Servers\Exception` — thrown for missing params, validator failures, and invalid validator instances

## Core patterns
- **Static hook registries** — `Base::init()`/`shutdown()`/`error()`/`onStart()`/`onEnd()` each push a `Hook` into a per-class static array, defaulting to the wildcard group `['*']`
- **Mode constants** — `MODE_TYPE_DEVELOPMENT|STAGE|PRODUCTION`; `isProduction()`/`isDevelopment()`/`isStage()` for guarding debug code paths
- **`prepare($context, $hook, $values, $requestParams)`** — forks a child `Container`, resolves params (callable defaults get their own injections), validates, registers each param back onto the scope as a factory
- **Validator-as-factory** — if `$param['validator']` is callable, it's registered as `_validator:{key}` on the scope with its own injection list so validators can themselves depend on request services
- **`reset()`** clears all static state — test fixtures only

## Gotchas
- All hook storage is `protected static` on `Base`, shared via late static binding per subclass — tests that register `Http::init()` then run a CLI test can bleed state unless `reset()` is called
- The parent constructor is commented out — subclasses **must** set `$this->container` themselves or `getContainer()` errors on an uninitialised property
- `validate()` caches validator callables as `_validator:{key}` — reusing the same param name across routes in the same request scope reuses the cached validator instance
- The `Exception` class throws with an HTTP status code (e.g. `400`) — fine for `http`, but CLI/Queue subclasses inherit the same semantics

## Appwrite leverage opportunities
- Appwrite has several `init.php` files registering identical `Http::init()` hooks for request-id, telemetry, and auth — extracting these as a reusable `Appwrite\Servers\Hooks` module that registers them on any `Base` subclass (CLI, workers, http) would unify observability across runtime types with one call
- The static-state sharing across `Http`/`CLI`/`Queue` is a latent coroutine bug — a `ScopedRegistry` refactor that keys `$init`/`$shutdown` arrays by the subclass FQN would remove the implicit global without breaking the public API
- `prepare()` runs validators sequentially; under Swoole coroutines, independent param validators (e.g. body JSON + header token) could fan out via `go {}` blocks — an `AsyncBase` that parallelises non-dependent validators would halve TTFB on high-param endpoints
- `Hook::label()` is used by Appwrite for SDK codegen (`sdk.method`, `abuse-key`); a `LabelSchema` validator run at boot would catch misspelled labels like `abuse-ket` that currently only surface in SDK generation

## Example
```php
use Utopia\Servers\Base;
use Utopia\Http\Http;

Http::init()
    ->groups(['api'])
    ->inject('request')
    ->action(function (Utopia\Http\Request $request) {
        if (Base::isProduction() && !$request->getHeader('x-api-key')) {
            throw new Utopia\Servers\Exception('Missing API key', 401);
        }
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('response')
    ->action(fn (Utopia\Http\Response $response) => $response->addHeader('x-server-mode', Base::getMode()));

Http::error()
    ->inject('error')->inject('response')
    ->action(fn (\Throwable $error, Utopia\Http\Response $response) =>
        $response->setStatusCode($error->getCode() ?: 500)->json(['message' => $error->getMessage()]));
```
