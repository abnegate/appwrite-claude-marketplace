# Appwrite / Utopia / Swoole Conventions

This plugin autoloads the ground-truth conventions for the Appwrite stack
so every session starts with the right priors. When you're working in
`appwrite`, `cloud`, `database`, `edge`, `console`, `proxy`, or
`sdk-generator`, these rules apply. When you're not, ignore them.

## Framework

- **Utopia PHP, not Laravel or Symfony.** Never suggest Eloquent, Artisan,
  Blade, Twig, Doctrine, Service Providers, or anything framework-specific
  to another PHP ecosystem. The routing, DI, and request pipeline all go
  through `utopia-php/framework`.
- **Swoole 6 runtime on PHP 8.3+.** Processes are coroutine-hooked via
  `Co::set(['hook_flags' => SWOOLE_HOOK_ALL])` at the very top of each
  entry point. Pools (database, Redis, HTTP clients) are built once per
  worker in `onWorkerStart`, never at file load and never as static
  singletons.
- **No traditional request/response lifecycle.** Expect long-running
  worker processes with persistent connections. Global state is worker-
  scoped, not request-scoped.

## Namespace layout

- Primary namespace: `Appwrite\`.
- When extending a Utopia library in the Appwrite or Cloud codebases, use
  `Appwrite\Utopia\<Library>\...`, not `Appwrite\<Library>\...`. Example:
  `Appwrite\Utopia\Database\Adapter\MySQL`, not
  `Appwrite\Database\Adapter\MySQL`. The `src/Utopia` directory is the
  namespace root for these.
- **Singular namespace names.** `Adapter` not `Adapters`, `Engine` not
  `Engines` — a namespace is a folder, plurality is implied by contents.
- **Implementations go in nested subdirectories.** `Engine/Driver/Postgres.php`,
  not `Engine/PostgresDriver.php`. The file name never repeats the
  namespace.

## Composer dependency constraints

- **Use `*` wildcards for Utopia packages**, not `^` or `~`. Example:
  `"utopia-php/framework": "0.33.*"`, never `"~0.33.0"` or `"^0.33.0"`.
  This matches the release cadence of the Utopia libraries.
- Third-party packages use `^` as normal.
- **Never downgrade a package** to work around a bug. Fix the underlying
  issue in the dependency, push it, and update the constraint.
- **Never use shims or patch files** for local dependencies. Edit source
  in the dependency repo, commit and push, then run composer update in
  the consuming repo. Local path repositories are fine for iteration.

## Code style (PHP)

- **First-class callable syntax:** `$this->action(...)`, not
  `[$this, 'action']`.
- **`??` for null coalescing, `?:` for falsy checks.** `getenv()` never
  returns null, so use `?:` with it.
- **Typed config objects over associative arrays.** Use `readonly class`
  with typed constructor property promotion wherever possible.
- **Full type hints on every parameter and return type.** No `mixed`
  unless there's no alternative.
- **Use `array_push($items, ...$new)` instead of `array_merge` in loops.**
  `array_merge` copies the full array every iteration — quadratic.
- **`array_values(array_unique($list))`** after deduping to keep the
  array a list, not a holey map.
- **Enums for constants.** `enum Suit: string`, never class constants
  for values used as switch targets.
- **One class per file, filename matches class name.**
- **Imports:** alphabetical, one per line, grouped `use const` / `use
  function` / `use <class>`.
- **Single quotes** for strings by default. Double quotes only when the
  string contains a single quote.

## Testing

- **PHPUnit 9+ with `assertSame` by default**, not `assertEquals`.
  `assertSame` checks type + value; `assertEquals` does loose comparison
  and has hidden gotchas with floats and objects.
- **Every bug fix ships with a regression test** that fails without the
  fix and passes with it. This is enforced by the `appwrite-hooks`
  `regression_test_hook.py` at commit time.
- **There are no "pre-existing issues".** If a test fails, fix it,
  regardless of when it broke.
- **No mocks for the database** — integration tests hit a real database.
  Mock/prod divergence has historically masked real migration bugs.

## REST conventions

- **Plural nouns** for resources: `/collections`, not `/collection`.
- **kebab-case** for multi-word paths: `/acme-challenge`, not
  `/acmeChallenge`.
- **camelCase** method and function names, even with acronyms:
  `updateMfa()`, not `updateMFA()`. Uppercase acronyms break SDK
  generation (`create_m_f_a` instead of `create_mfa`).
- **Config keys and constants** fully capitalize acronyms: `cacheTTL`,
  `parseURL`, `DATABASE_URL`.

## Branch and PR discipline

- **Appwrite PRs target the current version branch** (e.g., `1.9.x`),
  not `main`. `main` is reserved for release management.
- **Sparse updates when modifying a record** — pass only the changed
  attributes, not the full object. This matches how the Utopia Database
  layer handles updates and avoids unintended overwrites.
- **Conventional commits**, `(type): subject`, enforced by
  `appwrite-hooks` `conventional_commit_hook.py`.
- **Never use `--no-verify` or `--amend` on a failed-hook commit.** The
  `appwrite-hooks` `no_verify_guard_hook.py` blocks both by default.

## What's already captured elsewhere

Don't re-derive things that can be read from the code:
- Specific class/file locations — use Grep.
- Current dependency versions — read `composer.json`.
- The list of active branches — use `git branch -a`.
- The exact shape of an adapter interface — Read the base class.

These conventions are the stable priors that don't change session to
session. The moving parts belong in the code.
