---
description: Dispatch 3-5 parallel subagents to front-load research before editing
argument-hint: "<task description>"
---

# /fanout — Parallel Research Dispatch

Front-load a task with parallel subagent research before the parent session
touches a single file. This compresses the expensive Opus parent context by
keeping reads and greps in cheap Haiku subagent windows.

**When to use:**
- Any task in a repo where sessions historically run >$100 (`cloud`,
  `database`, `edge`, `sshoo`, `appwrite`).
- Anything touching more than one repo.
- Investigation / "figure out where X is broken" requests.
- Feature work that needs a design survey before editing.

**When NOT to use:**
- Single-file edits with known target.
- Trivial fixes where the diff is already written.

## Execution

1. Read `$ARGUMENTS` — the task description.
2. Decompose into 3-5 **independent** research questions. Good questions
   are narrow, factual, and answerable from reading the codebase:
   - "Where is the billing plan cache populated and invalidated?"
   - "Which files define the Swoole pool lifecycle across the three entry points?"
   - "What's the current shape of the VCS adapter interface vs the GitLab adapter?"
   - "List every call site of `Publisher::dispatch` and the payloads they send."
   - "Find all places that handle a nullable `BillingPlan` and check for null guards."

   Bad questions (too broad, produce sprawling context):
   - "How does the billing system work?"
   - "Summarize the authentication flow."

3. Dispatch all subagents in a **single message** using multiple
   Agent tool calls. Use `subagent_type: "Explore"` by default; use
   `general-purpose` if the question needs more than reads/greps.
   Set each to `thoroughness: "medium"` unless the question explicitly
   needs "very thorough".

4. In the prompt to each subagent, include:
   - The exact question
   - "Report under 300 words; include file:line references for every claim"
   - Any repo-specific context (branch name, recent commits, relevant
     directories the parent knows about)

5. Wait for all subagents to return. Do NOT start editing while they run —
   the whole point is to keep the parent context clean until all findings
   are back.

6. Synthesize the findings into a short plan (3-8 bullet points) and
   present it to the user before editing, unless the task is small enough
   that the path is obvious from the findings.

## Cost discipline

- Every subagent should ask a question the parent doesn't already know
  the answer to. Don't fan out just to fan out.
- If the task is small, skip fanout and edit directly.
- If a subagent comes back with a too-broad answer, re-dispatch it with a
  narrower question rather than reading the overflow into the parent.

## Example

**Input:** `/fanout fix the Swoole pool exhaustion that keeps biting on the cloud API pods`

**Decomposition:**
1. `Explore`: "Find every Swoole pool instantiation in ~/Local/cloud — which entry points (http, cli, worker) create which pools, and in what order relative to enableCoroutine?"
2. `Explore`: "Where is SwoolePool imported and what's the difference between SwoolePool and plain Swoole\\Coroutine\\Channel usage in this repo?"
3. `Explore`: "Which recent commits (last 2 weeks) touched pool initialization? Summarize subjects + file changes."
4. `Explore`: "Are there any shared pool singletons surviving across worker forks? Look for static properties + `Co::set` placement."
5. `general-purpose`: "Check the Swoole 6 docs for `Co::set(['hook_flags' => SWOOLE_HOOK_ALL])` placement requirements — must it precede pool instantiation?"

All five dispatched in one message. Synthesize. Plan. Edit.
