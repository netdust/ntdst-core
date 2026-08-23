# core-shape — ntdst-core 5.0.0: declare in Data, route through Rest, WordPress does the rest

**Status:** spec, revision 3 — approved by Stefan 2026-08-22 (Cluster 2 gate rulings: FR-6 declared origins apply to REST requests only; FR-4 a string permission is a capability; `defaults()` may set a posture, never an opening; write-verb rule is a READ allow-list; revision 2 2026-08-22 — Cluster 1 gate rulings approved by Stefan: FR-1 attribution, FR-2 json and repeater rows, FR-3 readers, SC-1 path, assumption on `additionalProperties`; revision 1 2026-08-23 09:40 — ground-truth amendments to FR-2, FR-9, FR-14, SC-10, two assumptions) — written 2026-08-23 from the rulings in
`docs/plans/2026-08-23-core-shape-brief.md`, confirmed in brainstorming the same
morning. Awaiting Stefan's review, then `writing-plans`.
**Target release:** v5.0.0 (breaking, unreleased — the 5.0.0 `Rest` rewrite is
pushed but untagged, so every break below lands in a major nobody has taken).
**Invariants:** `ARCHITECTURE-INVARIANTS.md` INV-1…7, authored 2026-08-23 for
this spec. Each FR below names the invariant it establishes.

---

## Intent decisions

Rulings, each a quote with a time. A change to any of these is a spec revision.

| # | Question | Ruling | Source |
|---|---|---|---|
| D1 | Does the model declare an exposure ceiling? | **Yes — `show_in_rest => true` per field, WordPress's meaning (opt in), and WordPress is the reader:** `register()` hands it to `register_post_meta()`. Custom routes filter themselves from `restFields()`. | Stefan 2026-08-22 01:40: "with show_in_rest we prove it. that's enough because if we use data.php to request meta, we need all and filter ourselves. we don't have any awkward public selfmade surface anymore" |
| D1a | Who adds `custom-fields` to `supports` when a field is declared? | **Core, automatically.** | Stefan 2026-08-23, brainstorm: "Core adds it automatically" |
| D2 | `Actions.php`? | **Removed. REST takes over commands.** | Stefan 2026-08-21 17:22 "if actions became obsolete, remove it"; 21:47 "we agreed that what actions does would be taken over by rest"; 2026-08-22 02:00 "actions goes" |
| D2a | Route default? | **Internal (`is_user_logged_in`) by default; `->public()` is the named exception; a capability is explicit.** | Stefan 2026-08-22 01:20: "public() is better, means that everything is default internal unless we set them public" |
| D2b | A write verb with no capability? | **Refused — does not register.** | Stefan 2026-08-23, brainstorm: "Refused — does not register" |
| D2c | Does internal imply `show_in_index => false`? | **No.** Separate word, WordPress default. | Stefan 2026-08-23, brainstorm |
| D2d | `publicSurface()` registry? | **Deleted.** WordPress's `get_routes()` is the registry. | Stefan 2026-08-22 01:30 "publicSurface(), what is this?" → brief ruling, confirmed in brainstorm |
| D2e | CORS list? | **WordPress's `allowed_http_origins`; core keeps only the fail-closed REST emitter. Credentials off unless asked.** | Stefan 2026-08-22 02:00: "we don't invent if wordpress has options for it"; 2026-08-23 brainstorm: "Off unless asked" |
| D3 | Same major or 6.0.0? | **All of it is 5.0.0.** | brief D3, unchallenged |
| D4 | Tag when? | **After all five phases are green.** | Stefan 2026-08-23, brainstorm |
| D5 | Scope | **The six request-flow files of core. Sites count consumers; they never design core.** Fleet breakage accepted. | Stefan 2026-08-22 00:45: "I know the fleet will break, that's ok, we can fix that. first we make sure that ntdst-core is the best shape it can be"; 00:55: "it started looking at sites like rossi and changing implementations based on that" |
| D6 | How does daan test unreleased core? | **Dev-only composer `path` repository.** No file substitution. | Stefan 2026-08-23, brainstorm |
| D7 | `Theme::single()/page()/archive()` forwarders? | **Dropped.** `$theme->pages()->single()` is one hop. | Stefan 2026-08-23, brainstorm |
| D8 | The governing rule | **Core wraps WordPress to make conventions easier, or adds what WordPress lacks. It never reinvents and never goes against WordPress.** | Stefan 2026-08-22 02:00: "ntdst-core wraps wordpress to implement conventions easier or adds smart methods so it becomes better, but doesn't reinvent or goes against wordpress" |

---

## Context — measured 2026-08-22/23 against `main` @ `a926ab4` and WordPress 7.0.4

Ground truth for every premise below is the brief's D2b–D2f tables, each line
cited to WordPress source (`~/Sites/daan/web/wp`). What is in the package today:

| file | lines | state |
|---|---|---|
| `api/Data.php` | 2,275 | query chain + field vocabulary (21 types). `restFields()` / `restSubFields()` exist (2026-08-22 00:17) with **zero readers**. `register()` never calls `register_post_meta()`; no model supports `custom-fields`, so `/wp/v2/<type>` emits no meta today |
| `api/Rest.php` | ~600 | wrap of `register_rest_route()`; refuses a route with no permission; memoizes permission; rate limit; own CORS list + emitter; own surface registry (`$surface`, +83 lines) |
| `api/Actions.php` | ~750 | command dispatcher: `POST /ntdst/v1/action`, `POST /ntdst/v1/get_nonce`, `public_actions` filter, `verifyOrigin()`. Consumers in core: `admin/RelationField.php:47`, `admin/MetaboxGenerator.php` (emitted JS), `assets/js/ntdst-api.js`, `ntdst-core.php:49` |
| `api/Response.php` | 755 | `NTDST_Response` + `NTDST_Template_Loader` in one file; `json()`, four `api*` envelope builders, inline error page, own MIME table, `render()` with `extract()`, hand-listed template hierarchy |
| `core/Pages.php` | 493 | `path()` matches `REQUEST_URI` in `template_include`, un-404s (`commitOk()`), suppresses `redirect_canonical`; three copies of the callback-result contract |
| `core/Theme.php` | 529 | config wrap over WP theme setup (sound); `style()`/`script()` (0 consumers, narrows WP's `$args`); three forwarders onto Pages; `the_generator` filter duplicated in baseline |

What WordPress provides for each reinvented piece (verified, file:line in the
brief): `rest_cookie_check_errors()` (CSRF for cookie auth), `action=rest-nonce`
+ `wp.apiFetch` nonce refresh, `WP_REST_Server::get_routes()`,
`allowed_http_origins` / `is_allowed_http_origin()`, `wp_send_json_*`,
`wp_die()` with `response`, `load_template($file, false, $args)`,
`wp_check_filetype()` + `mime_types`, `{$type}_template` with WP's candidate list,
`add_rewrite_rule()` + `query_vars` + `template_redirect`, `send_nosniff_header()`,
`register_post_meta()` with `show_in_rest` (requires `custom-fields` support).

Consumers on current-core sites (daan `^5.0`, todai-client `^5.0`, josworld,
netdust) were counted per method and are in the brief. They inform what breaks;
they do not inform the design (D5).

---

## Functional requirements

### Phase 1 — Data declares, WordPress reads

- **FR-1:** A field description may say `show_in_rest => true`, with WordPress's meaning: opt in. `NTDST_Data_Manager::register()` calls `$model->registerRestMeta($type)`, which calls `register_post_meta($type, <prefixed key>, [...])` for every such publishable field (revision 2) and adds `custom-fields` to the CPT's `supports` when at least one field is declared. An undeclared field is never registered with WordPress and never appears on `/wp/v2/<type>`. Establishes INV-1.
  Source: D1 + D1a — Stefan 2026-08-22 01:40 "with show_in_rest we prove it"; 2026-08-23 "Core adds it automatically"
- **FR-2:** The registration carries a REST schema derived from the field type: `int`/`signed_int`/`image`/`file` → `integer`; `float` → `number`; `bool` → `boolean`; `text`/`textarea`/`html`/`wysiwyg`/`content`/`select`/`date`/`email`/`url` → `string` (`email` → `format: email`, `url` → `format: uri`); `array` → `array` of `string`; `gallery`/`relation`/`post_relation`/`person` → `array` of `integer` (their sanitizers store a list of IDs — ground-truthed 2026-08-23 against `api/Data.php:231–244`); `json` → **not publishable** (a blob has no sub-field vocabulary to name — revision 2); `repeater` → `array` of `object` closed over its sub-fields, **publishable only when it has sub-fields and every one of them says `show_in_rest => true`** — WordPress validates the stored row against the closed schema, so a partial declaration would null the read and a legal write would wipe the undeclared keys (revision 2, proven on daan gig 297050). A declared field that is not publishable registers nothing and warns once per model. `single => true`; `sanitize_callback` is the model's sanitizer for the type; `auth_callback` requires `edit_post` on the post for the user WordPress asks about (`user_can($userId, 'edit_post', $postId)` — revision 2).
  Source: invented — approved 2026-08-23 (design §2, brainstorm); storage shapes ground-truthed 2026-08-23 (spec revision 1)
- **FR-3:** One private predicate (`declaresRest()`) is the only reader of the declaration inside `Data`; `restFields()`, `restSubFields()` and `restSchemaFor()` read through it, for routes that filter themselves (revision 2). `Data` gains no other exposure helper — no projector, no shape, no allow-list. Establishes INV-1.
  Source: Stefan 2026-08-21 22:14 "data is for quering. we would add a public/private to fields description"; A1/A2 in `docs/session-2026-08-21-actions-to-rest.md`

### Phase 2 — Rest is the one surface, internal by default

- **FR-4:** A route registered through `ntdst_rest()` with no `permission` is internal: its `permission_callback` is the string `'is_user_logged_in'`. `->public()` on the last-registered route sets `'__return_true'`. A string capability resolves to `current_user_can($cap)`. A write verb (`POST`, `PUT`, `PATCH`, `DELETE`) that names no capability is refused with one `_doing_it_wrong` and does not register. `show_in_index` is forwarded untouched. Establishes INV-3. Revision 3 (Cluster 2 gate): any unrecognised string is a capability — never a function name (`edit_post`, `activate_plugins`, `wp_is_json_request` are also global functions; a string must never gate a write as a function); the write-verb rule is a READ allow-list (`GET`, `HEAD`, `OPTIONS`) so a custom verb is a write; `defaults()` may set a posture (`'logged_in'`, a capability) but never an opening (`'public'` or a callable is refused and dropped), and defaults are frozen into a declaration when its verb runs; `->public()` marks only the declaration its verb returned and refuses loudly after that declaration has flushed. Stefan 2026-08-22, "drop the string": `'public'` as a permission STRING is not a permission — `->public()` is the one door (D2a); the string refuses the route like any unusable value (absent, one `_doing_it_wrong` naming `->public()`, one error log). `'logged_in'` stays.
  Source: D2a, D2b, D2c — Stefan 2026-08-22 01:20 "everything is default internal unless we set them public"; 2026-08-23 "Refused — does not register"; "No — separate word"
- **FR-5:** `NTDST_Rest::surface()`, `publicSurface()`, `opaqueSurface()`, `forgetSurface()` and the `$surface` property are removed. README shows the three-line assertion over `rest_get_server()->get_routes($ns)` filtered on `permission_callback === '__return_true'`. Establishes INV-5.
  Source: D2d — Stefan 2026-08-22 01:30 "publicSurface(), what is this?"; brief ruling "delete it"
- **FR-6:** `cors([...])` adds the origins to WordPress's `allowed_http_origins` filter **while a REST request is being served** (`wp_is_serving_rest_request()`; revision 3 — `send_origin_headers()` on admin-ajax/admin-post/the customizer grants `Access-Control-Allow-Credentials: true` unconditionally, so a REST declaration must not widen those surfaces) and keeps no list of its own; credentials are a per-origin attribute (revision 3); `sendCors()` decides with `is_allowed_http_origin()`, fails closed, sends `Access-Control-Allow-Credentials` only when `cors()` was asked, refuses `'*'`. `corsDecision()` stays a pure function over the WordPress list. Establishes INV-5.
  Source: D2e — Stefan 2026-08-22 02:00 "we don't invent if wordpress has options for it"; 2026-08-23 "Off unless asked"

### Phase 3 — Actions out

- **FR-7:** `api/Actions.php`, the routes `POST /ntdst/v1/action` and `POST /ntdst/v1/get_nonce`, `assets/js/ntdst-api.js`, the boot line `ntdst_actions()` in `ntdst-core.php`, `NTDST_Response::apiSuccess()` / `apiError()` / `apiSuccessResponse()` / `apiErrorResponse()`, and the Actions unit tests are removed. No alias, no forwarder. Establishes INV-2, INV-4.
  Source: D2 — Stefan 2026-08-21 17:22 "if actions became obsolete, remove it"; 21:47 "what actions does would be taken over by rest"
- **FR-8:** `NTDST_RelationField` registers `GET /ntdst/v1/relation/search` through `ntdst_rest()` with a closure permission that applies today's gate unchanged: the requested types must be declared relation targets and the caller must hold each target type's `edit_others_posts`. `assets/js/metabox-fields.js` calls it with `wp.apiFetch` (`wp-api-fetch` is a dependency of the enqueue); WordPress's nonce rule and refresh apply. Establishes INV-2, INV-4.
  Source: invented — approved 2026-08-23 (design §4, brainstorm); the gate itself is `admin/RelationField.php:handleRelationSearch()` today

### Phase 4 — Pages on rewrite rules; one template loader; Response trimmed

- **FR-9:** `NTDST_Pages::path($pattern, $callback, $method)` compiles `:param` placeholders into `add_rewrite_rule()` + registered query vars, and dispatches on `template_redirect` reading `get_query_var()`. The rule set is flushed when its hash (stored in an option) changes. A callback returns a template path or `null`; it never exits inside a WordPress filter. `commitOk()`, `resolveRouteResult()`, `renderResponse()`, `redirect()` (the package keeps one redirect: `NTDST_Response::redirect()`) and the `redirect_canonical` filter are removed; a refusal is `$wp_query->set_404()`. `template()` / `single()` / `page()` / `archive()` / `when()` remain as filter wraps whose callback returns a path. Establishes INV-6.
  Source: D8 — Stefan 2026-08-22 02:00 "doesn't reinvent or goes against wordpress"; design §5 approved 2026-08-23
- **FR-10:** `NTDST_Template_Loader` moves to `core/TemplateLoader.php`. `locate()` is the only search over the registry (traversal guard, hit-only cache). `templateInclude()` is replaced by a `{$type}_template` filter that iterates WordPress's own candidate list over the registry; `locateInCustomPaths()` calls `locate()`. `page()` + `ntdst_page_data()` is the one way data reaches a template. `NTDST_Response::addPath()` and `$extra_paths` are removed. *(Amended at the freshness review 2026-08-23, approved by Stefan 2026-08-23: the sentence "`Mailer` passes its directories to `locate()`'s existing `$extraPaths`" is void — core-trim FR-9 moved the Mailer out of core; `html()` keeps its two-parameter signature.)* Establishes INV-5, INV-6.
  Source: Stefan 2026-08-22 03:05 "how does pages.php work with NTDST_Template_Loader? no duplication anywhere?"; brief D2f; design §5 approved 2026-08-23
- **FR-11:** `NTDST_Response` loses `json()`, `render()`, `renderError()`, `getErrorHtml()`, `commitRenderStatus()`, `error()`'s rendering path, `$mimeTypes`, `getMimeType()`, `registerMimeType()`, and the global `ntdst_redirect()`. `html()` renders with `ob_start()` + `load_template($file, false, $data)`. `downloadHeaders()` resolves the MIME type with `wp_check_filetype($filename, wp_get_mime_types())` and emits nosniff through `send_nosniff_header()`; the four types WordPress's table lacks (`json`, `xml`, `vcf`, `svg`) are added through the `mime_types` filter. `notFound()` sets `$wp_query->set_404()`. `download()` / `inline()` / `downloadHeaders()` / `redirect()` / `with()` / `withData()` / `template()` / `getTemplate()` / `getStatus()` stay. Establishes INV-5.
  Source: Stefan 2026-08-22 03:15 "ok, audit response now"; brief D2c; design §5 approved 2026-08-23

### Phase 5 — Theme, docs, release

- **FR-12:** `NTDST_Theme` loses `style()`, `script()`, `single()`, `page()`, `archive()` and the `the_generator` filter; the `excerpt_length` / `excerpt_more` filters register only when the config sets a value. Everything else in `setup_theme()` and the mixin mechanism is unchanged.
  Source: D7 — Stefan 2026-08-23 "Drop them"; brief D2e (baseline `HeadCleanupService:143` owns `the_generator`)
- **FR-13:** README's 5.0.0 section documents every break in this spec with a migration table (was → now), and no longer describes `/download`, `ntdst/api_download/`, `ntdst_actions()` or `get_nonce` as live. `docs/philosophy.md` §1 states the new default (internal, not refused) and `specs/routing-services/spec.md` is marked superseded by this spec.
  Source: brief §1 "README is now wrong about the package"; D4 — Stefan 2026-08-23 "After all five phases are green"
- **FR-14:** daan gets a never-merged branch `chore/core-path-repo` whose `composer.json` adds a `path` repository for `~/Sites/ntdst-core` (mirrored, not symlinked — daan's DDEV container cannot follow a host symlink) and requires `netdust/ntdst-core:5.0.x-dev`; core's `composer.json` gains the branch alias `dev-main → 5.0.x-dev`. `ddev composer update netdust/ntdst-core` re-mirrors the working tree; every phase's observable is driven on daan's DDEV that way. daan's `master` is untouched. No file is ever copied by hand into `web/app/mu-plugins/ntdst-core`.
  Source: D6 — Stefan 2026-08-23 "Dev-only composer path repository"; brief §2.4 (the substitution hazard)
- **FR-15:** `v5.0.0` is tagged on `main` only after phases 1–5 are merged and `composer gate` exits 0, and the package is pushed then. The idle pane's typed `tag core 5.0.0` is not sent before that.
  Source: D4 — Stefan 2026-08-23 "After all five phases are green"
- **FR-16:** At the release commit every mechanical check in `ARCHITECTURE-INVARIANTS.md` INV-1…7 passes, and the doc's `Status:` lines read "holds" for all seven.
  Source: `planning` overlay (architecture invariants gate); brief §5

---

## Success criteria

- **SC-1:** On daan's DDEV with the path repo installed, `GET /wp-json/wp/v2/gigs/{id}` (daan's `rest_base` — revision 2) as an anonymous caller returns exactly the fields the gig model declares `show_in_rest => true` under `meta`, and 0 undeclared keys — proven with 1 seeded undeclared meta key (the `daan_internal_promo_budget` probe pattern) that must be absent.
- **SC-2:** Under Brain Monkey: `ntdst_rest('x/v1')->post('/t', $h)` with no capability leaves `/x/v1/t` absent from the captured `register_rest_route()` calls (0 registrations); `->get('/t', $h)` registers with `permission_callback === 'is_user_logged_in'`; `->get('/t', $h)->public()` registers with `'__return_true'`. 3 assertions, 1 test file.
- **SC-3:** `grep -rn "register_rest_route(\|wp_ajax_\|ntdst/api_data\|ntdst_actions\|get_nonce\|ntdstAPI" --include=*.php --include=*.js . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/|README"` returns 0 lines *(form hardened at the freshness review and again at the Cluster 3 gate — ugrep prints no `./` prefix, so `/tests/` must also match a top-level `tests/`)*; `assets/js/ntdst-api.js` and `api/Actions.php` do not exist.
- **SC-4:** On daan's DDEV, `GET /wp-json/ntdst/v1/relation/search?search=a&post_type[]=release` answers 401 anonymous *(array form — matches the route's `args`; amended at the freshness review)*, 403 as an Author (holds `edit_posts`, not `edit_others_posts`), 200 with a `results` array as Administrator. 3 probes.
- **SC-5:** On daan's DDEV, `GET /card` answers 200 with `$wp_query->is_404() === false` at `template_redirect` (asserted through a test-only mu-plugin or `wp eval`), and `grep -rn "is_404 = false\|redirect_canonical\|extract(" --include=*.php api core` returns 0 lines.
- **SC-6:** `grep -c "allowed_http_origins" api/Rest.php` ≥ 1; `grep -c "private static array \$surface\|private static array \$mimeTypes\|function publicSurface" api/*.php` = 0; `grep -c "wp_check_filetype\|send_nosniff_header\|load_template" api/Response.php` ≥ 3.
- **SC-7:** `composer gate` exits 0 at the release commit with 0 failures, and the unit suite still carries ≥ 1 denial-path test for each of: the write-verb refusal, the relation-search capability gate, the anonymous-meta absence, the CORS fail-closed decision.
- **SC-8:** README at the release commit names each of these 14 removed symbols at least once in the 5.0.0 section: `public_fields`, `publicRows`, `publicRow`, `getPublicShape`, `ntdst_actions`, `get_nonce`, `ntdst-api.js`, `apiSuccess`, `publicSurface`, `before_dispatch`, `ntdst_redirect`, `getMimeType`, `registerMimeType`, `Theme::style`.
- **SC-9:** All 7 `ARCHITECTURE-INVARIANTS.md` mechanical checks return their stated result at the release commit (7 commands, 0 unexpected hits).
- **SC-10:** On daan's `chore/core-path-repo` branch, after `ddev composer update netdust/ntdst-core`, `diff -rq --exclude=vendor --exclude=.git ~/Sites/ntdst-core web/app/mu-plugins/ntdst-core` reports 0 differing files at every review gate; daan `master`'s lock still points at the VCS repo.

---

## Security-relevant surfaces

The plan owes a `## Threat model`.

- [x] **Authorization** — the route default changes from "refused" to "logged in"; the write-verb refusal is the load-bearing mitigation and is tested as absence (SC-2). Every account on a site with open registration reaches every unnamed GET route; that is WordPress's own posture and is stated in README.
- [x] **Anonymous reach of post meta** — `register_post_meta(show_in_rest)` publishes declared fields on `/wp/v2/<type>` to anyone once `custom-fields` support is on. Opt-in is the mitigation. Revision 2: a repeater is publishable only when every sub-field is declared — the public-provenance-with-private-sale-price shape is NOT supported by declaration (it would need read-projection inside Data, an INV-1 exception, deliberately not taken). A field declared by mistake is a disclosure — README says so in the field's own docs.
- [x] **CSRF / session** — core stops minting nonces; cookie-authenticated REST relies on `rest_cookie_check_errors()`. Removing `verifyOrigin()` is recorded as guarding nothing the nonce rule does not (brief D2).
- [x] **Untrusted parsing** — relation-search params (`search`, `post_type[]`); rewrite-rule `:param` values reaching `get_query_var()`; both sanitized as today.
- [x] **Rate limiting** — unchanged primitive (INV-7); the relation-search route declares a `rate_limit`.
- [x] **CORS** — the list moves to `allowed_http_origins`, which admin-ajax also reads. Revision 3: the declared origins are appended only while serving a REST request, so admin-ajax/admin-post/the customizer keep WordPress's defaults (they would otherwise grant credentials unconditionally — a declared origin could read `admin-ajax.php?action=rest-nonce` and ride the victim's session). Stated in README; the fail-closed emitter stays.

## User-facing surfaces

The plan owes `## Acceptance flows`.

- [x] **The admin relation picker** (`metabox-fields.js` autocomplete) — every editor uses it; it moves from `ntdstAPI` to `wp.apiFetch` and a new route.
- [x] **`/wp/v2/<type>` meta** — the first public surface a site's declared fields have; external consumers (and AI) read it.
- [x] **Page routes** (`/card`, `/card/contact.vcf` on daan) — served by rewrite rules instead of the `template_include` matcher; canonical redirect behaviour changes from suppressed to WordPress-native.
- [x] **Theme setup** — menus, image sizes, sidebars unchanged; the three dropped forwarders fatal loudly on a site that still calls them.

---

## Assumptions

One line each; visible at the review gate.

- Meta is stored one value per key (`single => true`); the repeater is one serialized array under its key. Ground-truthed in the plan (FR-2).
- `image` and `file` store one attachment ID (`sanitizeAttachmentId`); `relation`, `post_relation`, `person`, `gallery` store a list of IDs. Verified 2026-08-23 (`api/Data.php:231–244`).
- ~~WordPress honours a partial `properties` list on a nested `object` schema … makes un-named sub-fields drop on read and write.~~ **Refuted at the Cluster 1 gate (revision 2):** `WP_REST_Meta_Fields::prepare_value()` (`class-wp-rest-meta-fields.php:556`) validates the STORED value against the schema and returns `null` on any error; `update_value()` validates before it sanitizes (400). Hence FR-2's all-or-nothing repeater rule.
- daan's DDEV is available and its integration suite's known reds (8) are the baseline; this spec adds no daan migration.
- Core's suite stays Brain Monkey unit-only; every "on daan's DDEV" criterion is the shake-out's, not the unit suite's.
- 5.0.0 is unreleased, so no shim, alias or deprecation path is owed to anyone.

---

## Out of scope

- daan's handler migration (`refactor/actions-become-rest` @ `ce5d72c` is a reference diff, not a branch to finish) — its own spec after v5.0.0.
- stride (`^3.0`, vendored 2.4.1), netdust (`^2.2`), rossi (Endpoints era) — each its own migration.
- The frozen `ntdst-baseline/specs/{search,import,export}` (A9; the direction doc says earn them first).
- rossi's provenance exposure (`docs/session-2026-08-21…` §3) — recorded, not this work.
- `Bootstrap.php`, `Container.php`, `Scheduler`, `Mailer`, `RateLimiter`, `ClientIp`, `MetaboxGenerator` beyond the JS call — not audited, not touched except where an FR names them.
- Any new capability (events, forms, newsletter, import, search) — baseline's, later.
