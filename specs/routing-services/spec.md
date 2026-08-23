# Routing services — one name per concept, and the REST leg the framework never had

ntdst-core routes three different things and only names two of them. This gives
each its own service and its own vocabulary, and adds the resource-routing
capability whose absence forced every adopter to hand-roll `register_rest_route`.

**Status:** superseded by `specs/core-shape` (2026-08-23). Shipped at v3.0.0;
the clauses v5.0.0 overturned are marked *(Superseded)* where they stand. This
document is kept as the record of what was decided on 2026-08-18 and why —
nothing below is rewritten to agree with the package as it is today.
**Target release:** v3.0.0 (breaking).

> **What v5.0.0 changed.** `ntdst_actions()` and its whole dispatcher are
> DELETED, so there are two routed services and not three: `ntdst_pages()` for
> pages and `ntdst_rest()` for everything reached over HTTP. A route that names
> no permission registers as `is_user_logged_in` instead of being refused, and
> the refusal moved to the write verbs. Read `specs/core-shape/spec.md` and
> README's `### 5.0.0 — BREAKING` for the shape that ships.

---

## Intent decisions

Rulings from the brainstorm (2026-08-18). A change to any of these is a spec
revision, not a build-time judgement call.

| # | Question | Ruling | Source |
|---|---|---|---|
| D1 | Add a REST leg to the existing Router, or restructure? | **Restructure into three named services.** | User: "it's A" |
| D2 | Keep old facades as deprecated aliases through v3? | **No aliases. Clean break at v3.0.0.** | User: "just make a clean repo, no aliasses" |
| D3 | Include CORS, and in what shape? | **REVISED 2026-08-18 — CORS is OUT.** WP core already ships `rest_send_cors_headers()`; a policy class rebuilds it, and there are zero consumers. Narrowing origins is a filter on an existing hook if it is ever needed. | User: "ntdst-core can't get bloated by edge use cases" + "not to reinvent WordPress" — supersedes the earlier "both, redesigned" ruling |
| D4 | Does this phase convert Stride's 33 admin routes? | **No.** Ship the service, prove it on the 6 Partner API routes; the 33 live dashboard routes convert in a later pass. | User approval of the Section 3 boundary |
| D5 | Why does REST get the deepest treatment? | It is the strategic surface — the reason other systems can integrate at all. | User: "rest routes are more important these days so sites can connect" |
| D6 | How much of the parked 582-line registrar ships? | **Only the delta over WordPress.** `register_rest_route()` is not rebuilt — it is wrapped, closing four named WP footguns. Target is roughly 150 lines, not 582. | User: "the whole idea is not to reinvent WordPress. provide a common api, add some improvements" |

---

## Context — measured, not assumed

Ground-truthed 2026-08-18 against `~/Sites/ntdst-core` @ v2.4.1 and Stride @ `29278adb`.

**What exists today**

| Concern | Facade | Owner | Size |
|---|---|---|---|
| Front-end URL patterns + WP template hooks | `ntdst_route()`, `ntdst_router()` | `NTDST_Router` | 487 |
| Commands (the "ajax" idiom) | `ntdst_api_action()` | `NTDST_Endpoints` | 749 |
| File bytes | `ntdst_download()` | `NTDST_Endpoints` | — |
| Nonce minting | — | `NTDST_Endpoints` | — |
| Emission | `ntdst_response()` | `NTDST_Response` | 718 |
| **Resource routes** | — | **nothing** | — |

The package registers exactly three routes, all its own: `/ntdst/v1/get_nonce`,
`/action`, `/download`. There is no public way for a consumer to declare a
resource route.

**The consequences, measured in Stride**

- **39 live `/stride/*` routes**, all hand-rolled `register_rest_route` — 33
  admin, 6 partner. Confirmed at runtime, not by grep.
- **68 dispatcher actions** (63 `api_data` + 5 `api_download`).
- Phase 2 closed the *AJAX* leg (19 → 0 raw `wp_ajax`); it never touched the
  REST routes (33 → 33 → 33 across the Phase 2 merge). The convergence
  inventory contradicts itself on this — line 18 says Phase 4, line 38 says
  Phase 2 — and line 38 is wrong.

**The naming collision**

`NTDST_Router::get()/post()` register a *front-end page pattern* matched on an
HTTP method. Any REST leg hung off the same object would want `get()` to mean
an HTTP GET resource route. Same verb, two meanings, one method apart.

**What is reusable**

`refs/parked/rest-registrar` **in the Stride repo** (`134a301d`) holds
`NTDST_Rest_Registrar` (582 lines) and `NTDST_Cors_Policy` (369) plus 2,106
lines of tests. It predates v2.4's `support/RateLimiter.php` and
`support/ClientIp.php` and carries its own copies of both.

**Adopters:** stride (40 facade call sites), daan, josworld, todai. The last
three are small; Stride is the large one and migrates in the same run.

---

## Functional requirements

**FR-1 — Three services, three vocabularies.** *(Superseded by core-shape FR-1:
`ntdst_actions()` is deleted at v5.0.0 and a command is a `->post()` route. TWO
services — `ntdst_pages()` and `ntdst_rest()`. The RULE this FR states, one
concept one name, is what deleted the third.)*
`ntdst_pages()` (front-end), `ntdst_actions()` (commands), `ntdst_rest()`
(resources). Each owns one concern, and no concept is reachable through two
names.
*Source:* User: "it's A"; Section 1 approved 2026-08-18

**FR-2 — HTTP verbs belong to `ntdst_rest()` alone.**
`NTDST_Router::get()/post()` become `path($pattern, $callback, $method)`. After
this change, `get()` in the package means an HTTP GET resource route, always.
*Source:* User: "without creating confusion so good naming"

**FR-3 — `ntdst_rest()` wraps `register_rest_route()` and closes WordPress's
known gaps — it does not replace WP's routing.**
Chainable per namespace: `ntdst_rest('ns')->get($route, $handler, $opts)` and
the same for post/put/patch/delete. WP core does the registering; this adds only
the delta, each item a documented WP behaviour:
- **WP fails open on a missing `permission_callback`.** Since 5.5 it fires
  `_doing_it_wrong()` and registers the route anyway; `rest-api.php:890` then
  skips the check when the callback is absent, leaving the route public. Here
  `permission` is REQUIRED with no default and a route without a callable one is
  **never registered**. *(Superseded by core-shape FR-4: v5.0.0 registers an
  unnamed route as `is_user_logged_in` — WordPress's own floor, never anonymous
  — and REFUSES only a write verb whose gate nobody named. Refusing every
  unnamed read taught authors to write a permission that means nothing.)*
- **WP invokes `permission_callback` twice per served request** (Allow-header
  computation) → memoized per request, per wrapper.
- *(A body-size / JSON-depth cap bullet was WITHDRAWN 2026-08-18. WP reads the
  whole body and decodes it at depth 512 before any hook this class can reach,
  so a route-level cap arrives after the cost is paid — and `max_body_bytes` was
  bypassable outright via multipart, where php://input is empty. Body size is a
  web-server control; depth is bounded by WP's own 512.)*
- **Handler returns are ad-hoc** → normalized through `NTDST_Response`, the
  package's one emission path. *(Superseded by core-shape FR-8: a REST route
  returns its payload or a `WP_Error` and WordPress builds the body. The
  `{success, data}` envelope and the `apiSuccess()` / `apiError()` family went
  with the dispatcher.)*
*Source:* User D5; parked `NTDST_Rest_Registrar` docblock §"Required-permission / public-route pattern (mitigation 6)"

**FR-4 — `ntdst_rest()` delegates to the package's existing limiter and IP resolver.**
CORRECTED 2026-08-18: an earlier draft claimed the parked files carried their own
copies to be discarded. They carry none — the parked registrar does **no rate
limiting at all**. So this is a gap to FILL, not a duplicate to remove: without
delegation to `support/RateLimiter.php` + `support/ClientIp.php`, the new REST
surface is the one unthrottled way into the site while `/ntdst/v1/action` is
throttled.
*Source:* invented — ground-truthed 2026-08-18 by reading both parked files (0 matches for limiter/IP logic); approved by D6

*(The fifth requirement — "CORS declared where the route is declared" — was
WITHDRAWN 2026-08-18 per the revised D3: WP core's `rest_send_cors_headers()`
already does it and there are zero consumers. **Superseded 2026-08-20**: the
admission test was re-run with evidence — two consumers, three incidents — and
CORS entered core, declared per NAMESPACE rather than per route and added to
WordPress's own `allowed_http_origins`. See `docs/philosophy.md` §6. Its number is left unused rather
than renumbering, so the Satisfies: references in tasks.md stay stable.)*

**FR-6 — Clean break at v3.0.0.**
`ntdst_api_action()`, `ntdst_router()`, `ntdst_route()` and `NTDST_Endpoints` /
`NTDST_Router` are **removed**, not aliased. No `class_alias`, no deprecation
forwarders.
*Source:* User: "just make a clean repo, no aliasses"

**FR-7 — Stride migrates its forced call sites.**
30 `ntdst_api_action()` → `ntdst_actions()->register()`, 10 `ntdst_router()` →
`ntdst_pages()`. Behaviour identical; this is a rename, not a redesign.
*Source:* mechanical consequence of FR-6

**FR-8 — The Partner API proves the service.**
Its 6 routes register through `ntdst_rest()`. URLs, auth model, company scoping
and JSON shape are unchanged.
*Source:* User approval of the Section 3 boundary; chosen as proving consumer because it is deferred (`memory/project_production_priorities.md`) and has no live traffic

**FR-9 — The rule is written down.** *(Superseded by core-shape INV-2, which is
where the rule lives now: there is ONE HTTP surface, `ntdst_rest()`, and a page
is `ntdst_pages()`. File bytes are a `->get()` route whose callback ends in
`ntdst_download()`.)*
An invariant states which service a new surface belongs to: command →
`ntdst_actions()`, resource → `ntdst_rest()`, file bytes →
`ntdst_actions()->download()`, page → `ntdst_pages()`. INV-11's "vacancy
window" language is retired now that its convergence point exists.
*Source:* User: "without creating confusion"

---

## Success criteria

- **SC-1**: `ntdst_rest('x/v1')->get('/thing', $cb)` with no `permission` leaves `/x/v1/thing` ABSENT from `rest_get_server()->get_routes()`. Proven by asserting absence, not by asserting a 403. *(Superseded by core-shape SC-2: at v5.0.0 that GET registers with `permission_callback === 'is_user_logged_in'`. The absence assertion moved to `->post('/t', $h)` with no capability, which is the case worth proving by absence.)*
- **SC-2**: A permission callable with a side effect is invoked exactly 1 time per served request (WP core calls it 2 times).
- **SC-3**: `grep -rn "ntdst_api_action\|ntdst_router\|ntdst_route("` over the package returns zero hits outside the changelog at the v3.0.0 tag.
- **SC-4**: `composer gate` in the package exits 0 at the release commit, with 0 failures.
- **SC-5**: Stride runs on v3.0.0 with full unit + integration suites green and zero references to the removed facades.
- **SC-6**: All 6 Partner API endpoints answer identically before and after — same URLs, status codes, JSON shape, auth — proven by `PartnerAPIIntegrationTest` / `PartnerAPICascadeTest` passing unmodified plus a recorded before/after capture per endpoint.
- **SC-7**: A partner authenticated for company A reads 0 fields of company B's data through any of the 6 endpoints, `enrollments/{id}` included. 1 denial test per endpoint.
- **SC-8**: `scripts/check-invariants.sh` in Stride passes with INV-11 stated as a standing rule and no "vacancy" language in `ARCHITECTURE-INVARIANTS.md`.

---

## Security-relevant surfaces

The plan therefore owes a `## Threat model`:

- [x] **Authorization** — `permission` moves from a per-route `permission_callback` to a declared option. Fail-closed (no permission → no route) is the load-bearing mitigation and must be tested as absence.
- [x] **Multi-tenancy** — the 6 Partner API routes are scoped by `_stride_company_id`; a scoping regression leaks one company's enrollment and attendance data to another.
- [x] **Auth / session** — Application Passwords over Basic auth; WP core's double invocation of `permission_callback` is load-bearing for memoized callables.
- [x] **Untrusted parsing** — request bodies on write verbs; body-size and JSON-depth caps (FR-3).
- [x] **Rate limiting** — FR-4 moves REST onto the shared limiter; a misconfiguration removes a control the dispatcher has today.

## User-facing surfaces

- [x] **The 6 Partner API endpoints** — `/stride/v1/partner/{users,enrollments,enrollments/{id},certificates,attendance}`. The plan owes an `## Acceptance flows` matrix driven through real authenticated HTTP, not direct PHP calls, covering denied, empty, boundary and cross-tenant edges. `memory/bug_enrollment_no_required_field_validation.md` records that no live REST round-trip test exists today; this phase closes that gap.

---

## Out of scope

- **Stride's 33 admin routes** (D4) — they keep working unchanged on v3; they
  convert in a later pass against a service already proven.
- **`Data.php`** (2,291 lines) and the service-layer `$wpdb` residue — Phase 5.
- **Splitting `NTDST_Router`'s two jobs** (URL matching vs template-hook
  wrapping) — renamed here, not restructured.
- **`NTDST_Response`** — emission is orthogonal to routing; untouched.
- Making the Partner API production-ready (per-partner rate limits, partner
  docs, onboarding).
