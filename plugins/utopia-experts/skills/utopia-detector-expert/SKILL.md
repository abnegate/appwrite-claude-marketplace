---
name: utopia-detector-expert
description: Expert reference for utopia-php/detector — project environment identification for Appwrite Sites/Functions (runtime, framework, packager, rendering). Consult when auto-configuring Sites builds or Function runtimes. NOT a user-agent detector despite the name.
---

# utopia-php/detector Expert

## Purpose
Environment identification for Appwrite Sites/Functions — detects runtime, framework, packager, and rendering mode from a list of project files.

## Public API
- `Utopia\Detector\Detector` (abstract)
- `Utopia\Detector\Detection` (abstract result)
- `Detector\Runtime`, `Detector\Framework`, `Detector\Packager`, `Detector\Rendering`
- `Detector\Strategy` with `FILEMATCH | EXTENSION | LANGUAGES`
- `Detection\Runtime\{Node, Bun, Deno, PHP, Python, Dart, Swift, Ruby, Java, CPP, Dotnet}`
- `Detection\Framework\{NextJs, Nuxt, Astro, Remix, SvelteKit, Angular, Analog, Flutter, Lynx}`
- `Detection\Packager\{NPM, PNPM, Yarn}`
- `Detection\Rendering\{SSR, Static}`
- `addInput(string $content, string $type)`, `addOption(Detection $option)`, `detect(): ?Detection`

## Core patterns
- **Strategy enum selects matcher**: filename intersect, extension intersect, or language tokens
- **Plugin list** — caller `addOption()`s the adapters they want in priority order; first match wins
- **First-match-wins on `array_intersect($detectorFiles, $inputs)`** — order of `addOption()` calls is priority
- **Detections are self-describing**: carry own `getFiles()`, `getLanguages()`, `getInstallCommand()`, `getCommands()`
- **Packager injected post-detection** (`setPackager()`) so runtime detection carries the resolved package manager context

## Gotchas
- **Not a UA/device detector despite the name** — `utopia-php/detector` identifies **project** environments (Node/Bun/etc.), not HTTP user agents. Do not reach for this to detect browsers or bots
- **First match wins by `addOption()` order** — adding `Node` before `Bun` with a `package.json` present silently hides Bun even if `bun.lockb` exists. Register narrow/more-specific detectors first
- **No confidence score / no multi-result** — ambiguous projects (e.g. Astro+SvelteKit hybrid) just return whichever is listed first
- Stable API but adapter list is moving — new frameworks added each minor release; pin and test

## Appwrite leverage opportunities
- **Sites auto-configure**: when a user imports a git repo, pipe the file list into a `Framework` detector to auto-fill build command, install command, and output directory — exactly what Cloud Sites needs to eliminate manual config
- **Functions runtime auto-pick**: given an uploaded zip, run `Runtime` with `FILEMATCH` to prefill the runtime dropdown — reduces support tickets for "which runtime do I pick"
- **SSR vs Static routing decision**: feed `Rendering` detector output into the edge proxy so static sites skip the SSR worker path entirely
- **Do not use for UA parsing** — UA strings drift and this library has zero UA logic. If you need device/bot detection, go to `matomo/device-detector` instead

## Example
```php
use Utopia\Detector\Detector\Framework;
use Utopia\Detector\Detection\Framework\{NextJs, SvelteKit, Remix};

$files = ['package.json', 'next.config.js', 'app/page.tsx'];
$detector = (new Framework($files, packager: 'pnpm'))
    ->addOption(new NextJs())
    ->addOption(new Remix())
    ->addOption(new SvelteKit());

$framework = $detector->detect();
$name = $framework?->getName();                 // 'nextjs'
$install = $framework?->getInstallCommand();    // 'pnpm install'
```
