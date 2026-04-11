# Changelog — appwrite-conventions

All notable changes to this plugin.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.0] — 2026-04-12

### Added
- `swoole-expert` skill (1,641 lines) — deep reference for production
  Swoole PHP code. Covers the long-running process mental model,
  coroutines, runtime hooks, Channel/WaitGroup/Barrier/defer, HTTP/
  WebSocket/TCP servers, `Swoole\Process` + `Process\Pool`, shared
  memory (Table/Atomic/Lock), coroutine clients, connection pooling,
  pitfalls, production tuning, debugging, testing, and Swoole 6.x
  version notes. Copied from `~/Local/claudes/skills/swoole-expert`
  and placed here because Swoole is the runtime every Utopia-based
  service sits on.

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
