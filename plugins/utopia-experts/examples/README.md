# utopia-experts examples

Sample router dispatches with the shape of the expected output.
These illustrate what the router picks for different question
types, and how the distilled answer composes when a question spans
multiple libraries.

## Single-library question → 1 skill

**User:**
> How do I page query results with a stable cursor?

**Router behaviour:**
1. Reads `skills/INDEX.md`
2. Matches "query results, cursor, paginate" against the `data`
   category descriptions
3. Picks `utopia-query-expert` (single hit — the question is
   about the query DSL, not the database adapter or cursor storage)
4. Reads `skills/utopia-query-expert/SKILL.md` in full
5. Returns:

```
## From utopia-query-expert

**Short answer:** Use Query::cursorAfter($lastId) — it's exclusive
and stable across inserts, unlike offset-based pagination.

**Details:**
- Every Query constructor returns a fresh immutable-ish object;
  build queries as arrays, don't mutate.
- cursorAfter and cursorBefore are mutually exclusive; passing
  both silently keeps whichever comes last.
- Query::groupByType() splits queries into buckets (filters,
  selections, cursor, orderAttributes) — the cursor bucket is
  what adapters use.
- equal() always takes an array of values, even for a single match.

**Citations:** utopia-query-expert#Core-patterns,
utopia-query-expert#Gotchas

**If you need more:** load plugins/utopia-experts/skills/utopia-query-expert/SKILL.md
for the full API surface and Appwrite leverage opportunities.
```

## Two-library question → 2 skills

**User:**
> I'm seeing pool exhaustion on our database adapter under load —
> is the pool sized wrong or is there a leak?

**Router behaviour:**
1. Matches "pool exhaustion, database adapter, sized" against the
   index
2. Picks `utopia-pools-expert` (primary — pool sizing + leak
   detection) and `utopia-database-expert` (secondary — the
   database layer's pool usage)
3. Reads both in its context
4. Returns a combined answer:

```
## From utopia-pools-expert + utopia-database-expert

**Short answer:** Most likely a put-back-or-leak — every pop must
have a matching push in a finally block. If the pool size is wrong,
the waitDuration histogram will tell you which direction.

**Pool hygiene (utopia-pools-expert):**
- Group::use(['db'], fn($pdo) => ...) is the idiomatic entry point:
  guarantees push in finally even on exception.
- Never call Pool::pop directly in request handlers — an exception
  that escapes leaks one connection per crash.
- Rule of thumb: size = max_concurrent_requests / worker_num.
  Under-sizing causes retryAttempts exhaustion → Connection timeout.
  Over-sizing starves the DB server.
- Expose waitDuration histogram via /v1/health/db. P99 < 5ms means
  size is right; > 50ms means double it.

**Database layer usage (utopia-database-expert):**
- Database adapter holds a single connection; use Adapter\Pool to
  wrap it in a Pool<PDO> with size = worker_num * max_coroutine.
- Under Swoole coroutines, shared static adapter serializes queries
  — check that Mongo adapter is wrapped too if applicable.

**Citations:**
  utopia-pools-expert#Core-patterns, utopia-pools-expert#Gotchas,
  utopia-database-expert#Appwrite-leverage-opportunities

**If you need more:** load the full utopia-pools-expert skill for
the complete Pool/Group/Connection API including reclaim semantics.
```

## Three-library question matching a composition pairing → 3 skills

**User:**
> Our worker is losing exceptions inside go() blocks and the audit
> rows are missing the trace_id.

**Router behaviour:**
1. Matches "exceptions, go(), audit rows, trace_id" against the
   index
2. Recognises this as the **Observability pipeline** composition
   (listed in INDEX.md's composition notes)
3. Picks `utopia-span-expert` + `utopia-audit-expert` +
   `swoole-expert` (since the go() lifetime question is Swoole-
   specific — router redirects to swoole-expert in conventions)
4. Returns:

```
## From swoole-expert + utopia-span-expert + utopia-audit-expert

**Short answer:** Two bugs, related but separate. Wrap every go()
body in try/catch (go doesn't propagate exceptions to the parent),
and stamp span.trace_id into the audit data map at log time.

**Swoole coroutine exception loss (swoole-expert):**
- Uncaught exceptions inside go() are silently dropped. Every
  go {} should have try/catch that logs or reports.
- defer() runs in LIFO on coroutine exit — use defer to guarantee
  cleanup even when the go body throws.

**Trace correlation (utopia-span-expert):**
- Span::init('http.request', $request->getHeader('traceparent')) at
  every request boundary stamps span.trace_id on the current span.
- Span::traceparent() outbound propagates it to downstream services.

**Audit stamping (utopia-audit-expert):**
- The audit Log's data column is free-form. Stamp
  Span::current()?->get('span.trace_id') into every log() call and
  the audit row links back to the request that produced it.

**Citations:**
  swoole-expert#Coroutines, utopia-span-expert#Appwrite-leverage,
  utopia-audit-expert#Appwrite-leverage

**If you need more:** utopia-span-expert has the full Observability
pipeline composition section — loading it in full gives you the
wiring for logger + telemetry + analytics too.
```

## Question that doesn't match any expert

**User:**
> How do Appwrite functions get their environment variables?

**Router behaviour:**
1. No direct expert match — this is about `appwrite/appwrite`
   application code, not a `utopia-php` library
2. Returns:

```
No utopia-php expert skill covers this. The question may be about
application-level code in Appwrite itself (appwrite/appwrite,
appwrite/cloud) rather than a utopia-php library.

If you're looking for config loading, see utopia-config-expert.
If you're looking for env-var parsing at the framework level, see
utopia-http-expert#Core-patterns.
```

## Notes

- Every router response stays under 400 words — if it starts to
  sprawl, the router is doing synthesis work the parent should
  do itself
- Citations always include a specific skill#section when possible,
  not just the skill name
- The router never answers from training; if the skill doesn't
  cover something, it says so explicitly
- When the index's Composition notes match, the router picks the
  whole pairing, not just one skill
