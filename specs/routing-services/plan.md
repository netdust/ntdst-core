# Routing services — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ntdst-core gains three named routing services — `ntdst_pages()`,
`ntdst_actions()`, `ntdst_rest()` — with HTTP verbs owned exclusively by the
REST one, shipped as a clean v3.0.0 with no aliases, and proven end-to-end by
migrating Stride's 6 Partner API routes onto it.

**Architecture:** `NTDST_Router` → `NTDST_Pages` (verbs `get/post` become
`path()`); `NTDST_Endpoints` → `NTDST_Actions` (keeps `/action`, `/download`,
`/get_nonce`); new `NTDST_Rest` built from the parked registrar, reconciled onto
v2.4's `support/RateLimiter` + `support/ClientIp`, with CORS as a declared
option rather than a bolt-on. `NTDST_Response` is untouched.

**Tech Stack:** PHP 8.2+, WordPress REST API, PHPUnit + Brain Monkey (package
gate), Stride's unit + wp-phpunit integration suites.

**Spec:** `specs/routing-services/spec.md`

**Repos:** the framework work lands in `~/Sites/ntdst-core`; the adoption work
in `~/Sites/stride`. Two repos, two branches, one sequence.

## Global Constraints

- Target release **v3.0.0** — breaking, semver-major.
- **No aliases, no `class_alias`, no deprecation forwarders** (FR-6).
- **One rate limiter, one client-IP resolver** — `support/RateLimiter.php`,
  `support/ClientIp.php`. Parked copies are discarded, not ported (FR-4).
- `permission` is REQUIRED on every REST route; absent → the route is never
  registered (FR-3).
- Partner API URLs, auth model, company scoping and JSON shape **do not change**
  (FR-8).
- Stride's 33 admin `register_rest_route` calls are **out of scope** and must
  still work untouched on v3 (D4).

---

## Stakes

Stakes: high — the phase changes an authorization mechanism (per-route
`permission_callback` → declared option) on a multi-tenant API, and ships a
breaking framework release to four sites. A scoping or fail-open regression
leaks one company's data to another; a bad release breaks four sites at once.

Per-cluster refinement:
- **Cluster A (rename):** standard — mechanical, compiler-visible, no
  authorization semantics change.
- **Cluster B (`ntdst_rest`):** high — this is where fail-closed lives.
- **Cluster C (Stride adoption):** standard — rename call sites, behaviour
  identical.
- **Cluster D (Partner API + invariants):** high — multi-tenancy boundary.

---

## Architecture invariants touched

From Stride's `ARCHITECTURE-INVARIANTS.md`:

- **INV-11** — *no hand-rolled CORS / `rest_pre_serve_request`.* Its convergence
  point (`NTDST_Rest_Registrar` / `NTDST_Cors_Policy`) has been VACATED since the
  core swap. This phase restores it and retires the "vacancy window" wording
  (FR-9, SC-8).
- **INV-14** — *admin actions ride `ntdst/api_data` or `ntdst/api_download`.*
  Currently green. Cluster A renames the facade those actions register through;
  the invariant's checker greps the filter names (`ntdst/api_data/…`), which do
  **not** change, so it must stay green throughout — a red INV-14 during
  Cluster C means the rename touched dispatch, not just naming.
- **INV-1** — *REST routes must declare `permission_callback`.* `ntdst_rest()`
  enforces this at registration; after Cluster D the invariant is satisfied
  structurally for migrated routes rather than by inspection.

---

## Spec-premise ground-truth

Every "reuse X" premise read against real source before this plan shipped:

| Premise | Verdict |
|---|---|
| "Restore the parked registrar" | **Amended.** The parked code lives in the *Stride* repo (`refs/parked/rest-registrar`), not ntdst-core, and predates `support/RateLimiter` + `support/ClientIp`, carrying its own copies. It is a source to port from, not a branch to merge. |
| "The Router grows a REST leg" (overview) | **Refuted.** `NTDST_Router` is a template router (`template/single/page/archive/handleTemplateInclude`). Its `get()/post()` already mean page-pattern-matched-on-method. A REST leg there collides. |
| "Phase 2 converted the admin REST routes" (inventory line 38) | **Refuted at runtime.** 33 → 33 → 33 across the Phase 2 merge; 39 live `/stride/*` routes today. Phase 2 closed `wp_ajax` (19 → 0), a different leg. |
| "Endpoints is bloated from accretion" | **Refuted.** Five commits since v2.3.0, net −18 lines; two were extractions OUT. Public surface is a regular 8. |
| "CorsPolicy is ready to ship" | **Amended.** Ships redesigned as a declared option (D3), so its public constructor contract is deliberately not preserved. |

---

## First working version

**Task:** T03 — after it, `ntdst_rest()` registers a real route with a real
permission, and you can see it:

```bash
cd ~/Sites/ntdst-core && vendor/bin/phpunit --filter NtdstRestTest
# and in a WP context:
wp eval 'do_action("rest_api_init"); print_r(array_keys(rest_get_server()->get_routes()));'
```

Expected: the declared namespace appears; a route queued without `permission`
does not.

---

## Constitution check

- **ntdst-core is THE base** — this adds the missing capability UPSTREAM rather
  than forking it into an adopter. No Stride-local registrar; the whole point of
  the phase is that there is one home for resource routing
  (`memory/feedback_ntdst_core_is_the_base.md`).
- **No second leg** — FR-4 discards the parked limiter/IP copies rather than
  porting them, so the release removes a duplicate instead of creating one.
- **Enrollment max rigor** — not touched by this phase; the Partner API is the
  only Stride surface migrated, and its enrollment write path is unchanged
  (`memory/project_enrollment_max_rigor.md`).
- **Simplicity** — the 33 admin routes are deliberately out of scope (D4); this
  ships a capability and one proving consumer, not a mass migration.

## Phases & review clusters

| Cluster | Repo | Tasks | Stakes | Review tier |
|---|---|---|---|---|
| A — rename existing services | CORE | T01–T02 | standard | STANDARD |
| B — the REST service | CORE | T03–T07 | high | FULL |
| C — release and adopt | CORE → STRIDE | T08–T09 | standard | STANDARD |
| D — prove on Partner API | STRIDE | T10–T12 | high | FULL |

A and B are independent and may run in parallel. C requires both. D requires C.

## Threat model

1. **Any resource route** — an anonymous caller reaches a route registered with a missing or typo'd `permission` key; WP defaults permissive → the route is world-readable. **Mitigation:** `permission` is REQUIRED with no default; a route without a callable permission is never handed to `register_rest_route()`, and the test asserts absence from the route table, not a 403. (T03)
2. **Company B's enrollments and attendance** — an authenticated partner of company A reads them because scoping was lost in migration, most likely on `enrollments/{id}` fetched by id without a company check. **Mitigation:** per-endpoint cross-company denial test, with `enrollments/{id}` called out explicitly as the only single-item route and the easiest to miss. (T10)
3. **Permission side effects** — WP core invokes `permission_callback` twice per served request, so a side-effectful callable (audit write, counter) fires twice, or a globally-keyed memo leaks one user's decision to the next request. **Mitigation:** per-request, per-wrapper memoization; the test asserts exactly one invocation per served request AND no leak across two requests as different users. (T04)
4. **Server memory and CPU** — an anonymous caller posts a huge or deeply-nested JSON body to a write verb. **Mitigation:** body-size and JSON-depth caps applied before handler dispatch; oversize → 413, over-depth → 400, handler invoked zero times. (T05)
5. **Rate-limit control** — REST routes bypass the limiter the dispatcher already enforces, so the new surface is the cheap one to abuse. **Mitigation:** `ntdst_rest()` delegates to `support/RateLimiter.php`; a burst past the threshold returns 429 through the REST leg. (T06)
6. **Cross-origin data read** — a malicious site in a victim's browser reads a namespace because CORS was declared permissively, or because CORS is absent and a hand-rolled header creeps back later. **Mitigation:** CORS is a declared option with a deny-by-default posture, and INV-11 returns to a standing zero-hit gate. (T07, T12)
7. **Four production sites** — v3.0.0 removes facades and an adopter bumps carelessly, taking fatals instead of notices. **Mitigation:** semver-major plus release notes naming every removed symbol and its replacement; Stride migrates in this run and is the proof. (T08)

**Explicitly out of scope:** per-partner rate limits and partner onboarding docs (spec §Out of scope) — the shared limiter's global buckets apply, which is what the dispatcher has today.

## Acceptance flows

Driven through real authenticated HTTP against the six Partner API endpoints —
not direct PHP calls. This closes the gap recorded in
`memory/bug_enrollment_no_required_field_validation.md` (no live REST
round-trip test exists today).

| # | Flow | Edge covered | Expected |
|---|---|---|---|
| AF-1 | `GET /partner/users` with valid Application Password | happy path | 200, same JSON shape as pre-migration capture |
| AF-2 | Same, partner whose company has no users | **empty** | 200 + empty collection, not 404 |
| AF-3 | Any endpoint with no auth header | **denied** | 401, no data |
| AF-4 | Any endpoint with a valid password for a user lacking `_stride_company_id` | **denied** | 403 |
| AF-5 | `GET /partner/enrollments/{id}` where id belongs to another company | **cross-tenant** | 404/403, and no field of the foreign record in the body |
| AF-6 | `POST /partner/enrollments` with a 10 MB body | **boundary** | 413 before the handler runs |
| AF-7 | `POST /partner/enrollments` with 200-deep nested JSON | **boundary** | 400 before the handler runs |
| AF-8 | `POST /partner/enrollments` twice with the same payload | **re-entry** | Second call behaves exactly as pre-migration (no new duplicate-suppression invented) |
| AF-9 | Burst above the limiter threshold | **concurrent** | 429 with a retry signal |
| AF-10 | Handler throws mid-request | **mid-flow failure** | 500 envelope, no partial JSON, no stack trace to the client |

---

## Loop budget

Loop budget: ~14 iterations — 12 tasks plus two expected review-fix rounds.
Not an unattended run — Cluster B and D are high-stakes and gated. If driven by
`/loop`, cap at one cluster per wake and stop at every `── REVIEW GATE ──`.

---

## Sequencing note

Cluster A must land before C, and B before D. A and B are independent of each
other and can run in parallel; both are framework-side. C and D are Stride-side
and cannot start until v3.0.0 is tagged, because there are no aliases to migrate
against incrementally.

**Stride bumps to v2.4.1 first** (a separate, already-released version) and
verifies green, so a shared-limiter regression and a routing regression cannot
be confused for one another. That is T08's first step.
