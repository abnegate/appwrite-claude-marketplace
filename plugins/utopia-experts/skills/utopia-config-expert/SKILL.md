---
name: utopia-config-expert
description: Expert reference for utopia-php/config — attribute-driven typed configuration loader that parses JSON/YAML/dotenv/env into validated readonly DTOs via reflection. Consult when taming env vars or implementing fail-fast boot validation.
---

# utopia-php/config Expert

## Purpose
Attribute-driven, statically-typed configuration loader — parses JSON/YAML/dotenv/PHP/env into validated readonly-ish config DTOs via reflection and Utopia validators.

## Public API
- `Utopia\Config\Config::load(Source, Parser, class-string $className)` — the single entry point; returns a fully-populated instance of `$className` or throws `Load`/`Parse`
- `Utopia\Config\Source` — abstract; implementations `File`, `Variable` (in-memory array), `Environment` (env vars)
- `Utopia\Config\Parser` — abstract; implementations `JSON`, `YAML`, `Dotenv`, `PHP`, `None` (pass-through)
- `Utopia\Config\Attribute\Key` — per-property attribute: `#[Key('db.host', new Text(1024), required: true)]`
- `Utopia\Config\Attribute\ConfigKey` — marks a property that holds a nested `Config`-loaded DTO
- `Utopia\Config\Exception\{Load, Parse}` — distinct exceptions for source-not-found vs parser-failed

## Core patterns
- **DTO with typed public properties** — no constructor, no methods (enforced: having methods throws). Reflection iterates properties, reads `#[Key]`, validates, assigns
- **Dot-notation key resolution** — `#[Key('db.host')]` walks `$data['db']['host']` and tries compound keys (`db.host` as a literal key) at each level via `resolveValueRecursive`
- **Validator pipeline** — every key carries a Utopia validator; `isValid($value)` failure throws `Load` at boot. `Nullable(new Text(...))` lets a key exist but be null
- **Composition via `#[ConfigKey]`** — a property typed as `FirewallConfig` is populated by another `Config::load()` call
- **Source/Parser split** — `Source` returns raw contents; `Parser` turns them into `array<string, mixed>`. `None` exists for sources that already return arrays

## Gotchas
- DTOs can have **no methods at all** — not even constructors, getters, or `__toString`. Trying to add a helper fails with `"Class X cannot have any functions."` at load time
- Properties must be typed — untyped `public $foo` throws `"Property foo is missing a type."`
- `resolveValueRecursive` tries every dotted/nested combination — a dotted literal key in the source will shadow a nested one, a silent footgun when migrating from flat env vars to structured YAML
- `required: true` + null throws `Load`, but `required: false` + null **skips** property assignment — properties must be nullable or have a default, or you hit `uninitialized property` at first access

## Appwrite leverage opportunities
- Appwrite currently reads hundreds of env vars via `App::getEnv('_APP_...')` scattered across init files — bundling them into one `AppwriteConfig` DTO with `#[Key]` attributes would fail-fast at boot on any typo/missing/wrong-type var instead of surfacing as nulls deep inside request handlers
- A `Source\Vault` adapter (HashiCorp Vault / AWS Secrets Manager) pulling secrets at boot via the same attribute schema would unify dev (`.env`), stage (`env`), and prod (`vault`) config handling with zero call-site changes
- `#[Key]` validators are Utopia validators — reuse the same `Hostname`, `URL`, `Email` validators applied to API params, turning "valid at API boundary" and "valid at boot" into the same validation surface
- No caching today — in FPM each request re-parses the YAML. A `CachedConfig` wrapper that serialises the loaded DTO to OPcache / APCu keyed by the source file mtime would cut boot time significantly

## Example
```php
use Utopia\Config\{Config, Source\Environment, Parser\None};
use Utopia\Config\Attribute\Key;
use Utopia\Validator\{Text, Integer, Hostname, Whitelist};

final class DatabaseConfig
{
    #[Key('_APP_DB_HOST', new Hostname(), required: true)]
    public string $host;

    #[Key('_APP_DB_PORT', new Integer(loose: true), required: true)]
    public int $port;

    #[Key('_APP_DB_USER', new Text(length: 64), required: true)]
    public string $username;

    #[Key('_APP_ENV', new Whitelist(['development', 'stage', 'production']), required: true)]
    public string $environment;
}

$config = Config::load(new Environment(), new None(), DatabaseConfig::class);
$pdo = new PDO("mysql:host={$config->host};port={$config->port}", $config->username, '');
```
