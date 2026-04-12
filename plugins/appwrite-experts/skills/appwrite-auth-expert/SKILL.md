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

Provider config stored on project document. OAuth adapter classes in `vendor/utopia-php/auth/src/Auth/OAuth2/`.

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

## Related skills

- `appwrite-teams-expert` — team membership and role-based auth
- `appwrite-realtime-expert` — how auth events propagate via channels
- `appwrite-workers-expert` — how session events trigger audit/webhook workers
