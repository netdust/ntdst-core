# Changelog

The migration tables live in `README.md` under `## Versions` — this file is the
short answer to "what changed in this tag". Nothing here replaces reading that
section before a MAJOR bump.

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
