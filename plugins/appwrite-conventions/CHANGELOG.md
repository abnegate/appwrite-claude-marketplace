# Changelog — appwrite-conventions

All notable changes to this plugin.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.3.0] — 2026-04-12

### Changed
- **Scope broadened from PHP-only to the full Appwrite ecosystem.**
  The conventions now cover three domains — Backend (PHP/Utopia/
  Swoole), Frontend (SvelteKit Console with Svelte 5 runes), and
  Infrastructure (Terraform with DigitalOcean/Cloudflare/K8s/Helm
  providers) — plus a cross-cutting section for rules that apply
  everywhere (testing, commits, PRs, cross-repo awareness).
- `CLAUDE.md` restructured into four clearly delimited sections so
  each domain is self-contained. The backend section is unchanged
  from 0.2.0 apart from being moved under an H1.

### Added
- **Frontend section** grounded directly in the real `AGENTS.md`
  from the `appwrite/console` repo. Covers the stack (SvelteKit 2 +
  Svelte 5 + TypeScript + Vite + rolldown-vite), tooling (bun for
  everything, `bun run <script>` always), route structure (three
  layout groups: public/console/authenticated), path aliases,
  barrel imports, Svelte 5 runes (preferred on new code; ~500
  legacy files still mid-migration), SDK clients and region-aware
  routing, data loading with `depends()` + 66-key `Dependencies`
  enum, state management patterns (writable/derived/conservative),
  wizard modal, notifications, analytics, theming (4 variants x 2
  modes), code style (Prettier 4-space), and common pitfalls.
- **Infrastructure section** covering Terraform conventions for
  the standard Appwrite Cloud stack: provider pinning with `~>`
  ranges, remote state with locking, one-state-per-environment,
  environment-prefixed kebab-case resource naming, mandatory tags,
  secrets via `tfvars`/env/secrets-manager (never committed), thin
  root module + one-concern modules with typed variables, safety
  rules (plan before apply, no `-target` outside emergency,
  `prevent_destroy` on stateful resources).
- **Cross-cutting section** consolidating testing, branch/PR
  discipline, and cross-repo awareness rules so they're visible
  for all domains.

### Plugin metadata
- `.claude-plugin/plugin.json` description updated to reflect the
  broader scope
- Marketplace `marketplace.json` entry updated with the new scope
- Version bumped to 0.3.0

## [0.2.0] — 2026-04-12

### Added
- `swoole-expert` skill (1,641 lines) — deep reference for production
  Swoole PHP code. Covers the long-running process mental model,
  coroutines, runtime hooks, Channel/WaitGroup/Barrier/defer, HTTP/
  WebSocket/TCP servers, `Swoole\Process` + `Process\Pool`, shared
  memory (Table/Atomic/Lock), coroutine clients, connection pooling,
  pitfalls, production tuning, debugging, testing, and Swoole 6.x
  version notes. Placed in this plugin because Swoole is the
  runtime every Utopia-based service sits on.

### Changed
- `utopia-patterns` frontmatter description now points at the
  per-library expert skills in `utopia-experts` for deep detail.
- `utopia-patterns` gains a pull-quote at the top directing readers
  to the matching `utopia-<library>-expert` skill for deep detail.
- `utopia-patterns` gains a "Deep-dive map" section at the bottom:
  a 51-row table mapping every section to its expert skill
  counterpart (50 utopia libraries + swoole-expert).

## [0.1.0] — 2026-04-11

### Added
- `CLAUDE.md` encoding Utopia framework priors: no Laravel, Swoole
  6 runtime, `src/Utopia` namespace layout, composer `*` wildcards
  for Utopia packages, first-class callable syntax, enum/assertSame/
  array_push conventions, REST naming, sparse updates, PR targeting
  version branches not main
- `utopia-patterns` skill — cross-cutting cheat sheet for routing,
  DI, Database queries, adapters, pools, validators, events, SDK
  codegen
