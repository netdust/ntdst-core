# core-shape — tasks

Plan: `specs/core-shape/plan.md` · Spec: `specs/core-shape/spec.md` (rev 1) · Invariants: `ARCHITECTURE-INVARIANTS.md`

Repos: **CORE** = `~/Sites/ntdst-core` (every code task). **DAAN** = `~/Sites/daan`, branch `chore/core-path-repo` only (T01), never merged.

Every CORE task closes with `cd ~/Sites/ntdst-core && composer gate` exit 0 and one atomic commit on a branch off `main` named in the task.

---

### Cluster 1 — Data declares, WordPress reads (CORE + DAAN branch)

Stakes: high — this is the anonymous-exposure surface; a wrong filter or schema is disclosure on every site that updates.

Behaviour: a field declared show_in_rest => true appears on WordPress's own /wp/v2/<type> endpoint for anonymous callers, and a field not declared never does — including a sub-field inside a declared repeater.
Observable: `curl -s https://daan.ddev.site/wp-json/wp/v2/gigs/<id> | jq '.meta'` lists exactly the declared keys and omits the seeded `daan_internal_promo_budget` probe.
RED until: tests/Unit/DataRegistersRestMetaTest.php

- [x] T01 — daan path repo branch and the probe declarations [Tier B]  (files: composer.json, ../daan/composer.json, ../daan/web/app/mu-plugins/daan-core/services/musician/TourService.php)
  Satisfies: FR-14, SC-10
  Test-author: solo — configuration, no code path
  Proven by: machine gate — `ddev composer update netdust/ntdst-core` then `diff -rq`
  Integration test: in CORE, composer.json gains `"extra": {"branch-alias": {"dev-main": "5.0.x-dev"}}`; in DAAN on new branch `chore/core-path-repo` (off master), composer.json gains repository `{"type":"path","url":"/home/ntdst/Sites/ntdst-core","options":{"symlink":false}}` FIRST in `repositories` and `require` pins `"netdust/ntdst-core": "5.0.x-dev"`; `ddev composer update netdust/ntdst-core` succeeds and `diff -rq --exclude=vendor --exclude=.git ~/Sites/ntdst-core web/app/mu-plugins/ntdst-core` prints 0 lines; `git -C ~/Sites/daan show master:composer.lock | grep -A3 '"name": "netdust/ntdst-core"'` still shows the VCS source. On the same DAAN branch, TourService's gig fields `venue_city` and `venue_country` gain `'show_in_rest' => true` (the probe declarations SC-1 reads); `ddev exec wp post list --post_type=gig --format=ids | head -1` names the gig id used by SC-1.

- [x] T02 — restSchemaFor(): field type to REST schema, sub-fields opt in [Tier A]  (files: api/Data.php, tests/Unit/DataRegistersRestMetaTest.php)
  Satisfies: FR-2, FR-3
  Test-author: split
  Proven by: new test
  Unit test: `restSchemaFor('f')` returns null for an undeclared field; `['type' => 'integer']` for int/signed_int/image/file; `number` for float; `boolean` for bool; `string` for text/textarea/html/wysiwyg/content/select/date, with `format: email` for email and `format: uri` for url; `['type' => 'array', 'items' => ['type' => 'string']]` for array; `['type' => 'array', 'items' => ['type' => 'integer']]` for gallery/relation/post_relation/person; null for json (revision 2: not publishable); for a repeater with sub-fields {a: show_in_rest, b: show_in_rest, c: not} → null (revision 2: partial repeaters are not publishable); for {a, b both declared} → `['type' => 'array', 'items' => ['type' => 'object', 'properties' => [a, b], 'additionalProperties' => false]]`; a repeater without sub_fields → null; an unknown type throws InvalidArgumentException (same rule as getDefaultSanitizer). Data.php gains no method other than restSchemaFor() and registerRestMeta() (T03) — assert via ReflectionClass that no public method name contains 'project', 'shape' or 'public'.

- [x] T03 — register(): every declared field goes to register_post_meta(), custom-fields support added [Tier A]  (files: api/Data.php, tests/Unit/DataRegistersRestMetaTest.php)
  Satisfies: FR-1, SC-1
  Test-author: split
  Proven by: new test
  Unit test: with `register_post_meta` captured through Brain Monkey `Functions\expect()`, a model with 3 fields declared `show_in_rest => true` (one repeater) and 2 undeclared yields exactly 3 calls, each with `$postType`, the prefixed meta key, `type` from restSchemaFor(), `single => true`, a callable `sanitize_callback` that equals the model's sanitizer for that field, an `auth_callback` that returns `user_can($userId, 'edit_post', $postId)`, and `show_in_rest` equal to `true` for scalars and `['schema' => …]` for array/object types; a model with 0 declared fields yields 0 calls and `register_post_type` receives `supports` WITHOUT `custom-fields`; with ≥1 declared field `register_post_type` receives `supports` containing `custom-fields` exactly once (not duplicated when the site already lists it); a field declared `show_in_rest => 'yes'` (non-strict true) is NOT registered.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && git checkout chore/core-path-repo && ddev composer update netdust/ntdst-core && curl -s https://daan.ddev.site/wp-json/wp/v2/gigs/$(ddev exec wp post list --post_type=gig --format=ids | cut -d' ' -f1) | jq -e '(.meta | keys) == ["venue_city","venue_country"]'` — `gigs` is daan's `rest_base`, and the jq asserts the key set EXACTLY (exit 1 on any extra or missing key), because `custom-fields` widens the `meta` object beyond what this package declared.

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster 2 — Rest: internal by default, WordPress's lists (CORE)

Stakes: high — the permission default of every route, and the CORS allow-list.

Behaviour: a route that says nothing is reachable only by a logged-in user; ->public() is the one way to make it anonymous; a verb outside GET/HEAD/OPTIONS that names no capability does not exist; the CORS allow-list is WordPress's own and fails closed.
Observable: `wp eval 'foreach (rest_get_server()->get_routes("probe/v1") as $r => $h) echo $r, " ", is_string($h[0]["permission_callback"]) ? $h[0]["permission_callback"] : "closure", "\n";'` prints `is_user_logged_in` for an unnamed GET and `__return_true` only for routes marked public. `rest_get_server()` ALONE, over a namespace some module declared through `ntdst_rest()`: calling `do_action("rest_api_init")` first fires the hook twice — `register_rest_route()` builds the server, which fires `rest_api_init` again — so the listing double-counts handlers and reads as a bug in the wrapper. A namespace nobody declared through `ntdst_rest()` prints nothing and the Observable is vacuous. *(freshness review 2026-08-23: the namespace is the probe mu-plugin's `probe/v1` — daan never declared `ntdst-baseline/v1`; Cluster 2 was closed against the probe.)*
RED until: tests/Unit/NtdstRestDefaultsTest.php

- [x] T04 — internal default, public(), write-verb refusal, WordPress's callables [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestDefaultsTest.php, tests/Unit/NtdstRestTest.php)
  Satisfies: FR-4, SC-2
  Test-author: split
  Proven by: new test
  Unit test: with `register_rest_route` captured, `ntdst_rest('x/v1')->get('/t', $h)` registers with `permission_callback === 'is_user_logged_in'` (the string, not a closure); `->get('/t', $h)->public()` registers with `'__return_true'`; `->get('/t', $h, ['permission' => 'manage_options'])` registers a closure that returns `current_user_can('manage_options')`; `->post('/t', $h)` with no capability registers NOTHING (0 calls for that route) and fires `_doing_it_wrong` once; `->post('/t', $h, ['permission' => 'logged_in'])` is likewise refused; `->post('/t', $h, ['permission' => 'edit_posts'])` registers; `->delete('/t', $h)->public()` is refused (public on a write); `public()` called after `rest_api_init` already fired refuses with `_doing_it_wrong` and changes no registration; `guard()` still evaluates the permission before `NTDST_RateLimiter::attempt` (the existing auth-before-limiter test stays green unmodified). NtdstRestTest's "absent without permission" assertion is rewritten to the write-verb form by the test-author, with the file's SPLIT header updated to cite FR-4.

- [x] T05 — delete the surface registry; README shows the WordPress assertion [Tier B]  (files: api/Rest.php, tests/Unit/NtdstRestSurfaceTest.php, README.md, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-5, SC-6
  Test-author: solo
  Proven by: machine gate — `composer gate` + the guard grep
  Unit test: tests/Unit/NtdstRestSurfaceTest.php is deleted; `grep -cE "publicSurface|opaqueSurface|forgetSurface|::surface\(|->surface\(|\\\$surface" api/Rest.php` prints 0 — the SYMBOL, not the word: this codebase writes "the exposure surface" in prose, so a bare `surface` grep fails on a sentence; `publicSurface`, `opaqueSurface`, `forgetSurface`, `NtdstRestSurfaceTest` and those three symbol forms are added to bin/guard.sh's REMOVED grep and PackageBootIntegrityTest::removedSymbolProvider(), whose rows carry the version that removed them; README gains a "Asserting your anonymous surface" block with the three-line `rest_get_server()->get_routes($ns)` filter on `permission_callback === '__return_true'`; composer gate exits 0 and the suite loses exactly the 6 NtdstRestSurfaceTest tests and no other.

- [x] T06 — cors() feeds allowed_http_origins; the emitter decides with is_allowed_http_origin() [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestCorsTest.php)
  Satisfies: FR-6, SC-6
  Test-author: split
  Proven by: new test
  Unit test: after `cors(['https://a.test'])`, applying the recorded `allowed_http_origins` filter to `['http://site.test', 'https://site.test']` returns those two plus `https://a.test` and nothing else; `cors(['*'])` refuses with `_doing_it_wrong` and adds nothing; `Rest::$cors` holds no `origins` key (the class keeps only `credentials` and `max_age`); `corsDecision('https://a.test', $policy)` with `is_allowed_http_origin` stubbed true sets `Access-Control-Allow-Origin: https://a.test` and REMOVES `Access-Control-Allow-Credentials` unless `cors(..., credentials: true)` was called; with it stubbed false the decision removes both headers (fail closed); a callable `$origins` is applied by the filter callback rather than stored; `mountCors()` still removes `rest_send_cors_headers` and mounts `sendCors`.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && ddev exec wp eval 'foreach (rest_get_server()->get_routes("probe/v1") as $r => $h) echo $r, " ", is_string($h[0]["permission_callback"]) ? $h[0]["permission_callback"] : "closure", "\n";'` — `rest_get_server()` alone (see the Observable). If daan's own consumers declare no `ntdst_rest()` routes on the checkout in hand, this prints nothing and proves nothing: drive it with a probe mu-plugin that declares one route of each posture instead.

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster 3 — Actions out (CORE)

> Runs after field-types Cluster D and core-trim Cluster D (`1f8b219`; `specs/field-types/plan.md` `## Schedule`): T07 edits `admin/MetaboxGenerator.php`, which field-types rewrote (`render_control()` ~:920, `assertDeclarations()` at register) and core-trim trimmed (RelationField no longer claims `metadata()`; `handleRelationSearch()` runs an inline `WP_Query` pinned by `tests/Unit/RelationFieldSearchTest.php`). Freshness review 2026-08-23 (Class B) at `0f741cb`.

Stakes: high — removes a CSRF mechanism and moves the admin picker's gate onto a route.

Behaviour: the relation picker works through a REST route and wp.apiFetch with the same per-type capability gate, and the package has no command dispatcher, no nonce endpoint and no JS client of its own.
Observable: on daan's edit screen the relation picker returns results for an Administrator; `curl -s -o /dev/null -w '%{http_code}' 'https://daan.ddev.site/wp-json/ntdst/v1/relation/search?search=a&post_type[]=release'` prints 401; `ls ~/Sites/ntdst-core/api/Actions.php ~/Sites/ntdst-core/assets/js/ntdst-api.js` prints two "No such file" lines.
RED until: tests/Unit/RelationSearchRouteTest.php

- [x] T07 — relation search becomes GET /ntdst/v1/relation/search; the picker uses wp.apiFetch [Tier A]  (files: admin/RelationField.php, admin/MetaboxGenerator.php, assets/js/metabox-fields.js, tests/Unit/RelationSearchRouteTest.php)
  Satisfies: FR-8, SC-4
  Test-author: split
  Proven by: new test
  Unit test: constructing NTDST_RelationField registers (captured `register_rest_route`) exactly one route `ntdst/v1` `/relation/search` with method GET, a closure `permission_callback`, a `rate_limit` of 60/60s declared through ntdst_rest(), and `args` declaring `search` (string, required, sanitize_text_field) and `post_type` (array of string) — the live wire today is `post_types` (`metabox-fields.js:106-109` sends `post_types: [postType]`, the handler reads `$params['post_types'] ?? $params['post_type']` at `:93`): the route arg is `post_type`, the JS stops sending `post_types`, the handler's dual read is deleted; the permission closure returns false for an empty `post_type`, false when any requested type is not a declared relation target, false when `current_user_can(<type>->cap->edit_others_posts)` is false for any requested type, true only when every requested type passes; the handler returns `['results' => [['id' => int, 'title' => string], …]]` capped at 20 by the inline `WP_Query` literal (`posts_per_page => 20`, the four defaults `RelationField.php:~117-135` restates — `ntdst_get_formatted_posts()` is GONE, pinned removed in `removedSymbolProvider()` and `bin/guard.sh`; writing it back fails `composer gate`), returning `id`/`title` only; `grep -c "ntdst_actions\|ntdstAPI" admin/RelationField.php admin/MetaboxGenerator.php assets/js/metabox-fields.js` prints 0 for all three; MetaboxGenerator's enqueue (`MetaboxGenerator.php:~79-83`: `ntdst_enqueue_api_client()` + `$deps[] = 'ntdst-api'`) lists `wp-api-fetch` in `$deps` and no `ntdst-api`; metabox-fields.js calls `wp.apiFetch({ path: '/ntdst/v1/relation/search?…' })` (with `URLSearchParams` emitting `post_type[]`) and reads `response.results` — the `post.ID || post.id` / `post.post_title || post.title` fallbacks at `:117-118` are deleted (the route returns `id`/`title` only). `tests/Unit/RelationFieldSearchTest.php` (core-trim's three cases on the `WP_Query` shape) is UPDATED by T07 onto the route, not left as a second owner of the handler.

- [x] T08 — delete Actions.php, get_nonce, the JS client, the api* envelopes; extend the removed-symbol guards [Tier B]  (files: api/Actions.php, assets/js/ntdst-api.js, ntdst-core.php, api/Response.php, core/Theme.php, core/Pages.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php, tests/Unit/ActionsDropsDownloadTest.php, tests/Unit/ActionsOriginGateTest.php, tests/Unit/ActionsRateBucketTest.php, tests/Unit/NtdstActionsTest.php, tests/Unit/RoutingFacadesTest.php)
  Satisfies: FR-7, SC-3
  Test-author: solo
  Proven by: machine gate — `composer gate` + PackageBootIntegrityTest + the SC-3 grep
  Unit test: the four Actions test files and api/Actions.php and assets/js/ntdst-api.js are deleted; ntdst-core.php no longer requires Actions.php, no longer calls `ntdst_actions()`, and `ntdst_enqueue_api_client()` is gone; `NTDST_Response::apiSuccess/apiError/apiSuccessResponse/apiErrorResponse` are gone; `ntdst_actions`, `NTDST_Actions`, `ntdstAPI`, `ntdst_enqueue_api_client`, `apiSuccess`, `apiError`, `apiSuccessResponse`, `apiErrorResponse`, `get_nonce`, `ntdst/api_data`, `ntdst/api/public_actions` are added to bin/guard.sh's REMOVED alternation (function/hook names — REMOVED is correct for these; method names go to `METHOD_PINS`) and to PackageBootIntegrityTest::removedSymbolProvider() with `'5.0.0'` — the two tables are mirrored by hand (`guard.sh:~27` states the contract) and `testEveryRemovedFiveOhSymbolHasAMigrationRow` (`:~1012`) requires a README migration row for each (T13 writes them — add both halves or the gate fails); RoutingFacadesTest no longer asserts `ntdst_actions` exists (it asserts it does NOT); `core/Theme.php:23,25` (the core-trim retirement note — `guard.sh:~231` references `:23`; keep the sentence, drop the two names) and `core/Pages.php:17` no longer mention apiAction/api_data/ntdst_actions; `ntdst-core.php` loses the function at `:39-56` and the require + `ntdst_actions();` at `:95-96` and the `api/` header line `:11`; the `apiSuccess/apiError/apiSuccessResponse/apiErrorResponse` methods at `api/Response.php:188,197,209,214` go; the SC-3 grep (hardened at this review to `grep -vE "^(\./)?api/Rest\.php|/vendor/|/tests/|README"` — ugrep prints no `./` prefix) prints 0 lines; composer gate exits 0.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && curl -s -o /dev/null -w '%{http_code}\n' 'https://daan.ddev.site/wp-json/ntdst/v1/relation/search?search=a&post_type[]=release'`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*
> Cluster 3 gate CLOSED 2026-08-23 at `2970d55` (range 9d1888a..2970d55): FULL panel (reviewer / security-sentinel / invariant-auditor / code-simplicity) + T08 task review + feature tests `tests/Unit/CoreShapeCluster3FeatureTest.php` (12). No Critical from reviewer/sentinel; audit 0 bypass / 0 reinvention / 11 doc-drift. Fix wave (split RED `4bc0126`, fixes `8c2443e`..`5506025`, round 2 `05c7f5f`..`2970d55`): `required` no longer exempts the retired-type pin (both homes); `post_type[]` capped at 20 (`maxItems` + refusal before the capability loop — 21 entries → 400 on daan's wire); picker errors carry 400/403; README FR-7 rows use `permission`/`->public()`, carry `rate_limit`, gain the anonymous-write row; four dead Extension-points rows → migration rows and zero-readers gains `inert-exception:`; INV-3/4/5/8/9 re-pinned, INV-4 pattern gains `wp_nonce_field`. Integration gate: core gate 0 (1029 tests), daan mirror anon 401 / home 200 / Unit 254. Scoped re-review round 2 APPROVED (one cosmetic sha-pin suggestion). verify-ratio 6.99 over the 4.0 ceiling — structural (a 700-line module deleted, RED + feature tests added).

---

### Cluster 4a — one template loader (CORE)

Stakes: standard — a wrong template is visible on the page, not a leak.

Behaviour: every template path in the package resolves through one function, WordPress's own hierarchy candidates are searched in the plugin template directories, and there is one way to hand data to a template.
Observable: `grep -rn "locate_template(\|extract(" --include=*.php ~/Sites/ntdst-core/api ~/Sites/ntdst-core/core` prints exactly one line, inside `NTDST_Template_Loader::locate()` (today: 3 — `api/Response.php:273,311,667`).
RED until: tests/Unit/TemplateLoaderTest.php

- [x] T09 — NTDST_Template_Loader in its own file: one locate(), {$type}_template over WP's candidates, one hand-off [Tier A]  (files: core/TemplateLoader.php, api/Response.php, ntdst-core.php, tests/bootstrap.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php, tests/Unit/TemplateLoaderTest.php)
  Satisfies: FR-10
  Test-author: solo — cluster stakes standard; template resolution, no authorization semantics
  Proven by: new test
  Unit test: the class `NTDST_Template_Loader` (today `api/Response.php:568-755`, incl. the `NTDST_Template_Loader::init();` call at `:755`; private `searchPaths()` `:688` and the traversal guard `isInside()` `:699` move with it) is defined in core/TemplateLoader.php and not in api/Response.php; `tests/bootstrap.php` gains `require_once …/core/TemplateLoader.php` in its `:82-88` block (it requires no Response today); `ntdst-core.php` requires `core/TemplateLoader.php` before `api/Response.php`; `init()` adds `single_template`, `page_template`, `archive_template`, `singular_template` and `index_template` filters with 3 accepted args, plus `theme_file_path`; `pickFromCandidates('/theme/single.php', 'single', ['single-gig-x.php', 'single-gig.php', 'single.php'])` returns `<custom path>/single-gig.php` when that file exists in a registered path and `/theme/single.php` otherwise — the candidate list is WordPress's argument, and the method contains no hard-coded template name; `locateInCustomPaths()` returns whatever `locate()` returns; `locate('../../etc/passwd')` returns null (guard kept); `NTDST_Response` has no `addPath()` method (`:126`) and no `extra_paths` property (`:27`, read at `:264,304,463`); `NTDST_Response::html()` keeps its two-parameter signature — freshness-review ruling 2026-08-23: `services/Mailer.php` left core (core-trim T10), so the planned third `$extraPaths` parameter would have zero readers (INV-9); `locate()` keeps its own `$extraPaths` for internal use only; `Template_Loader::templateInclude` (`:715`) does not exist — `templateInclude` joins `METHOD_PINS["core/TemplateLoader.php"]` in `bin/guard.sh` and `removedSymbolProvider()` (+ README row, T13).

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && curl -s -o /dev/null -w '%{http_code}\n' https://daan.ddev.site/ && [ "$(curl -sS https://daan.ddev.site/ | grep -c 'Stack trace\|Fatal error')" = 0 ]`
> Hardened at the Cluster 4a gate (T09 review I2): a mid-render fatal arrives after WordPress flushed a 200, so the status code alone passes a broken page; the body must carry no stack trace.

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity)*
> Cluster 4a gate CLOSED 2026-08-23 at `c385163` (range 872be6d..c385163; tier escalated STANDARD → FULL for the traversal fix). T09 `740f980`/`94f1f89`/`b915973`; task review found daan's theme helper calling the deleted `NTDST_Response::addPath()` (mid-render fatal behind a 200 — gate line hardened to read the body; daan proof branch `d1ead17` deletes the redundant call); feature tests `CoreShapeCluster4aFeatureTest.php` (15) found RF-1: `locate()`'s `locate_template()` fallthrough unguarded and cached → fixed `5fa3d61` (`themeDirs()`/`isInsideTheme()`, `isInside()` byte-identical); priority-5 pin `4b9dca1`; docs `dd60842`; sentinel PASS (30 probes), its residuals landed as round 2 `02af41f`..`c385163` (cache keyed per theme, realpath returned/cached, theme-compat refusal pinned). Both scoped re-reviews APPROVED. Suite 1074, gate 0; daan `/`, `/card` 200 with clean bodies. Shakeout owes: real `switch_to_blog()`, a child theme where stylesheet ≠ template dir, TOCTOU on the resolved path (not closable in PHP's include model).

---

### Cluster 4b — Pages on rewrite rules, Response trimmed (CORE)

Stakes: standard — routing and headers; the download header policy is kept byte-identical.

Behaviour: a page route is a WordPress rewrite rule, a template callback returns a path and never exits, and Response keeps only what WordPress has no word for.
Observable: `curl -s -o /dev/null -w '%{http_code}\n' https://daan.ddev.site/card` prints 200 while `ddev exec wp eval 'global $wp_rewrite; print_r(array_filter(array_keys($wp_rewrite->wp_rewrite_rules()), fn($r) => str_starts_with($r, "^card")));'` lists the card rules, and `grep -rn "is_404 = false\|redirect_canonical" ~/Sites/ntdst-core/core ~/Sites/ntdst-core/api` prints nothing (today: 3 — `core/Pages.php:53,380`, `api/Response.php:290`).
RED until: tests/Unit/NtdstPagesTest.php
> Freshness review 2026-08-23: the file EXISTS (5 cases, two are core-trim pins: `testTheHttpVerbMethodsAreGoneFromThePageRouter` `:88`, `testTemplateHelpersSurviveTheRename` `:102`); T10 EXTENDS it and the RED sentinel is the new case `testAPathIsARewriteRuleAndDispatchesOnTemplateRedirect`, not the file

- [x] T10 — path() registers rewrite rules and dispatches on template_redirect; callbacks return a path [Tier A]  (files: core/Pages.php, tests/Unit/NtdstPagesTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-9, SC-5
  Test-author: solo — cluster stakes standard; routing behaviour, no authorization semantics
  Proven by: new test
  Unit test: `path('/card/:slug', $cb)` adds (captured `add_rewrite_rule`) the rule `^card/([^/]+)/?$` → `index.php?ntdst_page=0&ntdst_p_slug=$matches[1]` at `top`, and the `query_vars` filter output contains `ntdst_page` and `ntdst_p_slug`; `path('/:anything', $cb)` (placeholder first segment) is refused with `_doing_it_wrong` and adds no rule; on `template_redirect` with `get_query_var('ntdst_page') === '0'` and method matching, the callback receives `['slug' => <value>]` and its string return becomes the `template_include` result via one filter, a null return exits nothing and sets nothing, and `set_404()` is called when the callback returns false; a POST-declared route does not dispatch on GET; the option `ntdst_pages_rules_hash` is written and `flush_rewrite_rules(false)` called only when the hash differs from the stored one (second init with the same rules: 0 flush calls); `NTDST_Pages` has no methods `redirect` (`:458`), `handleTemplateInclude` (`:269`), `resolveRouteResult` (`:331`), `commitOk` (`:376`), `renderResponse` (`:392`), `preventRedirectForRoutes` (`:60`); `single()`/`page()`/`archive()` (`:186,199,216`) keep their signatures — `core/Theme.php:292,310,333` forward into them until T12 deletes the forwarders; `template()`/`when()` (`:142`, `:235`) callbacks returning a string path are returned to WordPress and a non-string return leaves `$template` unchanged — no `exit`, no `render()`; the five existing `NtdstPagesTest` cases survive; the removed method names are added as a new `METHOD_PINS["core/Pages.php"]` row in bin/guard.sh (method names never go in the flat REMOVED alternation — `page`/`redirect`/`template` would false-positive) and to `removedSymbolProvider()` with README rows (T13).

- [x] T11 — Response keeps the file policy and html(); loses every envelope and table WordPress owns [Tier A]  (files: api/Response.php, tests/Unit/DownloadHeadersTest.php, tests/Unit/ResponseRenderStatusTest.php, tests/Unit/ResponseTrimTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-11, SC-6
  Test-author: solo — cluster stakes standard; header policy unchanged, asserted byte-identical
  Proven by: new test
  Unit test: `NTDST_Response` has no methods `json`, `jsonPayload`, `render`, `renderError`, `getErrorHtml`, `commitRenderStatus`, `getMimeType`, `registerMimeType`, `addPath` and no static `$mimeTypes`; `ntdst_redirect` is not defined; `error('m', 403)->getStatus()` is 403 (`error()` at `:94` is already status-only; the rendering half is `renderError()`/`getErrorHtml()`, which go) and `notFound()` (`:110`, today status-only) ADDS a `$wp_query->set_404()` call (captured) beside status 404 — the `is_404 = false` write lives in `commitRenderStatus()` `:290`, which goes; `html('t', ['a' => 1])` (today `:298-316`, `extract()` + `include`) calls `load_template($file, false, ['a' => 1])` inside an output buffer and returns the buffer (SC-6's `grep -c load_template ≥ 3`); `downloadHeaders(3, 'Ünïcode name.pdf')` returns the same four lines as before this task (DownloadHeadersTest unmodified except that nosniff is now asserted as a `send_nosniff_header()` call recorded by Brain Monkey, and the Content-Type line comes from `wp_check_filetype('…pdf', wp_get_mime_types())['type']`); `NTDST_Response::mimeTypes(['pdf' => 'application/pdf'])` returns the input plus `json`, `xml`, `vcf`, `svg` with their types and is mounted on the `mime_types` filter; a filename with an unknown extension yields `application/octet-stream`; ResponseRenderStatusTest is deleted (its subject is gone); the removed METHOD names (`json` `:170`, `jsonPayload` `:219`, `render` `:258`, `renderError` `:459`, `getErrorHtml` `:479`, `commitRenderStatus` `:286`, `getMimeType` `:442`, `registerMimeType` `:451`, `addPath` `:126`) go into `METHOD_PINS["api/Response.php"]`; `ntdst_redirect` (`:533`) and `$mimeTypes` (`:32`) go into the REMOVED alternation; all of them into `removedSymbolProvider()` with README rows (T13); `mimeTypes()` on the `mime_types` filter closes INV-5's outstanding `Response::$mimeTypes` item.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && ddev exec wp rewrite flush && curl -s -o /dev/null -w '%{http_code}\n' https://daan.ddev.site/card && curl -sI https://daan.ddev.site/card/contact.vcf | grep -i "content-disposition\|x-content-type-options"`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity)*
> Cluster 4b gate CLOSED 2026-08-23 at `b2d4f7f` (range f119d54..b2d4f7f). T10 `5bee797`/`0a79c70`, T11 `2fbde3d`/`e4ba086`; task reviews → two fix waves (`a5092a7`..`19754d5`: dispatcher terminates a handled return, params validated to the rule's shape, verb mismatch → 404, flush presence check; `f8626a8`..`bbf9a25`: `Response::notFound()` sends the status, README pins); panel = reviewer + simplicity + invariant-auditor + feature tests `CoreShapeCluster4bFeatureTest.php` (12; RF-1 = `?ntdst_page=0` invoked a parameterless route from any URL); gate fix wave `d47e963`..`b2d4f7f` (matched-rule pass-through gate — live: `/?ntdst_page=0` answers the front page; HEAD served by GET; plain-permalink guard; `jsonPayload`/`compilePattern` pins; README 5.0.0 table rejoined + markdown-integrity test; INV-5/6/8 re-pinned, two INV-6 Deliberate exceptions). Scoped re-review APPROVED (16/16). Suite 1149, gate 0; daan `/`, `/card/`, vCard, 404 page clean; daan Unit 254. verify-ratio 2.80 over 2.5 (structural). Parked to STATE: dead-but-pinned Response surface; notFound() convergence; shakeout probes for real `matched_rule`/flush/canonical.

---

### Cluster 5 — Theme, docs, release (CORE)

Stakes: standard — the tag is irreversible; everything before it is a doc or a trim.

Behaviour: Theme wires only what WordPress's theme setup functions do, README documents every break with its replacement, and v5.0.0 is tagged with every invariant holding.
Observable: `git -C ~/Sites/ntdst-core tag --points-at HEAD` prints `5.0.0` (Stefan's ruling 2026-08-23: the BARE scheme; the remote `5.0.0` at `3cc96b7` is MOVED to the release commit — `git tag -f 5.0.0 && git push origin main && git push --force origin refs/tags/5.0.0` — only after his README read); `bash -c 'cd ~/Sites/ntdst-core && for s in public_fields publicRows publicRow getPublicShape ntdst_actions get_nonce ntdst-api.js apiSuccess publicSurface before_dispatch ntdst_redirect getMimeType registerMimeType Theme::style; do grep -c "$s" README.md; done'` prints 14 non-zero numbers (baseline at `0f741cb`: 6 of 14 — `ntdst_actions`, `get_nonce`, `publicSurface`, `before_dispatch` and two more; T13 owes the other eight).
RED until: tests/Unit/ThemeTrimTest.php
> Freshness review 2026-08-23: the file EXISTS (core-trim T09, 6 cases incl. `testTheRemovedProxyIsAnUndefinedMethod` `:196`); T12 EXTENDS it and the RED sentinel is the new case `testThemeWiresOnlyWhatWordPressThemeSetupDoes`

- [x] T12 — Theme trims: no style()/script(), no Pages forwarders, no the_generator, excerpt filters only when configured [Tier A]  (files: core/Theme.php, tests/Unit/ThemeTrimTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-12
  Test-author: solo — cluster stakes standard; hook wiring, no authorization semantics
  Proven by: new test
  Unit test: `NTDST_Theme` has no methods `style` (`:242`), `script` (`:261`), `single`, `page`, `archive` (`:292,310,333` — since core-trim T09 these are one-line `ntdst_pages()->…` forwarders, so deletion relocates no logic); constructing it with an empty config and firing `setup_theme()` records 0 `the_generator` filters and 0 `excerpt_length`/`excerpt_more` filters; with `['excerpt' => ['length' => 20]]` it records exactly one `excerpt_length` filter; `$theme->pages()` throws `Error` — core-trim FR-8 deleted the mixin proxy; the existing case `ThemeTrimTest::testTheRemovedProxyIsAnUndefinedMethod` stands unmodified (freshness-review ruling 2026-08-23); the other five existing cases survive; the docblock's `apiAction`/`ntdst_actions` mentions (`core/Theme.php:23,25`) are T08's; the five removed method names extend `METHOD_PINS["core/Theme.php"]` in bin/guard.sh from `"__call mixin when"` to `"__call mixin when style script single page archive"` and join `removedSymbolProvider()` (README rows as `Theme::style` etc., T13).

- [x] T13 — README 5.0.0 upgrade guide for every break; philosophy and routing-services reconciled; invariants say "holds" [Tier B]  (files: README.md, docs/philosophy.md, specs/routing-services/spec.md, ARCHITECTURE-INVARIANTS.md)
  Satisfies: FR-13, FR-16, SC-8
  Test-author: solo
  Proven by: machine gate — the SC-8 grep + PackageBootIntegrityTest (README is scanned outside its `## Versions` section)
  Integration test: README's `### 5.0.0 — BREAKING` section (EXISTS at `README.md:~110-662` with Clusters 1–2, field-types and core-trim content — T13 EXTENDS it) carries a was → now table with one row per removed symbol (the 14 of SC-8 at minimum, plus `/download`, `ntdst/api_download`, `verifyOrigin`, `Pages::redirect`, `Response::addPath`, `Theme::single/page/archive`), a "Declaring fields for REST" block (`show_in_rest => true`, sub-fields, the `custom-fields` note, the disclosure warning), the READ-allow-list rule (any verb outside GET/HEAD/OPTIONS needs a capability or a callable) added to the EXISTING "`->public()` is the one door" (`:~183`) and "Asserting your anonymous surface" (`:~203`) blocks (the three states and the `get_routes()` assertion are already written), the CORS note is ALREADY WRITTEN (`:~121`, `:~133` — site-wide, REST-only, the unconditional-credentials sentence; landed with T06 — do not duplicate), and a "Page routes are rewrite rules" block (flush-on-change, literal-first-segment rule); README no longer describes `/download`, `ntdst/api_download/`, `ntdst_actions()` or `get_nonce` as live outside `## Versions`; docs/philosophy.md loses all THREE stale statements of the old rule — `:37` ("…`permission` never registers, and the permission runs once per request instead"), `:91-92` ("A route without a callable `permission` is refused at registration, loudly, with no implicit…") and `:127` ("…A REST route with no permission…") (re-pinned at the freshness review) — each replaced by the internal-default + READ-allow-list rule; every README migration row is mirrored in `removedSymbolProvider()` (`testEveryRemovedFiveOhSymbolHasAMigrationRow` `:~1012` enforces both halves); specs/routing-services/spec.md's Status line (`:7`) reads "superseded by specs/core-shape (2026-08-23)"; every `**Status:**` line in ARCHITECTURE-INVARIANTS.md reads "holds (established 2026-08-23, specs/core-shape)"; the SC-8 grep prints 14 non-zero counts.

- [x] T14 — release: invariants checks, gate, tag 5.0.0 (bare, moved), push [Tier B]  (files: ntdst-core.php, composer.json)
  Satisfies: FR-15, FR-16, SC-7, SC-9
  Test-author: solo
  Proven by: machine gate — `composer gate` + the ten INV checks (INV-8/9/10 landed with field-types and core-trim; `bash bin/zero-readers.sh` is INV-9's)
  Integration test: all TEN mechanical checks in ARCHITECTURE-INVARIANTS.md return their stated result (the INV-1/2/4/6 greps print 0 lines; INV-3's hits outside api/Rest.php are all GET; INV-5's `static array` hits are only `Rest::$limits`, `Rest::$reported`, `Rest::$instances`, `Rest::$cors` (declared/max_age only), `Rest::$corsOrigins`, `Rest::$corsResolvers`, `Rest::$defaults`, `Template_Loader::$custom_paths`, `$template_cache`, `$page_data`, plus the registries INV-5 names as deliberate exceptions — `Data::$models`, `Data::$globalScopes` (`api/Data.php:~1869,1878`), `Logger::$batchedLogs` (`services/Logger.php:~47`), `FieldTypes::$table`/`::RETIRED` — the three `Rest::` additions being the deliberate exceptions the invariant names (T14 adds the three core registries to INV-5's exception list with one line each); INV-7's transient grep names only support/RateLimiter.php); `composer gate` exits 0 with 0 failures; the plugin header still says 5.0.0; `git tag -f -a 5.0.0 -m "…"` on `main`, `git push origin main` and `git push --force origin refs/tags/5.0.0` (Stefan's ruling: bare `5.0.0`; the old remote tag at `3cc96b7` moves) — only after the [HUMAN] yield below (Stefan reads README's 5.0.0 section himself); `ntdst-core.php:5` and `composer.json:44` already read 5.0.0 / `5.0.x-dev` — no edit expected.
> T14 CLOSED 2026-08-23: machine half at `cc9227c` (ten INV checks verbatim, doc re-pinned; SC-7 gate 0 + four denial tests by name; SC-8 14/14; guard 0). Stefan read the README ('readme ok') and the tag moved: `5.0.0` now at `cc9227c` (annotated `ca8c750`, was `3cc96b7`), pushed with `--force` per the seam ruling; `feat/core-shape` pushed. The Observable's `git push origin main` clause is deferred to Stage 3's finishing (merge); the tag is on the release COMMIT, which the merge will carry.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && bash -c 'grep -rn "register_post_meta\|register_meta(" --include=*.php . | grep -vE "^(\./)?api/Data\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)" | wc -l' && git tag --points-at HEAD`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity + invariant-auditor)*

---

## [HUMAN] yield points

- After T01 — the daan branch `chore/core-path-repo` exists and pins a dev version; confirm it is understood as never-merged before any later task runs `ddev composer update` on it.
- After Cluster 3's review gate — `Actions.php` is gone from the working tree; todai-client and daan will fatal on their next `composer update` until they migrate. Confirm the fleet-breakage acceptance (D5) still stands before Cluster 4 starts.
- Before T14's tag — tagging `v5.0.0` and pushing is public and irreversible for every `^5.0` consumer. Confirm README (T13) has been read once by a human.
- After T14 — daan `master` and todai-client still point at the VCS `3cc96b7` lock; their `composer update` + handler migration is the next spec, not this one.
