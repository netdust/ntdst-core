# core-shape — tasks

Plan: `specs/core-shape/plan.md` · Spec: `specs/core-shape/spec.md` (rev 1) · Invariants: `ARCHITECTURE-INVARIANTS.md`

Repos: **CORE** = `~/Sites/ntdst-core` (every code task). **DAAN** = `~/Sites/daan`, branch `chore/core-path-repo` only (T01), never merged.

Every CORE task closes with `cd ~/Sites/ntdst-core && composer gate` exit 0 and one atomic commit on a branch off `main` named in the task.

---

### Cluster 1 — Data declares, WordPress reads (CORE + DAAN branch)

Stakes: high — this is the anonymous-exposure surface; a wrong filter or schema is disclosure on every site that updates.

Behaviour: a field declared show_in_rest => true appears on WordPress's own /wp/v2/<type> endpoint for anonymous callers, and a field not declared never does — including a sub-field inside a declared repeater.
Observable: `curl -s https://daan.ddev.site/wp-json/wp/v2/gig/<id> | jq '.meta'` lists exactly the declared keys and omits the seeded `daan_internal_promo_budget` probe.
RED until: tests/Unit/DataRegistersRestMetaTest.php

- [ ] T01 — daan path repo branch and the probe declarations [Tier B]  (files: composer.json, ../daan/composer.json, ../daan/web/app/mu-plugins/daan-core/services/musician/TourService.php)
  Satisfies: FR-14, SC-10
  Test-author: solo — configuration, no code path
  Proven by: machine gate — `ddev composer update netdust/ntdst-core` then `diff -rq`
  Integration test: in CORE, composer.json gains `"extra": {"branch-alias": {"dev-main": "5.0.x-dev"}}`; in DAAN on new branch `chore/core-path-repo` (off master), composer.json gains repository `{"type":"path","url":"/home/ntdst/Sites/ntdst-core","options":{"symlink":false}}` FIRST in `repositories` and `require` pins `"netdust/ntdst-core": "5.0.x-dev"`; `ddev composer update netdust/ntdst-core` succeeds and `diff -rq --exclude=vendor --exclude=.git ~/Sites/ntdst-core web/app/mu-plugins/ntdst-core` prints 0 lines; `git -C ~/Sites/daan show master:composer.lock | grep -A3 '"name": "netdust/ntdst-core"'` still shows the VCS source. On the same DAAN branch, TourService's gig fields `venue_city` and `venue_country` gain `'show_in_rest' => true` (the probe declarations SC-1 reads); `ddev exec wp post list --post_type=gig --format=ids | head -1` names the gig id used by SC-1.

- [ ] T02 — restSchemaFor(): field type to REST schema, sub-fields opt in [Tier A]  (files: api/Data.php, tests/Unit/DataRegistersRestMetaTest.php)
  Satisfies: FR-2, FR-3
  Test-author: split
  Proven by: new test
  Unit test: `restSchemaFor('f')` returns null for an undeclared field; `['type' => 'integer']` for int/signed_int/image/file; `number` for float; `boolean` for bool; `string` for text/textarea/html/wysiwyg/content/select/date, with `format: email` for email and `format: uri` for url; `['type' => 'array', 'items' => ['type' => 'string']]` for array; `['type' => 'array', 'items' => ['type' => 'integer']]` for gallery/relation/post_relation/person; `['type' => 'object', 'additionalProperties' => true]` for json; for a repeater with sub-fields {a: show_in_rest, b: show_in_rest, c: not} → `['type' => 'array', 'items' => ['type' => 'object', 'properties' => [a, b]]]` with exactly 2 properties and `additionalProperties => false`; an unknown type throws InvalidArgumentException (same rule as getDefaultSanitizer). Data.php gains no method other than restSchemaFor() and registerRestMeta() (T03) — assert via ReflectionClass that no public method name contains 'project', 'shape' or 'public'.

- [ ] T03 — register(): every declared field goes to register_post_meta(), custom-fields support added [Tier A]  (files: api/Data.php, tests/Unit/DataRegistersRestMetaTest.php)
  Satisfies: FR-1, SC-1
  Test-author: split
  Proven by: new test
  Unit test: with `register_post_meta` captured through Brain Monkey `Functions\expect()`, a model with 3 fields declared `show_in_rest => true` (one repeater) and 2 undeclared yields exactly 3 calls, each with `$postType`, the prefixed meta key, `type` from restSchemaFor(), `single => true`, a callable `sanitize_callback` that equals the model's sanitizer for that field, an `auth_callback` that returns `user_can($userId, 'edit_post', $postId)`, and `show_in_rest` equal to `true` for scalars and `['schema' => …]` for array/object types; a model with 0 declared fields yields 0 calls and `register_post_type` receives `supports` WITHOUT `custom-fields`; with ≥1 declared field `register_post_type` receives `supports` containing `custom-fields` exactly once (not duplicated when the site already lists it); a field declared `show_in_rest => 'yes'` (non-strict true) is NOT registered.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && git checkout chore/core-path-repo && ddev composer update netdust/ntdst-core && curl -s https://daan.ddev.site/wp-json/wp/v2/gig/$(ddev exec wp post list --post_type=gig --format=ids | cut -d' ' -f1) | jq '.meta'`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster 2 — Rest: internal by default, WordPress's lists (CORE)

Stakes: high — the permission default of every route, and the CORS allow-list.

Behaviour: a route that says nothing is reachable only by a logged-in user; ->public() is the one way to make it anonymous; a write verb that names no capability does not exist; the CORS allow-list is WordPress's own and fails closed.
Observable: `wp eval 'do_action("rest_api_init"); foreach (rest_get_server()->get_routes("ntdst/v1") as $r => $h) echo $r, " ", is_string($h[0]["permission_callback"]) ? $h[0]["permission_callback"] : "closure", "\n";'` prints `is_user_logged_in` for an unnamed GET and `__return_true` only for routes marked public.
RED until: tests/Unit/NtdstRestDefaultsTest.php

- [ ] T04 — internal default, public(), write-verb refusal, WordPress's callables [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestDefaultsTest.php, tests/Unit/NtdstRestTest.php)
  Satisfies: FR-4, SC-2
  Test-author: split
  Proven by: new test
  Unit test: with `register_rest_route` captured, `ntdst_rest('x/v1')->get('/t', $h)` registers with `permission_callback === 'is_user_logged_in'` (the string, not a closure); `->get('/t', $h)->public()` registers with `'__return_true'`; `->get('/t', $h, ['permission' => 'manage_options'])` registers a closure that returns `current_user_can('manage_options')`; `->post('/t', $h)` with no capability registers NOTHING (0 calls for that route) and fires `_doing_it_wrong` once; `->post('/t', $h, ['permission' => 'logged_in'])` is likewise refused; `->post('/t', $h, ['permission' => 'edit_posts'])` registers; `->delete('/t', $h)->public()` is refused (public on a write); `public()` called after `rest_api_init` already fired refuses with `_doing_it_wrong` and changes no registration; `guard()` still evaluates the permission before `NTDST_RateLimiter::attempt` (the existing auth-before-limiter test stays green unmodified). NtdstRestTest's "absent without permission" assertion is rewritten to the write-verb form by the test-author, with the file's SPLIT header updated to cite FR-4.

- [ ] T05 — delete the surface registry; README shows the WordPress assertion [Tier B]  (files: api/Rest.php, tests/Unit/NtdstRestSurfaceTest.php, README.md, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-5, SC-6
  Test-author: solo
  Proven by: machine gate — `composer gate` + the guard grep
  Unit test: tests/Unit/NtdstRestSurfaceTest.php is deleted; `grep -c "surface\|\$surface" api/Rest.php` prints 0; `publicSurface`, `opaqueSurface`, `forgetSurface` and `NtdstRestSurfaceTest` are added to bin/guard.sh's REMOVED grep and PackageBootIntegrityTest::removedSymbolProvider(); README gains a "Asserting your anonymous surface" block with the three-line `rest_get_server()->get_routes($ns)` filter on `permission_callback === '__return_true'`; composer gate exits 0 and the suite loses exactly the 6 NtdstRestSurfaceTest tests and no other.

- [ ] T06 — cors() feeds allowed_http_origins; the emitter decides with is_allowed_http_origin() [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestCorsTest.php)
  Satisfies: FR-6, SC-6
  Test-author: split
  Proven by: new test
  Unit test: after `cors(['https://a.test'])`, applying the recorded `allowed_http_origins` filter to `['http://site.test', 'https://site.test']` returns those two plus `https://a.test` and nothing else; `cors(['*'])` refuses with `_doing_it_wrong` and adds nothing; `Rest::$cors` holds no `origins` key (the class keeps only `credentials` and `max_age`); `corsDecision('https://a.test', $policy)` with `is_allowed_http_origin` stubbed true sets `Access-Control-Allow-Origin: https://a.test` and REMOVES `Access-Control-Allow-Credentials` unless `cors(..., credentials: true)` was called; with it stubbed false the decision removes both headers (fail closed); a callable `$origins` is applied by the filter callback rather than stored; `mountCors()` still removes `rest_send_cors_headers` and mounts `sendCors`.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && ddev exec wp eval 'do_action("rest_api_init"); foreach (rest_get_server()->get_routes("ntdst-baseline/v1") as $r => $h) echo $r, " ", is_string($h[0]["permission_callback"]) ? $h[0]["permission_callback"] : "closure", "\n";'`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster 3 — Actions out (CORE)

Stakes: high — removes a CSRF mechanism and moves the admin picker's gate onto a route.

Behaviour: the relation picker works through a REST route and wp.apiFetch with the same per-type capability gate, and the package has no command dispatcher, no nonce endpoint and no JS client of its own.
Observable: on daan's edit screen the relation picker returns results for an Administrator; `curl -s -o /dev/null -w '%{http_code}' 'https://daan.ddev.site/wp-json/ntdst/v1/relation/search?search=a&post_type[]=release'` prints 401; `ls ~/Sites/ntdst-core/api/Actions.php ~/Sites/ntdst-core/assets/js/ntdst-api.js` prints two "No such file" lines.
RED until: tests/Unit/RelationSearchRouteTest.php

- [ ] T07 — relation search becomes GET /ntdst/v1/relation/search; the picker uses wp.apiFetch [Tier A]  (files: admin/RelationField.php, admin/MetaboxGenerator.php, assets/js/metabox-fields.js, tests/Unit/RelationSearchRouteTest.php)
  Satisfies: FR-8, SC-4
  Test-author: split
  Proven by: new test
  Unit test: constructing NTDST_RelationField registers (captured `register_rest_route`) exactly one route `ntdst/v1` `/relation/search` with method GET, a closure `permission_callback`, a `rate_limit` of 60/60s declared through ntdst_rest(), and `args` declaring `search` (string, required, sanitize_text_field) and `post_type` (array of string); the permission closure returns false for an empty `post_type`, false when any requested type is not a declared relation target, false when `current_user_can(<type>->cap->edit_others_posts)` is false for any requested type, true only when every requested type passes; the handler returns `['results' => [['id' => int, 'title' => string], …]]` capped at 20 via `ntdst_get_formatted_posts`; `grep -c "ntdst_actions\|ntdstAPI" admin/RelationField.php admin/MetaboxGenerator.php assets/js/metabox-fields.js` prints 0 for all three; MetaboxGenerator's enqueue lists `wp-api-fetch` in `$deps` and no `ntdst-api`; metabox-fields.js calls `wp.apiFetch({ path: '/ntdst/v1/relation/search?…' })` and reads `response.results`.

- [ ] T08 — delete Actions.php, get_nonce, the JS client, the api* envelopes; extend the removed-symbol guards [Tier B]  (files: api/Actions.php, assets/js/ntdst-api.js, ntdst-core.php, api/Response.php, core/Theme.php, core/Pages.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php, tests/Unit/ActionsDropsDownloadTest.php, tests/Unit/ActionsOriginGateTest.php, tests/Unit/ActionsRateBucketTest.php, tests/Unit/NtdstActionsTest.php, tests/Unit/RoutingFacadesTest.php)
  Satisfies: FR-7, SC-3
  Test-author: solo
  Proven by: machine gate — `composer gate` + PackageBootIntegrityTest + the SC-3 grep
  Unit test: the four Actions test files and api/Actions.php and assets/js/ntdst-api.js are deleted; ntdst-core.php no longer requires Actions.php, no longer calls `ntdst_actions()`, and `ntdst_enqueue_api_client()` is gone; `NTDST_Response::apiSuccess/apiError/apiSuccessResponse/apiErrorResponse` are gone; `ntdst_actions`, `NTDST_Actions`, `ntdstAPI`, `ntdst_enqueue_api_client`, `apiSuccess`, `apiError`, `apiSuccessResponse`, `apiErrorResponse`, `get_nonce`, `ntdst/api_data`, `ntdst/api/public_actions` are added to bin/guard.sh's REMOVED grep and to PackageBootIntegrityTest::removedSymbolProvider(); RoutingFacadesTest no longer asserts `ntdst_actions` exists (it asserts it does NOT); Theme.php:36,60 and Pages.php:13–20 docblocks no longer mention apiAction/api_data/ntdst_actions; the SC-3 grep prints 0 lines; composer gate exits 0.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && curl -s -o /dev/null -w '%{http_code}\n' 'https://daan.ddev.site/wp-json/ntdst/v1/relation/search?search=a&post_type[]=release'`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster 4a — one template loader (CORE)

Stakes: standard — a wrong template is visible on the page, not a leak.

Behaviour: every template path in the package resolves through one function, WordPress's own hierarchy candidates are searched in the plugin template directories, and there is one way to hand data to a template.
Observable: `grep -rn "locate_template(\|extract(" --include=*.php ~/Sites/ntdst-core/api ~/Sites/ntdst-core/core` prints exactly one line, inside `NTDST_Template_Loader::locate()`.
RED until: tests/Unit/TemplateLoaderTest.php

- [ ] T09 — NTDST_Template_Loader in its own file: one locate(), {$type}_template over WP's candidates, one hand-off [Tier A]  (files: core/TemplateLoader.php, api/Response.php, services/Mailer.php, ntdst-core.php, tests/bootstrap.php, tests/Unit/TemplateLoaderTest.php)
  Satisfies: FR-10
  Test-author: solo — cluster stakes standard; template resolution, no authorization semantics
  Proven by: new test
  Unit test: the class `NTDST_Template_Loader` is defined in core/TemplateLoader.php and not in api/Response.php; `init()` adds `single_template`, `page_template`, `archive_template`, `singular_template` and `index_template` filters with 3 accepted args, plus `theme_file_path`; `pickFromCandidates('/theme/single.php', 'single', ['single-gig-x.php', 'single-gig.php', 'single.php'])` returns `<custom path>/single-gig.php` when that file exists in a registered path and `/theme/single.php` otherwise — the candidate list is WordPress's argument, and the method contains no hard-coded template name; `locateInCustomPaths()` returns whatever `locate()` returns; `locate('../../etc/passwd')` returns null (guard kept); `NTDST_Response` has no `addPath()` method and no `extra_paths` property; `NTDST_Response::html('t', [], ['/x'])` passes `['/x']` to `locate()`; `Mailer::renderTemplate()` calls `html($template, $data, $template_paths)`; `Template_Loader::templateInclude` does not exist.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && curl -s -o /dev/null -w '%{http_code}\n' https://daan.ddev.site/`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity)*

---

### Cluster 4b — Pages on rewrite rules, Response trimmed (CORE)

Stakes: standard — routing and headers; the download header policy is kept byte-identical.

Behaviour: a page route is a WordPress rewrite rule, a template callback returns a path and never exits, and Response keeps only what WordPress has no word for.
Observable: `curl -s -o /dev/null -w '%{http_code}\n' https://daan.ddev.site/card` prints 200 while `ddev exec wp eval 'global $wp_rewrite; print_r(array_filter(array_keys($wp_rewrite->wp_rewrite_rules()), fn($r) => str_starts_with($r, "^card")));'` lists the card rules, and `grep -rn "is_404 = false\|redirect_canonical" ~/Sites/ntdst-core/core ~/Sites/ntdst-core/api` prints nothing.
RED until: tests/Unit/NtdstPagesTest.php

- [ ] T10 — path() registers rewrite rules and dispatches on template_redirect; callbacks return a path [Tier A]  (files: core/Pages.php, tests/Unit/NtdstPagesTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-9, SC-5
  Test-author: solo — cluster stakes standard; routing behaviour, no authorization semantics
  Proven by: new test
  Unit test: `path('/card/:slug', $cb)` adds (captured `add_rewrite_rule`) the rule `^card/([^/]+)/?$` → `index.php?ntdst_page=0&ntdst_p_slug=$matches[1]` at `top`, and the `query_vars` filter output contains `ntdst_page` and `ntdst_p_slug`; `path('/:anything', $cb)` (placeholder first segment) is refused with `_doing_it_wrong` and adds no rule; on `template_redirect` with `get_query_var('ntdst_page') === '0'` and method matching, the callback receives `['slug' => <value>]` and its string return becomes the `template_include` result via one filter, a null return exits nothing and sets nothing, and `set_404()` is called when the callback returns false; a POST-declared route does not dispatch on GET; the option `ntdst_pages_rules_hash` is written and `flush_rewrite_rules(false)` called only when the hash differs from the stored one (second init with the same rules: 0 flush calls); `NTDST_Pages` has no methods `redirect`, `handleTemplateInclude`, `resolveRouteResult`, `commitOk`, `renderResponse`, `preventRedirectForRoutes`; `template()`/`when()` callbacks returning a string path are returned to WordPress and a non-string return leaves `$template` unchanged — no `exit`, no `render()`; the removed method names are added to bin/guard.sh and PackageBootIntegrityTest.

- [ ] T11 — Response keeps the file policy and html(); loses every envelope and table WordPress owns [Tier A]  (files: api/Response.php, tests/Unit/DownloadHeadersTest.php, tests/Unit/ResponseRenderStatusTest.php, tests/Unit/ResponseTrimTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-11, SC-6
  Test-author: solo — cluster stakes standard; header policy unchanged, asserted byte-identical
  Proven by: new test
  Unit test: `NTDST_Response` has no methods `json`, `jsonPayload`, `render`, `renderError`, `getErrorHtml`, `commitRenderStatus`, `getMimeType`, `registerMimeType`, `addPath` and no static `$mimeTypes`; `ntdst_redirect` is not defined; `error('m', 403)->getStatus()` is 403 and `notFound()` calls `$wp_query->set_404()` (captured) and sets status 404; `html('t', ['a' => 1])` calls `load_template($file, false, ['a' => 1])` inside an output buffer and returns the buffer; `downloadHeaders(3, 'Ünïcode name.pdf')` returns the same four lines as before this task (DownloadHeadersTest unmodified except that nosniff is now asserted as a `send_nosniff_header()` call recorded by Brain Monkey, and the Content-Type line comes from `wp_check_filetype('…pdf', wp_get_mime_types())['type']`); `NTDST_Response::mimeTypes(['pdf' => 'application/pdf'])` returns the input plus `json`, `xml`, `vcf`, `svg` with their types and is mounted on the `mime_types` filter; a filename with an unknown extension yields `application/octet-stream`; ResponseRenderStatusTest is deleted (its subject is gone); removed names are added to bin/guard.sh and PackageBootIntegrityTest.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && ddev exec wp rewrite flush && curl -s -o /dev/null -w '%{http_code}\n' https://daan.ddev.site/card && curl -sI https://daan.ddev.site/card/contact.vcf | grep -i "content-disposition\|x-content-type-options"`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity)*

---

### Cluster 5 — Theme, docs, release (CORE)

Stakes: standard — the tag is irreversible; everything before it is a doc or a trim.

Behaviour: Theme wires only what WordPress's theme setup functions do, README documents every break with its replacement, and v5.0.0 is tagged with every invariant holding.
Observable: `git -C ~/Sites/ntdst-core tag --points-at HEAD` prints `v5.0.0`; `bash -c 'cd ~/Sites/ntdst-core && for s in public_fields publicRows publicRow getPublicShape ntdst_actions get_nonce ntdst-api.js apiSuccess publicSurface before_dispatch ntdst_redirect getMimeType registerMimeType Theme::style; do grep -c "$s" README.md; done'` prints 14 non-zero numbers.
RED until: tests/Unit/ThemeTrimTest.php

- [ ] T12 — Theme trims: no style()/script(), no Pages forwarders, no the_generator, excerpt filters only when configured [Tier A]  (files: core/Theme.php, tests/Unit/ThemeTrimTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-12
  Test-author: solo — cluster stakes standard; hook wiring, no authorization semantics
  Proven by: new test
  Unit test: `NTDST_Theme` has no methods `style`, `script`, `single`, `page`, `archive`; constructing it with an empty config and firing `setup_theme()` records 0 `the_generator` filters and 0 `excerpt_length`/`excerpt_more` filters; with `['excerpt' => ['length' => 20]]` it records exactly one `excerpt_length` filter; `$theme->pages()` still returns the NTDST_Pages singleton (the mixin proxy); the docblock no longer mentions apiAction or ntdst_actions; the five removed method names are added to bin/guard.sh and PackageBootIntegrityTest (as `Theme::style` etc. in README's removed list).

- [ ] T13 — README 5.0.0 upgrade guide for every break; philosophy and routing-services reconciled; invariants say "holds" [Tier B]  (files: README.md, docs/philosophy.md, specs/routing-services/spec.md, ARCHITECTURE-INVARIANTS.md)
  Satisfies: FR-13, FR-16, SC-8
  Test-author: solo
  Proven by: machine gate — the SC-8 grep + PackageBootIntegrityTest (README is scanned outside its `## Versions` section)
  Integration test: README's `### 5.0.0 — BREAKING` section carries a was → now table with one row per removed symbol (the 14 of SC-8 at minimum, plus `/download`, `ntdst/api_download`, `verifyOrigin`, `Pages::redirect`, `Response::addPath`, `Theme::single/page/archive`), a "Declaring fields for REST" block (`show_in_rest => true`, sub-fields, the `custom-fields` note, the disclosure warning), a "Routes are internal by default" block (the three states, the write-verb rule, the `get_routes()` assertion from T05), a "CORS is WordPress's list" note (admin-ajax shares it), and a "Page routes are rewrite rules" block (flush-on-change, literal-first-segment rule); README no longer describes `/download`, `ntdst/api_download/`, `ntdst_actions()` or `get_nonce` as live outside `## Versions`; docs/philosophy.md §1 replaces "a route without a callable permission never registers" with the internal-default + write-verb rule; specs/routing-services/spec.md's Status line reads "superseded by specs/core-shape (2026-08-23)"; every `**Status:**` line in ARCHITECTURE-INVARIANTS.md reads "holds (established 2026-08-23, specs/core-shape)"; the SC-8 grep prints 14 non-zero counts.

- [ ] T14 — release: invariants checks, gate, tag v5.0.0, push [Tier B]  (files: ntdst-core.php, composer.json)
  Satisfies: FR-15, FR-16, SC-7, SC-9
  Test-author: solo
  Proven by: machine gate — `composer gate` + the seven INV checks
  Integration test: all seven mechanical checks in ARCHITECTURE-INVARIANTS.md return their stated result (the INV-1/2/4/6 greps print 0 lines; INV-3's hits outside api/Rest.php are all GET; INV-5's `static array` hits are only `Rest::$limits`, `Rest::$reported`, `Rest::$instances`, `Rest::$cors` (credentials/max_age only), `Template_Loader::$custom_paths`, `$template_cache`, `$page_data`; INV-7's transient grep names only support/RateLimiter.php); `composer gate` exits 0 with 0 failures; the plugin header still says 5.0.0; `git tag -a v5.0.0 -m "…"` on `main` and `git push origin main --tags` — only after the [HUMAN] yield below.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && bash -c 'grep -rn "register_post_meta\|register_meta(" --include=*.php . | grep -v "^./api/Data.php\|/vendor/\|/tests/" | wc -l' && git tag --points-at HEAD`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity + invariant-auditor)*

---

## [HUMAN] yield points

- After T01 — the daan branch `chore/core-path-repo` exists and pins a dev version; confirm it is understood as never-merged before any later task runs `ddev composer update` on it.
- After Cluster 3's review gate — `Actions.php` is gone from the working tree; todai-client and daan will fatal on their next `composer update` until they migrate. Confirm the fleet-breakage acceptance (D5) still stands before Cluster 4 starts.
- Before T14's tag — tagging `v5.0.0` and pushing is public and irreversible for every `^5.0` consumer. Confirm README (T13) has been read once by a human.
- After T14 — daan `master` and todai-client still point at the VCS `3cc96b7` lock; their `composer update` + handler migration is the next spec, not this one.
