# Changelog

The migration tables live in `README.md` under `## Versions` — this file is the
short answer to "what changed in this tag". Nothing here replaces reading that
section before a MAJOR bump.

## Unreleased

Additive. `^5.1` consumers upgrade without a code change — every changed hook
gains an APPENDED argument, and WordPress hands a listener only as many
arguments as it registered for.

### Added

- `ntdst/model/meta_updated` — `($post_type, $id, array $data, array $previous)`,
  fired by `updateMeta()` and `updateMetaBatch()` on the success path only.
  `$data` is the sanitized values actually written (unprefixed field keys);
  `$previous` maps the same keys to `['exists' => bool, 'value' => mixed]`
  before-state snapshots. A batch fires ONE event carrying every written key —
  a batch is one caller action, and a listener rendering it (the audit log)
  wants one row, not N. The direct meta write paths were invisible to the
  model hooks before this: an edition status change or a quote lock never
  passed through `update()` at all.
- `ntdst/model/meta_deleted` — `($post_type, $id, $key, array $previous)`,
  fired by `deleteMeta()` only when something was actually deleted
  (`delete_post_meta()` answers false for a failure AND for a key that was
  never set; neither is a change).

### Changed

- `ntdst/model/updated` carries a fourth argument: the before-state `update()`
  already captured for rollback — `['post' => [column => previous], 'meta' =>
  [field => ['exists' => bool, 'value' => mixed]]]`, covering exactly the
  fields the caller wrote. This is what lets an audit listener say "changed
  Status from draft to published" instead of "post 4821 updated".
- `ntdst/model/deleting` and `ntdst/model/deleted` carry a third argument: the
  pre-delete snapshot `['post' => WP_Post, 'meta' => formatted fields]` — after
  the delete it is the only place the row's content survives to.

## 5.1.1

Additive/behaviour-correcting. `^5.1` consumers upgrade without a code change.

### Fixed

- `array`/`json` field values kept flattening a stored multiline string on
  every read — `nested()` ran each string leaf through `sanitize_text_field()`,
  which collapses `\n`/`\r`, so a `json` field holding free-form content (an
  admin note's `content`) lost its line breaks on every save.
- `repeater()` silently emptied a field that stored the JSON-STRING shape a
  metabox textarea posts (or a raw/legacy write left behind) — it refused
  anything that was not already a PHP array, so converting an existing `json`
  field to `repeater` (a `speakers` field) reduced every stored value to `[]`.

### Note

- The `5.1.1` tag itself shipped with the plugin header still reading `5.1.0`
  (the 2.4.1 precedent, again). The header is bumped on `main` right after the
  tag; nothing reads the header for dependency resolution — composer resolves
  the tag.

## 5.1.0

Additive. `^5.0` consumers upgrade without a code change.

### Added

- `NTDST_Data_Model::whereGroup(string $relation, callable $build)` — a nested
  `meta_query` clause with its own `AND`/`OR` relation. The callback receives a
  child builder of the same model, so keys are prefixed and scopes resolve
  inside the group. Groups nest. An unknown relation throws
  `InvalidArgumentException`; an empty group is a no-op.
- `NTDST_Data_Model::whereMissing(string $field)` — `NOT EXISTS` on the
  prefixed key.
- `NTDST_Data_Model::whereNotIn(string $field, array $values)` — `whereIn()`'s
  negation; `'ID'` sets `post__not_in`.

Together these give 5.0.0's migration advice for the removed `orWhere()` — "a
`meta_query` with an explicit relation" — a destination inside the chain,
instead of sending the caller out of it to hand-write the argument bag.
