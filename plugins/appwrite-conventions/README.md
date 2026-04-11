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

This plugin is framed for work in these repos:

```
~/Local/appwrite
~/Local/cloud
~/Local/database
~/Local/edge
~/Local/console
~/Local/proxy
~/Local/sdk-generator
~/Local/query
~/Local/open-runtimes
```

When you're working in a repo from a different ecosystem (Kotlin, Rust,
Terraform), these conventions still load but don't apply. You can
ignore them.

## Why auto-load instead of re-deriving

The Appwrite stack has ~230 Claude sessions historically, averaging
several kilobytes of framework preamble that Claude re-asks about every
time: "is this Laravel?", "what's the namespace convention?", "what's
the composer constraint format?", "is Swoole hooked?". That preamble
compresses cleanly into a ~200-line CLAUDE.md that autoloads once per
session.

This isn't documentation you're expected to read — it's context Claude
is expected to have before the first prompt.
