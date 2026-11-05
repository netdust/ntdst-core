# Changelog

The migration tables live in `README.md` under `## Versions` — this file is the
short answer to "what changed in this tag". Nothing here replaces reading that
section before a MAJOR bump.

## 5.7.0

Additive. `^5.6` consumers upgrade without a code change.

### Added
- The scheduled-job registry and run log, moved from Stride's `ntdst-cron` plugin with their surface unchanged: `Ntdst\Cron\CronRegistry` and `Ntdst\Cron\RunLog` (`services/`), and `ntdst_cron_register( $hook, $config )`. Config keys `label`, `description`, `recurrence`, `callback`; options `ntdst_cron_runs` and `ntdst_cron_paused`, never autoloaded.
- Registration schedules the event once, wraps the callback, records every fire (outcome, duration, summary), and supports pause and run-now. A thrown message is logged in full; the stored summary has the credential constants and `ABSPATH`/`WP_CONTENT_DIR` removed and is capped at 500 characters. At most 20 runs per hook are kept.
- Core binds both classes on `ntdst/services_registered`; `ntdst_cron_register()` is callable from `ntdst/features_ready` onward.
- INV-12 (a recurring job registers through `CronRegistry`) and its line in `bin/guard.sh`.

### Notes on update
- A site running the Stride `ntdst-cron` plugin keeps working: core loads first as a mu-plugin, its classes and helper win, and the plugin's copy is dead. A later Stride change removes the plugin's classes and bindings and requires core 5.7.
- `services/` now holds `Logger.php`, `RunLog.php` and `CronRegistry.php`; the 5.0.0 Scheduler stays removed (the registry wraps `wp_schedule_event`, it is not a second scheduler).
- baseline's `MaintenanceService` is the known open INV-12 hit until `baseline-vulnerability-check` migrates it.

## 5.6.0

`^5.5` consumers upgrade without a code change, apart from the removed protected method below; what changes on update is listed here.

### Added
- An editable model keeps WordPress revisions, its declared fields included. Every model whose registered post type has `show_ui` (an unset `show_ui` follows `public`, as WordPress resolves it) gains `revisions` support. `'revisions' => false` in the model's config opts out.
- At most 5 revisions per record, and never more than the site allows: a lower `WP_POST_REVISIONS` (or `wp_revisions_to_keep` filter) is kept, `0` stays off, and WordPress's unlimited (`-1`) reads as 5.
- A field-only change makes a revision, and restoring a revision restores the fields it holds. `callback` fields are not revisioned (their storage is the consumer's). A restore takes one more revision, holding the restored fields.
- A revision that holds no copy of a field (taken before 5.6, or while the field was unset) restores without emptying it: the field keeps its current value.
- Model fields stay out of autosaves: WordPress would copy raw `$_POST` / REST `/autosaves` meta for a revisioned key, and restoring that autosave would write it unsanitised. Nothing is registered with `register_post_meta()`, no sanitizer attaches and, with that, no stored value changes.

### Changed
- `NTDST_Data_Model::update()` writes the fields before the post, so the revision WordPress takes holds the new values, and a title + field update makes one revision, not two. A `save_post` listener now sees the new field values during a Data-API update. A fields-only `update()` now saves a revision, so `save_post` fires for that revision post. A record with no revision yet has its original saved first, so its first edit can be undone. Rollback is unchanged: a failed field write or post write restores the fields written so far.

### Removed
- `NTDST_Data_Model::restorePostData()` (protected): nothing writes the post before the fields any more, so there is nothing to roll back. A subclass that called it must drop the call.

### Notes on update
- Revisions appear for every `show_ui` model from the first save after the update; the database grows by up to 5 revisions (and their copied meta) per record.
- Models without a `label` register no post type, so they get no revisions.
- WordPress's own `/revisions` and `/autosaves` REST routes now mount for `show_in_rest` models, behind `edit_post`; only registered fields appear.

## 5.5.0

Additive. `^5.4` consumers upgrade without a code change.

### Added
- The `ability` option on `ntdst_rest()` routes: a route becomes a WordPress ability (6.9+), executed by dispatching the route itself.
- `NTDST_Rest::mountAbilities()` and the two walks on `wp_abilities_api_categories_init` / `wp_abilities_api_init`, the only callers of `wp_register_ability_category()` and `wp_register_ability()`.
- INV-11 (an ability is the shadow of a route) and its line in `bin/guard.sh`.

## 5.4.2

Fix. `^5.4` consumers upgrade without a code change; a field that already
declared a `label` now shows it.

### Fixed

- The metabox draws a top-level field's declared `label` as its label. It was
  always made from the key (`contact_name` became "Contact Name") and a `label`
  on the declaration was ignored, while a repeater sub-field already read it. A
  blank or missing `label` still falls back to the key, and the label is
  escaped on output.

## 5.4.1

Fix. `^5.4` consumers upgrade without a code change.

### Fixed

- `NTDST_Theme::setup_theme()` loads the text domain from the child theme's
  `languages/` (`get_stylesheet_directory()`) when it exists, falling back to
  the parent's (`get_template_directory()`). Under a child theme the parent
  folder was searched, so the child's own translations never loaded.

## 5.4.0

Additive. `^5.3` consumers upgrade without a code change — without languages,
`path()` and `url()` register and build exactly what they did.

### Added

- Page routes per language. Filter `ntdst/pages/languages`
  (`list<array{code, prefix, query, default?}>`, default `[]`) and filter
  `ntdst/pages/current_language` (`?string`); core does not know who answers
  them — ntdst-baseline's polylang `route_languages` does.
- `path(string|array $pattern, …)`: one route entry per language sharing the
  callback, rule `^{prefix}/{words}/?$` with `&{query}` appended when the
  language has one. An array pattern is keyed by language code; a missing code
  uses the default's words, and with no languages the first entry. Variants
  with different placeholders are refused (`_doing_it_wrong`, no rule).
- `url(string|array $pattern, array $params = [], ?string $language = null)`:
  the given language, else the current, else the default; prefixed.
- `NTDST_Pages::language(): ?string` — the current language, `null` without.

## 5.3.0

Additive. `^5.2` consumers upgrade without a code change.

### Added

- `whereRelated(string $field, int $id)` on the chain — "which posts does this
  relation field point at `$id` from?". A relation is one meta row holding a
  serialized list of ints, so a LIKE on `i:12;` also matches the list's index
  keys (`i:1;` is value 1 and slot 1). The clause is an anchored `REGEXP` that
  walks the list as key/value pairs and only ever compares a value. It
  composes inside `whereGroup()`, which is how "credited under any role" is
  asked. First consumer: ntdst-baseline's reverse relation fields in the
  YOOtheme module (a person's productions), proven against MariaDB in
  laika's integration tier. It relies on relations being stored as a
  serialized list of ints (`FieldTypes::ids()`).

## 5.2.0

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
