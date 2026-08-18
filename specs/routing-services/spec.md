# Routing services — one name per concept, and the REST leg the framework never had

ntdst-core routes three different things and only names two of them. This gives
each its own service and its own vocabulary, and adds the resource-routing
capability whose absence forced every adopter to hand-roll `register_rest_route`.

**Status:** spec — awaiting approval at the seam. No code written.
**Target release:** v3.0.0 (breaking).

---

## Intent decisions

Rulings from the brainstorm (2026-08-18). A change to any of these is a spec
revision, not a build-time judgement call.

| # | Question | Ruling | Source |
|---|---|---|---|
| D1 | Add a REST leg to the existing Router, or restructure? | **Restructure into three named services.** | User: "it's A" |
| D2 | Keep old facades as deprecated aliases through v3? | **No aliases. Clean break at v3.0.0.** | User: "just make a clean repo, no aliasses" |
| D3 | Include CORS, and in what shape? | **Yes — declared through the same facade as the route**, not a standalone bolt-on. | User: "Both, redesigned through the Router facade" |
| D4 | Does this phase convert Stride's 33 admin routes? | **No.** Ship the service, prove it on the 6 Partner API routes; the 33 live dashboard routes convert in a later pass. | User approval of the Section 3 boundary |
| D5 | Why does REST get the deepest treatment? | It is the strategic surface — the reason other systems can integrate at all. | User: "rest routes are more important these days so sites can connect" |

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

**FR-1 — Three services, three vocabularies.**
`ntdst_pages()` (front-end), `ntdst_actions()` (commands), `ntdst_rest()`
(resources). Each owns one concern, and no concept is reachable through two
names.
*Source:* User: "it's A"; Section 1 approved 2026-08-18

**FR-2 — HTTP verbs belong to `ntdst_rest()` alone.**
`NTDST_Router::get()/post()` become `path($pattern, $callback, $method)`. After
this change, `get()` in the package means an HTTP GET resource route, always.
*Source:* User: "without creating confusion so good naming"

**FR-3 — `ntdst_rest()` registers namespaced resource routes.**
Chainable per namespace: `ntdst_rest('ns')->get($route, $handler, $opts)` and
the same for post/put/patch/delete. Carries the parked registrar's security
properties:
- `permission` is REQUIRED with no default — a route queued without a callable
  permission is **never registered** (not registered-then-denied);
- per-request, per-wrapper permission memoization (WP core invokes
  `permission_callback` twice per served request);
- body-size and JSON-depth caps on write verbs;
- handler-return normalization through `NTDST_Response`.
*Source:* User D5; parked `NTDST_Rest_Registrar` docblock §"Required-permission / public-route pattern (mitigation 6)"

**FR-4 — One rate limiter, one client-IP resolver.**
`ntdst_rest()` uses `support/RateLimiter.php` and `support/ClientIp.php`. The
parked copies are discarded, not ported.
*Source:* invented — ground-truthed 2026-08-18 (v2.4.0 `c09ef90`/`76f7016` extracted both OUT of `Endpoints`); approved by D2's "clean repo" framing, since a second limiter is precisely the debt this release removes

**FR-5 — CORS declared where the route is declared.**
Cross-origin policy is an option on the namespace or route, not a separate
`new NTDST_Cors_Policy(...)` + `register($prefix)` call. `CorsPolicy` becomes
internal machinery with no public constructor contract.
*Source:* User D3

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

**FR-9 — The rule is written down.**
An invariant states which service a new surface belongs to: command →
`ntdst_actions()`, resource → `ntdst_rest()`, file bytes →
`ntdst_actions()->download()`, page → `ntdst_pages()`. INV-11's "vacancy
window" language is retired now that its convergence point exists.
*Source:* User: "without creating confusion"

---

## Success criteria

- **SC-1**: `ntdst_rest('x/v1')->get('/thing', $cb)` with no `permission` leaves `/x/v1/thing` ABSENT from `rest_get_server()->get_routes()`. Proven by asserting absence, not by asserting a 403.
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
- [x] **Cross-origin policy** — FR-5 decides which origins reach a namespace.
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
