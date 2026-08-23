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
