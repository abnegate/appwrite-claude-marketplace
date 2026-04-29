---
name: utopia-view-expert
description: Expert reference for utopia-php/view — minimalist .phtml rendering engine with `setParam`/`print`, registerable filters (escape, nl2p), parent/child composition via `exec()`, and an opt-in whitespace minifier that preserves `<textarea>`/`<pre>`. Consult when wiring server-rendered pages, transactional emails, or the legacy public site templates in Appwrite.
---

# utopia-php/view Expert

## Purpose
Minimalist server-side rendering engine. One class (`Utopia\View\View`) ties a `.phtml` template path to a parameter bag, a registry of named filters, and an `exec()` helper for composing parent/child views. Output is captured via `ob_start()` and optionally minified.

## Public API
- `Utopia\View\View(string $path = '')` — constructor; pre-registers `FILTER_ESCAPE` (`htmlentities` ENT_QUOTES UTF-8) and `FILTER_NL2P` (split on `\n\n` into `<p>`)
- `setPath(string $path): static` / `setRendered(bool $state = true): static` / `isRendered(): bool`
- `setParam(string $key, mixed $value, bool $escape = true): static` — values are `htmlspecialchars`'d when `$escape` and value is a string; throws if `$key` contains `.`
- `getParam(string $path, mixed $default = null): mixed` — supports dot-notation drill-down (`user.name`)
- `addFilter(string $name, callable $callback): static`
- `print(mixed $value, string|array $filter = ''): mixed` — runs one or many registered filters; throws if a filter is unknown
- `setParent(self $view): static` / `getParent(): ?self` — back-pointer set automatically by `exec()`
- `render(bool $minify = true): string` — `ob_start`, `include`s the path, returns HTML; minifier preserves `<textarea>`/`<pre>` literally
- `exec(array|self $view): string` — render a single child or an array of children, automatically wiring `setParent($this)`
- Constants: `FILTER_ESCAPE = 'escape'`, `FILTER_NL2P = 'nl2p'`

## Core patterns
- **`.phtml` templates run as PHP** — anything goes. Convention is to call `$this->print($this->getParam(...), View::FILTER_ESCAPE)` for output, never echo `getParam` raw
- **Parameters auto-escape on `setParam`** — pass `escape: false` only when storing trusted HTML you'll output verbatim. Don't double-escape by also running `FILTER_ESCAPE` in the template
- **Filters compose left-to-right** — `print($value, [FILTER_ESCAPE, FILTER_NL2P])` escapes first, then paragraph-wraps; reversed array reverses order
- **`exec()` wires parent on every call** — children can walk up via `getParent()->getParam('layout.title')` to share state with the layout
- **`setRendered(true)` short-circuits `render()`** — used as an "include once" guard so partials registered twice don't re-render
- **Minifier preserves `<pre>` and `<textarea>`** — replaces them with placeholders pre-collapse, restores after; otherwise collapses whitespace before/after tags and runs of whitespace

## Gotchas
- **Dot in param keys is forbidden** — `setParam('user.name', ...)` throws because `.` is the path separator for `getParam`. Nest arrays instead: `setParam('user', ['name' => $n])`
- **`render()` includes the path with raw PHP — XSS via template injection is on you** — never let user input become a template path; prepend `__DIR__` and validate
- **Minifier is regex-based** — single-line comments outside `<pre>`/`<textarea>` are safe but multi-line whitespace inside JS string literals can be collapsed. Pass `minify: false` for templates with embedded JS
- **`getParam` returns `null` for missing leaves** even when the leaf exists with a `null` value — the dot walk uses `isset()` semantics, not `array_key_exists`
- **No template cache** — every `render()` re-includes from disk. PHP's opcache makes this fast; do not pre-read the file into memory and `eval`, that breaks the contract
- **PHP 8.0 minimum, no Swoole dependency** — runs everywhere `utopia-php/http` runs (FPM/Swoole), but its global state (`ob_start`) is request-scoped; safe in long-running workers as long as `render()` returns before yielding

## Appwrite leverage opportunities
- **Transactional email rendering** — `Messaging` workers currently `str_replace` placeholders into HTML. `View::setParam` with auto-escape avoids three known XSS classes (subject, recipient name, project name) without the worker re-implementing escaping
- **Console error pages** — Appwrite's Traefik/error responses today are static HTML strings. A single `error.phtml` with `setParam('code', 500)` plus the layout `exec()` pattern unifies branding
- **Replace one-off templating in `cli` tasks** — `bin/setup`, `bin/migrate` print Markdown reports built via concatenation; `View` would let those tasks ship as `.phtml` next to the task code
- **Add `FILTER_MARKDOWN`** — Appwrite's docs site renders Markdown server-side; the existing filter registry would let `addFilter('markdown', fn($v) => $parsedown->text($v))` be the canonical hook so emails and pages share one Markdown pipeline

## Example
```php
use Utopia\View\View;

// Layout view (templates/layout.phtml uses $this->print(...))
$layout = (new View(__DIR__ . '/templates/layout.phtml'))
    ->setParam('title', 'Reset password — Appwrite');

// Page view, composed under the layout
$page = (new View(__DIR__ . '/templates/reset-password.phtml'))
    ->setParam('username', $userName)        // auto-escaped
    ->setParam('greeting', '<p>Hi!</p>', false) // trusted HTML
    ->setParam('user', ['email' => $email]);

$layout->setParam('body', $page->render(), escape: false);

echo $layout->render();
```

```php
// templates/reset-password.phtml
<h1>Hello, <?= $this->print($this->getParam('username'), View::FILTER_ESCAPE) ?></h1>
<?= $this->print($this->getParam('greeting'), '') /* already trusted */ ?>
<p>We sent the link to <?= $this->print($this->getParam('user.email'), View::FILTER_ESCAPE) ?></p>
```
