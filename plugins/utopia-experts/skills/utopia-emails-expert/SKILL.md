---
name: utopia-emails-expert
description: Expert reference for utopia-php/emails — email parser/classifier with provider-aware canonicalization, free/disposable/corporate detection. Consult for signup gating, account dedupe, and validator composition.
---

# utopia-php/emails Expert

## Purpose
Parse, validate, and classify email addresses (free vs disposable vs corporate) with provider-aware canonicalization.

## Public API
- `Utopia\Emails\Email` — parser/classifier: `get()`, `getLocal()`, `getDomain()`, `getProvider()`, `getSubdomain()`, `isValid()`, `isDisposable()`, `isFree()`, `isCorporate()`, `getFormatted(FORMAT_*)`
- `Utopia\Emails\Validator\Email` — basic validator
- `Utopia\Emails\Validator\EmailDomain` — domain-only validator
- `Utopia\Emails\Validator\EmailLocal` — local-part validator
- `Utopia\Emails\Validator\EmailNotDisposable` — disposable-domain rejection
- `Utopia\Emails\Validator\EmailCorporate` — corporate-only validator
- `Utopia\Emails\Canonicals\Provider` + providers: `Gmail`, `Outlook`, `Yahoo`, `Icloud`, `Fastmail`, `Protonmail`, `Walla`, `Generic`
- Constants: `FORMAT_FULL`, `FORMAT_LOCAL`, `FORMAT_DOMAIN`, `FORMAT_PROVIDER`, `FORMAT_SUBDOMAIN`; `LOCAL_MAX_LENGTH = 64`, `DOMAIN_MAX_LENGTH = 253`

## Core patterns
- **Rich value object**: one `Email` instance exposes parsed parts, classification, and formatted output
- **Static cache of `$freeDomains` / `$disposableDomains`** loaded from `data/*.php` arrays — zero runtime cost after first load
- **Provider-specific canonicalization** (Gmail strips dots + `+tags`, Outlook strips `+tags`, etc.) via `Canonicals\Provider` interface
- **Auto-normalization** in constructor (trim + lowercase)
- **Validators wrap the `Email` class** so `utopia-php/validators` compatibility is free

## Gotchas
- **Domain lists are compile-time PHP arrays, not live lookups** — run `composer import:all` in CI to refresh from external sources; stale data means new disposable services slip through
- `isCorporate()` is defined as "not free AND not disposable" — **any unknown domain is "corporate" by default**, so new free providers are misclassified until imported
- Requires `utopia-php/domains` for TLD parsing — ensure version alignment with the consuming app (`^1.0`)
- **No MX record check** — validation is lexical only; pair with DNS check if you need deliverability

## Appwrite leverage opportunities
- **Signup gating**: `EmailNotDisposable` as a field validator on the Auth `email` attribute blocks 10minutemail-style abuse without hitting an external API — zero latency
- **Dedupe accounts**: canonicalize via `getFormatted(FORMAT_PROVIDER)` + `Canonicals\Providers\Gmail` rules before inserting to the users collection — prevents `user+1@gmail.com` vs `user@gmail.com` duplicate accounts
- **Per-plan feature flags**: `EmailCorporate` for "business tier signup" gating, `isFree()` for "free tier only" email providers — project-level policy in Cloud
- **Cron refresh**: add a weekly cron that hits `import.php` and opens a PR against the data files — keeps disposable-domain coverage fresh

## Example
```php
use Utopia\Emails\Email;
use Utopia\Emails\Validator\EmailNotDisposable;

$email = new Email('User+Promo@Gmail.com');
$email->get();            // 'user+promo@gmail.com'
$email->getProvider();    // 'gmail.com'
$email->isFree();         // true
$email->isDisposable();   // false

$validator = new EmailNotDisposable();
if (!$validator->isValid('signup@10minutemail.com')) {
    throw new Exception('Disposable email not allowed');
}
```
