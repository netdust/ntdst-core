# ntdst-core

NTDST Core Framework — DI container, Bootstrap, routing, Data layer, admin form
layer for WordPress. This is the canonical framework repo: `main` is the
ground truth consumed by every adopter project (daan, josworld, and later
Stride) instead of a per-project vendored copy that drifts.

## What it is

- `core/` — Foundation (Container, Bootstrap, Theme, Pages)
- `support/` — Primitives with no dependencies (ClientIp, Cidr, RateLimiter)
- `api/` — Request flow (Actions, Rest, Data, Response)
- `admin/` — Admin UI (MetaboxGenerator, RelationField)
- `services/` — Built-in services (Logger, Mailer, Scheduler)
- `ntdst-core.php` — package-root loader; adopters require it via an explicit
  one-line shim, not a directory scan

## Which service to reach for

| You want | Use |
|---|---|
| a command, dispatched same-origin | `ntdst_actions()->register()` |
| a resource route | `ntdst_rest('ns/v1')->get()` / `->post()` |
| file bytes | `add_filter('ntdst/api_download/{action}', …)` |
| a page | `ntdst_pages()->path()` |
| a download response | `ntdst_download()` — never a hand-rolled CSV block |
| to count anything per caller | `NTDST_RateLimiter::attempt()` / `::reset()` |

## Branch convention

- Security or bug fixes land on `fix/{name-of-fix}`.
- Features land on `feature/{name}`.
- Both merge to `main` with `--no-ff` so the history shows the branch.

## Request dispatch filters

Handlers register on same-origin dispatch filters; `NTDST_Actions` owns the
auth gate so a handler never hand-rolls nonce/capability checks.

An action nothing has registered is refused before the gate does any work: it
gets no rate bucket, and `/get_nonce` will not mint a nonce for it. Registered
means listed in `ntdst/api/public_actions`, or having a handler on the dispatch
filter below.

- `ntdst/api_data/{action}` — POST `/action`. Handler returns
  `array|WP_Error`; the dispatcher emits the JSON envelope. Gate: rate limit,
  Origin/CSRF check, per-action nonce, and anonymous callers may reach only
  actions listed in `ntdst/api/public_actions`.
- `ntdst/api_download/{action}` — GET `/download` (since v2.3.0). Handler
  emits a file via `ntdst_response()->download()` / `->inline()` and exits;
  the dispatcher never reads a filename or path from the request. Same gate as
  `/action` **except** no Origin check: a browser `<a href>` download is a
  top-level navigation that carries no `Origin` header, so the per-action
  nonce in the URL is this surface's CSRF gate. A download action is never
  public unless listed in `ntdst/api/public_actions`; a handler that returns
  instead of emitting yields a 500 rather than a blank body.

## Versions

### 4.0.0 — adopting it

Read every line before upgrading. Nothing here is shimmed.

**Symbols that left the package.** Calling one is a fatal, deliberately.

| Gone | Replacement |
|---|---|
| `NTDST_SectorRegistry`, `ntdst_sectors()` | none — the system left core |
| a `sectors` key in service `metadata()` | inert; it gates nothing now |

**Behaviour changes a working consumer can notice.**

1. **`/get_nonce` no longer mints a nonce for an unregistered action.** It used
   to hand any logged-in caller a nonce for any string. Registered means listed
   in `ntdst/api/public_actions`, or having a handler on `ntdst/api_data/` or
   `ntdst/api_download/`. If you used `/get_nonce` as a generic nonce factory
   for your own AJAX, it now returns 401. Mint those with `wp_create_nonce()`
   yourself.
2. **An unregistered action gets no rate bucket and no 429.** It is refused
   with a bare `false` (401), same as an auth denial.
3. **An anonymous caller is refused BEFORE the limiter runs.** Only reachable
   callers are counted now, so per-IP bucket rows stop appearing for traffic
   that was always going to be refused. If you were reading `ntdst_rate_*`
   transients to measure attack volume, they will look quieter.
4. **A declared `metadata()['name']` now really does pin the service slug.**
   It was documented as doing so and did not. If one of your services declares
   a name whose slug differs from the class-derived one, its
   `ntdst_service_{slug}_enabled` filter and `ntdst_service_{slug}` option
   **change key**. Check every service that declares a `name`, and remember the
   `_enabled` filter is a DENY filter that fails OPEN — a stale key means a
   service you meant to switch off boots.
5. **A namespaced service's file path no longer depends on the active theme.**
   Bootstrap used to strip `basename(get_stylesheet_directory())` from the
   namespace path. It now tries the path as spelled and then with the leading
   segment dropped. Existing theme layouts resolve exactly as before. The one
   difference: if BOTH candidates exist on disk, the unstripped one now wins.
6. **A CORS preflight is charged against a rate-limited route.** `OPTIONS` used
   to cost nothing. It now spends one unit from a bucket of its own
   (`ntdst_rest_pf_*`), so a preflight flood can now return 429. It does **not**
   spend the verb budget your real request needs. **Consumers declare nothing**
   — a route that already sets `rate_limit` gets this; a route that sets none
   is still unthrottled.
7. **`composer lint` is phpcs now, not `php -l`.** If you called it in CI,
   `composer syntax` is the old behaviour. See `docs/gate.md`.

**Not a break, but check it:** the plugin header said `2.4.1` for the whole of
v3. If anything of yours read the version, it was reading the wrong one.

### 3.0.0

The routing surface was renamed with no aliases and no shims: `NTDST_Actions` +
`ntdst_actions()->register()` for commands, `ntdst_rest()` for resource routes,
`ntdst_pages()` for pages. A caller of a retired symbol gets a fatal,
deliberately, rather than silent inaction.

## Minimalism rule

Only upstream when a feature is asked for. ntdst-core can't be bloated — it's
a minimal WordPress layer. Provide solid, secure code; features enter only
with a named consumer.
