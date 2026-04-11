---
name: utopia-auth-expert
description: Expert reference for utopia-php/auth — dependency-free password hashing, token generation, and authentication proof primitives. Consult for hash migration, MFA code generation, session envelope encoding. Note OAuth2 providers live in appwrite/appwrite, not here.
---

# utopia-php/auth Expert

## Purpose
Dependency-free password hashing, token generation, and authentication proof primitives for Appwrite's user auth stack.

## Public API
- `Utopia\Auth\Hash` (abstract) + hashes: `Argon2`, `Bcrypt`, `Scrypt`, `ScryptModified`, `Sha`, `MD5`, `PHPass`, `Plaintext`
- `Utopia\Auth\Proof` (abstract) + proofs: `Password`, `Token`, `Code`, `Phrase`
- `Utopia\Auth\Store` — encodable key/value bag used for session payloads
- **No OAuth2 module** — OAuth2 providers live in `appwrite/appwrite` itself (`src/Appwrite/Auth/OAuth2`), not in `utopia-php/auth`

## Core patterns
- **Hash strategies swappable on a proof**: `$password->setHash(new Bcrypt())`. Argon2 is the default
- **Proofs unify `generate() → hash() → verify()`**. Migration between algorithms = verify with old hash then re-hash with new
- **Hashes carry tunable cost** via fluent setters (`setMemoryCost`, `setCpuCost`, `setThreads`, `setCost`, `setSalt`, `setLength`)
- **`Store::encode()` / `decode()`** round-trips typed data through a base64 blob — useful for signed session envelopes
- Requires `ext-scrypt` and `ext-sodium`; Appwrite Docker image already ships these

## Gotchas
- `Plaintext` hash exists and is loadable via `addHash()` — **never** register it in prod code paths
- `ScryptModified` is a bespoke Appwrite variant for importing from legacy Firebase/Dropbox-style hashes; do not confuse with standard `Scrypt`
- `Password::verify()` does not upgrade outdated hashes automatically — callers must detect legacy hash type and re-hash post-login
- `Token::hash()` uses SHA-256 and `hash_equals()` internally; do not re-wrap it with `password_verify` or you'll break constant-time comparison

## Appwrite leverage opportunities
- **Hash migration on login**: register every historical hash in the `Password` proof map keyed by the stored `user.hash` column, verify with the old algorithm, then immediately re-hash with `$password->hash()` inside the same request so users transparently rotate from Bcrypt → Argon2id
- **Token vs Code vs Phrase semantics**: recovery/verification/magic-URL tokens should use `Token` (high entropy) and MFA OTP codes should use `Code(6)` — both already hash-at-rest with constant-time verify, so never use `===` on token comparison in new endpoints
- **Session envelopes via `Store`**: instead of JSON+hmac bespoke code in Console sessions, encode Appwrite session state with `Store` then sign via `JWT::encode()` for stateless Functions auth
- When adding a new import source (e.g. Auth0 export), extend `Hash` rather than shimming — keeps the migration table uniform

## Example
```php
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;

$password = new Password([
    'argon2' => (new Argon2())->setMemoryCost(65536)->setTimeCost(4),
    'bcrypt' => (new Bcrypt())->setCost(12),
]);

$hash = $password->hash('s3cret!');          // argon2 default
if ($password->verify('s3cret!', $hash)) {
    // on legacy-algo detect, re-hash to default
    $fresh = $password->hash('s3cret!');
}
```
