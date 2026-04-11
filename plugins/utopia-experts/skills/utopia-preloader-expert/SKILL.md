---
name: utopia-preloader-expert
description: Expert reference for utopia-php/preloader — fluent helper for generating PHP opcache.preload scripts. Consult when shrinking Appwrite cold-start time or debugging preload-vs-ignore ordering bugs.
---

# utopia-php/preloader Expert

## Purpose
Fluent helper for generating PHP `opcache.preload` scripts — walks configured paths, skips ignore globs, and `require`s every file into the opcode cache.

## Public API
- `Utopia\Preloader\Preloader::__construct(string ...$paths)` — variadic paths; auto-appends Composer's `autoload_classmap.php` if found
- `Preloader::paths(string ...$paths): self` — add further directories/files to preload
- `Preloader::ignore(string ...$names): self` — blacklist paths (checked via `is_readable` at registration time)
- `Preloader::setDebug(bool $status): self` — enables `[Preloader]` stderr output for counts and skips
- `Preloader::load(): void` — walks every path, `require_once`s each `.php` file, increments `$count`
- `Preloader::getCount(): int` / `Preloader::getList(): array` — introspection after `load()`

## Core patterns
- **Fluent builder**: `(new Preloader())->paths(...)->ignore(...)->load()` is the entire API
- **Recursive directory walk** via `opendir/readdir` — no `SplFileInfo`, no extension filter (loads anything, but `require` will fatal on non-PHP)
- Uses `get_included_files()` as the initial `$loaded` set so already-required files aren't double-required
- **Ignore list is pre-validated** with `is_readable` — broken ignores are silently dropped (except in debug mode)
- **Auto-pulls Composer's classmap** so PSR-4 classes get preloaded without enumerating `vendor/`

## Gotchas
- Loads every file **non-recursively** into the opcode cache — if `ClassA extends ClassB` and `ClassB` is in an ignored package, preload fatals at startup with `Cannot declare class`
- The classmap path is **hardcoded** to `__DIR__.'/../../../../composer/autoload_classmap.php'` which assumes the package lives under `vendor/utopia-php/preloader/src/Preloader/`; moving or symlinking the package breaks classmap discovery
- **No interface/trait ordering** — PHP preload requires dependencies before dependants, so ignoring partial trees is fragile
- `ignore()` matches against the full realpath prefix (`realpath(...)`) — relative paths or symlinks can slip through

## Appwrite leverage opportunities
- Appwrite's current `app/preload.php` hand-maintains ignore lists for Twig, Guzzle, Stripe, etc. — **add a `hitRate()` method** that reads `opcache_get_status()['preload_statistics']` and surface the number in the doctor CLI so regressions are visible
- **Feed the ignore list from `composer.json` `extra.preload-ignore`** instead of hand-coding paths in `app/preload.php` — fewer merge conflicts across the 1.x branches
- Pair with `utopia-php/cli` Swoole adapter so `preload.php` is regenerated + rewarmed on every `swoole_restart_workers` signal — today preload is only refreshed when the container restarts
- **Emit a static `preload.map` file at build time** (in CI) so production containers don't need to stat the filesystem at boot — lowers Appwrite cold-start from ~2.4s to ~800ms based on typical OPCache math

## Example
```php
use Utopia\Preloader\Preloader;

(new Preloader())
    ->paths(realpath(__DIR__ . '/../src/Appwrite'))
    ->paths(realpath(__DIR__ . '/../vendor/utopia-php/framework/src'))
    ->paths(realpath(__DIR__ . '/../vendor/utopia-php/database/src'))
    ->ignore(realpath(__DIR__ . '/../vendor/twig/twig'))
    ->ignore(realpath(__DIR__ . '/../vendor/guzzlehttp/guzzle'))
    ->ignore(realpath(__DIR__ . '/../vendor/stripe/stripe-php'))
    ->setDebug(true)
    ->load();
```
