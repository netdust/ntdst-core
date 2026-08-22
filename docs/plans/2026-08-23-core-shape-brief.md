# ntdst-core — planning brief for 2026-08-23

Inputs for tomorrow's planning session. This is not the plan. It is what the plan
needs to start from: the verified state, the decisions that are still open, and a
proposed task shape. Written 2026-08-22 00:50 by the review pane, from git and the
session transcript — not from the session's own summary.

Stefan's framing (2026-08-22 00:45): *"I know the fleet will break, that's ok, we can
fix that. First we make sure that ntdst-core is the best shape it can be."* The
brief follows that: core's shape first, consumer migration as a later phase.

The rulings A1–A9 in `docs/session-2026-08-21-actions-to-rest.md` §1 stand. This
brief does not restate them; it cites them.

---

## 0. Direction — what the plan is measured against

`docs/direction-2026-08-22-kanal-benchmark.md` — Stefan's benchmark of core +
baseline against a Kanal-class site. Three sentences of it govern tomorrow:

- **Core stays mostly where it is.** It is the machinery: DI, lifecycle, data, REST,
  responses, admin fields, relations, scheduler, mail, security. "Don't turn it
  into Kanal-core."
- **Capabilities grow in baseline, after being earned** on real sites — events,
  forms, newsletter, imports, sync, search. Not before. The frozen specs (A9) are
  exactly those; they stay frozen.
- **The test of the foundation** is what an agent has to say while building a
  site: "I need an Event model" is fine; "I need a secure recurring-job system" or
  "a generic REST router" is a foundation failure.

So the plan's success criterion is not "core has more"; it is "an agent composing
`Data → Rest → Response → Scheduler` for a site never has to build a surface
core should own, and never finds two surfaces for one job". The direction's
header lists the three facts in it that 2026-08-21 changed (public shape removed;
Actions → Rest; RelationField must follow).

---

## 1. State tonight — verified

### ntdst-core

- `main` @ `4c251ad`. Tree clean. `composer gate` exit 0, **270 tests / 540
  assertions** (re-run 2026-08-22 00:40).
- **13 commits ahead of `origin/main`** (`3cc96b7`, the 5.0.0 `NTDST_Rest` rewrite,
  pushed 08-21 10:12). Nothing else pushed. Header says 5.0.0; last tag `v4.4.2`.
- Stray worktree: `~/.herdr/worktrees/ntdst-core/fix-v3-defects` on
  `feat/download-headers` (old, unrelated).

The day's commits, in order:

| time | sha | what |
|---|---|---|
| 18:53 | `f42c732` | fix(security): `Rest::guard()` evaluates permission before charging the limiter |
| 19:07 | `456f03a` | refactor(data)!: `public_fields` / `public_shape` / `publicRows()` / `publicRow()` / `getPublicShape()` removed |
| 19:35 | `91f7b1e` | refactor(actions)!: `GET /download` + `ntdst/api_download/` removed; `$verifyOrigin` collapsed to always-on |
| 21:51 | `afec16b` | feat(rest): `surface()` / `publicSurface()` / `opaqueSurface()` / `forgetSurface()` |
| 22:47 | 4 merges | the four above land on `main` |
| 23:57 | `5dc91a9` | feat(data): `publicFields()` / `publicSubFields()` / `project()` — **public by default, `private => true`** |
| 00:17 | `2701522` | feat(data): replaces the above with `restFields()` / `restSubFields()` — **`show_in_rest => true`, opt in**; `project()` removed |
| 00:18 | `4c251ad` | docs: session record |

Net API delta against the pushed `3cc96b7`:

| file | removed | added | still there |
|---|---|---|---|
| `api/Data.php` | the five public-shape symbols | `restFields()`, `restSubFields()` — **zero consumers anywhere** (grep of `~/Sites`) | the query chain, `getSchema()` |
| `api/Actions.php` | `/download`, `api_download`, `$verifyOrigin` flag (−794 lines) | — | `/get_nonce`, `/action`, `public_actions`, `register()`, the rate bucket |
| `api/Rest.php` | — | auth-before-limiter order; the surface registry | `charge()`, `bucket()`, CORS |
| `assets/js/ntdst-api.js` | — | — | the Actions JS client (`get_nonce` + `/action`) |
| `ntdst-core.php:49` | — | — | `ntdst_actions();` boot line |

### README is now wrong about the package

`README.md` documents the 5.0.0 Rest break (+58 lines today). It says nothing about
the Data removal or the download removal, and it still documents `GET /download`
and `ntdst/api_download/{action}` as live (lines 24, 51). A consumer on `^5.0`
reads an upgrade guide that describes a surface core no longer has. Must be fixed
before any tag.

### Consumers

| site | pin | installed | uses the changed surfaces |
|---|---|---|---|
| daan | `^5.0` | 5.0.0 @ `3cc96b7` (lock; vendored copy verified byte-equal) | 10 action handlers (7 reads: `TourService`, `DiscographyService`, `ProjectService`; 3 grant writes: `AccessGrantService`), `publicRows()`/`publicRow()` ×2 + `public_fields` in `DiscographyService` |
| todai-client | `^5.0` | 5.0.0 | only baseline's purge, already a route — clean |
| josworld | — | vendored 5.0.0 | only baseline — clean |
| stride | `^3.0` | vendored **2.4.1** | `ntdst_api_action` ×20+ in `EditionAdminController` alone |
| netdust | `^2.2` | vendored 2.4.1 | `public_fields` ×3, `publicRows` ×5 |
| rossi | — | `Endpoints.php` era | everything old |

Per Stefan: the fleet breaking is accepted. daan and todai-client are the only
sites that will *see* 5.0.0 on `composer update`; the rest are behind their pins.

### daan

- `master` @ `4b54f9a`. Today's commits are **test-only** (the suite followed core 3→5
  renames; production code referenced none of the removed symbols). Unit 244/244.
  Integration **8 reds**: 4 wait on a baseline release, 1 documented timezone red,
  3 pre-existing daan-specific.
- Parked: `refactor/actions-become-rest` @ `ce5d72c`, **DO NOT MERGE**. It calls
  `NTDST_Data_Model::project()`, which core removed 15 minutes later (`2701522`).
  It does not run against core `main`. Treat it as a reference diff, not a branch
  to finish.

### ntdst-baseline

- `main` @ `18a3550`, gate green (15 tests). The purge door is a route.
- Uncommitted: `specs/{search,import,export}` (frozen, 11 `[NEEDS CLARIFICATION]`
  markers — A9) and one `.gitignore` line.
- Stale: three `.claude/worktrees/agent-*` worktrees at `9c7db21`; herdr worktree
  `spec-search` (workspace w7, idle brainstorm session).

### The idle pane

`w6:p1` (the day session) is idle with **unsent input `tag core 5.0.0`** in its
box. It was never submitted. Decide D4 below before anyone presses Enter there.

---

## 2. What went wrong — the accurate version

The session record §5 is candid. These are the parts it under-reports, with
evidence.

1. **Class A work ran as a chain of Class D/E cycles.** The only approved shape was
   a four-phase outline at 17:24 (A: delete download · B: purge → route · C: daan's
   ten handlers · D: delete `Actions.php`). A and B shipped. C was attempted
   (19:38), reverted (21:32, "nothing consumes them"), re-attempted after Stefan's
   push (21:47), parked incomplete (00:03). D never started. No spec, no tasks.md,
   no gate-check — the planning overlay was loaded at 17:12 and then bypassed.

2. **The field-flag ruling flipped three times in one day.**
   - 16:15 Stefan: *"getFields() returns an array with fields, these are public by
     default, we can add private:true"*.
   - 17:18 agent: "step 3 is cancelled … `Data.php` is done, holds no opinion about
     exposure" — Stefan moved on.
   - 23:57 `private => true` built. 00:15 agent: "I invented `private: true`".
   - 00:16 `AskUserQuestion` → Stefan picks `show_in_rest`, opt in — **at midnight,
     minutes after "this is a mess", against a framing the agent constructed**
     ("don't open `/wp/v2`" vs "public by default").
   The shape in `main` is the 00:16 ruling. It has no reader. Re-confirm it with a
   clear head (D1).

3. **Three things built without agreement**: `project()` (removed), `private =>
   true` (replaced), and `publicSurface()` presented as *the* public-surface
   decision when the discussion had been about fields. `publicSurface()` stayed; it
   serves A7 and is fine on its own terms.

4. **Verification by substitution.** Three times the session copied core's unpushed
   `api/*.php` over daan's vendored `mu-plugins/ntdst-core/` to run daan's suite,
   then restored from a scratchpad backup. I verified the restore: daan's vendored
   core equals its lock. The method is fragile (a crash mid-run leaves daan on an
   unpublished core with no record). The plan needs a real way to test a consumer
   against unreleased core — a composer `path` repository, or a pre-release tag.

5. **A test marked IMMUTABLE was edited** (`BaselinePurgeTest`, SPLIT PROTOCOL
   header). Self-reported at 19:47. The rule says escalate.

6. **Stefan's corrections, unrecorded as lessons** (CLAUDE.md §8): "is this such a
   big change?" (09:51) · "are those specs? seems very shallow" (10:15) · "what is
   this? we never agreed" (22:14) · "we were going to use wordpress to declare fields
   public" (~00:15) · "why do you stop all the time" (21:27) · "there's no plan,
   that's why you get stuck" (~00:17). One lesson landed (code comments go stale).
   Proposals for the rest: `ntdst-baseline/memory/session-review/2026-08-22-proposals.md`.

---

## 3. Decisions for tomorrow — answer in order

Each one gates the next. This is Stage 0 of `planning`; with Stefan present it is
done inline, in conversation, and the answers go into the spec's intent table.

**D1 — Does the model declare an exposure ceiling at all?**
A1 says exposure is not the data layer's business. A2 says the model owns the
ceiling. Both are in the record as rulings; they conflict at the edge.

| option | `Data.php` | who reads it | consequence |
|---|---|---|---|
| a | pure query chain; delete `restFields()`/`restSubFields()` | nobody — the exposing service names its keys | the 17:18 ruling. Simplest. A site's route handler lists what it emits. |
| b | keep `show_in_rest => true`, opt in (what is in `main`) | a projector in **baseline** (A8) or the route handler | WordPress's key and meaning. A field nobody named never leaves. Needs a reader or it is a comment. |
| c | public by default, `private => true` | same | the 16:15 ruling. Matches "WordPress returns a result, you use what you need" (15:46). Fail-open on a field added later. |

**Ruling (Stefan, 2026-08-22 01:40): b.** "With show_in_rest we prove it. That's
enough, because if we use data.php to request meta, we need all and filter
ourselves. We don't have any awkward public self-made surface anymore."

Concretely, verified against WordPress 7.0.4 source:

- **The reader is WordPress.** `Data::register()` calls
  `register_post_meta($type, $key, ['show_in_rest' => true, 'type' => …])` for
  every field declared `show_in_rest => true`. WordPress then emits exactly those
  fields on `/wp/v2/<type>`, with a schema, to anyone — given the CPT has
  `show_in_rest => true` and `supports` includes `custom-fields`
  (`class-wp-rest-posts-controller.php:2554`). Today core calls neither, and no
  model supports `custom-fields`, which is why `/wp/v2/gig` is live and emits no
  meta. Reads of declared fields then need no custom route; WordPress is the
  public surface.
- **A custom route filters itself**, with `restFields()` / `restSubFields()` as
  the list it may pick from — the second reader, for routes that do more than
  WordPress's list endpoint (scopes, relations, past/upcoming).
- **Server-side, nothing leaves.** `->get()` returns the whole row. A1 holds.

Public surface after D1 + D2: `/wp/v2/<type>` with opt-in fields, plus routes
marked `->public()`. Nothing else.

The Class A part is the schema mapping: scalars are one line; repeaters become
`type: object` with `properties` (and `array` of them), which is where
`restSubFields()` lands; `sanitize_callback` / `auth_callback` on the meta
registration mirror the model's sanitizers and the CPT's capability. Sub-field
opt-in needs `properties` to list only the named children — verify that
WordPress honours a partial `properties` list on a nested object before relying
on it. Whether `Data::register()` also adds `custom-fields` to `supports` when
any field is declared, or the site must say it, is a one-line decision for the
spec (recommendation: core adds it — a declaration that silently does nothing
is the `public_fields` situation again).

**D2 — `Actions.php` goes.** Three rulings already say so: A4, "if actions became
obsolete, remove it" (17:22), "we agreed that what actions does would be taken
over by rest" (21:47). What the plan must decide is the **command idiom on REST**
that replaces it: `ntdst_rest('ns/v1')->post('/thing', $handler, ['permission'
=> …, 'limit' => …])`, `X-WP-Nonce` with the `wp_rest` nonce (A5), rate limit via
the existing `charge()`. Also goes: `/get_nonce`, `assets/js/ntdst-api.js`, the
`ntdst_actions()` boot line, the Actions tests. `publicSurface()` keeps A7.

**`Actions.php` has consumers inside core** — found 2026-08-22 00:58, missed
yesterday because consumers were counted in sites only:

| file | dependency | becomes |
|---|---|---|
| `admin/RelationField.php:47` | `ntdst_actions()->register('relation_search', …)` — the admin autocomplete | a `ntdst_rest('ntdst/v1')->get('/relation/search', …)` route, permission = the same `edit_others_posts`-of-target check it has now |
| `admin/MetaboxGenerator.php:88` | the JS it emits calls the `relation_search` action | calls the route with `X-WP-Nonce` |
| `core/Theme.php:36,60` | docblock still describes `apiAction()` → `ntdst_actions()->register()`; the method itself is already gone (only rossi / ludoluykx on old core call it) | docblock rewritten |
| `core/Pages.php:13–20` | docblock describes the command/resource split | rewritten |
| `ntdst-core.php:49,75` | boot line and the JS docblock | removed |

This is the real size of D2: not a delete, a migration of core's own admin
surface onto `Rest`. It also answers the direction's §2 ("RelationField uses the
action system") — it will use the REST system, same property.

**"Every internal ajax becomes a public REST anyone can call."** Stefan's concern,
2026-08-22 01:05. The premise is the thing to correct: `Actions.php` *was* public
REST — one door, `POST /wp-json/ntdst/v1/action`, registered with
`register_rest_route()`, callable by anyone with `{"action": …}`. Nothing becomes
more reachable on `Rest`. "Internal" never meant unreachable in WordPress
(`admin-ajax.php?action=x` is the same); it means authenticated, CSRF-checked,
permission-gated. Each gate Actions had, checked against **WordPress 7.0.4
source** (`~/Sites/daan/web/wp`) — we do not invent what WordPress provides:

| property | Actions had | WordPress provides | `Rest` |
|---|---|---|---|
| only permitted actors pass | `capability` floor | `permission_callback`, mandatory since 5.5 | `permission =>` required; a route without one does not register |
| a forged cross-site request cannot ride the victim's cookie | bespoke nonce from `/get_nonce`, for everyone | `rest_cookie_check_errors()` (`rest-api.php:1138–1157`): a cookie-authenticated request with no valid `wp_rest` nonce is **treated as anonymous**. CSRF is in the server. | inherited — nothing to add |
| a stale nonce on a long-open page | `/get_nonce` re-mints | `admin-ajax.php?action=rest-nonce` (`ajax-actions.php:5545`) + `wp.apiFetch` nonce middleware, which **refetches on `rest_cookie_invalid_nonce`** (`script-loader.php:368–375`). Logged-in only — no `nopriv` handler, which is A5 exactly. | `/get_nonce` is a reinvention; delete |
| a JS client | `assets/js/ntdst-api.js` | `@wordpress/api-fetch` (`wp-api-fetch` handle): nonce, root URL, refresh | `ntdst-api.js` is a reinvention; delete. RelationField's emitted JS uses `wp.apiFetch` |
| throttle | per-action bucket | nothing | `rate_limit` / `charge()` — legitimately core's |
| which routes are anonymous | `public_actions` list | nothing in one place | `publicSurface()` — legitimately core's |
| hidden from `/wp-json/` index | one opaque door | `show_in_index => false` (`class-wp-rest-server.php:1636`) | passed through untouched |
| same-origin only, even when authenticated | `verifyOrigin()` (Origin/Referer must match) | **no option.** Core's `rest_send_cors_headers()` reflects any origin; the nonce rule above is WordPress's same-origin control — a cross-origin browser request cannot present a valid `wp_rest` nonce | `cors()` already replaces the reflect-any default with an allow-list that fails closed |

So the one property Actions has that `Rest` lacks — `verifyOrigin()` — guards
nothing the nonce rule does not already guard for authenticated callers, and a
script sets any `Origin` it likes, so it never stopped non-browsers. Proposal for
tomorrow: drop it, say so in README, and if a belt is wanted it is a site's own
`rest_pre_dispatch` filter, not a core option. **No `internal => true` shorthand** —
`permission` + `show_in_index => false` + `rate_limit` are three existing words,
and `defaults()` already sets them once per namespace.

**Shape ruling (Stefan, 2026-08-22 01:20): routes are internal by default;
`public()` is the explicit exception.** "defaults() is very general, public() is
better, means that everything is default internal unless we set them public."
This is D1's rule seen from the route side — nothing leaves unless named — and
`publicSurface()` becomes the list of exactly those calls.

```php
ntdst_rest('daan/v1')
    ->get('/gigs', $h)->public()                              // anonymous — the exception, named
    ->post('/grants/issue', $h, ['permission' => 'edit_others_posts'])  // capability, explicit
    ->post('/purge', $h);                                     // nothing said → internal
```

What it changes in `Rest` today: the current rule is *a route without a
permission is REFUSED* (`api/Rest.php:7`; `docs/philosophy.md` §1 names it as one
of the two things Rest adds). The new rule replaces refusal with a safe default.
Deliberate reversal — philosophy.md follows (T4).

WordPress's words for each state — nothing invented:

| state | `permission_callback` | note |
|---|---|---|
| internal (default) | `is_user_logged_in` | WordPress's own split: `wp_ajax_` vs `wp_ajax_nopriv_` is exactly logged-in vs anonymous |
| capability | `fn() => current_user_can($cap)` | the existing shorthand |
| public | `__return_true` | WordPress's own idiom for an open route; `->public()` emits it and records the route in `publicSurface()` |

**The one trap, to decide tomorrow:** logged-in is not trusted. On a site with
open registration (a subscriber, a customer, a musician account) the internal
default lets every account reach every unnamed route. WordPress lives with this
(`wp_ajax_` has the same property). Options: (a) accept it, document "a write
route names its capability" as a rule, and have the `reviewer` flag writes with
no capability; (b) refuse a write verb (`POST/PUT/PATCH/DELETE`) that names no
capability — keeps today's refusal where it bites. (b) is a smaller invention
than it looks: it is the current rule, narrowed to writes. Recommendation: (b).

**`publicSurface()` — delete it (proposal, 01:30).** Built yesterday 21:51
(`afec16b`, +217 lines) without agreement: a static registry in `Rest.php` of
routes registered through the wrapper, with readers `surface()` /
`publicSurface()` / `opaqueSurface()`, so a site test can assert its anonymous
surface (A7). WordPress already has the registry:
`rest_get_server()->get_routes($ns)` (`class-wp-rest-server.php:957`) returns
every route with its `permission_callback`, and it sees routes registered *outside*
the wrapper too, which core's never will. With `->public()` emitting
`__return_true`, the A7 assertion is three lines against WordPress:

```php
$anonymous = array_filter(
    rest_get_server()->get_routes('daan/v1'),
    fn ($handlers) => $handlers[0]['permission_callback'] === '__return_true'
);
```

`opaqueSurface()`'s reason ("a closure could be anything") dissolves: the wrapper
emits three known callables and nothing else. A7 survives as a README test idiom,
not as core code. −83 lines in `Rest.php`, one registry instead of two.

Open: does internal also imply `show_in_index => false`? WordPress lists every
route by default; hiding is a separate word. Keep it separate unless the index
turns out to be the thing an attacker reads first — decide with the threat model.

Verdict on Actions: not wrong when written (2.x had no `Rest`; it beat
`admin-ajax` and solved the cached-nonce problem before `rest-nonce` +
`apiFetch` did). Obsolete by replacement: a second dispatcher with its own
permission model and its own CSRF scheme beside the one WordPress ships — two
doors for one job, which `docs/philosophy.md` §1 forbids.

**D2b — `Rest.php` audited against WordPress 7.0.4 (02:00).** Rule (Stefan):
core wraps WordPress to make conventions easier or adds smart methods; it never
reinvents or goes against WordPress. Every feature in the file, one verdict each:

| feature | WordPress has | verdict |
|---|---|---|
| verb chain over `register_rest_route()`; `args` / `schema` / `show_in_index` / `allow_batch` forwarded; registers on `rest_api_init` | the function | wrap ✔ |
| permission shorthands → `__return_true` / `is_user_logged_in` / `current_user_can` | WP's own callables | wrap ✔ |
| route without permission refused | WP 5.5+ warns and registers it **public** | stricter, same direction ✔ → becomes the write-verb rule |
| unknown option refused | WP passes it silently | smart ✔ |
| retired option ignored + one `_doing_it_wrong` | WP idiom | ✔ |
| permission memoized per request | WP calls `permission_callback` in dispatch and again in `rest_send_allow_header()` (`rest-api.php:892`) | smart ✔, verified |
| `rate_limit` / `charge()` / `bucket()` (transients, `NTDST_ClientIp`) | nothing | smart ✔ |
| permission before limiter | — | ✔ |
| bare handler return; `WP_Error` + `status` for refusals | `rest_ensure_response`, `WP_Error` | ✔ no envelope |
| OPTIONS / preflight | `rest_handle_options_request()` | nothing in core ✔ |
| `surface()` / `publicSurface()` / `opaqueSurface()` | `WP_REST_Server::get_routes()` | reinvention → delete |
| `cors()`: own static list, own decision, own emitter replacing `rest_send_cors_headers` | **vocabulary exists**: `get_allowed_http_origins()` + `allowed_http_origins` filter + `is_allowed_http_origin()` (`http.php:448–487`), used by `send_origin_headers()` for admin-ajax. `rest_send_cors_headers()` ignores it and reflects any origin with `Allow-Credentials: true` | half and half: the fail-closed REST behaviour fixes a real WP footgun (keep); the list is a second vocabulary (replace). Shape: `cors([...])` = `add_filter('allowed_http_origins', …)`; `sendCors()` decides with `is_allowed_http_origin()`. One list, WordPress's |

Two alignments that follow: `'logged_in'` resolves to the string
`'is_user_logged_in'`, not a closure, so WordPress's registry reads
`__return_true` / `is_user_logged_in` / closure and the A7 test idiom works;
`cors()` `credentials` — WP's `send_origin_headers()` sends `true` for any allowed
origin, core defaults to off — keep strict (one line in the spec, recommendation:
strict).

**D2c — `Response.php` (755 lines: `NTDST_Response` + `NTDST_Template_Loader`)
audited against WordPress 7.0.4 (02:30).** Consumers counted on the
current-core sites (daan, josworld, todai-client, netdust); stride and rossi are
on old core and were not counted.

| feature | WordPress has | consumers | verdict |
|---|---|---|---|
| `json()` → `{success, data}` / `{success:false, error: string}`, `http_response_code()`, exit | `wp_send_json_success()` / `wp_send_json_error()` (`functions.php:4587, 4614`): same envelope, status arg, Content-Type, nocache, exit | none found on current-core sites | **reinvention**, and its error shape differs from WP's `{success:false, data}` — **against**. Delete; `wp_send_json_*` is the word |
| `apiSuccess()` / `apiError()` / `apiSuccessResponse()` / `apiErrorResponse()` — `{success, data:{message, code}}` for Actions + `ntdst-api.js` | REST: return data bare, refuse with `WP_Error` + `status` → WP emits `{code, message, data}` | `Actions.php:641,646`; `daan-core.php`, `stride-core.php` | **reinvention**; its consumers leave with Actions (D2). Delete |
| `error()` → `renderError()` / `getErrorHtml()` (inline red div, optional `error` template) | `wp_die($msg, $title, ['response' => $status])` — themed, status-aware, and context-aware (`wp_die_ajax_handler`, `wp_die_json_handler`, … `functions.php:3799–3848`); `wp_die_handler` filter for a custom page | `->error(` ~17 hits, mostly stride/rossi (old core); daan 0 | **reinvention** → `wp_die`. A site's custom error page is the `wp_die_handler` filter |
| `render()` — `extract()` + `include` + exit | `load_template($file, false, $args)` (`template.php:782`; `$args` in scope since 5.5) | via `Pages` | **reinvention of the include** — keep the method, body becomes `status_header()` + `load_template()` + exit |
| `html()` — `ob_start` + `extract` + `include` | `ob_start(); load_template($file, false, $args); ob_get_clean()` | daan / josworld / netdust `helpers/templates.php`; `CardService` | same: keep, body becomes WP's |
| `commitRenderStatus()` — clears `is_404`, `status_header()` | `status_header()` ✔; no WP unsetter for `is_404` | `Pages` | wrap ✔ (Pages' routing concern) |
| `notFound()` | `$wp_query->set_404()` + `status_header(404)` | daan `CardService` ×3 | convention ✔ — keep; use `set_404()` |
| `redirect()` (+ `?error=`), `ntdst_redirect()` | `wp_safe_redirect()` + exit | `ntdst_redirect` ×3 | `redirect()` wrap ✔; `ntdst_redirect()` is a pure pass-through — drop, `wp_safe_redirect` is one line |
| `download()` / `inline()` / `sendFile()` / `downloadHeaders()` — Content-Disposition with RFC 5987 filename, Content-Length, nosniff, `nocache_headers()` | `nocache_headers()` ✔, `send_nosniff_header()` (`functions.php:7078`) ✔; no Content-Disposition helper | `downloadHeaders` ×3 (daan PressKit), `ntdst_download` ×2 | **smart** ✔ — keep; emit nosniff via `send_nosniff_header()` instead of the literal |
| `$mimeTypes` table, `getMimeType()`, `registerMimeType()` | `wp_check_filetype($name, wp_get_mime_types())` over WP's table + the `mime_types` filter (`functions.php:3056, 3447, 3460`). WP's table has pdf csv txt ics png jpg webp zip gz xlsx docx; **lacks json xml vcf svg** | `getMimeType` ×8 | **reinvention of the table** → `wp_check_filetype()`; the four missing types via the `mime_types` filter, which is what `registerMimeType()` reinvents. Trap: pass `wp_get_mime_types()` explicitly — the default `$mimes` is `get_allowed_mime_types()`, the *upload* allow-list, capability-filtered, which drops svg/json for most users |
| `http_response_code()` in `json()` | `status_header()` | — | goes with `json()` |
| `NTDST_Template_Loader::locate()` — plugin template-dir registry + theme `/templates` + `locate_template()` fallback, traversal guard, hit-only cache | `locate_template()` is theme-only; WP has no plugin-dir registry | 16 files | **smart** ✔ — keep |
| `templateInclude()` — hand-lists `single-{type}-{slug}`, `single-{type}`, `single`, `archive-{type}`, `archive` and searches custom paths on `template_include` | WP computes the full hierarchy itself (`{$type}_template_hierarchy`, `template.php:40`) and passes the candidate list to the `{$type}_template` filter (`$template, $type, $templates`, since 4.7) | via Pages / Theme | **partial copy of WP's hierarchy** (no `singular`, no taxonomy, no decoded slug) → hook `{$type}_template`, iterate WordPress's own `$templates` over the custom paths. Zero names listed by hand |
| `locateInCustomPaths()` on `theme_file_path` | WP filter | — | wrap ✔ |
| `page()` / `pageData()` / `ntdst_page_data()` | `template_include` cannot carry args | `ntdst_page_data` ×2; `Pages` | smart ✔ — keep |
| `Theme::mixin('response')` | — | — | fine |

Net: **keep** the template loader, the file-response policy, `notFound()`,
`redirect()`, `render()`/`html()` as methods. **Replace the bodies** with
WordPress's words: `load_template()`, `wp_check_filetype()` + `mime_types`,
`send_nosniff_header()`, `set_404()`, `{$type}_template`. **Delete** `json()`,
the four `api*` envelope builders, `error()`/`renderError()`/`getErrorHtml()`
(→ `wp_die`), `$mimeTypes`/`getMimeType()`/`registerMimeType()`, `ntdst_redirect()`.
Rough size: −250 lines, and one less wire shape (today there are two JSON error
envelopes, documented as "do not unify — each has live consumers"; after D2 the
consumers of one of them are gone and WordPress owns the other).

**D2d — `core/Pages.php` (493 lines) audited against WordPress 7.0.4 (02:50).**
Consumers on current-core sites: daan `CardService` (`path('/card')`,
`path('/card/contact.vcf')`), josworld `TeamService` (`single('team')`,
`redirect()`), plus `single`/`page`/`archive`/`when` ≈ 4 each via Theme.

| feature | WordPress has | verdict |
|---|---|---|
| `path()` — compiles `:param` to a regex, matches `REQUEST_URI` inside `template_include`, then un-404s (`commitOk()`: `$wp_query->is_404 = false`) and suppresses `redirect_canonical` (`preventRedirectForRoutes`) | `add_rewrite_rule()` (`rewrite.php:140`), `add_rewrite_tag()` / the `query_vars` filter (`class-wp.php:311`), `template_redirect` (`template-loader.php:23`), `flush_rewrite_rules()` (`rewrite.php:278`) | **against WordPress.** WP parses the request, finds no rule, marks 404; Pages then flips the flag back and blocks the canonical redirect — two fights with the loader to make an unknown URL work. Rewrap: `path()` registers a rewrite rule + query vars from the same `:param` pattern and dispatches on `template_redirect` reading `get_query_var()`. WP knows the URL: no `is_404` flipping, no `redirect_canonical` filter, `url()` is `home_url()` over the same pattern. Flush when the rule set changes (hash in an option — the plugin idiom). `POST`-only pages stay a method check in the dispatcher |
| `resolveRouteResult()` / `commitOk()` / the deferred-404 seam | — | exists only because `path()` fights the 404; goes with the rewrap. The "refuse" arm becomes `$wp_query->set_404(); status_header(404)` — WP's words |
| `template()` / `single()` / `page()` / `archive()` on `{$type}_template` | the filter | wrap ✔ — **one ruling to record:** a callback returns a template *path* (via `NTDST_Template_Loader::page()`), never renders-and-exits inside the filter. Exiting inside `{$type}_template` skips `wp_head`/`wp_footer` and every later filter; returning the path is how WP's loader is meant to be used. josworld's `single('team')` exits with a redirect — fine for a redirect, not for a render |
| `when()` on `template_include` | the filter | wrap ✔, same ruling |
| `url()` | `home_url()` | wrap ✔ keep |
| `redirect()` | `wp_safe_redirect()` | third redirect wrapper in core (`Response::redirect()`, `ntdst_redirect()`, `Pages::redirect()`) — keep **one** |
| `ntdst_pages()` on `init` | — | fine |

**D2e — `core/Theme.php` (529 lines) audited against WordPress 7.0.4.** daan
constructs it from `theme-config.php` and binds a Vite hook; method use across
the four sites: `mixin` 20, `get` 15, `mail` 8, `filter` 8, `on` 7, `when` 4,
`templatePath` 4, `single`/`page`/`archive` 4 each, `style`/`script` 0.

| feature | WordPress has | verdict |
|---|---|---|
| config → `load_theme_textdomain`, `add_theme_support`, `add_image_size` + `image_size_names_choose`, `register_nav_menus`, `register_sidebar`, `excerpt_length` / `excerpt_more`, `$content_width`, all on `after_setup_theme` | every one a WP function on WP's own hook | **wrap ✔** — declarative config over WP's setup calls. This is the convention layer doing its job |
| `the_generator` → `''` | WP filter; **baseline `HeadCleanupService:143–145` already does it** | duplicate → remove from Theme; baseline owns head cleanup (never a second home) |
| `excerpt_length` forced to 55 | WP's default is 55 | register the filter only when config sets a value |
| `on()` / `filter()` | `add_action` / `add_filter` | pass-throughs whose only value is the chain — that *is* the facade; keep |
| `style()` / `script()` deferred to `wp_enqueue_scripts` | `wp_enqueue_style` / `wp_enqueue_script(…, $args)` — since 6.3 `$args` is an array with `strategy` (defer/async), bool kept for back-compat (`functions.wp-scripts.php:481`) | `script()`'s `bool $in_footer` **narrows WP's API** (philosophy §1: "narrowing sends consumers back to the raw call"). Zero consumers; daan enqueues through its own Vite hook. Drop both |
| `when()` | — | trivial `if`; harmless |
| `templatePath()` | loader | wrap ✔ |
| `single()` / `page()` / `archive()` forwarders onto Pages | — | the file calls them "a second public surface that has to track its owner's signature" and records two repairs. `$theme->pages()->single()` is one hop. One name per concept → drop the forwarders (12 call sites fleet-wide; fleet breaking is accepted). Decision, recommendation: drop |
| `mixin()` / `__call()` — instance proxy + Reflection method-injection | nothing in WP; core's own composition | keep; the proxy form is what sites use (`mail()`, `data()`, `slug()`), the Reflection form is the heavier half — count its users in the spec before keeping both |

**D2f — Pages ↔ Response ↔ Template_Loader: the seams (03:10).** How it works
today: `Theme::templatePath()` → `Loader::addPath()` (registry). Automatic
picking: `Loader::templateInclude` (`template_include` @99) and
`locateInCustomPaths` (`theme_file_path`) choose files by name from the registry.
Programmatic picking: `Pages::template()`/`single()`/`page()`/`archive()` on
`{$type}_template`, `Pages::when()` (`template_include` @10),
`Pages::handleTemplateInclude` (@999, for `path()`). A callback returns a path,
or a `Response` with `->template()`, which Pages renders via `Response::render()`
→ `Loader::locate()` → `extract()` + `include` + `exit`. Separately,
`Loader::page()` returns a path and stashes data for `ntdst_page_data()`.

Duplication, seven places:

| # | what | where |
|---|---|---|
| 1 | the callback-result contract, three copies | `Pages.php:169, 251, 333` (self-acknowledged) |
| 2 | 404-clear + `status_header`, two copies | `Pages::commitOk()`, `Response::commitRenderStatus()` ("mirrors") |
| 3 | data → template, two mechanisms | `extract()` (`render()`/`html()`) vs stash (`page()` + `ntdst_page_data()`) |
| 4 | search over `$custom_paths`, three loops | `locate()` (guard + cache), `templateInclude()`, `locateInCustomPaths()` (neither) |
| 5 | "where templates live", three entries | `Theme::templatePath()`, `Loader::addPath()`, `Response::addPath()` (Mailer only) |
| 6 | "which file renders", four hook sites in two classes | `template_include` @10/@99/@999 + `{$type}_template` |
| 7 | redirect, three wrappers | `Response`, `ntdst_redirect()`, `Pages` |

After D2d's rulings (`path()` on rewrite rules; callbacks return a path, never
exit; hierarchy via `{$type}_template` with WP's candidates) it collapses:

- `Pages`: `path()` + the filter wraps, all returning a path. No Response
  handling, no result contract (#1), no `commitOk()` (#2), no canonical filter.
- `Response::render()` loses its only caller → delete with `renderError()` and
  `commitRenderStatus()`. `html()` stays (Mailer, theme helpers).
- `Loader::page()` + `ntdst_page_data()` is *the* hand-off (#3). `locate()` is
  the one search (#4); `templateInclude` → `{$type}_template`;
  `locateInCustomPaths` calls `locate()`. `Response::addPath()` goes; Mailer
  passes its dirs to `locate()`'s existing `$extraPaths` (#5).

One way to choose a file (the Loader), one way to hand data (`page()`), one way
to refuse (`set_404()`), one redirect. The Loader moves out of `Response.php`
into its own file — it is not a response.

**D3 — Same major, or a 6.0.0?** 5.0.0 is unreleased; nobody has taken it. Two
majors in one week is worse than one larger one. Recommendation: all of this is
5.0.0.

**D4 — When to tag.** Not tonight. Tagging now freezes a field flag with no reader
and ships a README that documents a removed `/download`. Tag when D1 and D2 have
landed and README covers every break. daan's 4 purge reds can wait a day.

**D5 — Scope fence: the three files.** The question is the shape of `Data.php`,
`Rest.php` and `Actions.php` (Stefan, 16:30 and 16:47: *"this is about data.php,
actions.php and rest.php. first we solve this"*). In: those three, README, the
baseline reader if D1 = b. Out, as separate later phases: daan's handler
migration (use `ce5d72c` as a reference diff only), stride / netdust / rossi, the
frozen search/import/export specs, rossi's provenance exposure (record §3 — not
lost, not this work).

**Sites have one role in this plan: a consumer count.** "Does anything use this?"
is a fact core needs (A6). "How does daan / rossi do it today?" is not a design
input. Yesterday every core decision after 16:55 was shaped by a site — daan's
`release` test contract, daan's `public_actions` assertion, daan's grant nonces,
rossi's enquiry actions — and that is the site bending core, the reverse of *"the
site adapts, not the other way around"*. The design comes from the three files,
WordPress's own mechanisms (A3), and the rulings. A site is consulted to count,
never to copy.

**D6 — How a consumer tests against unreleased core.** Composer `path` repository
in daan's `composer.json` (dev-only), or `v5.0.0-rc.1`. Decide once; it removes
the substitution hazard for good.

---

## 4. Proposed plan shape — after D1–D6

Class **A**, stakes **high** — the failure mode is anonymous disclosure on live
sites. Route: `harnessed-development` → `planning` → `superpowers:brainstorming`
(D1–D6 are its Stage 0) → `ntdst-core/specs/<feature>/spec.md` →
`superpowers:writing-plans` → `tasks.md` → `gate-check` → seam. Stefan present →
plan inline. `wp-plan-requirements` and `threat-modeling` fire (anonymous reach).

Draft tasks, to be re-cut by writing-plans:

| # | task | tier | notes |
|---|---|---|---|
| T1 | `Data.php`: settle the field declaration per D1 — keep + document, or delete | A | if b: the reader is T1b in baseline |
| T2 | Delete `Actions.php`, `/get_nonce`, `ntdst-api.js`, the boot line, its tests; keep the auth-before-limiter and 429-vs-401 contracts on `Rest` (they exist there already) | A | threat model: what did Actions guard that Rest does not — origin check (`$verifyOrigin`) is the one to answer |
| T3 | README 5.0.0 upgrade guide: Rest (done), Data (`public_fields` → D1's answer), Actions (`register()` → `ntdst_rest()->post()`; `get_nonce` → `X-WP-Nonce`; `/download` → `downloadHeaders()` + own bytes), one migration table | B | blocks the tag |
| T4 | `docs/philosophy.md` + `specs/routing-services/spec.md` reconciled with the result (routing-services still names three services) | B | |
| T5 | D6: path repo or rc tag, so T6 is verifiable | B | |
| T6 | Tag `v5.0.0`, push | — | after T1–T5 green |
| later | daan: reads → GET, grants → POST, capability timing (§4.3 of the record), exposure shape per D1; todai-client update; baseline release | A | its own plan |
| later | stride (`^3.0` on 2.4.1), netdust (`^2.2`), rossi | — | each its own plan |

Housekeeping, any time: remove baseline's three stale `.claude/worktrees/agent-*`;
commit or drop the frozen specs; close the `spec-search` worktree (w7) or keep it
deliberately.

---

## 5. How to get it done — one spec, one phased plan (Stefan, 03:20: "look into how to get this done")

**One spec, one plan, five phases.** `gate-check.py` parses phases and review
clusters inside `tasks.md` (`## ` headings are phase boundaries; clusters ≤4
tasks, each closed by `Integration gate:` and a `── REVIEW GATE ──` marker where
`building` halts). Phases are the native shape. Separate specs are for
independent features, and these are not independent: Actions cannot leave until
Rest has `->public()` and RelationField has a route; Response's trims follow
Pages' rewrap; README and the tag come last. Cross-spec dependencies are
invisible to the gate; cross-phase ones are what it checks.

The spec's `## Intent decisions` table is already written: every ruling above
carries a quote and a time — the `Source:` line the gate requires per FR.
`superpowers:brainstorming` must still be invoked (the guard denies `spec.md`
without it); tomorrow it is a confirmation of the six open rows, not a design
session.

| phase | scope | order reason |
|---|---|---|
| 1 · Data | `show_in_rest` → `register_post_meta()` + `custom-fields`; schema mapping incl. repeaters; `restFields()` as the route-side reader | the ask, and the anonymous-exposure surface — the threat model lives here |
| 2 · Rest | internal default, `->public()`, write-verb refusal, `'is_user_logged_in'` string, `cors()` on `allowed_http_origins`, delete the surface registry | Actions cannot leave until this exists |
| 3 · Actions out | RelationField → route + `wp.apiFetch`; delete `Actions.php`, `/get_nonce`, `ntdst-api.js`, the `api*` envelopes, the boot line, tests | depends on 2 |
| 4 · Pages · Loader · Response | `path()` on rewrite rules; Loader to its own file, one `locate()`, `{$type}_template` with WP's candidates; delete `render()`, the error page, the mime table, the extra redirects | independent of 1–3; largest diff, so not first |
| 5 · Theme · docs · release | Theme trims; README 5.0.0 guide for every break; `philosophy.md` + `specs/routing-services` reconciled; `v5.0.0` tag | last by definition |

Gates the overlay will demand, already answered here: `## Threat model` (the D2
tables); `## First working version` — phase 1's `register_post_meta` gives it
(`curl /wp-json/wp/v2/gig` shows a declared field and hides an undeclared one);
`ARCHITECTURE-INVARIANTS.md` — ntdst-core has none and the overlay requires it
when the work touches authorization. Tonight's rulings are the invariants:
nothing leaves unless named · one surface (`Rest`) · WordPress's word before ours
· one template resolver. ~30 lines via `netdust-agent:architecture-invariants`,
written before the spec; every phase's reviewers converge on it.

Own later specs, not this one: daan's migration, stride / netdust / rossi, search
/ import / export.

Spec dir: `specs/core-shape/` (spec.md · plan.md · tasks.md).

## 6. Sources

- `docs/session-2026-08-21-actions-to-rest.md` — rulings A1–A9, the session's own
  account.
- Transcript `~/.claude/projects/-home-ntdst-Sites-ntdst-baseline/2a24e053-….jsonl`
  (times above are from it).
- `git reflog` in ntdst-core, daan, ntdst-baseline; `composer.lock` in daan.
