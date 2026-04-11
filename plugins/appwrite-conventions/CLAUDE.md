# Appwrite Conventions

This plugin autoloads the ground-truth conventions for Appwrite
ecosystem development so every session starts with the right priors.
Conventions are grouped by domain — load the sections that apply to
your current task and ignore the rest.

## Scope

These rules apply to any work inside the Appwrite ecosystem:

- **Backend PHP** — the core monorepo (`appwrite/appwrite`), Cloud,
  every `utopia-php/*` library, `appwrite/sdk-generator`, host-side
  code in `appwrite/open-runtimes`, internal PHP services, and any
  new PHP project built on the stack
- **Frontend** — `appwrite/console` (the SvelteKit web dashboard),
  plus any `@appwrite.io/pink-*` design-system work
- **Infrastructure** — Terraform modules targeting DigitalOcean,
  Cloudflare, Kubernetes, and Helm (the stack Appwrite Cloud runs on)

Each section below is self-contained. The backend rules don't apply
when you're editing Svelte; the frontend rules don't apply when
you're writing PHP. Pick the domain that matches the file you're
touching.

---

# Backend — PHP / Utopia / Swoole

Applies to any PHP file in the ecosystem. Auto-loads framework priors
so Claude doesn't have to re-derive "is this Laravel?", "what's the
namespace layout?", "how does composer pin work here?" every session.

## Framework

- **Utopia PHP, not Laravel or Symfony.** Never suggest Eloquent,
  Artisan, Blade, Twig, Doctrine, Service Providers, or anything
  framework-specific to another PHP ecosystem. Routing, DI, and the
  request pipeline all go through `utopia-php/framework`.
- **Swoole 6 runtime on PHP 8.3+.** Processes are coroutine-hooked via
  `Co::set(['hook_flags' => SWOOLE_HOOK_ALL])` at the very top of each
  entry point. Pools (database, Redis, HTTP clients) are built once
  per worker in `onWorkerStart`, never at file load and never as
  static singletons.
- **No traditional request/response lifecycle.** Expect long-running
  worker processes with persistent connections. Global state is
  worker-scoped, not request-scoped.

## Namespace layout

- Primary namespace: `Appwrite\`.
- When extending a Utopia library in the Appwrite or Cloud codebases,
  use `Appwrite\Utopia\<Library>\...`, not `Appwrite\<Library>\...`.
  Example: `Appwrite\Utopia\Database\Adapter\MySQL`, not
  `Appwrite\Database\Adapter\MySQL`. The `src/Utopia` directory is
  the namespace root for these.
- **Singular namespace names.** `Adapter` not `Adapters`, `Engine`
  not `Engines` — a namespace is a folder, plurality is implied.
- **Implementations go in nested subdirectories.**
  `Engine/Driver/Postgres.php`, not `Engine/PostgresDriver.php`. The
  file name never repeats the namespace.

## Composer dependency constraints

- **Use `*` wildcards for Utopia packages**, not `^` or `~`. Example:
  `"utopia-php/framework": "0.33.*"`, never `"~0.33.0"`. Matches the
  release cadence of the Utopia libraries.
- Third-party packages use `^` as normal.
- **Never downgrade a package** to work around a bug. Fix the
  underlying issue in the dependency, push it, and update the
  constraint.
- **Never use shims or patch files** for local dependencies. Edit
  source in the dependency repo, commit and push, then run composer
  update in the consuming repo.

## Code style (PHP)

- **First-class callable syntax:** `$this->action(...)`, not
  `[$this, 'action']`.
- **`??` for null coalescing, `?:` for falsy checks.** `getenv()`
  never returns null, so use `?:` with it.
- **Typed config objects over associative arrays.** Use
  `readonly class` with typed constructor property promotion wherever
  possible.
- **Full type hints on every parameter and return type.** No `mixed`
  unless there's no alternative.
- **`array_push($items, ...$new)` instead of `array_merge` in loops.**
  `array_merge` copies the full array every iteration — quadratic.
- **`array_values(array_unique($list))`** after deduping to keep the
  array a list, not a holey map.
- **Enums for constants.** `enum Suit: string`, never class constants
  for values used as switch targets.
- **One class per file, filename matches class name.**
- **Imports** alphabetical, one per line, grouped
  `use const` / `use function` / `use <class>`.
- **Single quotes** for strings by default. Double quotes only when
  the string contains a single quote.

## REST conventions

- **Plural nouns** for resources: `/collections`, not `/collection`.
- **kebab-case** for multi-word paths: `/acme-challenge`, not
  `/acmeChallenge`.
- **camelCase** method and function names, even with acronyms:
  `updateMfa()`, not `updateMFA()`. Uppercase acronyms break SDK
  generation (`create_m_f_a` instead of `create_mfa`).
- **Config keys and constants** fully capitalize acronyms:
  `cacheTTL`, `parseURL`, `DATABASE_URL`.

---

# Frontend — Console (SvelteKit / Svelte 5)

Applies to `appwrite/console` and any derivative SvelteKit work on
top of the `@appwrite.io/pink-svelte` design system. Grounded in the
Console's own `AGENTS.md` — re-read that file for specifics beyond
what's captured here.

## Stack

- **SvelteKit 2 + Svelte 5, TypeScript** (`strict: false`),
  `@sveltejs/adapter-static` — the Console is a static SPA served
  behind Nginx at `/console`, no SSR.
- **Vite 7**, overridden to `rolldown-vite`.
- **Design system:** `@appwrite.io/pink-svelte`,
  `@appwrite.io/pink-icons-svelte`. Don't introduce a different
  component library.
- **UI primitives:** Melt UI (`@melt-ui/svelte` with preprocessor).
- **API client:** `@appwrite.io/console` SDK (pinned to GitHub
  commit, not a semver range).
- **Code editing:** CodeMirror 6. **Charts:** ECharts 5.
  **3D:** Three.js via Threlte. **Payments:** Stripe.
  **AI:** Vercel AI SDK (`@ai-sdk/svelte`).
- **Testing:** Vitest + `@testing-library/svelte` (unit),
  Playwright (E2E).
- **Error tracking:** Sentry. **Analytics:** Plausible + custom
  Growth endpoint.

## Tooling — bun, not npm or pnpm

All commands use **bun**. Use `bun run <script>` consistently —
`bun run build` is required to avoid invoking bun's built-in bundler.

| Command | Purpose |
|---|---|
| `bun run dev` | Dev server (port 3000) |
| `bun run build` | Production build (custom `build.js` via Vite) |
| `bun run check` | `svelte-kit sync && svelte-check` |
| `bun run format` | Prettier write + cache |
| `bun run lint` | Prettier check + ESLint |
| `bun run tests` | Unit + E2E |
| `bun run test:unit` | Vitest (`TZ=EST`) |
| `bun run test:e2e` | Playwright |
| `bun run clean` | Remove node_modules + `.svelte-kit`, reinstall |

**Always run before committing:**
`bun run format && bun run check && bun run lint && bun run tests && bun run build`

## Route structure

SvelteKit file-based routing with three layout groups under
`src/routes/`:

- `(public)/` — unauthenticated: `(guest)/` (login, register),
  auth (OAuth, magic URL), invite, recover, card, functions/sites
  deploy, hackathon, templates
- `(console)/` — authenticated console (projects, orgs, account,
  onboarding)
- `(authenticated)/` — post-login flows (MFA, Git authorization)

Dynamic segments use region-aware IDs:
`project-[region]-[project]`, `organization-[organization]`.

### Route files

Each route can have `+page.svelte` + `+page.ts`,
`+layout.svelte` + `+layout.ts`, plus a local `store.ts` for
route-scoped state and feature components colocated alongside
(e.g. `table.svelte`, `create.svelte`).

## Path aliases

| Alias | Path |
|---|---|
| `$lib` | `src/lib` (SvelteKit built-in) |
| `$routes` | `src/routes` |
| `$themes` | `src/themes` |
| `$database` | `src/routes/(console)/project-[region]-[project]/databases/database-[database]` |

## Imports — barrel exports

Components use barrel exports. **Always import from the directory's
`index.ts`**, not the individual file:

```typescript
import { Card, Modal, Steps } from '$lib/components';
import { Shell, Container } from '$lib/layout';
import { InputText, Button, Form } from '$lib/elements/forms';
```

## Svelte 5 runes — preferred on new and modified code

The Console is mid-migration: ~500 files on Svelte 4 legacy syntax,
~240 migrated to runes. **When touching a file, migrate it to runes
if practical.** Don't mix syntaxes within a single component.

```svelte
<script lang="ts">
    // $bindable() enables two-way binding so parents can mutate `items`
    let { items = $bindable(), disabled = false }: Props = $props();
    let selected = $state<string | null>(null);
    const count = $derived(items.length);
    const filtered = $derived.by(() => items.filter((i) => i.active));

    $effect(() => {
        console.log('selected changed:', selected);
    });
</script>
```

Never reintroduce `export let`, `$:` reactive declarations, or
`onMount`-instead-of-`$effect` in new code.

## SDK usage

Four client instances in `$lib/stores/sdk.ts`:

- **`sdk.forConsole`** — console API (global)
- **`sdk.forConsoleIn(region)`** — region-scoped console API
- **`sdk.forProject(region, projectId)`** — project API, admin mode
- **`clientRealtime`** — realtime subscriptions

Region-aware subdomain routing: `fra.`, `nyc.`, `syd.`, `sfo.`, `sgp.`, `tor.`.

```typescript
await sdk.forConsole.account.get();
await sdk.forConsoleIn(region).projects.get({ projectId });
await sdk.forProject(region, projectId).tablesDB.listTables();
```

## Database types (feat-dedicated-db)

Databases unify multiple backends behind a polymorph API
(`$database/(entity)/helpers/sdk.ts`):

| Type | Entity | Field | Record | Status |
|---|---|---|---|---|
| `tablesdb` | table | column | row | Implemented |
| `documentsdb` | collection | attribute | document | Implemented |
| `vectorsdb` | — | — | — | Not yet implemented |
| `dedicateddb` | table | column | row | Cross-repo (cloud/edge) |

Use `useDatabaseSdk()` for the unified interface and
`useTerminology()` for the correct singular/plural names for the
current database type.

## Data loading and cache invalidation

Load functions declare dependencies for invalidation via
`depends()`:

```typescript
export const load: LayoutLoad = async ({ depends, parent, params }) => {
    depends(Dependencies.DATABASE);
    return { database: await sdk.forProject(...).tablesDB.get(...) };
};
```

Invalidate with `await invalidate(Dependencies.DATABASE)` after
mutations. `Dependencies` enum in `src/lib/constants.ts` has 66+
keys for fine-grained cache invalidation (`DATABASES`, `TABLES`,
`FUNCTIONS`, `USERS`, `DEPLOYMENTS`, etc.).

## State management

Stores in `$lib/stores/` use three patterns: writable, derived, and
"conservative" (selective update via `createConservative()` from
`$lib/helpers/stores`). Key stores: `app`, `user`, `organization`,
`projects`, `billing`, `wizard`, `notifications`, `sdk`.

### Wizard pattern

Modal wizard flow: `wizard.start(Component, media?, step?, props?)`
to open, `wizard.hide()` to close. Methods:
`setInterceptor(callback)` for async pre-step validation,
`setNextDisabled(bool)` for flow control, `setStep(n)` /
`updateStep(cb)` for navigation, `showCover(Component)` for overlays.

### Notifications

```typescript
import { addNotification } from '$lib/stores/notifications';
addNotification({ type: 'error', message: error.message });
```

Types: `success | error | info | warning`. Auto-dismisses after 6s.
Max 5 visible.

### Analytics

Track events via `trackEvent(Click.* | Submit.*, data)` and errors
via `trackError(exception, Submit.*)`. Respects
`navigator.doNotTrack`.

## Theming and modes

Four theme variants in `src/themes/`: `light`, `dark`, `light-cloud`,
`dark-cloud`. Resolved based on the `isCloud` flag and user
preference. Two deployment modes in `src/lib/system.ts`: `cloud` and
`self-hosted`, set via `PUBLIC_CONSOLE_MODE`. Gate features with
`isCloud` (cloud-only) or `isSelfHosted` (self-hosted-only).

## Code style (Svelte/TypeScript)

- **Prettier** — 4 spaces, single quotes, no trailing commas,
  100 char width, bracket same line
- **Prefer Svelte 5 runes** on new and modified code
- **Types from `@appwrite.io/console` SDK** (`Models`, `Query`,
  enums) — don't redefine what the SDK provides
- **Error handling** — try/catch with `addNotification()` for
  user-facing errors, `trackError()` for analytics
- **Queries** use the SDK's `Query` builder:
  `Query.equal()`, `Query.limit()`, `Query.offset()`, etc.
- **Tech debt markers** — `@todo`, never `@fixme`
- **Don't add new dependencies** without consulting the team

## Common pitfalls

- **Blank page in dev:** disable ad blockers if seeing "Failed to
  fetch dynamically imported module"
- **OOM on build:** `NODE_OPTIONS=--max_old_space_size=8192`
- **Use `bun run tests`, not `bun test`** — the shell script sets
  `TZ=EST` and runs both unit + E2E; bare `bun test` skips that
- **TS errors not showing** — run `bun run check` explicitly; dev
  server doesn't always surface them
- **Format vs lint conflicts** — run `bun run format` before
  `bun run lint`
- **Stale build** — clear `.svelte-kit` if changes not reflected:
  `rm -rf .svelte-kit && bun run build`

---

# Infrastructure — Terraform / DigitalOcean / Cloudflare / Kubernetes

Applies to any Terraform work in the Appwrite infrastructure stack:
provisioning Kubernetes clusters on DigitalOcean, managing Cloudflare
DNS/WAF, rolling out applications via Helm, and the glue around them.

## Provider pinning

- **Pin every provider to a narrow version range.** Use `~>` (pessimistic)
  for minor-version flexibility: `~> 2.0`, not `>= 2.0` or `*`.
- **Lock provider versions** with `terraform init` and commit
  `.terraform.lock.hcl`. Never `.gitignore` it.
- **Standard providers** for the Appwrite stack:
  - `digitalocean/digitalocean` — Droplets, DOKS, Spaces, Databases
  - `cloudflare/cloudflare` — DNS, WAF, certificates, rate limits
  - `hashicorp/kubernetes` — namespaces, secrets, CRDs
  - `hashicorp/helm` — chart releases
  - `hashicorp/random` — stable unique suffixes for resources

## State management

- **Remote state only.** Never commit `terraform.tfstate`. Spaces +
  `spaces` backend, S3 + DynamoDB locking, or DO-managed state.
- **State locking is mandatory** — unlocked state corrupts on
  concurrent apply.
- **One state file per environment** (`dev`, `stage`, `prod`) via
  separate workspaces or backend keys. Never share state across
  environments.
- **Never edit state by hand.** Use `terraform state mv` /
  `import` / `rm`, never manual JSON edits.

## Naming and tagging

- **Resource names** are environment-prefixed and kebab-case:
  `appwrite-prod-api-lb`, `appwrite-stage-db-primary`.
- **Tags / labels on every resource**: `environment`, `project`,
  `managed-by: terraform`, `owner`. DigitalOcean and Cloudflare both
  support tags — use them.
- **No hardcoded regions.** Pass `var.region` through the module;
  multi-region work shouldn't require find-and-replace.

## Secrets

- **Never commit secrets**, not even encrypted. Secret values flow
  through `tfvars` files (gitignored), environment variables
  (`TF_VAR_*`), or a secrets manager (HashiCorp Vault, DO secrets,
  SOPS-encrypted files).
- **Mark sensitive variables** with `sensitive = true` so Terraform
  elides them from plan output and state diff displays.
- **Rotate provider tokens** on schedule, and store rotation dates
  somewhere Terraform can read them so drift is visible.

## Module structure

- **Root module is thin.** Root only wires modules together, passes
  variables, and exposes outputs. Never put resource blocks directly
  in the root.
- **One module per concern**, under `modules/<name>/`. A module has
  `main.tf`, `variables.tf`, `outputs.tf`, and optionally
  `versions.tf` for its own provider requirements.
- **Variables declare `type` and `description`.** No bare
  `variable "x" {}` blocks.
- **Outputs are the module's public API.** Anything a caller might
  need (hostname, ID, endpoint) must be an `output`; don't reach
  into a module's state from outside.

## Safety rules

- **Always `plan` before `apply`.** Never run `apply` in CI without
  first running `plan` on the same commit and surfacing the diff
  for human review.
- **Never `-target`** outside of emergency. Targeted applies skip
  the dependency graph and leave state inconsistent.
- **Destroy protection** (`lifecycle { prevent_destroy = true }`)
  on long-lived stateful resources: databases, Spaces buckets,
  production clusters. Safer to fail loudly than lose data.
- **Review `plan` output for destroy/replace** — any time a line
  starts with `- destroy` or `-/+ replace`, stop and confirm with
  the team before applying.
- **No root-privilege helm charts** without explicit justification.
  Use `podSecurityContext` and `securityContext` to run as
  non-root wherever possible.

---

# Cross-cutting

These rules apply to every domain.

## Testing

- **Every bug fix ships with a regression test** that fails without
  the fix and passes with it. Enforced at commit time by
  `appwrite-hooks` `regression_test_hook.py`.
- **There are no "pre-existing issues".** If a test fails, fix it,
  regardless of when it broke.
- **No mocks for the database** in backend integration tests — hit
  a real database. Mock/prod divergence has historically masked
  real migration bugs.
- **Backend** — PHPUnit 9+ with `assertSame` by default, not
  `assertEquals`. `assertSame` checks type + value.
- **Frontend** — Vitest unit tests with `TZ=EST`. Use
  `@testing-library/svelte` for component tests, Playwright for
  E2E flows.

## Branch and PR discipline

- **Backend PRs target the current version branch** (e.g., `1.9.x`),
  not `main`. `main` is reserved for release management.
- **Console branch naming:** `TYPE-ISSUE_ID-DESCRIPTION` (e.g.
  `feat-548-add-backup-ui`). Types: `feat`, `fix`, `doc`, `cicd`,
  `refactor`.
- **Sparse updates when modifying a record** (backend) — pass only
  the changed attributes, not the full object. Matches how the
  Utopia Database layer handles updates.
- **Conventional commits**, `(type): subject`, enforced by
  `appwrite-hooks` `conventional_commit_hook.py`.
- **Never `--no-verify` or `--amend` on a failed-hook commit.**
  Blocked by `appwrite-hooks` `no_verify_guard_hook.py`.

## Cross-repo awareness

Some features span multiple repos. The `feat-dedicated-db` initiative
touches `appwrite`, `cloud`, `edge`, and `console` simultaneously.
When modifying API contracts or response models, check the other
repos for breaking changes before merging. See the `/fanout` command
in `appwrite-skills` for parallel cross-repo research.

## What's already captured elsewhere

Don't re-derive things that can be read from the code:

- **Specific class/file locations** — use Grep.
- **Current dependency versions** — read `composer.json` /
  `package.json` / `versions.tf`.
- **The list of active branches** — use `git branch -a`.
- **The exact shape of an adapter or component interface** — read
  the base class / Props type.

These conventions are the stable priors that don't change session to
session. The moving parts belong in the code.
