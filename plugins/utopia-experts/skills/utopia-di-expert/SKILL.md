---
name: utopia-di-expert
description: Expert reference for utopia-php/di — a ~100-line PSR-11 container with parent-child scoping and lazy singletons. Consult when wiring request-scoped services, mocking dependencies in tests, or debugging resolution order bugs.
---

# utopia-php/di Expert

## Purpose
A ~100-line PSR-11 container with parent-child scoping and lazy singleton caching, zero dependencies — the backbone of Utopia's per-request dependency resolution.

## Public API
- `Utopia\DI\Container` — PSR-11 container; `set(id, factory, deps[])`, `get(id)`, `has(id)`, optional parent for hierarchical resolution
- `Utopia\DI\Dependency` — fluent builder (`setName`, `inject`, `setCallback`) used by `http`, `platform` to compose factories
- `Utopia\DI\Exceptions\NotFoundException` — PSR-11 `NotFoundExceptionInterface`
- `Utopia\DI\Exceptions\DependencyException` — raised on resolution/build failures

## Core patterns
- **Factories with explicit dep lists** — `set('db', fn (array $config) => new PDO(...), ['config'])`. The third argument is the ordered list of IDs; positional, not named — typos are runtime errors
- **Lazy singletons** — `get()` caches the concrete in `$concrete[$id]`; subsequent calls return the same instance until the container is destroyed
- **Parent-child scopes** — `new Container($parent)`. Child falls back to parent for unknown keys; when the child defines a key locally, it gets its own cached copy (perfect for request scopes)
- **Override-by-re-set** — calling `set()` on an existing ID clears its cached concrete; used for test doubles

## Gotchas
- Factory dependency list is **positional**, matching the callable's parameter order. Reordering parameters without reordering the array silently injects wrong values of the same type
- Cache invalidation is per-container-instance only — calling `set()` on the parent **after** a child has resolved a fallback value doesn't refresh the child; the child keeps the stale parent-resolved copy
- No circular-dependency detection — a cycle triggers infinite recursion / stack overflow, not a helpful exception
- `Dependency` (the fluent builder) is not consumed by `Container::set()` directly — it's an `http`/`platform` construct. Mixing `$container->set($dependency)` with raw `set(id, fn, deps)` will confuse contributors

## Appwrite leverage opportunities
- Appwrite's `init.php` wires ~40 globals (`$register`, `$dbForConsole`, `$cache`, `$queue...`) — migrating them to a single root `Container` registered once at boot, then forking a child per request, replaces multiple ad-hoc singleton patterns and makes integration tests trivially swappable
- A `ValidatingContainer` wrapper that asserts factory param counts match dep-list lengths at boot (via reflection) would catch the positional-order footgun before any request hits it
- Add a `cycles()` debug method that does a topo-sort on the registered graph — Appwrite's dep graph is large enough that a cycle today would be a production pager
- Under Swoole coroutine, a container holding a `PDO` is shared across coroutines — a `CoroutineContainer` adapter keyed by `Swoole\Coroutine::getCid()` would eliminate Appwrite's current "clone the container per coroutine" boilerplate in workers

## Example
```php
use Utopia\DI\Container;

$root = new Container();
$root->set('config', fn () => ['dsn' => 'mysql:host=db', 'user' => 'root', 'pass' => '']);
$root->set('pdo', fn (array $config) => new PDO($config['dsn'], $config['user'], $config['pass']), ['config']);

// Per-request child scope — isolates request-id without touching root
$scope = new Container($root);
$scope->set('requestId', fn () => bin2hex(random_bytes(8)));
$scope->set('logger', fn (PDO $pdo, string $requestId) => new AuditLogger($pdo, $requestId), ['pdo', 'requestId']);

$logger = $scope->get('logger'); // pdo resolved on root, requestId on child
```
