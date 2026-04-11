---
name: utopia-platform-expert
description: Expert reference for utopia-php/platform — the OO scaffolding that turns routes/tasks/workers into reusable Action/Service/Module/Platform classes. Consult when organizing endpoint catalogues or refactoring closure-style controllers into classes.
---

# utopia-php/platform Expert

## Purpose
Object-oriented scaffolding that turns Utopia route/task/worker definitions into reusable `Action`/`Service`/`Module`/`Platform` classes — the way Appwrite organises its endpoint/worker catalogue.

## Public API
- `Utopia\Platform\Platform` — root; holds a core `Module`, extra modules, a `CLI`, and a `Queue\Server`. `init('http'|'Task'|'Worker'|'GraphQL', $params)` wires actions onto the underlying Utopia library
- `Utopia\Platform\Module` — bag of `Service` instances, queried by service type during `init()`
- `Utopia\Platform\Service` — typed container of `Action`s; `TYPE_HTTP|GRAPHQL|TASK|WORKER`
- `Utopia\Platform\Action` (abstract) — one endpoint/task/job; `type` (`TYPE_DEFAULT`/`INIT`/`SHUTDOWN`/`ERROR`/`OPTIONS`/`WORKER_START`), params, injections, labels, groups, callback
- `Utopia\Platform\Scope\HTTP` — trait mixed into `Action`, exposes `httpPath`, `httpMethod`, `httpAliasPath`

## Core patterns
- **Type-dispatched init** — `Platform::init('http')` iterates every module, pulls services of that type, and translates `Action`s into `Http::get/post/init/error` calls. Same method works for `Task` (CLI) and `Worker` (Queue)
- **Action as a class** — params/injections accumulated in the action's constructor via `$this->param(...)`/`$this->inject(...)`; `HTTP` trait declares the path/method — one file per endpoint
- **Options array carries config** — params/injections stored as generic records so the same metadata describes HTTP routes, CLI tasks, and workers
- **Lazy CLI/worker construction** — `$this->cli ??=` / `$this->worker ??=` only materialises instances when that init type is first invoked
- **Labels for codegen** — `Action::label('sdk.method', 'create')` is read during SDK generation; the platform preserves labels across all three runtime types

## Gotchas
- `initWorker` filters by `strtolower($key) === $workerName` — action keys must be lowercased or the job is silently skipped
- `initGraphQL()` is an **empty method** — the type exists but does nothing; Appwrite's GraphQL wiring happens outside this library
- Composer requires **`ext-redis`** even for HTTP-only usage because of the transitive queue dependency
- The `Platform` constructor requires a `core` module — building an "empty" platform means passing a dummy module first, awkward in tests

## Appwrite leverage opportunities
- Appwrite's `app/controllers/` currently uses closure-style `App::post('/v1/...')` registration — migrating these to `Action` subclasses would get automatic CLI/Worker re-use of the same validation metadata without duplicating param definitions in `app/workers/`. Over 100 controllers = huge DRY win
- A `Platform::boot()` method that validates all action metadata (labels against a schema, required injections exist in the container, HTTP paths are unique) at startup catches issues before the first request
- `initGraphQL()` being empty is an open slot — implementing it to auto-generate a GraphQL schema from `Action` metadata + labels gives `/v1/graphql` zero-config parity with REST
- A `PlatformTestCase` PHPUnit base class that takes a `Platform`, calls `init('http')` into a throwaway `Http` instance, and provides a `dispatch($method, $path, $body)` helper would replace Appwrite's current E2E-only testing with fast unit tests per action

## Example
```php
use Utopia\Platform\{Action, Service, Module, Platform};
use Utopia\Validator\UID;

class GetUserAction extends Action {
    public function __construct() {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId')
            ->desc('Get user by ID')
            ->groups(['api'])
            ->label('sdk.method', 'get')
            ->param('userId', '', new UID(), 'User ID')
            ->inject('response')
            ->callback($this->action(...));
    }
    public function action(string $userId, $response): void { $response->json(['id' => $userId]); }
}

$users = (new Service())->setType(Service::TYPE_HTTP)->addAction('get', new GetUserAction());
$platform = new Platform(new class extends Module {});
$platform->addService('users', $users);
$platform->init('http');
```
