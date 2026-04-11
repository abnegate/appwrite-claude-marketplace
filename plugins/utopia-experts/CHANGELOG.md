# Changelog — utopia-experts

All notable changes to this plugin.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.0] — 2026-04-12

### Added
- `agents/utopia-router.md` — Haiku subagent dispatched automatically
  on any utopia-php question. Reads `skills/INDEX.md`, picks 1-3
  relevant expert skills, reads them in its own context, returns a
  200-400 word synthesised answer with exact citations. Hard rules:
  never loads >3 skills, never loads speculatively, never answers
  from training, read-only, redirects Swoole questions to
  `swoole-expert`, handles `utopia-usage-expert` stub explicitly.
- `commands/utopia?.md` — explicit `/utopia? <question>` wrapper
  that dispatches the router agent and returns its answer verbatim.
- `skills/INDEX.md` — auto-generated catalogue of all 50 skills
  grouped into 10 categories, with a Composition notes section
  listing 7 known cross-library pairings (observability pipeline,
  Swoole pool stack, SDK regen cascade, custom-domain onboarding,
  ingestion pipeline, rate limiting, messaging worker).
- `scripts/generate_index.py` — regenerates `skills/INDEX.md` from
  the 50 SKILL.md frontmatter blocks. Verified idempotent.

### Changed
- Command originally shipped as `/utopia-lookup` renamed to `/utopia?`
  to match the query-suffix convention for information-retrieval
  commands (primary output is information, not action).

## [0.1.0] — 2026-04-11

### Added
- 50 per-library expert skills, one per `utopia-php` library:
  - Framework core: http, di, servers, platform, config
  - Data layer: database, mongo, query, pools, dsn
  - Storage & I/O: storage, cache, fetch, compression, migration
  - Auth & security: auth, jwt, abuse, waf, validators
  - Runtime: cli, system, orchestration, preloader, proxy
  - Observability: logger, telemetry, audit, analytics, span
  - Messaging: messaging, queue, websocket, async, emails
  - Domain logic: pay, vcs, domains, dns, locale
  - Utilities: ab, registry, detector, image, agents
  - Misc: console, cloudevents, clickhouse, balancer, usage
- Each skill follows the same shape: Purpose / Public API / Core
  patterns / Gotchas / Appwrite leverage opportunities / Example
- Built from parallel deep research across github.com/utopia-php
  with source-verified public API surfaces and composer.json
  inspection. Findings include several upstream bug-class
  discoveries (utopia-php/jwt main branch empty, RS* verify path
  uses openssl_pkey_get_private, utopia-php/abuse circular dep on
  appwrite/appwrite, utopia-php/vcs README underclaiming adapter
  coverage, utopia-php/usage is a stub).
