# core-shape — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ntdst-core 5.0.0 where `Data` declares, `Rest` is the only HTTP surface
(internal by default), `Actions` is gone, and WordPress does everything it has a
word for — no second registry, nonce, JSON envelope, MIME table, template
hierarchy or URL parser inside core.

**Architecture:** Five phases on one repo. `Data::register()` hands
`show_in_rest` fields to `register_post_meta()` (WordPress emits them). `Rest`
resolves three permission states to WordPress's own callables and refuses an
unnamed write; its CORS list becomes `allowed_http_origins`; its route registry
is deleted. `RelationField` moves onto a `Rest` route and `wp.apiFetch`, then
`Actions.php` and its client are deleted. `Pages::path()` becomes rewrite rules;
`NTDST_Template_Loader` gets its own file and one `locate()`; `Response` keeps
the file-response policy and `html()`, loses every envelope and table WordPress
owns. Theme is trimmed, README documents every break, `v5.0.0` is tagged.

**Tech Stack:** PHP 8.2+, WordPress 7.0.4 (`~/Sites/daan/web/wp` is the source
of truth for every WP claim), PHPUnit 10 + Brain Monkey (`composer gate`), daan's
DDEV for the observables through a composer `path` repository.

**Spec:** `specs/core-shape/spec.md` (revision 1) ·
**Invariants:** `ARCHITECTURE-INVARIANTS.md` INV-1…7 ·
**Brief:** `docs/plans/2026-08-23-core-shape-brief.md` (the WordPress-source citations)

**Repos:** CORE = `~/Sites/ntdst-core` (all code). DAAN = `~/Sites/daan`, touched
only on the never-merged branch `chore/core-path-repo` (FR-14).

## Global Constraints

- Target release **v5.0.0**, breaking, unreleased; no alias, shim or forwarder for anything removed (spec D3, Assumptions).
- **Where WordPress has a word, core uses it** (D8). A task that adds a static table, an envelope, a nonce or a URL matcher to core is wrong by construction.
- **Nothing leaves unless named**: `show_in_rest => true` on a field, `->public()` on a route (INV-1, INV-3).
- A write verb with no capability **does not register** (FR-4).
- Every task's suite runs through `cd ~/Sites/ntdst-core && composer gate` (syntax · phpunit · `bin/guard.sh` · `composer audit`). `bin/guard.sh` and `tests/Unit/PackageBootIntegrityTest.php` carry the removed-symbol lists and must be extended by the task that removes a symbol.
- No file is copied by hand into any site's `mu-plugins/ntdst-core` (FR-14, SC-10).
- Sites are consulted to **count** consumers, never to design (D5).

---

## Stakes

Stakes: high — phase 1 publishes post meta to anonymous callers on `/wp/v2`, and
phase 2 changes the permission default of every route in the package. A wrong
default or a wrong schema is disclosure on live sites (daan, todai-client the
moment they update), and the fleet will take this release as a whole.

Per-cluster refinement:
- **Cluster 1 (Data declares):** high — the anonymous-exposure surface.
- **Cluster 2 (Rest default):** high — authorization default and CORS.
- **Cluster 3 (Actions out):** high — removes a CSRF mechanism and moves an admin route's gate.
- **Cluster 4a (Loader):** standard — template resolution; a wrong file is visible, not a leak.
- **Cluster 4b (Pages, Response):** standard — routing and headers; `downloadHeaders()` keeps its nosniff/Disposition policy unchanged.
- **Cluster 5 (Theme, docs, release):** standard — the tag is irreversible and is a `[HUMAN]` yield.

---

## Architecture invariants touched

From `ARCHITECTURE-INVARIANTS.md` (authored 2026-08-23 for this spec; each says "established by phase N"):

- **INV-1** — a field reaches an HTTP surface only if named. Established by Cluster 1 (T02, T03). Mechanical check: `register_post_meta` only in `api/Data.php`; no `public_fields`/`publicRow` anywhere.
- **INV-2** — one HTTP surface through `ntdst_rest()`. Established by Cluster 3 (T07, T08). Check: no `register_rest_route(`/`wp_ajax_`/`ntdst/api_data`/`ntdst_actions` outside `api/Rest.php`.
- **INV-3** — anonymous reach is named; internal default; writes name a capability. Established by Cluster 2 (T04). Check: every `__return_true` outside `api/Rest.php` is a GET.
- **INV-4** — CSRF and nonces are WordPress's. Established by Cluster 3 (T07, T08). Check: no `wp_create_nonce`/`get_nonce`/`HTTP_ORIGIN` in `api core admin assets`; `assets/js` fetches only through `wp.apiFetch`.
- **INV-5** — no table WordPress already keeps. Established by Cluster 2 (T05, T06) and Cluster 4 (T09, T11). Check: each `static array` in core is a WordPress-less concern.
- **INV-6** — one template resolver; page routes are rewrite rules; callbacks return a path. Established by Cluster 4 (T09, T10). Check: no `is_404 = false`/`redirect_canonical`/`extract(` in `api core`.
- **INV-7** — one throttling primitive, charged after permission. Holds today; T04 and T07 must keep `guard()`'s order and route the new relation-search route through `rate_limit`.

T13 flips every `Status:` line to "holds"; T14 runs all seven checks (SC-9).

---

## Spec-premise ground-truth

Every "X already does Y" premise read against real source before this plan shipped:

| Premise | Verdict |
|---|---|
| "`register_post_meta(show_in_rest)` makes WordPress emit the field on `/wp/v2/<type>`" | **Confirmed, with a condition.** `WP_REST_Posts_Controller` adds the `meta` field only when `post_type_supports($type, 'custom-fields')` (`class-wp-rest-posts-controller.php:2554`). Hence FR-1's auto-`supports` (D1a). |
| "`type => array` meta needs a schema" | **Confirmed.** `register_meta()` refuses `show_in_rest` on `array` without `schema.items` (`meta.php:1490`). FR-2's mapper always supplies `items`. |
| "A partial `properties` list drops un-named sub-fields" | **Confirmed.** `WP_REST_Meta_Fields::default_additional_properties_to_false()` (`class-wp-rest-meta-fields.php:606`). |
| "`relation`/`person` store an integer" | **Refuted.** `api/Data.php:231–244`: `relation`, `post_relation`, `person`, `gallery` sanitize to a list of `absint`; `image`/`file` to one ID. Spec revision 1 corrected FR-2. |
| "WordPress's CSRF rule treats a cookie request without `X-WP-Nonce` as anonymous" | **Confirmed.** `rest_cookie_check_errors()` sets `wp_set_current_user(0)` when no nonce (`rest-api.php:1147–1149`). |
| "`wp.apiFetch` refreshes a stale nonce itself" | **Confirmed.** `script-loader.php:368–375` wires `createNonceMiddleware` + `nonceEndpoint = admin-ajax.php?action=rest-nonce`; the middleware refetches on `rest_cookie_invalid_nonce`. `rest-nonce` has no `nopriv` handler (logged-in only). |
| "`allowed_http_origins` is WordPress's origin list" | **Confirmed.** `get_allowed_http_origins()` / `is_allowed_http_origin()` (`http.php:448–487`); `rest_send_cors_headers()` ignores it and reflects any origin (`rest-api.php:797–814`). |
| "WordPress calls `permission_callback` twice" | **Confirmed.** dispatch + `rest_send_allow_header()` (`rest-api.php:892`). Memoization stays. |
| "WordPress computes the template hierarchy and hands it to a filter" | **Confirmed.** `get_query_template()` → `{$type}_template_hierarchy` → `locate_template()` → `{$type}_template($template, $type, $templates)` (`template.php:23–44`). T09 hooks the last one. |
| "`wp_check_filetype()` knows core's MIME list" | **Partly.** WP's table has pdf csv txt ics png jpg webp zip gz xlsx docx; **lacks json xml vcf svg**. T11 adds those four through the `mime_types` filter. Trap: `wp_check_filetype()`'s default table is `get_allowed_mime_types()` — the capability-filtered upload list; T11 passes `wp_get_mime_types()` explicitly. |
| "daan can symlink core through a composer path repo" | **Refuted.** daan runs in DDEV; a host symlink to `~/Sites/ntdst-core` is dangling inside the container. Spec revision 1: mirror (`symlink: false`) + `ddev composer update` to re-mirror. |
| "`path()` can become a rewrite rule" | **Confirmed.** `add_rewrite_rule()` (`rewrite.php:140`), `query_vars` filter (`class-wp.php:311`), `template_redirect` (`template-loader.php:23`). Rules persist in the `rewrite_rules` option, hence the flush-on-hash. |
| "`Theme::apiAction()` still exists" | **Refuted.** Only its docblock survives (`core/Theme.php:36,60`). T12 rewrites the docblock. |

---

## First working version

**Task:** T03 — after it, a declared field is visible on WordPress's own endpoint
and an undeclared one is not:

```bash
cd ~/Sites/daan && git checkout chore/core-path-repo && ddev composer update netdust/ntdst-core
ddev exec wp post meta add <gig-id> daan_internal_promo_budget 999        # the undeclared probe
curl -s https://daan.ddev.site/wp-json/wp/v2/gigs/<gig-id> | jq '.meta'
```

Expected: `.meta` lists exactly the gig fields declared `show_in_rest => true`
on the branch (`venue_city`, `venue_country` — the probe declarations), and
`daan_internal_promo_budget` is absent. Before T02 the same call shows no `meta`
key at all.

---

## Constitution check

- **ntdst-core is THE base** — every change here removes a site's reason to hand-roll (daan's `PUBLIC_SHAPE`, rossi's MIME list, a site's own nonce fetch). Nothing moves *into* a site.
- **Wrap, never replace** (`docs/philosophy.md` §1, spec D8) — each task names the WordPress function it calls; the reviewer checks the Spec-premise table above against the diff.
- **One name per concept** — `Theme` forwarders, three redirects, two `addPath`s, two template hand-offs, two JSON envelopes: each ends with one (T09–T12).
- **Simplicity** — the direction doc says core does not grow. Net line count of this plan is negative: Actions (−750), Response (−250), Pages (−200), the surface registry (−83) against Data's mapper (+120), Rest's defaults (+40), the loader's filter (+30).
- **Sites count, never design** (D5) — daan appears only as the observable's host, on a branch that is never merged.

## Phases & review clusters

| Cluster | Phase | Tasks | Stakes | Review tier |
|---|---|---|---|---|
| 1 — Data declares, WordPress reads | 1 | T01–T03 | high | FULL |
| 2 — Rest: internal by default, WordPress's lists | 2 | T04–T06 | high | FULL |
| 3 — Actions out | 3 | T07–T08 | high | FULL |
| 4a — one template loader | 4 | T09 | standard | STANDARD |
| 4b — Pages on rewrite rules, Response trimmed | 4 | T10–T11 | standard | STANDARD |
| 5 — Theme, docs, release | 5 | T12–T14 | standard | STANDARD |

Order is the spec's: 1 → 2 → 3 → 4a → 4b → 5. Cluster 3 depends on 2 (the
relation route needs the internal default and the capability closure shape).
Cluster 4 is independent of 1–3 but lands after them because it is the largest
diff and the least security-relevant. T01 (the daan path repo) is first so that
every later cluster's observable is drivable the moment its code lands.

## Interfaces

Names every task must use. An implementer sees only its own task; this block is
how neighbouring tasks agree.

```php
// api/Data.php — Cluster 1
final class NTDST_Data_Model {
    /** REST schema for one declared field, or null when it is not declared. */
    public function restSchemaFor(string $field): ?array;          // T02
    /** Hands every declared field to register_post_meta(). Called from NTDST_Data_Manager::register(). */
    public function registerRestMeta(string $postType): void;     // T03
}
// register_post_meta args produced by T03, per field:
[
  'type'              => 'integer'|'number'|'boolean'|'string'|'array'|'object',
  'single'            => true,
  'sanitize_callback' => <the model's sanitizer for the field>,
  'auth_callback'     => static fn($allowed, $metaKey, $postId, $userId): bool => user_can($userId, 'edit_post', $postId),
  'show_in_rest'      => true | ['schema' => <restSchemaFor()>],  // array/object types carry the schema
]

// api/Rest.php — Cluster 2
final class NTDST_Rest {
    public function public(): self;                               // T04 — marks the most recently declared route anonymous
    // permission resolution (T04):
    //   null/absent   → 'is_user_logged_in'                  (a string; shows as such in get_routes())
    //   'public'      → '__return_true'
    //   'logged_in'   → 'is_user_logged_in'
    //   capability    → static fn() => current_user_can($cap)
    //   callable      → as given
    // registerOne() refuses a write verb (POST|PUT|PATCH|DELETE) whose resolved permission is 'is_user_logged_in'.
    public function cors(array|callable $origins, bool $credentials = false, int $maxAge = 0): self; // T06 — feeds allowed_http_origins
    public static function corsDecision(?string $origin, array $policy): array;  // unchanged signature; $policy['origins'] is now read through is_allowed_http_origin()
    // REMOVED (T05): surface(), publicSurface(), opaqueSurface(), forgetSurface(), $surface
}

// admin/RelationField.php — Cluster 3 (T07)
// GET /wp-json/ntdst/v1/relation/search?search=<q>&post_type[]=<type>
//   permission: static fn(WP_REST_Request $r): bool => $this->mayPickFromAll((array) $r->get_param('post_type'))
//   rate_limit: 60 / 60s
//   returns: ['results' => [ ['id' => int, 'title' => string], … ]]   (bare array; WordPress wraps nothing)
// assets/js/metabox-fields.js: wp.apiFetch({ path: '/ntdst/v1/relation/search?' + new URLSearchParams(...) })

// core/TemplateLoader.php — Cluster 4a (T09), class name unchanged: NTDST_Template_Loader
final class NTDST_Template_Loader {
    public static function addPath(string $path): void;
    public static function getCustomPaths(): array;
    public static function locate(string $template, array $extraPaths = []): ?string;   // the ONE search
    public static function page(string $template, array $data = [], array $extraPaths = []): ?string;
    public static function pageData(): array;
    public static function init(): void;   // hooks {$type}_template for single|page|archive|singular|index + theme_file_path
    public static function pickFromCandidates(string $template, string $type, array $templates): string; // the {$type}_template callback
    public static function locateInCustomPaths(string $path, string $file): string;      // calls locate()
}
// REMOVED: templateInclude(); NTDST_Response::addPath(), ::$extra_paths
// NTDST_Response::html(string $template, array $data = [], array $extraPaths = []): string   // T09 adds $extraPaths; Mailer uses it

// core/Pages.php — Cluster 4b (T10)
final class NTDST_Pages {
    public function path(string $pattern, callable $callback, string $method = 'GET'): self; // registers add_rewrite_rule() + query vars on init; dispatches on template_redirect
    public function template(string $type, callable $callback, ?string $post_type = null): self; // callback returns ?string path
    public function single(?string $post_type = null, ?callable $callback = null): self;
    public function page(string|callable $slug_or_callback, ?callable $callback = null): self;
    public function archive(?string $post_type = null, ?callable $callback = null): self;
    public function when(callable $condition, callable $callback): self;
    public function url(string $pattern, array $params = []): string;
    // query vars: 'ntdst_page' (route index) and 'ntdst_p_<name>' per :name placeholder
    // option 'ntdst_pages_rules_hash' — md5 of the rule set; flush_rewrite_rules(false) when it changes
    // REMOVED: redirect(), handleTemplateInclude(), resolveRouteResult(), commitOk(), renderResponse(), preventRedirectForRoutes()
}

// api/Response.php — Cluster 4b (T11)
class NTDST_Response {
    // KEPT: reset(), with(), withData(), template(), getTemplate(), getStatus(), notFound(), redirect(), html(), download(), inline(), downloadHeaders()
    // REMOVED: json(), jsonPayload(), render(), renderError(), getErrorHtml(), commitRenderStatus(), error() (the rendering half; status-only error() stays as error(string $message, int $status)),
    //          $mimeTypes, getMimeType(), registerMimeType(), ntdst_redirect(), ntdst_download()/ntdst_inline() stay.
    public static function downloadHeaders(int $length, string $filename, ?string $contentType = null, string $disposition = 'attachment'): array; // MIME via wp_check_filetype($filename, wp_get_mime_types())
    public static function mimeTypes(array $mimes): array;  // the mime_types filter callback adding json|xml|vcf|svg
}
```

## Threat model

Named assets → attacks → mitigations. Reviewers converge on these; a task that
weakens a numbered mitigation is rejected at its gate.

1. **Every post's custom meta on daan / todai-client** — phase 1 turns on `custom-fields` support and registers meta with WordPress; an anonymous `GET /wp/v2/<type>` now returns declared fields. *Attack:* a field nobody meant to publish is declared, or all fields leak because registration ignores the flag. *Mitigation:* `registerRestMeta()` registers **only** fields with `show_in_rest === true` (strict); the unit test seeds 3 declared and 2 undeclared fields and asserts exactly 3 `register_post_meta` calls; SC-1 on daan seeds an undeclared probe and asserts absence over HTTP. *Second-order:* declaring **any** field turns on `custom-fields` for the type, and that support is not per-key — WordPress then also emits every key some other code registered globally with `register_meta('post', …, 'show_in_rest' => true)` on that type's `meta` object, and mounts the editor's Custom Fields panel for `edit_post` holders. Both are widenings this package did not declare. README documents them; SC-1 asserts `.meta` keys **equal** the declared set, so a widening shows up as a failing gate rather than as a quiet extra key. (T02, T03, T13)
2. **A sensitive sub-field inside a public repeater** (the rossi provenance shape: public repeater, private `sale_price`). *Attack:* the repeater schema lists all sub-fields. *What WordPress actually does:* it VALIDATES the stored value against the closed schema it was given — `WP_REST_Meta_Fields::prepare_value()` (`class-wp-rest-meta-fields.php:556`) runs `rest_validate_value_from_schema()` and returns **`null`** when the stored row does not match, and `update_value()` validates BEFORE sanitizing, so a REST write carrying an undeclared key is refused **400**. A legal write of only the declared keys REPLACES the row, dropping the undeclared one from storage. Publishing a repeater partially therefore does not hide the private sub-field — it breaks the field: the whole value reads as null, writes are refused, and an admin write silently wipes `sale_price`. *Mitigation:* a repeater publishes **only** when every sub-field, at every depth, declared `show_in_rest => true`; one silent sub-field makes the whole field unpublishable. `json` is never publishable — a blob names no sub-fields, so nothing inside one ever opted in. Both refusals are LOUD: `registerRestMeta()` warns once per model naming the field and the reason, so a module that declared and got nothing can find out. The rossi shape needs a projection option (prepare_callback read + write denial), which is a separate decision, not this cluster's. (T02, T03)
3. **Meta writes through `/wp/v2`** — `register_post_meta` with `show_in_rest` also accepts **writes** from authenticated REST callers. *Attack:* a subscriber PATCHes a gig's `venue_city`. *Mitigation:* `auth_callback` requires `user_can($userId, 'edit_post', $postId)` — the user WordPress names in the callback's fourth argument, not the current session; `sanitize_callback` is the model's own sanitizer, and a non-scalar `$user_id`/`$post_id` is refused outright rather than cast (an object casts to `1`, a real user id). Test asserts both callbacks are present and drives each on real arguments. *The floor this sets:* `edit_post` on the post being written is not an editor-only capability — the effective write floor is **the post's own `edit_post` holder, which includes a Contributor on their own draft**. A site that wants a higher floor filters `map_meta_cap`; the package does not invent a capability. (T03)
4. **Every unnamed route in the package and its consumers** — the default moves from "refused" to "logged in". *Attack:* a site with open registration exposes an unnamed `POST /purge` to every subscriber. *Mitigation:* a write verb whose resolved permission is `is_user_logged_in` is **not registered** (`_doing_it_wrong` + log), tested as absence from the captured `register_rest_route` calls, never as a 403. GET routes default to logged-in — WordPress's own `wp_ajax_` posture, stated in README. (T04)
5. **`->public()` lands on the wrong route** — fluent misuse after `rest_api_init` has fired. *Attack:* a route intended internal becomes anonymous, or vice versa. *Mitigation:* `public()` applies to the most recent pending registration only; if that registration already ran (hook fired), `public()` refuses with `_doing_it_wrong` and changes nothing. Test covers both orders. (T04)
6. **Admin relation picker** — `relation_search` moves from the action router (login + per-type `edit_others_posts`) to a route. *Attack:* the route's permission is `is_user_logged_in` by default, so any account enumerates every relation-target type's posts. *Mitigation:* the route declares a closure permission that runs `mayPickFrom()` for **every** requested type and denies when any fails or the list is empty; unit test: anonymous → not reached (WordPress), Author → false, Administrator → true, empty list → false. Rate-limited. (T07)
7. **CSRF on the relation route** — it is a GET with no side effect; no nonce needed. Cookie auth still requires `X-WP-Nonce` for the caller to count as logged in; `wp.apiFetch` supplies it. *Mitigation:* the enqueue depends on `wp-api-fetch` so the middleware is present; the JS never builds a nonce itself. (T07)
8. **Removing `verifyOrigin()` with `Actions.php`** — *Attack:* a cross-site page drives an authenticated write. *Mitigation:* none needed beyond WordPress's: a cookie request without a valid `wp_rest` nonce is anonymous (`rest-api.php:1147`), and a cross-origin page cannot obtain that nonce. Recorded in README as the reason the check went; a site wanting a belt writes a `rest_pre_dispatch` filter. (T08)
9. **CORS list widening** — `cors([...])` now feeds `allowed_http_origins`, which admin-ajax's `send_origin_headers()` also reads. *Attack:* an origin allowed for a REST namespace gains admin-ajax CORS with credentials. *Mitigation:* stated in README; core's REST emitter still sends credentials only when asked (D2e); `'*'` is refused; the unit test asserts the filter output equals WP's defaults + the declared list and nothing else. (T06)
10. **Rewrite rules** — `path()` registers regexes WordPress will match for every visitor. *Attack:* a pattern shadows a WordPress route (e.g. `/:slug` matching everything), or a `:param` value reaches a handler unsanitized. *Mitigation:* rules are added `top` only for patterns with a literal first segment; a pattern whose first segment is a placeholder is refused at registration with `_doing_it_wrong`. Query var values reach callbacks through `get_query_var()` and are cast to string; handlers sanitize as today. Rules flush only when the hash changes (no flush on every request). (T10)
11. **`downloadHeaders()` regression** — *Attack:* a crafted filename injects a header or a body sniffs to HTML. *Mitigation:* the CRLF/quote strip, basename, RFC 5987 pair and nosniff stay byte-identical; `DownloadHeadersTest` is not weakened, and the nosniff assertion now expects `send_nosniff_header()` to have been called. (T11)
12. **Four consumer sites take 5.0.0 and fatal** — *Mitigation:* README's migration table names every removed symbol (SC-8); the tag is a `[HUMAN]` yield; daan's and todai-client's updates are their own work after the tag. (T13, T14)

**Explicitly out of scope:** daan's handler migration; rossi's provenance exposure (recorded in `docs/session-2026-08-21…` §3); any new capability.

## Acceptance flows

Driven at shake-out on daan's DDEV (`chore/core-path-repo`, re-mirrored) through
real HTTP and a real browser — never direct PHP calls. Edges: empty, denied,
re-entry, concurrent, boundary, mid-flow failure.

| # | Flow | Edge | Expected |
|---|---|---|---|
| AF-1 | anonymous `GET /wp-json/wp/v2/gigs/{id}` | happy path | 200; `.meta` has exactly the declared keys; probe key absent (SC-1) |
| AF-2 | same, a gig with no meta saved yet | **empty** | 200; `.meta` has the declared keys with WP's type defaults (`""`, `0`, `[]`), no error |
| AF-3 | `PATCH /wp/v2/gigs/{id}` `{"meta":{"venue_city":"x"}}` as Subscriber with nonce | **denied** | 403 `rest_cannot_update`; meta unchanged |
| AF-4 | same as Administrator | happy path (write) | 200; value sanitized by the model's sanitizer |
| AF-5 | `GET /wp-json/ntdst/v1/relation/search?search=a&post_type[]=release` anonymous | **denied** | 401 `rest_not_logged_in` (SC-4) |
| AF-6 | same as Author | **denied** | 403 (SC-4) |
| AF-7 | same as Administrator, in the edit screen via the picker | happy path | 200, `results[]`; the picker renders them (browser) |
| AF-8 | picker after 13h open tab (stale nonce) | **re-entry** | `wp.apiFetch` refetches `rest-nonce`, second call succeeds — simulated by invalidating the nonce cookie |
| AF-9 | 61 picker searches in 60s as Administrator | **concurrent** | the 61st answers 429 with `retry_after` |
| AF-10 | `POST /wp-json/ntdst-baseline/v1/purge` anonymous / Subscriber / Administrator with nonce | **denied ×2**, happy | 401 / 403 / 200 — the write-verb rule and `manage_options` hold on a real consumer route |
| AF-11 | `GET /card` on daan | happy path | 200, daan's card template; `is_404` never set (SC-5) |
| AF-12 | `GET /card/` and `GET /CARD` | **boundary** | trailing slash 200 (rewrite rule allows `/?$`); case-different 404 — WordPress-native, no canonical redirect suppression |
| AF-13 | `GET /card/contact.vcf` | happy path | 200, `text/vcard`, `Content-Disposition: attachment; filename="…"; filename*=UTF-8''…`, `X-Content-Type-Options: nosniff` |
| AF-14 | `GET /nonexistent-page` | **denied/404** | WordPress's own 404 template; `Pages` touched nothing |
| AF-15 | a `path()` callback throws | **mid-flow failure** | WordPress's fatal handler (500), no partial output, no core-rendered red div |
| AF-16 | edit screen loads with `ntdst-api.js` absent from the page | **boundary** | no console error; `metabox-fields.js` runs on `wp.apiFetch` alone |

---

## Loop budget

Loop budget: ~18 iterations — 14 tasks plus four expected review-fix rounds.
Not an unattended run: Clusters 1–3 are high-stakes and gated. If driven by
`/loop`, cap at one cluster per wake and stop at every `── REVIEW GATE ──`.

---

## Sequencing note

T01 (daan path repo + probe declarations) precedes everything so SC-1 is
drivable the moment T03 lands. Cluster 2 before Cluster 3: the relation route
needs `Rest`'s closure-permission and `rate_limit` shape settled. `bin/guard.sh`
and `PackageBootIntegrityTest` are extended **in the same task** that removes a
symbol (T05, T08, T10, T11, T12), never after — that is what made v3's removals
loud instead of silent.
