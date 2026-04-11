---
description: Ask a utopia-php question and get a distilled answer from the right 1-3 expert skills
argument-hint: "<question about any utopia-php library>"
---

# /utopia? — Routed lookup across the 50 utopia expert skills

Thin wrapper around the `utopia-router` agent for times when you want to
explicitly route a question to the utopia experts rather than relying on
automatic dispatch.

## When to use

- You know the answer is in one of the utopia-php libraries but you don't
  want to spend parent-session context guessing which one.
- You want a synthesised answer rather than loading a full SKILL.md.
- You're in an expensive session (`cloud`, `edge`, `database`) and want to
  minimise context bloat.

## When NOT to use

- You already know exactly which skill you need — just load it directly
  (`read plugins/utopia-experts/skills/utopia-<name>-expert/SKILL.md`).
- The question is about Swoole itself, not a utopia-php library — use the
  `swoole-expert` skill in `appwrite-conventions` instead.
- The question is about application code in `appwrite/appwrite` or
  `appwrite/cloud` — these aren't utopia-php libraries.

## Execution

1. Take `$ARGUMENTS` as the question (default: ask the user for one if empty).
2. Dispatch the `utopia-router` agent with the question as the prompt.
3. Return the router's synthesised answer to the user verbatim — don't
   re-synthesise or expand. The whole point of routing is to keep the
   parent session context clean.
4. If the router suggests loading a specific skill in full for more
   detail, surface that suggestion to the user rather than auto-loading.
   The user decides whether the distilled answer is enough.

## Example

```
/utopia? how do I page query results with a stable cursor?
```

Expected router output (which this command surfaces unchanged):

```
## From utopia-query-expert

**Short answer:** Use Query::cursorAfter($lastId) — it's exclusive and
stable across inserts, unlike offset-based pagination.

**Details:**
- Every Query constructor returns a fresh immutable-ish object; build
  queries as arrays, don't mutate.
- cursorAfter and cursorBefore are mutually exclusive; passing both
  silently keeps whichever comes last.
- Query::groupByType() splits queries into buckets (filters, selections,
  cursor, orderAttributes) — the cursor bucket is what adapters use.
- equal() always takes an array of values, even for a single match.

**Citations:** utopia-query-expert#Core-patterns, utopia-query-expert#Gotchas

**If you need more:** load plugins/utopia-experts/skills/utopia-query-expert/SKILL.md
for the full API surface and Appwrite leverage opportunities.
```
