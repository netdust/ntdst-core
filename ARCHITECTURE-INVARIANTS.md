# Architecture invariants — ntdst-core

The cross-cutting properties of this package and the ONE place each is decided.
Reviews flag bypasses and second homes against this list instead of re-auditing
the property. Authored 2026-08-23 at plan-time for `specs/core-shape`, from the
rulings in `docs/plans/2026-08-23-core-shape-brief.md` and
`docs/session-2026-08-21-actions-to-rest.md` §1.

Each entry says whether it **holds today** or is **established by** a phase of
`specs/core-shape`, `specs/field-types` or `specs/core-trim` — INV-9 and INV-10
are core-trim's, and core-trim re-ran INV-8's two commands at its own merge
commit; the mechanical check is what `invariant-auditor` runs verbatim.
The governing rule behind all of them is `docs/philosophy.md` §1: wrap WordPress,
never replace it — where WordPress has a word, core uses it.

**Run the checks with the `-E` form.** `grep` on this machine is **ugrep**, which
prints a recursive hit as `api/Data.php` where GNU grep prints `./api/Data.php`.
An exclusion anchored on `^./` therefore matches nothing, and the check passes for
the wrong reason. Every exclusion below is written
`grep -vE "^(\./)?<file>|(^|/)vendor/|(^|/)tests/"` so it holds under both.

---

## INV-1 — A post-meta field reaches an HTTP surface only if its description names it

A field leaves the model only when its description says `show_in_rest => true`,
with WordPress's meaning: opt in, nobody-named-nobody-leaves. The model declares;
it never shapes a response.

**Convergence point:** `api/Data.php` — `NTDST_Data_Manager::register()` hands every
declared field to `NTDST_Data_Model::registerRestMeta()`, which is the only caller of
`register_post_meta()`. The declaration has ONE reader, `declaresRest()`, with two
callers, both private to the class: `restFields()` and `schemaFor()` (reached only
through `registerRestMeta()`); `restSchemaFor()`/`restSubFields()` were removed in
5.0.0 and guard.sh + PackageBootIntegrityTest pin them. `schemaFor()` asks the
question again at EVERY depth — the recursion is the guard, so one silent sub-field
makes the whole field unpublishable. `registerRestMeta()` never tests the declaration
itself; it decides entirely through those two. A further site re-spelling the
predicate is a bypass even when it agrees, because agreeing today is not the same as
converging. `registerRestMeta()` is `public` by necessity — `NTDST_Data_Manager::register()`
is a different class — and it fails closed on anything it cannot publish: a field with
no publishable shape registers nothing and is warned about once per model. A field
whose type is foreign to the vocabulary never reaches it: the model resolves every
type name when it is CONSTRUCTED, so a typo is a fatal at `register()` naming the
field, and `registerRestMeta()` needs no per-field `try/catch`.
**Bypass smell:** `register_post_meta()` / `register_meta()` called outside
`api/Data.php`; a route handler returning a row or `getMeta()` bag without
projecting through `restFields()`; any `public_fields` / `public_shape` /
`PUBLIC_SHAPE`-style allow-list; a `private`, `hidden` or `exposed` key on a field.
**Mechanical check:** `grep -rn "register_post_meta\|register_meta(" --include=*.php . | grep -vE "^(\./)?api/Data\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"` → empty. (The trailing filter drops COMMENT lines: prose may name `register_post_meta()` — `api/FieldTypes.php` says the sanitizer must be idempotent because that function runs it again — and a docblock is not a second caller.) (The `-E` form and the optional `./` are load-bearing: GNU grep prints `./api/Data.php` but ugrep prints `api/Data.php`, so an anchored `^./` exclusion silently matched nothing and the check passed for the wrong reason.) Second: `grep -rn "show_in_rest" --include=*.php api/Data.php` → every hit is `declaresRest()` itself (`:122`, the ONE reader), a docblock (`:128`, `:1811`), a WARNING STRING that names the key it is telling the author about (`:223`, `:266`, `:1834`, `:1954` — four literals, none of them a test), or an ARG being written to `register_post_meta()` / `register_taxonomy()` (`:206`, `:2006`). No second READER of the declaration. `grep -rn "public_fields\|public_shape\|publicRow" --include=*.php . | grep -vE "(^|/)vendor/|(^|/)tests/"` → empty. (`tests/` is excluded for a reason, not for convenience: `tests/Unit/DataDropsExposureTest.php` is the test that ENFORCES this ban, and it has to name the banned vocabulary in order to forbid it. Without the exclusion the check reports its own enforcement as the violation.) The `show_in_rest` check has one hit that is NOT about a field: `NTDST_Data_Manager::register()` reads `$args['show_in_rest']` of the POST TYPE, to warn when a declaration can reach no route. That is a different key on a different thing, not a second reader.
**Deliberate exceptions:**
- A model with no `label` registers no post type and therefore no meta, and warns once per model.
- A post type that is not itself in REST (`show_in_rest` absent or false) still registers its meta — WordPress emits none of it — and warns once per model.
- `json`, `array`, any partially-declared repeater, and a repeater that has no `sub_fields` are **not publishable at all**, and each warns once per model. Half a repeater is not half published: WordPress validates the stored row against the closed schema, so the value reads back `null`, a write carrying the undeclared key is refused 400, and a legal write drops that key from storage.
- A scalar registers `show_in_rest => true` rather than a schema, so `format` (email, uri) is advisory only. A `format` in the schema would validate stored legacy values and read them back as `null`; the model's sanitizer, not the schema, is what enforces the shape (DD-9).
**Status:** established by Cluster 1 (T02–T03); code holds at `42d7090`; flips to
holds-today at the release commit (FR-16).

## INV-2 — One HTTP surface: every route registers through `ntdst_rest()`

Reads and commands are REST routes, registered by the wrapper. There is no
command dispatcher, no `admin-ajax` handler, no second door.

**Convergence point:** `api/Rest.php` — `NTDST_Rest::registerOne()` is the only
caller of `register_rest_route()` in the package.
**Bypass smell:** `register_rest_route(` anywhere else; `add_action('wp_ajax_`;
an `ntdst/api_data/` or `ntdst/api/` dispatch filter; a route whose permission
is decided somewhere other than its registration.
**Mechanical check:** `grep -rn "register_rest_route(\|wp_ajax_\|ntdst/api_data\|ntdst_actions" --include=*.php --include=*.js . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"` → the hits Status names, and nothing else. (The comment filter is INV-1's, for INV-1's reason: `core/Theme.php`, `core/Pages.php` and `api/Actions.php` all NAME the v2 dispatcher in docblocks that explain what replaced it, and a docblock is not a second door.)
**Status:** established by phase 3. Today the live hits are all of them, and
there are seven: `ntdst-core.php:95` (`ntdst_actions()` booted at load),
`admin/RelationField.php:47` (`ntdst_actions()->register()`), and
`api/Actions.php:63`, `:132`, `:147`, `:716`, `:717` — the `ntdst/api_data/`
filter prefix, the two `register_rest_route()` calls this invariant forbids
outside `api/Rest.php`, and the `ntdst_actions()` accessor. All leave with
`Actions.php` in phase 3.

## INV-3 — Anonymous reach is named; internal is the default; a write names its capability

A route is reachable without login only through `->public()`. A route that says
nothing is `is_user_logged_in`. Only a READ verb (`GET`, `HEAD`, `OPTIONS`) may
carry that posture: any other verb — the four writes and every custom one, since
`PURGE` empties a cache and a proxy will route it — does not register unless it
names a capability or hands over its own callable. A string permission is a
CAPABILITY, never a function name.

This governs the routes CORE REGISTERS. The anonymous reach of declared DATA over
WordPress's own `/wp/v2` is not decided here — it is INV-1's decision, taken by the
field description, and `/wp/v2` is anonymous-readable by WordPress's design.

**Convergence point:** `api/Rest.php` — `NTDST_Rest::permission()` is the ONE
resolver: it maps every declared shape to WordPress's own callables
(`'__return_true'`, `'is_user_logged_in'`, `current_user_can`), and
`registerOne()` refuses on the RESOLVED POSTURE rather than on the spelling, so
absent, `'logged_in'`, a namespace default and `->public()` all
reach the same rule. The two postures register as LITERAL STRINGS, so
`get_routes()` can be read back — except on a rate-limited route, where the
limiter has to run and `guard()`'s closure registers instead. `->public()`
marks the ONE pending declaration it was chained onto, and nothing else.
**Bypass smell:** `'permission' => '__return_true'` or a closure that returns
`true` unconditionally; `->public()` on a write; a capability check inside a
handler body instead of on the route; a second permission registry; a
`public_actions`-style list of names that may go without a gate.
**Mechanical check:** `grep -rn "__return_true\|=> 'public'\|->public()\|public_actions" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"` → every route hit is a `GET`. The comment filter drops seven lines, and all seven are prose about `ntdst/api/public_actions`: `api/Actions.php:32`, `:70`, `:306`, `:670`, `:707`, plus `core/Theme.php:202` and `admin/RelationField.php:54`. A docblock explaining the filter is not a second permission registry. What is left is six lines, all in `api/Actions.php`: `:104` (the `$public_actions` property), `:186`–`:187` and `:246`–`:247` (the filter read and the membership test, once per dispatch path), and `:673` (the registration helper that adds to it). That IS the second permission registry this invariant forbids, and it leaves with `Actions.php` in phase 3. A site asserts its anonymous surface with `rest_get_server()->get_routes($ns)` filtered on `permission_callback === '__return_true'` — WordPress's registry, not ours.
**Status:** established by Cluster 2 (T04–T06); code holds at `d91e117` for
routes through `ntdst_rest()`; `Actions.php:132,147` + `$public_actions` register
their own routes outside it until phase 3; flips to holds-today at FR-16.

## INV-4 — CSRF and nonces are WordPress's; core mints nothing

A cookie-authenticated REST request carries the `wp_rest` nonce in `X-WP-Nonce`
and WordPress (`rest_cookie_check_errors()`) decides; a stale nonce is refreshed
by `wp.apiFetch` against `admin-ajax.php?action=rest-nonce`. A nonce is a CSRF
token, never access control.

**Convergence point:** WordPress — `rest_cookie_check_errors()` and the
`wp-api-fetch` nonce middleware. Core has no convergence point of its own, and
that is the invariant.
**Bypass smell:** `wp_create_nonce(` / `wp_verify_nonce(` / `check_ajax_referer(`
in `api/`, `core/` or `admin/`; a route that mints or returns a nonce; a `fetch()`
to `/wp-json/` in core's own JS that is not `wp.apiFetch`; an `Origin`/`Referer`
check standing in for the nonce.
**Mechanical check:** `grep -rn "wp_create_nonce\|wp_verify_nonce\|check_ajax_referer\|get_nonce\|HTTP_ORIGIN\|HTTP_REFERER" --include=*.php --include=*.js api core admin assets` → the hits Status names, and nothing else: `api/Actions.php:25` (a docblock listing the route), `:132`, `:134`, `:453`, `:454`, `:582`, `:592`, `:606` (core's own nonce mint and its `Origin`/`Referer` pair), `assets/js/ntdst-api.js:37`, and `admin/MetaboxGenerator.php:1864`. `grep -rn "fetch(" assets/js` → four raw `fetch()` calls in `ntdst-api.js`, none of them `wp.apiFetch`. Both leave with `Actions.php` in phase 3, except the metabox pair below.
**Status:** established by phase 3 (today `api/Actions.php` and `assets/js/ntdst-api.js` violate it).
One hit is NOT a violation and never was: `admin/MetaboxGenerator.php:1864`'s
`wp_verify_nonce(wp_unslash($_POST[$nonce_name]), …)`, paired with the
`wp_nonce_field()` calls at `:342` and `:712`. A classic-editor metabox is a
WordPress ADMIN FORM POST, and that pair IS WordPress's own CSRF gate for one —
core mints nothing of its own there and invents no second token. This invariant is
about core's HTTP surface, where the token is `wp_rest` and
`rest_cookie_check_errors()` decides.

## INV-5 — Core keeps no table WordPress already keeps

A registry, allow-list or lookup table in core exists only for something
WordPress has no table for. Known WordPress tables: routes
(`WP_REST_Server::get_routes()`), allowed origins (`allowed_http_origins`),
MIME types (`wp_get_mime_types()` + `mime_types`), the template hierarchy
(`{$type}_template_hierarchy` → `{$type}_template`), rewrite rules
(`add_rewrite_rule()`), JSON envelopes (`wp_send_json_*`, `WP_Error`).

**Convergence point:** the WordPress function or filter, named in the calling
code. `docs/philosophy.md` §1 is the rule; the brief's D2b–D2e tables are the
audit that applied it.
**Bypass smell:** a `private static array $…` in core that lists things; a class
that re-implements a lookup WordPress's own function answers; a hand-written
list of template names; a `{success, data}` array built by hand.
**Mechanical check:** `grep -rnE '(private|protected) +(static +\??array +\$|const +)[A-Za-z_]+ *= *(\[|null)' --include=*.php api core admin support services` (SINGLE quotes: in double quotes the shell eats `\$` and the variable half of the pattern silently matches nothing) → each hit is either a WordPress-less concern (rate buckets, template dirs, declared limits), a named deliberate exception below, or a bypass. (Broadened from `private static array`: a list hidden as a `const`, or as a `?array` built lazily, is the same second table. `NTDST_FieldTypes::$table` and `NTDST_FieldTypes::RETIRED` are the named exception — the field VOCABULARY is a thing WordPress has no table for, so it is INV-8's convergence point, not an INV-5 bypass.)
**Status:** `Rest::$surface` and `Rest::$cors['origins']` are gone at `d91e117`
(Cluster 2, T05–T06): the route register is `WP_REST_Server::get_routes()` and
the origin list is `allowed_http_origins`. Outstanding, for phase 4:
`Response::$mimeTypes`, `Template_Loader::templateInclude()`'s hand-listed
hierarchy, the `api*` envelopes.

## INV-6 — One template resolver; page routes are rewrite rules; a template callback returns a path

Every template path resolves through `NTDST_Template_Loader::locate()`. A page
URL core owns is a WordPress rewrite rule, so WordPress parses it; nothing
un-404s a request or suppresses `redirect_canonical`. A callback on
`template_include` / `{$type}_template` returns a path and never exits.

**Convergence point:** `NTDST_Template_Loader::locate()` (its own file after
phase 4); `NTDST_Pages::path()` → `add_rewrite_rule()` + the `query_vars` filter
+ dispatch on `template_redirect`; data reaches a template only through
`NTDST_Template_Loader::page()` / `ntdst_page_data()`.
**Bypass smell:** `include`/`require` of a template outside the loader;
`locate_template(` outside `locate()`; `$wp_query->is_404 = false`; a
`redirect_canonical` filter; `exit` inside a template filter callback; a second
way to pass data to a template (`extract()` over a caller array); a second
`addPath`.
**Mechanical check:** `grep -rn "is_404 = false\|redirect_canonical\|locate_template(\|extract(" --include=*.php api core` → the hits Status names: `core/Pages.php:53` (a `redirect_canonical` filter) and `:380`, and `api/Response.php:273`, `:290`, `:311`, `:667` (two `extract()` calls over a caller array, a second `is_404 = false`, and a `locate_template()` outside the loader). `grep -rn "function addPath\|function redirect" --include=*.php api core` → two each, not one: `api/Response.php:126` + `:579` and `api/Response.php:235` + `core/Pages.php:458`. Phase 4 takes both counts to one.
**Status:** established by phase 4 (today `core/Pages.php` and `api/Response.php` violate every clause).

## INV-7 — Throttling is one primitive, charged from the permission callback

Every rate limit in the package and its consumers counts through
`NTDST_RateLimiter`, keyed by `NTDST_Rest::bucket()`, and is charged only after
the permission has passed — a refused caller never makes the site write.

**Convergence point:** `support/RateLimiter.php`; `api/Rest.php` —
`NTDST_Rest::guard()` (order) and `charge()` (the public spend).
**Bypass smell:** a transient or option that counts attempts outside
`RateLimiter`; `attempt()` called before the permission check; a limiter keyed
on `$_SERVER['REMOTE_ADDR']` instead of `NTDST_ClientIp`.
**Mechanical check:** `grep -rn "set_transient\|get_transient" --include=*.php api core admin services support` → only `support/RateLimiter.php` counts attempts. (`support` is in the list on purpose: without it the check never reads the one file it is about. `admin/MetaboxGenerator.php`'s `SAVE_ERROR_TRANSIENT_PREFIX` is a hit and not a counter — it parks one save-error MESSAGE for one redirect and is consumed on read.) In `guard()`, `$permission($request)` precedes `RateLimiter::attempt`.
**Status:** holds today (`f42c732`); baseline's login throttle converges on it (`ntdst-baseline` `51f7e2e`).

## INV-8 — One field-type vocabulary; a type name resolves in one table

Every field type name on this site resolves in `NTDST_FieldTypes`. One entry says
what sanitizes a value, what it publishes as, what draws it, whether it may sit in
a repeater row, and how it reads back. A name used to mean four things in four
files, and four tables drift: a `bool` was cleaned one way on the metabox path and
another on the model path, and adding a type meant editing seven places.

The set is CLOSED — 17 names, no filter and no registration method. A pluggable
vocabulary is one a plugin can widen with a type whose sanitizer is a no-op.

**Convergence point:** `api/FieldTypes.php`. `NTDST_FieldTypes::get()` is the one
read of the table; `declaredType()` is the one answer to "what type does this
declaration NAME"; `rowKey()` is the one answer to "what key is a cell stored
under"; `assertDeclarations()` is the one place a `fields` array is judged. The
model's constructor and `NTDST_MetaboxGenerator::register()` both call it, and
neither keeps a copy of a rule.
**Bypass smell:** a `match` / `switch` / `===` over a TYPE NAME anywhere else; a
second list of type names (a `const TYPES`, a `$sanitizers` map built from
literals, a metabox `sanitize_field()`); a default type spelled by hand; a
per-caller copy of the row-key rule.
**Mechanical check:** TWO commands, both run from the package root.

`N` is the name list, and it is REGENERATED from the registry rather than typed:

```sh
php -r 'define("ABSPATH", __DIR__); require "api/FieldTypes.php";
  $n = NTDST_FieldTypes::names();
  $r = array_keys((new ReflectionClass("NTDST_FieldTypes"))->getConstant("RETIRED"));
  $c = array_map(static fn (string $t): string => NTDST_FieldTypes::get($t)->control, $n);
  $all = array_unique(array_merge($n, $r, $c, ["callback"])); sort($all);
  echo implode("|", $all), "\n";'
```

That prints the list below. A type added to the registry changes it, and a list
typed by hand would not — which is how a check goes quiet without anyone
deciding it should.

```sh
N='array|bool|boolean|callback|checkbox|content|date|datetime|decimal|double|email|file|float|gallery|html|image|int|integer|json|longtext|media|number|person|post_relation|relation|repeater|select|signed_int|string|text|textarea|url|wysiwyg'

# (A) a switch/match head, a case, a type-name pair, a MAP KEY, a declaration, a comparison
grep -rnE "(switch|\bmatch) *\(|case '($N)'|'($N)' *, *'|'($N)' *=>|=> *'($N)'|[!=]== *'($N)'"'|[!=]== *"('"$N"')"' \
    --include=*.php . \
  | grep -vE "^(\./)?api/FieldTypes\.php|(^|/)vendor/|(^|/)tests/|(^|/)specs/" \
  | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"

# (B) a second TABLE of them
grep -rnE '(const|static +\??array +\$)[ A-Za-z_]*[Tt][Yy][Pp][Ee][Ss]?[A-Za-z_]* *=' \
    --include=*.php . \
  | grep -vE "^(\./)?api/FieldTypes\.php|(^|/)vendor/|(^|/)tests/|(^|/)specs/"
```

Every part of (A)'s form is load-bearing:

- The dir list is `.` with INV-1's exclusions, not a hand-kept list of five
  directories. A hand-kept list answers for the directories somebody remembered;
  `ntdst-core.php` and any file added at the root were never in it. The
  `(^|/)specs/` exclusion joins vendor and tests for the same reason those two
  are there: a spec NAMES the retired vocabulary in order to retire it.
- The comparison alternative is SUBJECT-AGNOSTIC. The first version pinned it to
  `$…type…` and missed `declaredType() === 'repeater'` and
  `($config['type'] ?? '') === 'image'` — the two shapes a real second vocabulary
  is written in.
- The MAP-KEY alternative `'($N)' *=>` is what surfaces a second table written as
  a literal map. Without it, `$sanitizers = ['int' => 'intval', 'bool' =>
  'boolval']` returns NO hit at all — the exact bypass smell this invariant
  names, invisible to the check that names it. It is the noisiest alternative
  (29 of the 59 hits), because a type name is also an ordinary array key; every
  one of those is grouped and named below.
- `(switch|\bmatch) *\(` is restricted ONLY by the file exclusions, so a
  `match ($entry->control)` head surfaces and has to be justified. The `\b` keeps
  `preg_match(` out; without it every regex call in the package is a hit.
- The double-quoted alternative reads the media-picker JS that
  `admin/MetaboxGenerator.php` emits inline. Inline JS compares with `"`, so a
  single-quoted pattern never looks at it.
- The name list carries the 17 canonical names, the 13 in `RETIRED`, the two
  CONTROL names that are not types (`media`, `checkbox`), and `callback` — the
  render directive that has no entry.
- The last filter drops COMMENT lines, the way INV-1's does. A docblock reading
  `@param string $field Field to match (term_id, …)` is prose, not a switch.

(A) returns **51 lines** and (B) returns **1**. Every one of them is named in
`## Deliberate exceptions` below, grouped by WHAT it is rather than by where it
sits. Anything else is a bypass. (It was 59 while `services/Logger.php` still
declared the `log_entry` model; core-trim FR-5 deleted that model and took eight
hits with it, and FR-4 took the `meta_query` `['relation' => 'OR']`.)

**Deliberate exceptions:** in the file-wide `## Deliberate exceptions` section,
under "INV-8 — every hit the field-type check returns". They live there once.
A second copy here is the same defect this invariant is about: two lists of the
same thing, free to disagree.

**Status:** established by field-types Clusters A–C, re-pinned by core-trim T13.
(A) 51 hits / (B) 1, all named; code holds at `3b41562` — both commands were
re-run verbatim there. The count moved from 59 to 51 because core-trim DELETED
code, not because the check was relaxed: `services/Logger.php` has no hit left
(−8: five field declarations, one `orderby` value, two `url` payload keys) and
`api/Data.php` lost its `['relation' => 'OR']` (−1), while
`admin/RelationField.php` gained an `'orderby' => 'date'` (+1). Per file, (A) is
now MetaboxGenerator 28, Data 11, Rest 3, RelationField 3, Pages 2, Actions 2,
LogLevel 1, Response 1.

## INV-9 — A public symbol has a reader, or it is a published extension point

Every public method, global function and hook of core is read by somebody: by
core outside the file that defines it, by one of the four consumer sites, or —
for a published extension point — by consumer code written after the release,
in which case README names WHO. A symbol with no reader at all is not an API. It
is a thing that must keep working, keep being tested, and be understood by the
next reader of the file, in exchange for nothing. core-trim deleted ~45 of them.

**Convergence point:** `bin/zero-readers.sh`, and README's
`#### Extension points` table. The script decides the question for hooks and
global functions; the table is the only way to exempt one, and the script
refuses an exemption the table does not carry, so "documented extension point"
is a fact a machine checks rather than a claim.
**Bypass smell:** a `public` method added beside its only caller in the same
file; a hook fired "so consumers can hook it" with no consumer; a helper added
because the shape looked symmetrical (`ntdst_inline()` beside
`ntdst_download()`); an exemption added to the script's `EXCEPTIONS` list with a
reason that does not name a reader.
**Mechanical check:**

```sh
bash bin/zero-readers.sh | wc -l   # 0
```

Every line on stdout is a finding; notes and the advisory list go to stderr, and
the exit code is 1 when stdout is not empty. The script searches the package
plus the roots in its own `CONSUMER_ROOTS` array — that array is the list, and
this document does not keep a second copy of it. `vendor/` and `tests/` are
excluded: a vendored copy of core is not a reader of core, and a test that names
a symbol in order to assert its shape is not a consumer of it. A consumer root
that is not on this machine is a FINDING, never a skip: a sweep that quietly
drops four roots prints 0 for the wrong reason.

The script answers for HOOKS and GLOBAL FUNCTIONS. It cannot answer for an
INTERFACE at all — an implementer names the interface in a `implements` clause
in a repository the sweep may never see — so for `NTDST_Service_Meta` README's
table is the only check there is.

Three details are load-bearing, and each was got wrong first:

- **`grep -e`.** This machine's grep is ugrep, which reads a pattern beginning
  with `-` as an option, and `->NAME(` is exactly that shape.
- **The methods half is ADVISORY and prints to stderr.** A name search is
  receiver-blind and wrong in both directions: `->register(` cannot tell
  `NTDST_Bootstrap::register()` from `NTDST_Data_Manager::register()`, so a
  common name is masked by any other class's caller; and the reader of
  `render_metabox()` is WordPress, through a callback the defining file
  registers on itself. The script removes that second class of false positive by
  counting the array-callable shape `[$this, 'NAME']` as a reader, including in
  the defining file — no grep removes the first. So the half runs on every
  sweep, prints a candidate list a human reads, and does not fail the gate. The
  hooks and globals halves are exact, and they are what the gate counts.
- **A dynamic hook is searched by its literal stem.**
  `"ntdst/service/{$slug}/config"` is searched as `ntdst/service/`, because the
  reader writes the interpolated name.

**Deliberate exceptions:** 19 published symbols, each with its reader named in
README's `#### Extension points` table (the human home) and its reason in
`bin/zero-readers.sh`'s `EXCEPTIONS` array (the machine home). This document
kept a third copy and it went stale; the two homes above are the list.
**Status:** established by core-trim Clusters B and C. Holds at `3b41562` —
stdout empty and exit 0, with all twelve consumer roots present and 28 advisory
method candidates on stderr. The file and line totals the run prints are NOT
recorded here: they move whenever a consumer repository does, and a status line
that goes stale on somebody else's commit teaches a reader to skip it.

## INV-10 — Core loads nothing by guessing

Core installs no autoloader, scans no directory, parses no PHP source and
derives no file path from a class name. A listed service class is one PHP can
already resolve — by the consumer's `require_once`, by Composer, or by any
autoloader the consumer installed — and `register()` refuses anything else.
Before 5.0.0, Bootstrap stripped `basename(get_stylesheet_directory())` off a
namespace to build a path, `require_once`'d a `*Service.php` glob under
`services.discovery_paths`, and regex-parsed the file for its class name: a
writable directory on that list was code execution.

**Convergence point:** `core/Bootstrap.php::registerService()`. `class_exists()`
is the whole admission test (`:408`), after a legal-class-name check that runs
BEFORE it because `class_exists()` hands the string it was given to every
registered autoloader. A refusal is one `_doing_it_wrong()` naming the class and
the sector, plus an error-level log line — `_doing_it_wrong()` is `WP_DEBUG`-
gated, and a missing service on a live site would otherwise be silent.
**Bypass smell:** `glob()`, `scandir()`, `opendir()`, a `require` with a
variable in it, `spl_autoload_register()`, `file_get_contents()` on a `.php`
path, a regex over `namespace`/`class`, a class name concatenated with `.php`,
a second reader of the `services` config key.
**Mechanical check:** four commands, all from the package root.

```sh
# (1) no scanner, no parser, no autoloader, in the two files that load things
grep -c "glob(\|file_get_contents(\|preg_match('/^\\\\s*namespace\|spl_autoload_register" \
    core/Bootstrap.php ntdst-core.php

# (2) no file path derived from a class name, package-wide — five shapes:
#     `$class . '.php'`, `'.php' . $class`, `"{$class}.php"`, a CALL whose
#     arguments mention a class variable concatenated with a `.php` literal
#     (the deleted line was `str_replace('\\', '/', $class) . '.php'`), and a
#     sprintf whose arguments carry one. `[$]` and never `\$`: bash reads
#     `$[…]` as arithmetic expansion, and a mangled pattern returns nothing on
#     every input — which looks exactly like a pass.
grep -rnE "[Cc]lass[A-Za-z_]* *\. *['\"][^'\"]*\.php|['\"][^'\"]*\.php['\"] *\. *[$][[:alpha:]_]*[Cc]lass|\{[$][[:alpha:]_]*[Cc]lass[A-Za-z_]*\}[^'\"]*\.php|\([^)]*[$][[:alpha:]_]*[Cc]lass[A-Za-z_]*[^)]*\) *\. *['\"][^'\"]*\.php|sprintf *\([^)]*[$][[:alpha:]_]*[Cc]lass" \
    --include=*.php . | grep -vE "(^|/)vendor/|(^|/)tests/|(^|/)specs/"

# (3) the `services` config key has exactly ONE reader
grep -rln "\['services'\]" --include=*.php . | grep -vE "vendor|tests|specs"

# (4) every ntdst_set() of a class, and the gate in front of it
grep -rn "ntdst_set(" --include=*.php api core admin services support ntdst-core.php
```

(1) prints `0` for `core/Bootstrap.php` and `0` for `ntdst-core.php` — an
unordered pair; grep prints the files in the order it was given them, and a
reader checking a fixed order is checking the argument list. (2) is empty. (3) is
`core/Bootstrap.php`, one file. (4) has one call that takes a class —
`core/Bootstrap.php:501` — and the only gate reached before it is
`class_exists()` at `:408`; the other hits are `core/Container.php`'s own
declaration and docblock examples, and `core/Theme.php:48` registering an
instance of itself, which guesses nothing.

The PROOF of this invariant is the pair of autoload-recorder tests —
`BootstrapRefusesMalformedServiceListsTest` and
`BootstrapWalksItsServiceListOnceTest` — which register an autoloader, drive
`register()`, and record every path core asked for. (2) is the cheap net beside
them: a grep cannot prove a negative about runtime, but it fails on a fresh
checkout with no vendor/ and no PHP run, and it names the shape in a line a
reviewer can read.

**Deliberate exceptions:**
- **`basename(str_replace('\\', '/', $class))`** (`core/Bootstrap.php:835`)
  derives the short NAME for the slug, never a path. It is fed to the config
  filter key, not to `require`.
- **`core/Container.php`'s `new $class`** is a variable class name and it is
  gated: the container instantiates only what was `set()`, and `set()` is
  reached through `register()`'s `class_exists()`.
- **A `conditional` entry's condition must be a Closure or an array.** Anything
  else is refused. A string condition would be a callable name resolved at boot
  — the same guess, in a different key.
**Status:** established by core-trim T01 (FR-1). Holds at `3b41562` — the four
commands above were run verbatim and returned `0`/`0`, empty, one file, and the
single gated `ntdst_set($class)`.

---

## Deliberate exceptions

Things core does that WordPress also does, kept on purpose. Each names why.

- **`NTDST_Rest::cors()` replaces `rest_send_cors_headers()`.** WordPress's
  handler reflects any `Origin` with `Allow-Credentials: true` — a footgun, not
  a policy. Core's emitter fails closed. The *list* is WordPress's
  (`allowed_http_origins`, INV-5); only the REST emitter is ours.
- **`NTDST_Rest` refuses a route with a typo'd option.** WordPress passes unknown
  keys through silently; a control the author believes is on and isn't is worse
  than a refused route.
- **`NTDST_Rest::$corsOrigins` / `$corsResolvers` are not an allow-list.** They
  are the INPUT to WordPress's own filters — `allowed_http_origins` and
  `allowed_http_origin` — with one filter reader each (`filterAllowedOrigins()`, `filterAllowedOrigin()`); `corsDecisionFor()` reads `$corsOrigins` only for the per-origin credentials flag, never for allow-ness — that question has one address, `is_allowed_http_origin()`. They exist because a filter callback must be a
  NAMED static to de-duplicate, and a named static has nowhere to close over.
  `$corsOrigins` maps origin → the credentials the declaration that named it
  asked for, so one module's grant does not reach another module's origin.
- **`NTDST_Rest::$defaults`.** Per-namespace option defaults; WordPress has no
  namespace-level route defaults to converge on.
- **`NTDST_Rest` memoizes the permission per request.** WordPress calls
  `permission_callback` in dispatch and again in `rest_send_allow_header()`;
  a capability check twice per request is waste, not a property. The two
  SHORTHANDS are exempt from it on purpose: they register as bare core function
  names (`'is_user_logged_in'`, `'__return_true'`) rather than memoized
  closures, because a core function has no side effect worth memoizing and a
  literal string is the only thing `get_routes()` can be read back for (INV-3).
  A rate-limited route is the exception to the exception — a budget still has to
  be spent, so it registers `guard()`'s closure and trades that readability.
- **`NTDST_Template_Loader` searches plugin template directories.**
  `locate_template()` is theme-only; a package that ships templates needs a
  registry WordPress does not have. The hierarchy *names* stay WordPress's.
- **`NTDST_Response::downloadHeaders()`.** WordPress has no Content-Disposition
  helper; the RFC 5987 filename pair and `Content-Length` are the policy one
  consumer (daan's press kit) got three-quarters right by hand. `nosniff` is
  `send_nosniff_header()`.
- **`NTDST_FieldTypes::nested()` is not `map_deep()`.** WordPress's helper
  applies one callback to every leaf and leaves the keys alone. `array` and `json`
  need the opposite on both counts: a typed scalar must stay typed (a JSON `false`
  must not become `""`), and a KEY is a meta-ish identifier, so it goes through
  `sanitize_key()` while the VALUE goes through `sanitize_text_field()`. One
  callback cannot say two things. The recursion is ours; the two cleaning functions
  are WordPress's.
- **`=== 'repeater'` and `=== 'relation'` outside `api/FieldTypes.php`.**
  `api/Data.php` asks whether a field publishes an OBJECT schema rather than a
  leaf; `admin/RelationField.php` picks the relation fields out of a declared
  `fields` array. Both are STRUCTURAL questions about one type, not a second
  vocabulary — the registry has no "is structural" column, and adding one to
  answer three lines is the more expensive copy. INV-8's group below names them,
  and the check surfaces them on every run.
- **`NTDST_RateLimiter`, `NTDST_ClientIp`.** WordPress has neither.
- **`ntdst/service_before_boot/{class}` and `ntdst/service_after_boot/{class}`
  are fired with no reader** (INV-9). They are the documented way to wrap ONE
  named service's boot, and the `{class}` half means the reader's spelling
  cannot appear in this repository at all. README's `#### Extension points`
  table is the list; `bin/zero-readers.sh` refuses to exempt a name that table
  does not carry.
- **`NTDST_Service_Meta` has no implementer in core** (INV-9). It is the
  optional service shape — what a service may declare — and the fleet ships its
  implementers outside the roots the sweep searches: six in bavi, and dozens in
  netdust-legacy. An interface with no implementer HERE is the normal state of a
  published contract, so it is kept and named rather than deleted. The count is
  a hand count on purpose: the sweep cannot enumerate an interface, so no
  machine check will correct this number when it drifts.
- **Seven of the 19 `EXCEPTIONS` rows are INERT** (INV-9). Drop the row and the
  sweep still says nothing, because the symbol has a reader the script can see
  or is a shape it cannot judge: `ntdst/model/created`, `ntdst/model/updated`,
  `ntdst/model/registering`, `ntdst/service/`, `ntdst_container`,
  `NTDST_Bootstrap::config()` and `NTDST_Service_Meta`. They are kept so the
  array reads as the WHOLE published set — a reader should not have to ask which
  extension point was left out for being called somewhere. The other twelve are
  load-bearing: drop one and a finding appears.
- **The 13 retired type names are guarded by DECLARATION POSITION, not as bare
  words.** `signed_int` is a distinctive token and is pinned bare
  (`bin/guard.sh`, `removedSymbolProvider()`). The other 12 are ordinary
  JSON-Schema and English words — a bare sweep hits 617 shipped lines, and
  `api/FieldTypes.php` legitimately writes `['type' => 'integer']` as a publish
  column. They are pinned as `'type' => '<retired>'`, a bare shorthand value,
  and `new NTDST_FieldType('<retired>'`, with one line-anchored exemption for
  the RETIRED rows and the vocabulary rows. The exemption anchors to the ROW,
  never to the file: a real `new NTDST_FieldType('integer', …)` still fires
  inside the file that retires the name.

---

### INV-8 — every hit the field-type check returns

(A) returns 51 lines and (B) returns 1. Each group below says WHAT the hits are,
not which line they sit on: a line number is stale after the next edit, and a
reader matching 59 greps against stale numbers stops reading. Where a group has
a test that holds it, the test is named — that is its mechanical home.

- **A `match`/`switch` head over something that is not a type name** — 4 hits.
  `api/Rest.php`'s `match (true)` refusal cause and `match ($permission)` posture
  resolver, `core/LogLevel.php`'s `match ($this)` over an enum, and
  `api/Data.php`'s `match ($key)` over WordPress POST COLUMN names.
- **`admin/MetaboxGenerator.php`'s `match ($control)` head and its fifteen
  arms** — 16 hits. The one control switch, and it reads `$control` OFF the
  registry entry. It is the rendering half of the same entry, not a copy of it:
  a new type adds one arm here and nothing anywhere else. Mechanical home:
  `MetaboxGeneratorRenderTest::testNoTypeNameSwitchSurvivesInTheSource`, which
  fails if a TYPE-name switch grows back in that file.
- **The `callback` render directive** — 3 hits in `admin/MetaboxGenerator.php`,
  one on the render side and two on the save side. `callback` has no entry on
  purpose: the field draws itself and the consumer's code owns what it stores.
  Both sides must step past it before they ask the registry anything, or a
  posted `callback` field throws and kills the whole edit screen. Same
  mechanical home.
- **The `media` arm** — 4 hits in `admin/MetaboxGenerator.php`: one PHP test of
  `image` against `file` to choose the picker's library, and three `mediaType`
  comparisons in the picker JS it emits inline. One control serves two types,
  and which of the two is a fact only the declaration holds. Same mechanical
  home.
- **CONTROL-name comparisons** — 5 hits in `admin/MetaboxGenerator.php`:
  `checkbox` and `media` carry no native `required`; `select` and `json` take no
  readonly attribute; `decimal` displays differently; a row cell whose control
  is `media`; `relation`, `gallery` and `repeater` clear when they are absent
  from the POST. Each asks about the CONTROL the registry entry names, never
  about the declared type name. That is the direction this invariant wants.
- **`admin/RelationField.php`'s two `=== 'relation'` selectors** — 2 hits. They
  pick the relation fields out of a declared `fields` array. Structural, and
  INV-2 moves this file in phase 3.
- **`api/Data.php`'s `=== 'repeater'`** — 1 hit, the one structural test: a
  repeater publishes an OBJECT schema built from `sub_fields`, not a leaf. The
  registry has no "is structural" column, and one test is cheaper than one more
  column.
- **JSON-Schema type words** — 2 hits in `api/Data.php`:
  `in_array($type, ['array', 'object'], true)` and a `'type' => 'array'` being
  written INTO a schema. They are the same two words as two field types and an
  entirely different vocabulary. This is the false-positive SHAPE to recognise,
  not a second table.
- **WordPress's own vocabularies** — 3 hits. `date` as a `WP_Query` `orderby`
  value in `api/Data.php` and in `admin/RelationField.php`, and as a column name
  (`'post_date' => 'date'`). The Cluster B gate named RelationField's hit for the
  ordinary-array-key group below; it sits here instead, beside the identical
  `orderby` in `api/Data.php`, because this section groups by WHAT a hit is and a
  WP_Query sort value is one thing written in two files. `services/Logger.php`'s
  `orderby` was the third hit until core-trim deleted the model that used it.
- **The Logger model declaring its own fields** — 0 hits, and the group is kept
  as a NOTE rather than deleted. `services/Logger.php` declared five fields by
  canonical name, which was the registry being USED — the thing this invariant
  exists to make possible. core-trim FR-5 removed the `log_entry` post type, so
  the example is gone; the shape it named is still the one to recognise, and a
  reader comparing this list to an older run needs to know why eight hits left.
- **MAP KEYS that are not type names** — 11 hits, all from the map-key
  alternative, in four shapes. WordPress's own `callback` ARGUMENT key
  (`api/Actions.php`, `api/Rest.php`, `core/Pages.php` — 5). WordPress POST
  COLUMN and query words as array keys in `api/Data.php`: the `content` column
  map, its `match ($key)` arm, and `content`/`date` in a projected row (4) — the
  `meta_query` `['relation' => 'OR']` left with `orWhere()`. One payload key
  named for what it carries: `url` in `api/Data.php`'s attachment payload (1);
  the two in `services/Logger.php` went with the model. And `api/Response.php`'s
  `'json' => 'application/json'` MIME entry (1), which is (B)'s hit as well.
- **(B)'s one hit — `api/Response.php`'s `$mimeTypes`.** MIME types, not field
  types: the `[Tt][Yy][Pp][Ee][Ss]?` fragment catches the word. It is an INV-5
  item — WordPress keeps that table as `wp_get_mime_types()` — and INV-5 already
  lists it as outstanding for phase 4.
