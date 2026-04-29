---
name: appwrite-auth-expert
description: Authentication, sessions, MFA, OAuth, tokens, and user admin in the Appwrite backend. Covers account.php (41 routes) and users.php (44 routes).
---

# Appwrite Auth Expert

## Route files

- `app/controllers/api/account.php` — end-user auth (sessions, MFA, OAuth, recovery, verification)
- `app/controllers/api/users.php` — admin user management (CRUD, targets, identities, quotas)

## Auth types

Defined in `src/Appwrite/SDK/AuthType.php`:

| AuthType | Label | When to use |
|---|---|---|
| `AuthType::SESSION` | Cookie-based session | Browser clients, Console |
| `AuthType::KEY` | API key header (`X-Appwrite-Key`) | Server-side SDKs |
| `AuthType::JWT` | Bearer token | Mobile/SPA after session exchange |
| `AuthType::ADMIN` | Admin scope | Console management endpoints |

Routes declare allowed auth via the SDK label:
```php
->label('sdk', new Method(
    auth: [AuthType::SESSION, AuthType::JWT],
    // ...
))
```

## Session lifecycle

1. **Create** — `POST /v1/account/sessions/email` (or `/anonymous`, `/token`, OAuth callback)
2. Session document stored in `sessions` collection with hashed secret
3. Cookie set on response: `a_session_{projectId}` (HTTP-only, domain-aware)
4. **Validate** — middleware resolves session from cookie or `X-Appwrite-Session` header
5. **Refresh** — OAuth sessions auto-refresh via provider's `refreshTokens()`
6. **Delete** — `DELETE /v1/account/sessions/{sessionId}` or `DELETE /v1/account/sessions` (all)

Cookie domain logic: `$domainVerification` flag controls whether cookies are scoped to the verified domain or use fallback cookies via `X-Fallback-Cookies` header.

## OAuth flow

1. `GET /v1/account/sessions/oauth2/{provider}` — redirects to provider
2. Provider callback hits `GET /v1/account/sessions/oauth2/callback/{provider}/{projectId}`
3. Creates/updates identity in `identities` collection
4. Links to user, creates session
5. Redirects to `success` URL with session secret

Provider adapters live **in `appwrite/appwrite` itself** under `src/Appwrite/Auth/OAuth2/` (not `utopia-php/auth`, which only ships the proof primitives). Recently added adapters: `Kick`, `FusionAuth`, `Keycloak`, plus the existing big set (Google, GitHub, Microsoft, Apple, Discord, Spotify, Slack, Authentik, Okta, OIDC, etc.).

### Public OAuth2 management endpoints

OAuth provider configuration is no longer console-only. The public API exposes read endpoints for project/console provider catalogues plus update endpoints:

- `GET /v1/projects/:projectId/oauth2/providers` — list configured providers (project-scoped)
- `GET /v1/console/oauth2/providers` — list providers the server build supports, with a `parameters` schema (each `ConsoleOAuth2ProviderParameter` describes the body field used by the project-scoped update endpoint, e.g. `clientId`, `appKey`, `tenant`)
- Project update endpoints accept the parameter set the catalogue exposes and store secrets write-only on the project document

Use `MODEL_OAUTH2_PROVIDER_LIST` / `MODEL_CONSOLE_OAUTH2_PROVIDER_LIST` / `MODEL_CONSOLE_OAUTH2_PROVIDER_PARAMETER` for response shaping.

## API key types

`app/init/constants.php` defines four:

| Constant | Use |
|---|---|
| `API_KEY_STANDARD` (`'standard'`) | Long-lived stored key, `X-Appwrite-Key` header |
| `API_KEY_EPHEMERAL` (`'ephemeral'`) | JWT-encoded short-lived key (1–3600 s, default 900 s); intended for per-execution Function calls and fine-scoped agent flows. Created via `POST /v1/project/keys/ephemeral` (alias `/v1/projects/:projectId/jwts`); request body is `scopes: string[]` + `duration: int`; response model `MODEL_EPHEMERAL_KEY` |
| `API_KEY_ORGANIZATION` (`'organization'`) | Cross-project org-scoped key |
| `API_KEY_ACCOUNT` (`'account'`) | Account-bound key |

Header form for ephemeral keys: `x-appwrite-key: ephemeral_<jwt>`. The wrapper in `app/controllers/general.php` decodes the JWT, resolves a `Key` DTO, and runs the same scope-gating path as standard keys. Expiration is enforced; expired ephemeral keys throw a "Please don't use ephemeral API keys for more than duration of the execution" error.

`Appwrite\Auth\Key` is the unified runtime DTO across all four types (`getType()` distinguishes them).

## User impersonation

Admin-capability users (those with the impersonator label) can act as another user on an already-authenticated request:

| Header | Query param fallback | Notes |
|---|---|---|
| `X-Appwrite-Impersonate-User-Id` | `?impersonateUserId=` | The only ID form **also accepted as query param**; values are cast to string to prevent array injection |
| `X-Appwrite-Impersonate-User-Email` | header-only | Cross-site CSRF risk on cookies → no query fallback |
| `X-Appwrite-Impersonate-User-Phone` | header-only | Same reason |

CSRF guard is fail-closed: requests with `Sec-Fetch-Site` not equal to `same-origin` are rejected when the query-param fallback is used, regardless of impersonator capability. `X-Appwrite-Key` alone is **not** sufficient — impersonation requires an authenticated user with the impersonator capability so audit logs always have a real actor.

Audit logs always attribute the action to the original impersonator user. The impersonated target is recorded only inside the audit payload (`impersonatedUserId`/`impersonatedUserName`/`impersonatedUserEmail`); the top-level `userId` on the log is the impersonator. The `Account`/`User` response models gain `impersonatorUserId` (present only during impersonation) and a `canImpersonate` flag.

The realtime connection initializer (`app/init/realtime/connection.php`) reads the same headers + `impersonateUserId` / `impersonateEmail` / `impersonatePhone` query params, so subscriptions reflect the impersonated user.

## MFA

- `POST /v1/account/mfa/{type}` — enable TOTP/phone/email/recovery
- Challenge-response flow: create challenge → verify with OTP
- Recovery codes generated on TOTP enrollment, stored hashed
- MFA status on user document: `mfa` boolean + `factors` array

## Token model

`POST /v1/account/tokens/email` / `tokens/phone` / `tokens/magic-url`:
- Creates one-time token with configurable expiry
- Token secret hashed before storage, returned once in response
- Verified via `PUT /v1/account/sessions/token` — exchanges token for session

## Password hashing

Configurable per-project via `auth.passwordHashOptions`:
- Algorithms: argon2, bcrypt, scrypt, scryptmod, sha, md5, phpass
- `ProofsPassword` class handles hash+verify with algorithm detection
- Rehashing on login if algorithm changed since last hash

## User admin (users.php)

Admin-only endpoints for server-side user management:
- CRUD users with password/hash import
- List with queries (status, email, phone, labels)
- Manage targets (push tokens, email addresses, phone numbers)
- Manage identities (OAuth provider links)
- Quota enforcement via project settings

## Authorization model

```php
// Skip auth for specific operations (admin context)
$user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

// Add role for request scope
$authorization->addRole(Role::user($user->getId())->toString());
```

Roles checked against document `$permissions` array. Permission types: `read`, `update`, `delete`, `create`.

## Key injected dependencies

| Name | Type | Purpose |
|---|---|---|
| `store` | `Utopia\Auth\Store` | Session encode/decode |
| `proofForPassword` | `ProofsPassword` | Password hash/verify |
| `proofForToken` | `ProofsToken` | Token hash/verify |
| `domainVerification` | `bool` | Cookie domain scoping |
| `cookieDomain` | `?string` | Cookie domain value |

## Gotchas

- `getenv()` never returns null in PHP — use `?:` not `??` for env fallbacks
- Session secrets are hashed before storage; the raw secret is only in the creation response
- OAuth token refresh happens transparently on session validation, not on a dedicated endpoint
- `X-Appwrite-Response-Format` header triggers request/response filters (V16-V21) that transform parameters for backwards compatibility
- The `abuse-key` label on auth routes uses `email:{param-email}` — rate limiting is per-email, not per-IP
- Anonymous sessions create a real user document; converting to email/OAuth merges, not replaces
- **Project JWT migrated to ephemeral key** — the legacy project JWT path was rebuilt on top of `API_KEY_EPHEMERAL` for backwards compatibility; old code that special-cases `'jwt'` keys should now look at `API_KEY_EPHEMERAL` (or `'dynamic'`, kept for transitional code)
- **Ephemeral key duration is bounded at 3600 s** — anything longer should be a standard key. The error string `'The ephemeral API key has expired'` is what callers see when reuse exceeds the issued window
- **Impersonation query param is ID-only** — extending the fallback to email/phone re-opened a CSRF surface that the codebase intentionally leaves header-only; do not relax this without a Sec-Fetch-Site review

## Related skills

- `appwrite-teams-expert` — team membership and role-based auth
- `appwrite-realtime-expert` — how auth events propagate via channels
- `appwrite-workers-expert` — how session events trigger audit/webhook workers
