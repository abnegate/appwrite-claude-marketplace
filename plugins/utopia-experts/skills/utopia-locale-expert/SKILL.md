---
name: utopia-locale-expert
description: Expert reference for utopia-php/locale — dependency-free i18n K/V translation library with placeholder interpolation and single-step fallback. Consult when wiring Console translations, debugging static-state leaks in Swoole, or adding plural support.
---

# utopia-php/locale Expert

## Purpose
Dependency-free i18n key-value translation library with placeholder interpolation and language fallback.

## Public API
- `Utopia\Locale\Locale` — a single class
- Static registration: `Locale::setLanguageFromArray(string $code, array $translations)`, `Locale::setLanguageFromJSON(string $code, string $path)`, `Locale::getLanguages()`
- Instance: `new Locale(string $default)`, `getText(string $key, array $placeholders = [])`, `setDefault(string $code)`
- Public `$fallback` property, `$exceptions` static toggle

## Core patterns
- **Translations are a static class-level map** (`self::$language`) shared across all instances — register once at bootstrap, instantiate per-request cheaply
- **Placeholder syntax is `{{likesAmount}}`**; unprovided placeholders leave the literal token in the string (no error, no blank)
- **Fallback chain is two-deep only**: `default` → `fallback` (a single string). No multi-step chain like `en-GB → en → root`
- **`$exceptions` static flag** flips between throwing and silently returning on missing keys/files — lets you enable strict mode in dev, lenient in prod
- **`DEFAULT_DYNAMIC_KEY = '[[defaultDynamicKey]]'`** is a sentinel replaced with the `{{key}}` wrapped value at runtime — enables indirection

## Gotchas
- **Static state is process-wide** — in Swoole, `setLanguageFromArray()` at request time leaks into other workers' memory. Register in worker startup, not request handlers
- **No plural/gender rules** (no ICU MessageFormat). Russian/Arabic pluralization must be handled by naming keys `key.one`, `key.few`, `key.many` and selecting in app code
- **Fallback is a single locale string**, not an ordered list — can't express `fr-CA → fr-FR → en-US`
- **`setLanguageFromJSON` reads synchronously and parses on every call**; no caching/memoization. Bootstrap-time loading only or you'll hit disk per request

## Appwrite leverage opportunities
- **Appwrite Console already uses this** for all 40+ console languages. The 2-deep fallback chain is the reason untranslated UI strings show English (fallback) rather than keys — worth documenting so translators know the contract
- **Translation memory**: pair with `utopia-php/cache` by hashing `(locale, key, placeholders)` — avoid reparsing JSON on hot error paths (e.g., webhook validation messages)
- **Message catalogs for user-facing emails**: the `DEFAULT_DYNAMIC_KEY` indirection lets you reference `brand.name` and `support.email` from every email template without string concatenation — single source of truth for white-label Cloud tenants
- **Upstream contribution**: add an ICU-lite plural selector (`getText('items', ['count' => 5])` → reads `items.plural` closure map). Would unblock proper RTL/plural support in Console without pulling `symfony/translation` (200+ deps)

## Example
```php
use Utopia\Locale\Locale;

Locale::$exceptions = false;
Locale::setLanguageFromJSON('en-US', __DIR__ . '/locale/en-US.json');
Locale::setLanguageFromJSON('fr-FR', __DIR__ . '/locale/fr-FR.json');
Locale::setLanguageFromJSON('fr-CA', __DIR__ . '/locale/fr-CA.json');

$locale = new Locale('fr-CA');
$locale->fallback = 'en-US'; // fr-CA → en-US only (no fr-FR intermediate)

echo $locale->getText('dashboard.welcome', [
    'name' => $user->getName(),
    'count' => 12,
]);
// "Bonjour Jane, vous avez 12 notifications"
```
