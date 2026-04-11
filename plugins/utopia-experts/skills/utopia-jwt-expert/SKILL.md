---
name: utopia-jwt-expert
description: Expert reference for utopia-php/jwt — single-class static JWT encode/decode supporting HS/RS/ES algorithms. Consult for key rotation, Functions runtime tokens, and clock-skew handling. Note main branch is empty; real code lives on feat-encode-decode.
---

# utopia-php/jwt Expert

## Purpose
Single-class, static, dependency-free JWT encode/decode supporting HS/RS/ES algorithms for stateless Appwrite Functions and SDK tokens.

## Public API
- `Utopia\JWT\JWT::encode(array $payload, string $key, string $algorithm, ?string $keyId = null): string`
- `Utopia\JWT\JWT::decode(string $jwt, string $key, string $algorithm, array &$headers = null): array`
- Supported algorithms: `HS256`, `HS384`, `HS512`, `RS256`, `RS384`, `RS512`, `ES256`, `ES256K`, `ES384`
- **Note**: the library lives on branch `feat-encode-decode` — `main` is effectively empty. PSR-4 namespace is `Utopia\JWT\` rooted at `src/Utopia/JWT`

## Core patterns
- **All-static API** — there is no `JWT` instance to construct. Ideal for edge functions
- **Validates `exp`, `nbf`, and `iat`** claims automatically (with current `\time()` — no clock-skew leeway parameter)
- Uses `openssl_sign` / `openssl_verify` for RS*/ES*, `hash_hmac` for HS*; ES256/ES256K/ES384 signatures converted between OpenSSL DER and raw r||s
- URL-safe base64 via internal `safeBase64Encode/Decode` — strips `=` padding
- **Enforces `hash_equals($algorithm, $headers['alg'])`** on decode — prevents `alg: none` and alg-confusion attacks

## Gotchas
- **No leeway parameter** — NTP drift between API nodes can cause spurious `nbf`/`exp` failures. Wrap callers with a small tolerance if needed
- `decode()` passes raw `$key` for HS*, but `openssl_pkey_get_private($key)` for RS* **in the verify path**. Passing a public key for RS* verification silently falls through `openssl_pkey_get_private` — this is a subtle bug pattern worth auditing callers for
- No JWK / kid-based key rotation built in — you must look up the key by `$headers['kid']` outside the library and pass the correct key in
- `encode()` uses `JSON_UNESCAPED_SLASHES` but not `JSON_UNESCAPED_UNICODE` — multibyte payloads are escaped

## Appwrite leverage opportunities
- **Key rotation**: Appwrite session JWTs should set `kid` on encode and maintain a `kid → secret` map in Appwrite's config. On decode, read `$headers['kid']` first, resolve the secret, then call `JWT::decode`. Build a small `JwtKeyring` wrapper rather than sprinkling lookup code in controllers
- **Open source contribution opportunity**: the RS*/ES* verify path using `openssl_pkey_get_private` for verification is semantically wrong — should be `openssl_pkey_get_public`. File upstream PR
- For Functions runtime JWT, prefer HS256 with a per-project signing secret stored in Vault rather than rolling asymmetric keypairs per project
- **Add a bounded leeway wrapper** (e.g. `AppwriteJwt::decode($jwt, $keyring, leeway: 30)`) to absorb replica clock skew before bug reports land

## Example
```php
use Utopia\JWT\JWT;

$secret = getenv('APP_JWT_SECRET') ?: 'dev-secret';

$token = JWT::encode(
    payload: ['userId' => $user->getId(), 'iat' => time(), 'exp' => time() + 900],
    key: $secret,
    algorithm: 'HS256',
    keyId: 'appwrite-session-v1',
);

$headers = [];
$claims = JWT::decode($token, $secret, 'HS256', $headers);
```
