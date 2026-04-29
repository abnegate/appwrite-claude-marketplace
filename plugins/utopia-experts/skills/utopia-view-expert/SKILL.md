---
name: utopia-view-expert
description: Expert reference for utopia-php/view — single-class `.phtml` templating engine with named filters, parent/child composition, and opt-in HTML minification. Consult for transactional email rendering, server-rendered admin pages, or escaping/filter chaining inside Appwrite-stack services.
---

# utopia-php/view Expert

## Purpose
Tiny single-class templating engine that includes a `.phtml` file under output buffering, exposes assigned params, applies named filters via `print()`, and optionally minifies HTML. No autoloader scanning, no caching layer — one class, one render call.

## Public API
- `Utopia\View\View(string $path = '')` — constructor; `setPath($path)` after the fact also works
- `setParam(string $key, mixed $value, bool $escape = true): static` — auto `htmlspecialchars` for strings unless `$escape = false`
- `getParam(string $path, mixed $default = null): mixed` — dot-path access into nested arrays (`$view->getParam('user.email')`)
- `addFilter(string $name, callable $callback): static` — register a named filter
- `print(mixed $value, string|array $filter = ''): mixed` — apply a single filter or chain (left-to-right) when `$filter` is an array
- `setParent(View)` / `getParent(): ?View` — parent linkage for composition
- `setRendered(bool $state = true)` / `isRendered()` — opt out a child of being re-rendered (e.g. cached partials)
- `render(bool $minify = true): string` — execute the template, return the buffered output; throws if template path is unreadable
- `exec(View|View[] $view): string` — render child views (or an array of them), wiring `setParent($this)` automatically
- Built-in filters: `View::FILTER_ESCAPE` (`htmlentities` ENT_QUOTES UTF-8) and `View::FILTER_NL2P` (paragraph wrapping)

## Patterns
- **Output-buffered include** — `render()` does `ob_start()`, `include $this->path`, `ob_get_contents()`, `ob_end_clean()`. `$this` inside the template is the `View` instance; access params via `$this->getParam(...)` or assigned-via-`extract` patterns the template author writes
- **Auto-escape on `setParam`** — string values get `htmlspecialchars(ENT_QUOTES, UTF-8)` by default; pass `escape: false` for pre-rendered HTML (subviews, markdown output) — you own the trust call
- **Filter chaining via array** — `$view->print($body, ['nl2p', 'escape'])` runs `nl2p` first, then `escape`; filter chain is applied left-to-right so order matters for tag-emitting filters
- **Composition via `exec`** — a parent template includes child views by `<?= $this->exec($childView) ?>`; child's `setParent($this)` lets it resolve shared params upwards
- **`setRendered(true)` short-circuits** — useful for partials whose output is already cached/server-pushed; `render()` returns `''` instead of re-evaluating

## Gotchas
- **Auto-escape is on `setParam`, not on `print`** — calling `setParam` with `escape: false` then `<?= $this->getParam('html') ?>` emits unescaped HTML. The two-stage trust model is easy to misread; pick one (`escape: false` + `print(..., 'escape')` at site of use) and stay consistent
- **Dot keys are forbidden** — `setParam('user.email', ...)` throws because `getParam` uses `.` as the path separator. Build nested arrays (`setParam('user', ['email' => $e])`) then read with `'user.email'`
- **`render()` minifies by default** — strips whitespace between tags except inside `<textarea>` and `<pre>` (which it preserves via a placeholder swap). Pass `minify: false` for whitespace-sensitive output (XML, plaintext email)
- **Exception on unreadable path** — `render()` throws `Exception` with the literal path string; make sure templates are deployed alongside the class or pin paths to absolute roots
- **No template caching** — every `render()` re-`include`s the file; on Swoole long-running workers OPcache covers this, but on FPM cold starts it's per-request file IO
- **No layout/inheritance keyword** — composition is bottom-up via `exec`, not top-down via `extends`; the parent template explicitly emits the child's rendered string

## Composition
- **Transactional email rendering** — `utopia-emails-expert` parses MIME; `utopia-view-expert` renders the body. Compose `View::FILTER_NL2P` over user-supplied plaintext to get safe HTML paragraphs
- **Server-rendered admin/login pages** — pair with `utopia-locale-expert` (translation lookup as a custom filter) and `utopia-http-expert` (route action `$response->html($view->render())`)
- **Markdown-to-HTML in templates** — register a `markdown` filter via `addFilter` that delegates to a parser, then `print($body, ['markdown'])` — bypasses the auto-escape since the parser owns trust
- **Static-file partial cache** — combine with a memoising layer outside the view: render once, cache the string, then `setRendered(true)` on subsequent requests to skip re-include

## Example
```php
use Utopia\View\View;

$layout = new View(__DIR__ . '/templates/layout.phtml');
$layout
    ->setParam('title', 'Welcome')
    ->setParam('body', "Line one\n\nLine two\nstill line two", escape: false);

$layout->addFilter('upper', fn (string $v) => strtoupper($v));

// Inside layout.phtml:
//   <title><?= $this->print($this->getParam('title'), ['upper', 'escape']) ?></title>
//   <main><?= $this->print($this->getParam('body'), 'nl2p') ?></main>

echo $layout->render(minify: true);

// Composition: nest a child view
$header = new View(__DIR__ . '/templates/header.phtml');
$header->setParam('user', ['name' => 'Eldad']);

$page = new View(__DIR__ . '/templates/page.phtml');
// In page.phtml:  <?= $this->exec($header) ?>
$page->setParam('header', $header, escape: false);
echo $page->render();
```
