# CODE-MAP — decisions and traps

Rules this codebase decided, discovered the hard way, and enforces. No inventories, no
line numbers — those drift; the mechanical checks in `ARCHITECTURE-INVARIANTS.md` are
the counting home. Each entry says what holds and why. (Started at 5.0.0 spec-close,
2026-08-23.)

## Guards live in two homes, and the mirror is the contract
Every retired symbol is pinned in `bin/guard.sh` (runs on a fresh checkout, no vendor)
AND in `PackageBootIntegrityTest` (runs in the suite), and gets a `|` table row in
README's `## Versions` section — three homes, checked against each other by tests.
An exemption needs a discriminator the exempted shape cannot fake: a REST args schema
line is exempt only on REST-only vocabulary (`sanitize_callback`, `validate_callback`,
`items`, `enum`) — never `required`, which is live field-config vocabulary. README
prose outside `|` rows may never spell a retired symbol; call signatures README teaches
are guarded too (`ntdst_data(` with an argument does not exist).

## `callback` is a render directive, not a field type
A declaration may carry `'type' => 'callback'`; the vocabulary gate accepts it. The
MODEL skips it everywhere — no sanitizer bound, no schema row, no REST registration,
read back as stored — and only the metabox consumes it. Any reader of the declaration
that resolves types must ask `NTDST_FieldTypes::declaredType()` and skip `callback`,
or it re-introduces the fatal-at-`init` this rule closed.

## `ntdst/service/{slug}/config` is applied by the consumer
Core mounts a configured override as a priority-1 listener and never calls
`apply_filters` on that name itself; the apply site is inside the consumer's service
(the `getServiceConfig()` migration row; stride's `ServiceConfig` trait). A grep that
finds no apply site in core has found the design, not a bug — three reviewers did
before the comment landed.

## Page routes are rewrite rules with exactly one dispatcher
`NTDST_Pages::path()` registers a rewrite rule; one `template_redirect` callback
dispatches. The dispatcher runs only when WordPress's `matched_rule` is one of ours —
and on a foreign URL it PASSES THROUGH, never 404s: refusing there would let
`?ntdst_page=0` turn any URL on the site into a 404 (a cache-poisoning primitive).
`terminate(): never` is the one place a request the callback already answered ends
(recorded INV-6 exception). The loader mounts `{$type}_template` at priority 5 so a
consumer handler at WordPress's default 10 always wins.

## One template resolver, and the verified path is the returned path
`NTDST_Template_Loader::locate()` is the only resolution home. What `isInside()`
verified is what callers get and what the cache holds (`realpath`), the cache is keyed
per theme (a `switch_to_blog` must miss), and WordPress's `theme-compat` fallback is
deliberately refused. `$extraPaths` are trusted developer directories — request-derived
values there are an arbitrary-file-read by construction.

## A refusal is three lines, currently in two homes
`set_404()` alone leaves HTTP 200 on the wire (`WP::handle_404()` already queued it):
the refusal is `set_404()` + `status_header(404)` + `nocache_headers()`. It is written
in `NTDST_Pages` and `NTDST_Response`, recorded as a deliberate exception with the
convergence named (`Pages` calls `ntdst_response()->notFound()` when a shared require
is acceptable). An edit to one without the other is a defect — this pair drifted once.

## An ability is a route's shadow
`NTDST_Rest::registerOne()` records an `ability` option's declaration in `Rest::$abilities`; nothing is registered with WordPress there.
`mountAbilities()` (read by `ntdst-core.php`) hooks the two walks on `wp_abilities_api_categories_init` and `wp_abilities_api_init`, the only callers of `wp_register_ability_category()` and `wp_register_ability()`.
The `permission_callback` handed to WordPress is the route's resolved permission, never `guard()`; the execute callback is one `rest_do_request()`.
`bin/guard.sh` holds INV-11's grep: a `wp_register_ability` outside `api/Rest.php` fails the gate.

## Model revisions ride WordPress's filter, never `register_post_meta()`
`NTDST_Data_Manager::register()` gives a `show_ui` model `revisions` support (read from the registered post type, not the config; `'revisions' => false` opts out) and `registerRevisions()` hooks, once per model name: its prefixed field keys into `wp_post_revision_meta_keys` (`callback` fields skipped), a cap of `min(5, site setting)` on `wp_{type}_revisions_to_keep` (0 stays off, -1 reads as 5), and a restore guard.
- WordPress's restore step at priority 10 deletes every listed key and copies only what the revision holds, so the guard snapshots at 9 the keys the revision lacks and puts them back at 11, then takes one more revision (WordPress took its own before the fields came back).
- Autosaves never carry model fields: `add_post_metadata` / `update_post_metadata` refuse the keys on an autosave of the model, because WordPress copies raw `$_POST` / REST meta for a named key and restore would write it unsanitised. Do not "fix" that by routing the copy through `update()`.
- `NTDST_Data_Model::update()` writes fields before the post and saves the original first when a record has no revision yet. A revision restore changes fields without an `ntdst/model/*` hook: a deliberate exception.
