# ntdst-core

NTDST Core Framework — DI container, Bootstrap, Router, Data layer, admin form
layer for WordPress. This is the canonical framework repo: `main` is the
ground truth consumed by every adopter project (daan, josworld, and later
Stride) instead of a per-project vendored copy that drifts.

## What it is

- `core/` — Foundation (Container, Bootstrap, Theme, Router)
- `api/` — Request flow (Endpoints, Data, Response)
- `admin/` — Admin UI (MetaboxGenerator, RelationField)
- `services/` — Built-in services (Logger, Mailer)
- `ntdst-core.php` — package-root loader; adopters require it via an explicit
  one-line shim, not a directory scan

## Branch convention

- Security or bug fixes land on `fix/{name-of-fix}`.
- Features land on `feature/{name}`.
- Both merge to `main` with `--no-ff` so the history shows the branch.

## Minimalism rule

Only upstream when a feature is asked for. ntdst-core can't be bloated — it's
a minimal WordPress layer. Provide solid, secure code; features enter only
with a named consumer.
