---
name: utopia-validators-expert
description: Expert reference for utopia-php/validators — the dependency-free validator primitives used across Utopia framework routes and database attributes. Consult when composing validators, debugging the `Text` constructor arg order, or wiring SDK type mapping.
---

# utopia-php/validators Expert

## Purpose
Dependency-free input validation primitives with a uniform `isValid/getDescription/getType/isArray` contract used across Utopia framework routes and database attribute validation.

## Public API (categories)
- **Primitives**: `Boolean`, `Integer`, `FloatValidator`, `Numeric`, `Text`, `Range`
- **Strings/format**: `HexColor`, `JSON`, `URL`
- **Network**: `Domain`, `Host`, `Hostname`, `IP`
- **Collections**: `ArrayList`, `Assoc`, `WhiteList`, `Wildcard`
- **Composition**: `AllOf`, `AnyOf`, `NoneOf`, `Multiple`, `Nullable`
- Base class: `Utopia\Validator` (namespace `Utopia\` rooted at `src/`)

## Core patterns
- Every validator implements `isValid(mixed): bool`, `getDescription(): string`, `getType(): string` (one of `Validator::TYPE_*` — note `TYPE_FLOAT = 'double'` to match `gettype()`), `isArray(): bool`
- **Composition validators wrap others**: `new Multiple([new Text(20), new WhiteList(['a','b'])])` — both must pass. `AnyOf` requires one, `NoneOf` requires zero, `AllOf` requires all
- **`Nullable`** wraps any validator to allow `null` to pass unconditionally — avoids scattered `$value !== null && $validator->isValid($value)` checks
- Uses `gettype()`'s legacy name `'double'` for float — historical quirk preserved so dynamic validation against `gettype($var)` works
- **PSR-4 oddity**: `Utopia\Validator\Text` lives at `src/Validator/Text.php`, but the base `Validator` class is `Utopia\Validator` at `src/Validator.php`

## Gotchas
- `Text`'s constructor is `new Text(int $length, int $min = 1, array $allowList = [])` — **length is max, second arg is min** (named args strongly recommended)
- `WhiteList` is case-sensitive by default; pass `strict: true` to also compare types
- `Domain` vs `Hostname` vs `Host`: `Domain` validates TLD-bearing names, `Hostname` allows single labels (localhost), `Host` accepts both plus IPs
- `JSON` validator only checks syntax, not schema — don't use it for API payload validation
- `ArrayList` validates each element with a sub-validator but calls `isArray() = true` — callers must check this to decide whether to run element-wise

## Appwrite leverage opportunities
- **Compose per-attribute validators via `Multiple`** instead of custom one-off classes: e.g. a project slug is `new Multiple([new Text(36), new WhiteList([...])])`. Keeps `Appwrite\Utopia\Database\Validator\*` thin
- **Use `Nullable`** to collapse "optional field" branches in request validation — cleaner than `if ($value === null) continue;`
- **`Wildcard` validator** is effectively `true` for any input — use it as a placeholder in conditional route params rather than bypassing validation (keeps descriptions consistent for SDK generation)
- **SDK generation drives off `getType()`** — when adding a new validator to Appwrite, implement `getType()` correctly or the generated SDKs will break on that field
- Validator composability is under-used: define a reusable `Email` as `new Multiple([new Text(254), new /* regex */])` and export from a domain package rather than copy-pasting regexes

## Example
```php
use Utopia\Validator\{Text, Range, WhiteList, Multiple, Nullable, ArrayList};

$usernameValidator = new Multiple([
    new Text(length: 36, min: 3),
    new WhiteList(list: ['admin', 'root'], strict: true),
], Multiple::TYPE_STRING);

$ageValidator = new Nullable(new Range(min: 13, max: 120));
$tagsValidator = new ArrayList(new Text(32), max: 10);

if (! $usernameValidator->isValid($input['username'] ?? null)) {
    throw new Exception($usernameValidator->getDescription());
}
```
