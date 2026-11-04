# model-revisions — ntdst-core 5.6.0: an editable model keeps revisions, fields included

**Status:** short design, 2026-11-04, approved by Stefan the same day.
**Target release:** v5.6.0 (minor: every admin-editable model changes on update).
**Follow-up elsewhere:** ntdst-baseline's chat stops refusing ntdst model types (its spec R5), after this tag.

## Why

A model's records have no undo. `NTDST_Data::register()` gives a model `supports: title, editor,
thumbnail` (`api/Data.php:2236`) — no `revisions` — and WordPress revisions only `post_title`,
`post_content` and `post_excerpt` unless told which meta keys to copy. So an edit to a model record,
in wp-admin or through an agent, cannot be rolled back. Baseline's chat refuses model types for exactly
this reason.

## Requirements

**R1 — An admin-editable model has revisions.** Every model whose post type has `show_ui` gets
`revisions` support, whether or not the site passed its own `supports`.
*Source: Stefan, 2026-11-04: "cpt that are admin editable are default with revisions"*

**R2 — Every field goes into the revision.** For a model with revisions, every declared field's stored
meta key is copied into each revision and restored with it, and a change to a field alone makes a new
revision.
*Source: Stefan, 2026-11-04: "if the cpt gets revisions every field inside does too"*

**R3 — At most 5 revisions per record.**
*Source: Stefan, 2026-11-04: "ok, and we keep max 5 revisions"*

**R4 — A site can switch it off for one model** with `'revisions' => false` in the model's config.
*Source: invented — approved 2026-11-04.*

**R5 — Restoring an older revision never empties a field.** A revision made before 5.6.0 holds no copy
of the fields; WordPress's restore deletes each listed key, then copies it from the revision. When the
revision has no copy of a key, the record keeps its current value.
*Source: invented — found 2026-11-04 (`wp-includes/revision.php:530-543`), approved 2026-11-04.*

## Approaches

1. **Name the keys through `wp_post_revision_meta_keys` (WP 6.4), and add `revisions` support** —
   chosen. WordPress copies each key as stored; nothing is registered, no sanitizer attaches, no read or
   write path changes. Stored data is not migrated or rewritten.
2. **Register every field with `register_post_meta(..., 'revisions_enabled' => true)`** — ruled out:
   registration attaches core's sanitizer to every key, so site code writing a key directly with
   `update_post_meta()` would silently store something else; fields with no publishable shape cannot
   register at all.
3. **Opt-in per model** — ruled out: not what was asked; editable models would stay without undo.

## Design (calls marked as mine)

- **Where.** Inside `NTDST_Data::register()`, the one place a model's post type and fields are known —
  no new public method on `Data.php` (field-types D1). *My call.*
- **R3's cap** through `wp_{$post_type}_revisions_to_keep` = 5, per model type. `false` (R4) leaves
  `supports` without `revisions`. This per-type filter runs after the global `wp_revisions_to_keep`,
  so it also overrides a site's global setting (baseline `maintenance.limit_post_revisions`, also 5 by
  default) for model types. *My call.*
- **R2's keys** are the model's declared fields with their meta prefix. A `callback` field is left out:
  the consumer's code owns where it stores, so core does not know the key. *My call; README says so.*
- **R5** replaces WordPress's restore step for model types only: per key, delete and copy only when the
  revision holds that key (`metadata_exists('post', $revisionId, $key)`). *My call on the mechanism.*

## Non-goals

No migration of stored values. No revisions for models without `show_ui`. No change to how fields are
read, written, sanitized or published over REST. Baseline's chat change is its own plan.

## Tests (the plan's Test map will refine)

Integration on real WordPress: a `show_ui` model saves a revision holding its fields; a field-only
change makes a revision; restoring brings fields back; a pre-5.6 revision (no meta copy) restores
without emptying a field; the sixth save leaves 5 revisions; `'revisions' => false` gives none; a model
without `show_ui` gets none; a site's own `supports` list still gains `revisions`.
