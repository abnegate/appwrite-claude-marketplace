---
name: utopia-waf-expert
description: Expert reference for utopia-php/waf — dependency-free request rule engine with Condition DSL and Deny/Bypass/Challenge/RateLimit/Redirect actions. Consult when composing dynamic firewall rules from config or pairing with abuse for enforcement.
---

# utopia-php/waf Expert

## Purpose
Dependency-free request rule engine that evaluates `Condition`s against request attributes and emits an action (deny/bypass/challenge/ratelimit/redirect).

## Public API
- `Utopia\WAF\Firewall` — orchestrator with `setAttribute`, `addRule`, `verify`, `getLastMatchedRule`
- `Utopia\WAF\Condition` — fluent builder mirroring `Utopia\Database\Query`: `equal`, `notEqual`, `lessThan`, `greaterThan`, `contains`, `between`, `startsWith`, `endsWith`, `isNull`, `and`, `or` + `encode()/decode()`
- `Utopia\WAF\Rule` (abstract) + concretions: `Bypass`, `Deny`, `Challenge`, `RateLimit`, `Redirect`
- Action constants: `Rule::ACTION_BYPASS|DENY|CHALLENGE|RATE_LIMIT|REDIRECT`
- Requires PHP 8.2+

## Core patterns
- **Rules evaluated top-to-bottom; first match wins** — `verify()` stops iterating and calls `applyRule()`
- **`verify()` returns `true` if the request should be allowed** (bypass or matched RateLimit), `false` on deny/challenge/redirect. **Non-match returns `false` (fail closed)**
- **`Condition::encode()/decode()` produces JSON** suitable for storing rules in a database — enables user-editable WAF rules without PHP codegen
- **`Challenge`** carries a type (`TYPE_CAPTCHA`, etc.); **`RateLimit`** carries `limit`/`interval` metadata **without** enforcing — enforcement is delegated to `utopia-php/abuse` or an external layer
- **`Redirect`** carries `location` and `statusCode` — the caller issues the HTTP response

## Gotchas
- This is a **pure rule engine** — it does not block requests or write rate-limit state. You must hook `verify()` output into your HTTP layer and, for `RateLimit` matches, pipe the matched rule's `getLimit()`/`getInterval()` into `utopia-php/abuse`
- **Fail-closed** (`verify() === false` when no rule matches) is the opposite of typical middleware — make sure your default rule is an explicit `Bypass` with an always-true condition, or invert the check at the call site
- Attribute aliasing is internal (e.g. `requestIP`/`ip`, `requestPath`/`path`) — don't assume the attribute name you set is the only key matched
- `RateLimit` throws if `limit < 1` or `interval < 1` — guard against config-driven zeros

## Appwrite leverage opportunities
- **Compose WAF rules dynamically from project settings**: deserialize via `Condition::decode()` and push into `Firewall::addRule()` per-request. Unlocks self-serve WAF for Cloud customers without PHP redeploy
- **Bridge `RateLimit` matches into `utopia-php/abuse`**: when `getLastMatchedRule() instanceof RateLimit`, build a `TimeLimit\Redis` adapter with `limit = $matched->getLimit()`, `seconds = $matched->getInterval()`. Single composed middleware covers both libraries
- **Deny rules at the top of the chain** for known-bad IPs/UAs cached in Redis; **Bypass rules** for health checks just above that — `/v1/health` never consults the DB
- **Audit trail**: use `Condition::encode()` to log the exact rule that blocked each request — invaluable for customer support ("why did my request fail")
- **Functions runtime**: evaluate WAF rules in the proxy layer (Traefik plugin or dedicated PHP service) rather than inside each Function container — decouples enforcement from user code

## Example
```php
use Utopia\WAF\Firewall;
use Utopia\WAF\Condition;
use Utopia\WAF\Rules\{Deny, RateLimit, Bypass};

$firewall = (new Firewall())
    ->setAttribute('ip', $request->getIP())
    ->setAttribute('path', $request->getURI())
    ->setAttribute('method', $request->getMethod());

$firewall->addRule(new Deny([Condition::equal('ip', $blocklist)]));
$firewall->addRule(new Bypass([Condition::equal('path', ['/v1/health'])]));
$firewall->addRule(new RateLimit(
    [Condition::startsWith('path', '/v1/account')],
    limit: 60, interval: 60,
));

if (! $firewall->verify()) {
    $rule = $firewall->getLastMatchedRule();
    throw new Exception('Blocked by WAF: ' . ($rule?->getAction() ?? 'no-match'), 403);
}
```
