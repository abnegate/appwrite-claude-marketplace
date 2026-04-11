---
name: utopia-ab-expert
description: Expert reference for utopia-php/ab — simple server-side A/B test library with weighted variation selection. Consult when building Console/Cloud experiments and be aware it has no sticky assignment or coroutine safety out of the box.
---

# utopia-php/ab Expert

## Purpose
Simple server-side A/B test library with weighted variation selection and callable values.

## Public API
- `Utopia\AB\Test` — single class
- `Test::__construct(string $name)`
- `Test::variation(string $name, mixed $value, ?int $probability = null): self`
- `Test::run(): mixed`
- `Test::results(): array` — static accumulator
- Protected `chance(): string`

## Core patterns
- **Fluent builder**: `->variation()->variation()->run()`
- **Weighted random** — probabilities must sum to <=100; unassigned slots auto-filled from remainder split equally
- **Callable variations** — closures only executed when `run()` picks them (lazy evaluation)
- **Static `$results` map** keyed by test name — last-run result per test is globally readable
- **Bucketing uses `rand(0, sum*10)`** — no seeded PRNG, no sticky assignment

## Gotchas
- **No persistence/stickiness**: every `run()` re-rolls. Users get inconsistent variants across requests unless you cache the result in session/cookie yourself
- **Static `$results` leaks across requests** in long-lived Swoole workers — a shared coroutine could read another request's variant
- **Uses `\rand()`** (not `random_int`) — not cryptographically suitable and coroutine-safe only accidentally
- Throws if probabilities sum >100; silently redistributes if <100

## Appwrite leverage opportunities
- **Sticky bucketing for Console/Cloud experiments**: wrap `Test` with a user-id-hashed selector (MurmurHash mod 100) instead of `rand()`, persist variant on user/team attribute — current impl would flap on every page load
- **Exposure logging via audit library**: hook `run()` through a decorator that emits an exposure event to `utopia-php/audit` so funnel analytics have variant attribution
- **Worker safety**: clear `Test::$results` between requests in Swoole workers (in `Http::onRequest`) — static state is a footgun under coroutines
- **Feature-flag convergence**: pair with `utopia-php/registry` so experiment factories are lazy and memoised per-request context rather than rebuilt each hit

## Example
```php
use Utopia\AB\Test;

$test = new Test('checkout-cta');
$test
    ->variation('control', 'Buy now', 50)
    ->variation('urgency', 'Buy now — 3 left!', 50);

$copy = $test->run();
// Persist yourself for stickiness:
$response->addCookie('exp_checkout', $copy, time() + 86400);
```
