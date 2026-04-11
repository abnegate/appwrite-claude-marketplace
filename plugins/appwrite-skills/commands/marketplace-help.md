---
description: List every command, skill, agent, and hook across all appwrite-claude-marketplace plugins
argument-hint: "[filter]"
---

# /marketplace-help — Discover what's installed

Lists every command, skill, agent, and hook across the four plugins
in `appwrite-claude-marketplace`, grouped by plugin, with one-line
descriptions pulled from each file's frontmatter.

Optional `$ARGUMENTS` filters the output to items whose name or
description matches the filter (case-insensitive substring).

## Execution

1. Resolve the marketplace root. Look in the standard plugin
   locations:
   - `~/.claude/plugins/marketplaces/appwrite-claude-marketplace/`
   - `~/Local/appwrite-claude-marketplace/` (dev checkout)
   Pick the first one that exists.

2. For each plugin directory under `plugins/`:
   - Read `.claude-plugin/plugin.json` for the plugin description
   - Enumerate `commands/*.md`, `skills/*/SKILL.md`, `agents/*.md`
   - Parse each file's YAML frontmatter for `name` + `description`
   - For hooks: read `hooks/hooks.json` and list each registered
     hook command by its script filename

3. Apply the filter (if `$ARGUMENTS` is set) against each item's
   name + description. Drop non-matching items. If a plugin has
   zero matches after filtering, omit the plugin entirely.

4. Render in this shape:

```
# appwrite-claude-marketplace

## appwrite-hooks — PreToolUse hooks that enforce commit/push/edit discipline

Hooks:
  Bash            destructive_guard     Block rm -rf on system paths, dd, mkfs, find -delete
  Bash            force_push_guard      Block force-push to main/master/version branches
  Bash            no_verify_guard       Block --no-verify and --amend
  Bash            conventional_commit   Enforce (type): subject format
  Bash            regression_test       (fix): commits require a staged test file
  Bash            format_lint           Run composer lint / ktlint / cargo / prettier / ruff
  Edit|Write|     secrets_guard         Block writes to .env, *.pem, id_rsa, credential files
   MultiEdit

## appwrite-skills — Slash commands for parallel fanout, Swoole auditing, and merge-conflict resolution

Commands:
  /fanout          Dispatch 3-5 parallel subagents for research before editing
  /swoole-audit    Audit a PHP+Swoole project for 11 bug categories
  /swoole-fix      Fix findings from /swoole-audit using swoole-expert
  /merge-conflict  Resolve merge conflicts by intent with subagent analysis
  /marketplace-help  List every command/skill/hook in the marketplace

## appwrite-conventions — Framework priors auto-loaded as CLAUDE.md context

Skills:
  utopia-patterns  Cross-cutting cheat sheet for utopia-php idioms
  swoole-expert    Deep reference for production Swoole PHP (5.x/6.x)

## utopia-experts — 50 per-library expert skills + routing agent

Agents:
  utopia-router    Routes utopia-php questions to 1-3 relevant expert skills

Commands:
  /utopia?         Explicit router dispatch wrapper

Skills (50):
  utopia-http-expert          framework core — minimalist MVC framework
  utopia-di-expert            framework core — PSR-11 container
  ... (see plugins/utopia-experts/skills/INDEX.md for the full list)

To see a specific expert, load plugins/utopia-experts/skills/utopia-<name>-expert/SKILL.md
or dispatch the utopia-router agent.
```

5. End with a "useful starting points" section:

```
## Getting started

- Touching a utopia-php library? Dispatch the utopia-router agent
  (automatic) or use /utopia? <question> explicitly.
- Working on Swoole code? The swoole-expert skill auto-loads; for
  static analysis of a project run /swoole-audit then /swoole-fix.
- Branching into multi-repo work? /fanout decomposes the task.
- Resolving merge conflicts? /merge-conflict classifies by intent.

All commit/push/edit-time discipline is enforced by appwrite-hooks.
If something blocks you, the stderr message tells you what and which
env var to set for a one-off override.
```

## Filter example

```
/marketplace-help swoole
```

Outputs only: the three Swoole-related items (swoole-expert skill,
/swoole-audit, /swoole-fix) plus their parent plugin headers. Other
plugins are omitted entirely.

## Notes

- Hook list is **derived from `hooks.json`**, not hardcoded — so
  new hooks added to the plugin automatically surface here
- The 50 utopia-experts are summarised, not listed in full (the
  `INDEX.md` is the canonical full list)
- Filtering is case-insensitive substring matching across name +
  description, not a regex — keep the filter short
- This command is read-only — never modify anything based on the
  help output. If the user wants to act on what they see, dispatch
  the appropriate command separately.
