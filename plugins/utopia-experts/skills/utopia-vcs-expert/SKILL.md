---
name: utopia-vcs-expert
description: Expert reference for utopia-php/vcs — webhook-driven Git provider abstraction with GitHub/GitLab/Gitea/Gogs/Forgejo adapters. Consult when wiring Appwrite Functions VCS integration, planning Bitbucket support, or fixing installation token caching.
---

# utopia-php/vcs Expert

## Purpose
Webhook-driven Git provider abstraction for creating repositories, branches, PRs, webhooks, tags, and reading commit statuses across multiple Git hosts.

## Public API
- `Utopia\VCS\Adapter` — root abstract
- `Utopia\VCS\Adapter\Git` — Git-specific abstract
- **5 Git adapters**: `Adapter\Git\GitHub`, `GitLab`, `Gitea`, `Gogs`, `Forgejo` (the README's "only GitHub" table is stale — the source tree ships all 5)
- Key methods: `initializeVariables()`, `createRepository()`, `createBranch()`, `createFile()`, `createPullRequest()`, `createWebhook()`, `createTag()`, `getCommitStatus()`, plus repo/tree/content reads

## Core patterns
- **GitHub App auth**: `initializeVariables($installationId, $privateKey, $githubAppId)` generates a short-lived JWT via `adhocore/jwt`, exchanges it for an installation access token, caches it in `utopia-php/cache` until expiry
- **Uses `utopia-php/fetch`** (not Guzzle) for HTTP — Swoole-compatible, no cURL blocking in coroutines
- **All mutation methods return `array<mixed>`** from the provider API; webhook creation uniquely returns `int` (webhook ID) for subsequent deletion
- **No Adapter/Type split beyond `TYPE_GIT`** — implies future Mercurial/SVN adapters were planned but never built
- Forgejo/Gitea/Gogs share near-identical REST shapes, so they likely inherit from a common Gitea-family implementation

## Gotchas
- **Installation tokens expire after 1 hour** — cache TTL must be shorter than token lifetime to avoid 401s mid-request. Cache key should include installation ID
- **GitHub webhook signature verification (`X-Hub-Signature-256`, HMAC-SHA256) is NOT provided** by the library — you verify in your Appwrite route before handing to VCS handlers
- `GITHUB_APP_ID` and `installationId` are strings in the API but numeric in GitHub's UI — passing integers may break JWT claims (`iss` must be string)
- **No rate-limit handling** — GitHub App installations have 5,000 req/hr shared; bulk operations (import 100 repos) will 403 without backoff

## Appwrite leverage opportunities
- **Appwrite Functions VCS integration**: use `createWebhook()` on deploy-key setup, verify `X-Hub-Signature-256` in route, then enqueue build jobs via utopia-php/queue. Persist `installationId` per project in Appwrite DB
- **Add a `Bitbucket` adapter** — real Cloud customers on Bitbucket Server/Cloud are blocked from using VCS Functions. Follow Gitea's pattern (OAuth app) since Bitbucket uses basic or OAuth2, not GitHub App JWTs
- **Token refresh middleware** that treats 401 as "retry with fresh installation token" rather than bubbling — handles cache race conditions and GitHub App key rotation
- **Rate-limit adapter**: wrap `Git` in a decorator that reads `X-RateLimit-Remaining` and pauses via `System::sleep()` when < 100 remain. Essential for Cloud's multi-tenant bulk operations

## Example
```php
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\VCS\Adapter\Git\GitHub;

$cache = new Cache(new RedisCache($redis));
$github = new GitHub($cache);
$github->initializeVariables(
    installationId: '12345678',
    privateKey: file_get_contents('/secrets/appwrite-app.pem'),
    githubAppId: '987654',
);

$pr = $github->createPullRequest(
    owner: 'appwrite',
    repositoryName: 'appwrite',
    title: 'fix: patch SSRF',
    head: 'hotfix/ssrf',
    base: '1.9.x',
    body: 'See SEC-1234',
);
```
