# Architecture invariants — ntdst-core

The cross-cutting properties of this package and the ONE place each is decided.
Reviews flag bypasses and second homes against this list instead of re-auditing
the property. Authored 2026-08-23 at plan-time for `specs/core-shape`, from the
rulings in `docs/plans/2026-08-23-core-shape-brief.md` and
`docs/session-2026-08-21-actions-to-rest.md` §1.

Each entry says whether it **holds today** or is **established by** a phase of
`specs/core-shape`; the mechanical check is what `invariant-auditor` runs verbatim.
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
`register_post_meta()`. The declaration itself has ONE reader:
`NTDST_Data_Model::declaresRest()` — the strict `show_in_rest === true` test, written
once. Its callers are `restFields()` (which fields may leave), `schemaFor()` (in what
shape, asked again at EVERY depth — the recursion is the guard, so one silent
sub-field makes the whole field unpublishable) behind the public `restSchemaFor()`,
and `restSubFields()` (the same question one level down, kept because consumers read
it — FR-3). `registerRestMeta()` never tests the declaration itself; it decides
entirely through the first two. A further site re-spelling the predicate is a bypass
even when it agrees, because agreeing today is not the same as converging.
`registerRestMeta()` is `public` by necessity — `NTDST_Data_Manager::register()` is a
different class — and it fails closed on anything it cannot publish: a field with no
schema registers nothing, and a field whose type is foreign to the vocabulary throws
inside a per-field `try/catch` that unpublishes that one field rather than aborting
`init` and taking the post type off the site.
**Bypass smell:** `register_post_meta()` / `register_meta()` called outside
`api/Data.php`; a route handler returning a row or `getMeta()` bag without
projecting through `restFields()`; any `public_fields` / `public_shape` /
`PUBLIC_SHAPE`-style allow-list; a `private`, `hidden` or `exposed` key on a field.
**Mechanical check:** `grep -rn "register_post_meta\|register_meta(" --include=*.php . | grep -vE "^(\./)?api/Data\.php|(^|/)vendor/|(^|/)tests/"` → empty. (The `-E` form and the optional `./` are load-bearing: GNU grep prints `./api/Data.php` but ugrep prints `api/Data.php`, so an anchored `^./` exclusion silently matched nothing and the check passed for the wrong reason.) Second: `grep -rn "show_in_rest" --include=*.php api/Data.php` → every hit is `declaresRest()` itself, a docblock, or an ARG being written to `register_post_meta()` / `register_taxonomy()`; no second READER of the declaration. `grep -rn "public_fields\|public_shape\|publicRow" --include=*.php . | grep -vE "(^|/)vendor/|(^|/)tests/"` → empty. (`tests/` is excluded for a reason, not for convenience: `tests/Unit/DataDropsExposureTest.php` is the test that ENFORCES this ban, and it has to name the banned vocabulary in order to forbid it. Without the exclusion the check reports its own enforcement as the violation.) The `show_in_rest` check has one hit that is NOT about a field: `NTDST_Data_Manager::register()` reads `$args['show_in_rest']` of the POST TYPE, to warn when a declaration can reach no route. That is a different key on a different thing, not a second reader.
**Deliberate exceptions:**
- A model with no `label` registers no post type and therefore no meta, and warns once per model.
- A post type that is not itself in REST (`show_in_rest` absent or false) still registers its meta — WordPress emits none of it — and warns once per model.
- `json`, any partially-declared repeater, and a repeater that has no `sub_fields` are **not publishable at all**, and each warns once per model. Half a repeater is not half published: WordPress validates the stored row against the closed schema, so the value reads back `null`, a write carrying the undeclared key is refused 400, and a legal write drops that key from storage.
- A scalar registers `show_in_rest => true` rather than a schema, so `format` (email, uri) is advisory only. A `format` in the schema would validate stored legacy values and read them back as `null`; the model's sanitizer, not the schema, is what enforces the shape (DD-9).
**Status:** established by Cluster 1 (T02–T03); code holds at `b52b855`; flips to
holds-today at the release commit (FR-16).

## INV-2 — One HTTP surface: every route registers through `ntdst_rest()`

Reads and commands are REST routes, registered by the wrapper. There is no
command dispatcher, no `admin-ajax` handler, no second door.

**Convergence point:** `api/Rest.php` — `NTDST_Rest::registerOne()` is the only
caller of `register_rest_route()` in the package.
**Bypass smell:** `register_rest_route(` anywhere else; `add_action('wp_ajax_`;
an `ntdst/api_data/` or `ntdst/api/` dispatch filter; a route whose permission
is decided somewhere other than its registration.
**Mechanical check:** `grep -rn "register_rest_route(\|wp_ajax_\|ntdst/api_data\|ntdst_actions" --include=*.php --include=*.js . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/"` → empty.
**Status:** established by phase 3 (today `api/Actions.php` and
`admin/RelationField.php:47` violate it).

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
absent, `'logged_in'`, `'public'`, a namespace default and `->public()` all
reach the same rule. The two postures register as LITERAL STRINGS, so
`get_routes()` can be read back — except on a rate-limited route, where the
limiter has to run and `guard()`'s closure registers instead. `->public()`
marks the ONE pending declaration it was chained onto, and nothing else.
**Bypass smell:** `'permission' => '__return_true'` or a closure that returns
`true` unconditionally; `->public()` on a write; a capability check inside a
handler body instead of on the route; a second permission registry; a
`public_actions`-style list of names that may go without a gate.
**Mechanical check:** `grep -rn "__return_true\|=> 'public'\|->public()\|public_actions" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/"` → every route hit is a `GET`. Today the hits are: `services/Mailer.php:574`, a DOCBLOCK IDIOM (`add_filter('ntdst_wrap_all_emails', '__return_true')` — a filter switch, not a route permission), and `api/Actions.php`'s `$public_actions` + its `ntdst/api/public_actions` filter, which is the second permission registry this invariant forbids and which leaves with `Actions.php` in phase 3. A site asserts its anonymous surface with `rest_get_server()->get_routes($ns)` filtered on `permission_callback === '__return_true'` — WordPress's registry, not ours.
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
**Mechanical check:** `grep -rn "wp_create_nonce\|wp_verify_nonce\|check_ajax_referer\|get_nonce\|HTTP_ORIGIN\|HTTP_REFERER" --include=*.php --include=*.js api core admin assets` → empty. `grep -rn "fetch(" assets/js` → only `wp.apiFetch`.
**Status:** established by phase 3 (today `api/Actions.php` and `assets/js/ntdst-api.js` violate it).

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
**Mechanical check:** `grep -rn "private static array\|protected static array" --include=*.php api core admin support services` → each hit is either a WordPress-less concern (rate buckets, template dirs, declared limits), a named deliberate exception below, or a bypass.
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
**Mechanical check:** `grep -rn "is_404 = false\|redirect_canonical\|locate_template(\|extract(" --include=*.php api core` → only `NTDST_Template_Loader`, and `extract(` nowhere. `grep -rn "function addPath\|function redirect" --include=*.php api core` → one each.
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
  `allowed_http_origin` — with exactly one reader each
  (`filterAllowedOrigins()`, `filterAllowedOrigin()`), and nothing ever asks
  them whether an origin is allowed: that question has one address,
  `is_allowed_http_origin()`. They exist because a filter callback must be a
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
- **`NTDST_RateLimiter`, `NTDST_ClientIp`.** WordPress has neither.
