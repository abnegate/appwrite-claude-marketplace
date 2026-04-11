# Changelog — appwrite-skills

All notable changes to this plugin.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.0] — 2026-04-12

### Added
- `/swoole-fix` — companion to `/swoole-audit`. Takes either an
  audit's finding list or a path to re-audit, loads `swoole-expert`
  as primary reference, classifies findings into mechanical /
  structural / uncertain, dispatches fix subagents in parallel,
  returns a diff per finding for human review. Does not auto-apply.
- `/marketplace-help` — discovery command that lists every command,
  skill, agent, and hook across the four marketplace plugins with
  one-line descriptions from each file's frontmatter. Optional
  `$ARGUMENTS` filter.

### Changed
- `/swoole-audit` rewritten to cover 11 generic Swoole bug categories
  instead of the 5 Appwrite-specific ones. Now framework-agnostic.

## [0.1.0] — 2026-04-11

### Added
- `/fanout` — decomposes a task into 3-5 parallel research subagents
- `/swoole-audit` — Swoole correctness audit (originally 5 Appwrite-
  specific bug classes)
- `/merge-conflict` — intent-aware merge conflict resolver
- `CLAUDE.md` with three workflow rules:
  - Opus for parent edits, Haiku for research subagents
  - Multi-repo tasks start in plan mode
  - Edit over Write
