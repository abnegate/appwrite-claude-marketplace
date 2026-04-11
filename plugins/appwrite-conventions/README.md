# appwrite-conventions

Auto-loaded context plugin encoding the stable priors for the
Appwrite ecosystem across backend, frontend, and infrastructure.
Install this once and stop re-explaining the stack to every session.

## What's in it

### `CLAUDE.md` (auto-loaded)

The domain-level conventions Claude should never have to re-derive,
grouped by domain so you can scan the section that matches the file
you're editing:

**Backend — PHP / Utopia / Swoole**
- Utopia PHP framework (not Laravel/Symfony), Swoole 6 runtime on
  PHP 8.3+, coroutine-hooked entry points, per-worker pool init
- `src/Utopia/<Library>/...` namespace layout for Utopia extensions,
  singular nouns, nested subdirs for implementations
- Composer `*` wildcards for Utopia packages, never shim or patch
  local deps
- First-class callables, `??` vs `?:`, typed config objects, enums
  over class constants, `assertSame` over `assertEquals`,
  `array_push` not `array_merge` in loops
- REST: plural nouns, kebab-case paths, camelCase method names

**Frontend — Console (SvelteKit / Svelte 5)**
- Grounded directly in `appwrite/console`'s own `AGENTS.md`
- Bun for all tooling (`bun run <script>`, never bare `bun test`)
- SvelteKit 2 + Svelte 5, TypeScript, `@sveltejs/adapter-static`,
  static SPA behind Nginx at `/console`
- Route layout groups: `(public)`, `(console)`, `(authenticated)`
- Path aliases: `$lib`, `$routes`, `$themes`, `$database`
- Barrel imports via `index.ts`, prefer Svelte 5 runes, migrate
  legacy on touch
- SDK clients: `sdk.forConsole`, `sdk.forConsoleIn(region)`,
  `sdk.forProject(region, projectId)`
- Data loading via `depends()` + `Dependencies` enum
- Store patterns (writable, derived, conservative), wizard modal,
  notifications, analytics
- Prettier 4 spaces, single quotes, no trailing commas, 100 char
- Run `bun run format && check && lint && tests && build` before
  every commit

**Infrastructure — Terraform / DigitalOcean / Cloudflare / Kubernetes**
- Provider pinning (`~>` version ranges, committed
  `.terraform.lock.hcl`)
- Remote state with locking, one state per environment, never
  edit state by hand
- Environment-prefixed kebab-case resource names, tags on every
  resource, no hardcoded regions
- Secrets via `tfvars` (gitignored), env vars, or a secrets
  manager — never committed
- Thin root module, one module per concern, typed variables,
  outputs as public API
- Always plan before apply, never `-target` outside emergencies,
  `prevent_destroy` on stateful resources

**Cross-cutting**
- Testing discipline (regression test for every bug fix, no DB
  mocks, no "pre-existing issues")
- PR targeting (backend PRs target the version branch, not main)
- Branch naming (`feat-548-add-backup-ui`)
- Conventional commits + no-verify/amend block (enforced by
  `appwrite-hooks`)
- Cross-repo awareness (`feat-dedicated-db` spans 4 repos)

### `skills/utopia-patterns/SKILL.md`

Reference guide for the patterns that repeat across `utopia-php`
libraries — routing, DI, the Database query builder, pools,
validators, events/queues, SDK codegen. Consulted on-demand when a
session needs to recognize the idiom without re-reading the
framework source.

### `skills/swoole-expert/SKILL.md`

1,641-line deep reference for writing production Swoole PHP code —
coroutines, hooks, Channel/WaitGroup/Barrier, HTTP/WebSocket/TCP
servers, Process/Pool, shared memory, connection pooling, pitfalls,
production tuning, version notes for 5.x/6.x. Consulted on-demand
for anything touching the Swoole runtime.

## Scope

Install this plugin if you work on any facet of Appwrite development:

- **Backend** — core monorepo (`appwrite/appwrite`), Cloud, every
  `utopia-php/*` library, `appwrite/sdk-generator`, host-side code
  in `appwrite/open-runtimes`, internal PHP services, and any new
  PHP project built on the stack
- **Frontend** — `appwrite/console` (the SvelteKit dashboard) plus
  any `@appwrite.io/pink-*` design-system work
- **Infrastructure** — Terraform modules targeting DigitalOcean,
  Cloudflare, Kubernetes, and Helm (the stack Appwrite Cloud runs on)

Each section of `CLAUDE.md` is self-contained. The backend rules
don't apply when you're editing Svelte; the frontend rules don't
apply when you're writing PHP. Pick the domain that matches the
file you're touching.

## Why auto-load instead of re-deriving

Every ecosystem has a preamble that Claude would otherwise re-derive
in every session: "is this Laravel?" (backend), "is this using bun
or npm?" (frontend), "what Terraform providers?" (infrastructure).
Compressing those priors into a single CLAUDE.md that auto-loads
once per session replaces dozens of "let me read package.json /
composer.json / versions.tf first" loops with ambient context.

This isn't documentation you're expected to read — it's context
Claude is expected to have before the first prompt.
