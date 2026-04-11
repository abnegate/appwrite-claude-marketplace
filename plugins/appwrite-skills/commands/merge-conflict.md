---
description: Resolve git merge conflicts with intent-aware subagent analysis
argument-hint: "[base-branch]"
---

# /merge-conflict — Intent-Aware Conflict Resolver

Resolve merge conflicts by analyzing the **intent** of each side rather
than diffing lines in isolation. Optimized for the cross-branch merge
pattern that dominates Appwrite multi-feature branches (`feat-documentsdb`
into `feat-vectordb`, `origin/main` into `feat-dedicated-db`, etc).

`$ARGUMENTS` is the name of the branch being merged in. Defaults to
`origin/main` if empty.

## Execution

1. **Confirm a merge is in progress.**
   ```bash
   git status
   git ls-files -u | head
   ```
   If not, run:
   ```bash
   git merge $ARGUMENTS
   ```
   and let git surface the conflicts naturally.

2. **Enumerate conflicted files.**
   ```bash
   git diff --name-only --diff-filter=U
   ```

3. **Gather intent context in parallel.** For each conflicted file,
   dispatch a subagent with:
   - The file path
   - `git log --oneline -10 HEAD -- <file>` (ours history)
   - `git log --oneline -10 MERGE_HEAD -- <file>` (theirs history)
   - `git show :1:<file>` (common ancestor)
   - `git show :2:<file>` (ours)
   - `git show :3:<file>` (theirs)
   - The conflicted working tree file with markers

   All subagents in a single message. Each returns a 150-word summary:
   - What was "ours" trying to accomplish?
   - What was "theirs" trying to accomplish?
   - Are the intents orthogonal (keep both), overlapping (merge
     semantically), or contradictory (requires human decision)?
   - Recommended resolution with exact final file contents.

4. **Classify each file:**
   - **Orthogonal** — intents don't touch the same concern. Auto-merge
     by concatenating / interleaving the changes. Apply directly.
   - **Overlapping** — intents touch the same concern but compose.
     Produce a combined version that preserves both goals. Apply and
     flag for user review.
   - **Contradictory** — intents conflict semantically (e.g. one side
     renames a method, the other changes its signature). **Stop and
     ask the user** with both sides' summaries side by side.

5. **Apply resolutions.** For orthogonal + overlapping files:
   - Write the resolved file
   - `git add <file>`

6. **Leave contradictory files for the user.** Print a numbered list:
   ```
   Needs human decision:
   1. src/Foo.php — ours renames bar() to baz(), theirs changes bar() signature
   2. src/Bar.kt  — ours drops the deprecated field, theirs adds validation to it
   ```
   Do NOT `git add` these.

7. **Summary output.**
   ```
   ## Conflict resolution — merging origin/main into feat-dedicated-db
   
   Auto-resolved (7 files):
     src/Cache/Swoole.php     — orthogonal: import added + method renamed
     src/HTTP/Router.php      — overlapping: both added middleware, merged
     ... (5 more)
   
   Needs review (2 files):
     src/Appwrite/Database.php — see above
     src/Utopia/Queue.php     — see above
   
   To finalize: resolve the 2 files above, `git add`, then `git commit`.
   ```

## Safety rules

- NEVER auto-resolve a contradictory file. The whole point of this skill
  is to catch those before they silently corrupt a branch.
- NEVER `git commit` the merge. That's the user's call after review.
- NEVER delete a file if only one side deleted it — flag as
  contradictory ("ours deleted, theirs modified" or vice versa).
- Preserve conflict markers in any file that is NOT fully resolved. No
  half-resolutions.
- If the merge touches a generated file (SDK output, lock files),
  prefer "theirs" from whichever branch regenerated it most recently
  and suggest re-running the generator after the merge lands.
