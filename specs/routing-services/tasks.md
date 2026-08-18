# Routing services — tasks

Plan: `specs/routing-services/plan.md` · Spec: `specs/routing-services/spec.md`

Repos: **CORE** = `~/Sites/ntdst-core`, **STRIDE** = `~/Sites/stride`.

---

### Cluster A — rename the two existing services (CORE)

Stakes: standard — mechanical and compiler-visible; no authorization semantics change.

Behaviour: the package exposes ntdst_pages() and ntdst_actions(), and no longer exposes ntdst_router(), ntdst_route() or ntdst_api_action().
Observable: `wp eval 'var_dump(function_exists("ntdst_router"), function_exists("ntdst_pages"));'` prints false then true.
RED until: tests/Unit/RoutingFacadesTest.php

- [ ] T01 — NTDST_Router becomes NTDST_Pages, verbs freed [Tier A]  (files: core/Pages.php, tests/Unit/NtdstPagesTest.php, tests/Unit/RoutingFacadesTest.php)
  Satisfies: FR-1, FR-2
  Test-author: solo — A-lite, pure rename with no authorization semantics
  Proven by: new test
  Unit test: registering path('/x', $cb, 'POST') matches a POST to /x and does NOT match a GET; function_exists('ntdst_router') is false and class_exists('NTDST_Router') is false.

- [ ] T02 — NTDST_Endpoints becomes NTDST_Actions [Tier A]  (files: api/Actions.php, tests/Unit/NtdstActionsTest.php)
  Satisfies: FR-1, FR-6
  Test-author: solo — A-lite, pure rename; filter names unchanged
  Proven by: new test
  Unit test: ntdst_actions()->register('x', $cb, ['capability' => 'manage_options']) mounts the ntdst/api_data/x filter and denies a caller lacking that capability with a WP_Error; function_exists('ntdst_api_action') is false. The ntdst/api_data/* and ntdst/api_download/* filter NAMES are unchanged, so adopter handlers still resolve.

Integration gate: `cd ~/Sites/ntdst-core && composer gate`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity)*

---

### Cluster B1 — the REST service: registration and authorization (CORE)

Stakes: high — this is where fail-closed authorization lives.

Behaviour: a consumer declares namespaced resource routes, and a route without a callable permission is never registered.
Observable: `wp eval 'do_action("rest_api_init"); print_r(array_keys(rest_get_server()->get_routes()));'` lists the declared route and omits the permission-less one.
RED until: tests/Unit/NtdstRestTest.php

- [ ] T03 — ntdst_rest() wraps register_rest_route(), permission required [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestTest.php)
  Satisfies: FR-3, SC-1
  Test-author: split
  Proven by: new test
  Unit test: a route declared WITH a callable permission appears in rest_get_server()->get_routes(); an otherwise-identical route declared WITHOUT one is ABSENT from that array — assert absence of the key, never a 403 status. WP core registers a permission-less route and skips the check (rest-api.php:890), so absence is the whole point. Port ONLY the delta from the parked NTDST_Rest_Registrar: registration must call WP's register_rest_route(), not reimplement matching, and api/Rest.php stays under ~200 lines (D6).

- [ ] T04 — permission memoization, per request and per wrapper [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestTest.php)
  Satisfies: FR-3, SC-2
  Test-author: split
  Proven by: new test
  Unit test: a counting permission callable is invoked exactly 1 time across one served request (WP core calls permission_callback twice); two successive requests as different users each get their own decision, proving no cross-request memo leak.

Integration gate: `cd ~/Sites/ntdst-core && composer gate`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + code-simplicity)*

---

### Cluster B2 — the REST service: limits (CORE)

Stakes: high — these are the controls that stop abuse of a public surface.

Behaviour: oversized or over-nested bodies are refused before any handler runs, and REST shares the package's one rate limiter instead of being the unthrottled way in.
Observable: `curl -X POST --data-binary @10mb.json <site>/wp-json/x/v1/thing` returns 413 and the handler log stays empty.
RED until: tests/Unit/NtdstRestLimitsTest.php

- [ ] T05 — body-size and JSON-depth caps [Tier A]  (files: api/Rest.php, tests/Unit/NtdstRestLimitsTest.php)
  Satisfies: FR-3
  Test-author: solo — A-lite, caps are a pure input guard with no tenancy dimension
  Proven by: new test
  Unit test: a body over the configured size returns 413 and the handler is invoked 0 times; JSON nested past the depth cap returns 400 and the handler is invoked 0 times. Assert the invocation count, not only the status.

- [ ] T06 — one limiter, one client-IP resolver [Tier B]  (files: api/Rest.php, tests/Unit/NtdstRestLimitsTest.php)
  Satisfies: FR-4
  Test-author: solo
  Proven by: new test
  Unit test: a burst past the configured threshold returns 429 through the REST leg; api/Rest.php contains 0 second implementations of rate limiting or IP resolution (it delegates to support/RateLimiter.php and support/ClientIp.php).

Integration gate: `cd ~/Sites/ntdst-core && composer gate`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + code-simplicity)*

---

### Cluster C — release and adopt (CORE then STRIDE)

Stakes: standard — rename call sites; behaviour identical.

Behaviour: Stride runs on v3.0.0 with no reference to a removed facade.
Observable: `grep -rn "ntdst_api_action\|ntdst_router" --include=*.php web/app/ | grep -v /ntdst-core/ | wc -l` prints 0 and both suites are green.
RED until: tests/Unit/NtdstFacadeMigrationTest.php

- [ ] T08 — tag v3.0.0 and bump Stride via v2.4.1 [Tier B]  (files: composer.json, CHANGELOG.md)
  Satisfies: FR-6, SC-3, SC-4
  Test-author: solo
  Proven by: machine gate — composer gate in CORE, plus phpunit --testsuite Unit in STRIDE
  Integration test: after bumping STRIDE to ^2.4 its unit and integration suites are green with counts at or above the recorded baseline (1372 unit tests at 2026-08-18); after tagging CORE v3.0.0 and bumping STRIDE to ^3.0, `wp eval 'echo 1;'` boots with 0 fatals and `composer gate` in CORE exits 0.

- [ ] T09 — migrate Stride's 40 forced call sites [Tier B]  (files: web/app/mu-plugins/stride-core, web/app/themes/stridence, tests/Unit/NtdstFacadeMigrationTest.php)
  Satisfies: FR-7, SC-5
  Test-author: solo
  Proven by: existing test — tests/Unit + tests/Integration Stride suites, and scripts/check-invariants.sh INV-14
  Integration test: 30 ntdst_api_action() and 10 ntdst_router() call sites are rewritten with 0 behaviour change; Stride's unit and integration suites are green at or above baseline; check-invariants.sh INV-14 stays green because the ntdst/api_data/* filter names did not change — a red INV-14 here proves dispatch was touched, not just naming.

Integration gate: `cd ~/Sites/stride && ddev exec vendor/bin/phpunit --testsuite Unit && STRIDE_TEST_DB_DISPOSABLE=1 ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist`

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity)*

---

### Cluster D — prove it on the Partner API (STRIDE)

Stakes: high — multi-tenancy boundary.

Behaviour: the six Partner API endpoints are served by ntdst_rest() and answer exactly as before, including cross-tenant denial.
Observable: authenticated curl against each of the 6 endpoints returns the same status and JSON shape as the recorded pre-migration capture.
RED until: tests/Integration/PartnerApiRestMigrationTest.php

- [ ] T10 — capture the before, then migrate the six routes [Tier A]  (files: web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php, tests/Integration/PartnerApiRestMigrationTest.php, tests/_data/partner-api-baseline)
  Satisfies: FR-8, SC-6, SC-7
  Test-author: split
  Proven by: new test
  Integration test: step 1 records an authenticated response capture for all 6 endpoints into tests/_data/partner-api-baseline/ BEFORE any edit; step 2 replaces the 6 register_rest_route calls with ntdst_rest(). Each endpoint then matches its baseline byte-for-byte on status and JSON shape, and a cross-company request is denied per endpoint — enrollments/{id} for another company's id returns 404 or 403 with 0 fields of the foreign record in the body.

- [ ] T11 — drive the acceptance matrix through real HTTP [Tier A]  (files: tests/acceptance/PartnerApiCest.php)
  Satisfies: FR-8, SC-6
  Test-author: solo — A-lite, drives an already-migrated surface; no new code path
  Proven by: new test
  Integration test: all 10 acceptance flows AF-1 to AF-10 from the plan pass against a running site over authenticated HTTP, not direct PHP calls; AF-5 (cross-tenant), AF-6 and AF-7 (caps) and AF-10 (mid-flow failure) are each asserted explicitly.

- [ ] T12 — restore INV-11 and write the routing rule [Tier B]  (files: ARCHITECTURE-INVARIANTS.md, scripts/check-invariants.sh, docs/plans/convergence-workaround-inventory.md)
  Satisfies: FR-9, SC-8
  Test-author: solo
  Proven by: machine gate — scripts/check-invariants.sh
  Integration test: `bash scripts/check-invariants.sh` exits 0; ARCHITECTURE-INVARIANTS.md contains 0 occurrences of "vacancy"; a new invariant states command to ntdst_actions(), resource to ntdst_rest(), bytes to ntdst_actions()->download(), page to ntdst_pages(); convergence-workaround-inventory.md line 38 is corrected to record Leg 1 as 33 remaining, not 0.

Integration gate: `cd ~/Sites/stride && ddev exec vendor/bin/phpunit --testsuite Unit && bash scripts/check-invariants.sh && ddev exec codecept run acceptance --env ci`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

## [HUMAN] yield points

- After T08 step 2 — tagging v3.0.0 is public and irreversible for four adopters. Confirm before the tag is pushed.
- After Cluster D — daan, josworld and todai still run v2.x and must migrate before taking any v3 release. Out of this plan's scope; surfaced so it is not forgotten.
