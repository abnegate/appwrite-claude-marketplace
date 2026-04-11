# appwrite-conventions

Auto-loaded context plugin encoding the stable priors for the Appwrite
/ Utopia / Swoole stack. Install this once and stop re-explaining the
framework to every session.

## What's in it

### `CLAUDE.md` (auto-loaded)

The framework-level conventions Claude should never have to re-derive:

- **Framework choice.** Utopia PHP, not Laravel / Symfony. No Eloquent,
  no Artisan, no Blade.
- **Runtime.** Swoole 6 on PHP 8.3+. Coroutine-hooked entry points,
  per-worker pool init, no static singletons.
- **Namespace layout.** Singular nouns (`Adapter`, not `Adapters`),
  `src/Utopia/<Library>/...` for Utopia extensions, nested subdirs
  for implementations.
- **Composer constraints.** `*` wildcards for Utopia packages
  (`"utopia-php/framework": "0.33.*"`), never `^` or `~`. Never shim
  or patch local dependencies — edit source, push, update.
- **Code style.** First-class callables, `??` vs `?:`, typed config
  objects, enums over class constants, one class per file, `assertSame`
  over `assertEquals`, `array_push` not `array_merge` in loops.
- **REST conventions.** Plural nouns, kebab-case paths, camelCase
  method names (including acronyms: `updateMfa()`, not `updateMFA()`).
- **Testing.** No database mocks. Every bug fix ships with a regression
  test. There are no "pre-existing issues".
- **PRs target the active version branch** (`1.9.x`, not `main`).

### `skills/utopia-patterns/SKILL.md`

Reference guide for the patterns that repeat across `utopia-php`
libraries — routing via `App::get()->label()->param()->inject()->action()`,
DI via `App::setResource()`, the Database query builder, the adapter
pattern, pool lifecycle, validators, and events/queues.

Consulted on-demand when a session needs to recognize the idiom
without re-reading the framework source.

## Scope

Install this plugin if you work on PHP code in the Appwrite ecosystem.
Typical targets include:

- **Core monorepo** — `appwrite/appwrite`
- **Cloud** — `appwrite/cloud`
- **Utopia libraries** — every `utopia-php/*` package (database, cache,
  pools, http, framework, queue, messaging, storage, pay, vcs, audit,
  telemetry, etc.)
- **SDK tooling** — `appwrite/sdk-generator`
- **Functions infrastructure** — `appwrite/open-runtimes` (host-side
  PHP, not the non-PHP runtime images themselves)
- Internal PHP services, task workers, Appwrite CLI, and any new PHP
  project that imports a `utopia-php/*` package or runs under Swoole

The conventions auto-load into every session. When you're working on a
Svelte frontend, Terraform module, or non-PHP runtime, the conventions
still load silently but their rules don't apply — ignore them.

## Why auto-load instead of re-deriving

The framework preamble — "is this Laravel?", "what's the namespace
convention?", "what's the composer constraint format?", "is Swoole
hooked?" — compresses cleanly into a ~200-line CLAUDE.md that
autoloads once per session instead of being re-derived every time.

This isn't documentation you're expected to read — it's context Claude
is expected to have before the first prompt.
