# ntdst-core

NTDST Core Framework — DI container, Bootstrap, Router, Data layer, admin form
layer for WordPress. This is the canonical framework repo: `main` is the
ground truth consumed by every adopter project (daan, josworld, and later
Stride) instead of a per-project vendored copy that drifts.

## What it is

- `core/` — Foundation (Container, Bootstrap, Theme, Router)
- `api/` — Request flow (Endpoints, Data, Response)
- `admin/` — Admin UI (MetaboxGenerator, RelationField)
- `services/` — Built-in services (Logger, Mailer, Scheduler)
- `ntdst-core.php` — package-root loader; adopters require it via an explicit
  one-line shim, not a directory scan

## Branch convention

- Security or bug fixes land on `fix/{name-of-fix}`.
- Features land on `feature/{name}`.
- Both merge to `main` with `--no-ff` so the history shows the branch.

## Request dispatch filters

Handlers register on same-origin dispatch filters; `NTDST_Endpoints` owns the
auth gate so a handler never hand-rolls nonce/capability checks.

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

## Minimalism rule

Only upstream when a feature is asked for. ntdst-core can't be bloated — it's
a minimal WordPress layer. Provide solid, secure code; features enter only
with a named consumer.
