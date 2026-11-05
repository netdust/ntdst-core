# Plan review — core-cron-seam (plan 1 of 2: ntdst-core)
Reviewed-plan: bc028eaf0937f2971f772c7862a4acab4ca29545 /home/ntdst/Sites/ntdst-core/specs/core-cron-seam/plan.md
Verdict: ready with fixes

Spec: /home/ntdst/Sites/ntdst-core/specs/core-cron-seam/spec.md. Source opened: Stride's `web/app/plugins/ntdst-cron/{ntdst-cron.php,src/CronRegistry.php,src/RunLog.php}` and `tests/Unit/Cron/*`, core's `ntdst-core.php`, `core/Container.php`, `core/Bootstrap.php`, `services/Logger.php`, `bin/guard.sh`, `bin/zero-readers.sh`, `ARCHITECTURE-INVARIANTS.md`, `tests/bootstrap.php`, `composer.json`, `phpcs.xml`, `CHANGELOG.md`, `README.md`, baseline's `services/MaintenanceService.php`, and the drift reviewer agent in the marketplace source.

## Blocking

None. The code being moved is exactly what the plan describes, the container and hook contracts it binds to exist as named, every threat-model mitigation has a task, and every spec requirement in scope has one.

## Should fix

1. **Task 2 Step 3, Test map row 2, Task 3 Step 1 — the helper and the binding are placed where no unit test can reach them.** The plan puts `ntdst_cron_register()` and the `add_action('ntdst/services_registered', …)` binding in `ntdst-core.php`, then tests them in the unit suite (`testTheBindingResolvesOneRegistryFromServicesRegistered`, `testTheHelperRegistersThroughTheBoundRegistry`) and loads "the helper" in Task 3's subprocess. Nothing in `tests/` executes `ntdst-core.php`: every reference reads it as text (`tests/Unit/BootstrapLoadsNothingByGuessingTest.php:540`, `tests/Unit/PackageBootIntegrityTest.php:1455`, `tests/Unit/CoreShapeCluster3FeatureTest.php:485`, `tests/Unit/CoreTrimClusterCFeatureTest.php:574`), and it cannot be executed outside WordPress — `ntdst-core.php:24` calls `plugins_url()` and `:66` calls `NTDST_Rest::mountAbilities()` at load. As written, the row's two tests and the Task 3 probe have no file to require. Smallest change: define the helper and the binding callback in `services/CronRegistry.php` beneath the class — the spec's own option ("beside the registry", Left to the builder) and the shape `services/Logger.php:299` already uses for `ntdst_log()` — leaving `ntdst-core.php` with the `require_once` only. Write one consequence into the step: Brain Monkey defines `add_action` only inside `Monkey\setUp()` (`vendor/brain/monkey/inc/api.php:28`) and core's `tests/bootstrap.php:28` defines `add_filter` only, so a file-scope `add_action(…)` needs the file required inside the test's `setUp()` after `Monkey\setUp()`, or a bootstrap `add_action` recorder beside the `add_filter` one.

2. **Task 2 Step 1 / Interfaces (Consumes) — the ported failure-path cases need two constants core's harness does not define.** `CronRegistry::failureSummary()` reads `WP_CONTENT_DIR` unconditionally (Stride `src/CronRegistry.php:226`) and `testTheDatabasePasswordIsNotStoredInAFailureSummary` reads `constant('DB_PASSWORD')` (Stride `tests/Unit/Cron/CronRegistryTest.php:91`). Stride's harness defines both for exactly this (Stride `tests/bootstrap.php:41-45`, "A stand-in credential, never the real one"). Core's `tests/bootstrap.php:4` defines `ABSPATH` only; `DB_PASSWORD` appears nowhere under `tests/`; `WP_CONTENT_DIR` is defined in-file by `tests/Unit/LoggerSurfaceTest.php:86` and `tests/Unit/CoreTrimClusterBFeatureTest.php:183` to temp paths, so whether it exists when the cron file runs depends on file order. Five of the 23 cases pass through `failureSummary()` and throw `Undefined constant` on core's harness. Smallest change: Step 1 opens the test file with `defined('WP_CONTENT_DIR') || define(…)` and `defined('DB_PASSWORD') || define('DB_PASSWORD', 'unit-test-db-password')` (Stride's idiom; an unguarded bootstrap define would collide with the two in-file defines), and lists both under Consumes.

3. **Task 4 — the moved file adds one hit to INV-5 and one to INV-8, and the task re-runs only INV-10.** Run against the file being copied: INV-5's check returns `CronRegistry.php:30 private const SECRET_CONSTANTS = [`; INV-8 (A) returns `CronRegistry.php:65 'callback' => $callback,` (the map-key alternative). Both invariants enumerate every hit and say anything else is a bypass (`ARCHITECTURE-INVARIANTS.md:188-191`, `:470-473`); the drift reviewer's Part 1 parses the file's checks live (`plugins/netdust-wp/agents/ntdst-drift-reviewer.md:10`), so the Drift constraint in Global Constraints reports both as unnamed. Smallest change: a Task 4 step re-runs INV-5 and INV-8 (A), names `CronRegistry::SECRET_CONSTANTS` under INV-5's status (the credential constant names — a list WordPress keeps no table for, the `ClientIp::DEFAULT_TRUSTED_PROXIES` kind) and the `'callback' =>` job-config key under INV-8's "MAP KEYS that are not type names" group (the job's callable, the same shape as WordPress's own `callback` argument key already named there, `:858-859`), and moves the counts: INV-8 (A) 56 → 57; INV-5 to what the re-run measures plus one (the status records TWENTY-ONE at `824ef7c`, the check returns 22 on the 5.6.0 tree already — pin what is measured).

4. **Task 2 Step 1 "Port the 26 cases" and Step 4 "Expected: PASS, 30 tests" — the file has 23.** `grep -c "public function test" stride/tests/Unit/Cron/CronRegistryTest.php` → 23, no data providers. 23 ported + 4 new = 27. Fix both numbers; a count only, no dependency rests on it.

5. **Review Focus 3, second clause — "a lock that cannot be released does not throw" names no test.** Task 1 Step 1's `testTheLockIsReleasedWhenTheWriteThrows` proves a different property (the write throws, release still runs). `releaseLock()` is one `$wpdb->get_var()` (Stride `src/RunLog.php:64-69`); wpdb does not throw, so there is nothing of RunLog's to test. Drop the clause, or name the test that owns it.

## Note

1. **Simpler design for the helper's guard.** The "weird case" the Simplest design names (a site that loads the old plugin first) and Task 3's `testTheHelperIsNotRedeclaredWhenTheOldPluginDefinedItFirst` describe an order WordPress cannot produce: core is a `wordpress-muplugin` (`composer.json:4`), mu-plugins load before plugins, and the plugin's own file returns before declaring anything when core is absent (Stride `ntdst-cron.php:17-24`). Spec R4's "or the reverse" is old core with the new plugin, which plan 2 answers with its `require core ≥ 5.7`. `services/Logger.php:293-297` argues the opposite shape for this very collision: "A !function_exists() wrapper here would let a second, older copy … win the declaration and silently serve every log line — a redeclare fatal names that collision instead." An unconditional declaration (Logger's shape) is simpler, drops one test and the comment, and Task 3 Step 3's RED becomes "unguard the fixture's copy → redeclare fatal". The wrapper is still legal in core (ten definition wrappers exist; `bin/guard.sh:479-488` exempts them by shape), so this is a choice to make explicit; the justification as written names a site that does not occur.

2. **Simpler design for `testEveryCredentialConstantIsRedacted` (Task 2 Step 1).** Ten subprocesses are not needed: no test redefines a constant, and `secrets()` reads whichever are defined (`CronRegistry.php:233-247`). Define all ten once with distinctive values (`defined() || define()`, beside `DB_PASSWORD` from Should fix 2) and assert in-process in one case. Nothing in shipped core reads an auth key or salt (grep over api/core/admin/services/support: only `WP_CONTENT_DIR`, in Logger). `SECRET_CONSTANTS` is `private const` (`:30`), so the test reads it by reflection or spells the ten names — say which.

3. **INV-12's status line (Task 4 Step 1) names one known open hit; on a Stride root the same check returns four more that are not third-party.** `stride-core/Support/cron.php:31`, `ntdst-audit/src/cron-fallback.php:34` (the fallbacks the spec's Risks call dead code), `ntdst-assistant/ntdst-assistant.php:62` (a bare hourly cleanup), and the vendored baseline copy at `mu-plugins/ntdst-baseline/services/MaintenanceService.php:146`. If the status is what makes the drift reviewer "report it as such, not as a surprise" (R6), name the Stride three for plan 2 as well.

4. **Execution-time trips, no ruling needed.** (a) `bin/guard.sh:12` demands the literal `defined('ABSPATH')` in every `.php` outside vendor — the two Task 3 fixtures and the three new test files included (`tests/Unit/RateLimiterTest.php:2` is the shape); the fixture that "throws on load" must carry the string before it throws. (b) `phpcs.xml` is PSR-12 and Stride's two files already are; the plan's own snippets are WordPress-spaced (`ntdst_set( RunLog::class )`) — copy the Stride shape, not the plan's. (c) The spec's and Risks' "four Stride callers" are two call sites (`ntdst-audit/src/AuditService.php:54-56`, `stride-core/Modules/Reminder/GateReminderService.php:91-92`) plus two fallbacks that never call the helper; nothing in plan 1 consumes this, plan 2 will.

## Premises checked

- "copy Stride's `src/RunLog.php` unchanged: `MAX_RUNS = 20`, `append()`, `runsFor()`, option `ntdst_cron_runs` written autoload false" → Stride `src/RunLog.php:13-33` → holds
- "`CronRegistry::__construct(RunLog)`, `register/all/has/run/isPaused/pause/resume`" → `src/CronRegistry.php:45-155` → holds
- "`failureSummary()`/`secrets()`, `MAX_SUMMARY` 500, `PAUSED_OPTION`, exact-match redaction, ABSPATH/WP_CONTENT_DIR stripped, logger gets the full message" → `src/CronRegistry.php:19, :27, :196-200, :224-247` → holds
- "fixed lock name, 5-second timeout, released in `finally`, unavailable lock still records" → `src/RunLog.php:17-38` → holds
- "the plugin's helper is `function_exists`-guarded; its bindings are made on `ntdst/services_registered`" → `ntdst-cron.php:65-70`, `:31-42` → holds
- "Stride's PSR-4 `Ntdst\Cron\` entry" → Stride `composer.json:86` (core pinned `^5.4`, `:55`) → holds
- "`ntdst_set(string $id, mixed $value = null)`; no second argument registers the id as its own class; `ntdst_get()`" → `core/Container.php:72-80, :296, :308` → holds
- "`ntdst/services_registered` fired by Bootstrap; `ntdst/features_ready` after boot" → `core/Bootstrap.php:293`, `:682` → holds
- "INV-10 check (4): one gated `ntdst_set($class)` at `core/Bootstrap.php:501` behind `class_exists()` at `:408`" → holds (plus `core/Theme.php:58`, as the invariant says)
- "Logger is required first at `ntdst-core.php:40`; `BootstrapLoadsNothingByGuessingTest` reads the require list and pins every `ntdst_log` caller after it" → `tests/Unit/BootstrapLoadsNothingByGuessingTest.php:535-594` → holds
- "the log recorder in `tests/bootstrap.php` captures `ntdst_log`" → `tests/bootstrap.php:49-61` → holds
- "`CronBootstrapGuardTest::bootWithoutCore()` subprocess pattern" → Stride `tests/Unit/Cron/CronBootstrapGuardTest.php:53-90` → holds
- "RunLogTest: five cases with the five names given" → Stride `tests/Unit/Cron/RunLogTest.php:42-99` → holds
- "CronRegistryTest: 26 cases" → 23 → does not hold (Should fix 4)
- "Review Focus 1's three names and Review Focus 5's three cases exist in Stride's file" → `:89, :125, :149`; `:387, :253, :274` → holds
- "`bin/guard.sh` has an INV-11 line to copy" → `bin/guard.sh:276-285` → holds
- "the INV-12 grep as written is EMPTY on core today; after the move its only hit is the excluded `services/CronRegistry.php`" → run on the tree → holds
- "`wp_schedule_single_event` stays outside the grep" → the substring `wp_schedule_event` does not occur in it → holds
- "`composer gate` = syntax, test, guard, audit:deps; `composer lint` = phpcs" → `composer.json:23-35` → holds
- "CHANGELOG 5.6.0 has `### Added` / `### Notes on update`; README has `## Versions` (:46), `### 5.6.0 —` (:48), `#### Extension points` (:631)" → holds
- "`bin/zero-readers.sh` sweeps a root holding a caller of `ntdst_cron_register`" → `bin/zero-readers.sh:81` (`stride-core`, where `GateReminderService` lives) → holds
- "baseline's `MaintenanceService` calls `wp_schedule_event()` bare" → `ntdst-baseline/services/MaintenanceService.php:149` → holds
- "`ntdst-drift-reviewer` picks a new invariant up as a Part 1 check" → it parses the vendored invariants file live, `agents/ntdst-drift-reviewer.md:10` → holds
- "the helper's `function_exists` wrapper passes `bin/guard.sh`'s FR-3 sweep" → exempt by shape, comment lines skipped, `bin/guard.sh:509-531` → holds
- "the helper and binding in `ntdst-core.php` are reachable from the unit suite" (implicit in Test map row 2 and Task 3 Step 1) → no test executes `ntdst-core.php` → does not hold (Should fix 1)
- "core's harness provides what the ported cases consume" (Task 2 Consumes) → `tests/bootstrap.php:4` defines `ABSPATH` only; `DB_PASSWORD` nowhere in `tests/` → does not hold (Should fix 2)

## The cut

- Plan 1 (`specs/core-cron-seam/plan.md`, ntdst-core) → one feature. It builds and its tests run with nothing from Stride merged: the two files are copied in, the suite is core's own, the fixture stands in for the old plugin. Owns R1, R2, R4's core half and fixture test, R5's ports, R6, R7.
- Plan 2 (Stride, unwritten) → one feature by the spec's own description. Owns R3, R4's plugin half, R5's real-WordPress proof. Its boundary with plan 1 is Composer's version constraint (Stride pins `netdust/ntdst-core` `^5.4` today), not a shared file: plan 1 edits no Stride path, plan 2 edits no core path, and plan 1 is testable before plan 2 exists. Not artificially split. No requirement unowned or double-owned. Nothing to merge or split; the Hand-over names what plan 2 must read off plan 1 (the two container ids, the helper's shape, the CHANGELOG note) — if Should fix 1 or Note 1 changes the helper's file or guard, that hand-over line changes with it.

## Applied after the review (by the planning session, not the reviewer)

All five Should fix items and Notes 1–4 were applied to the plan; the plan's blob moved from `bc028eaf0937f2971f772c7862a4acab4ca29545` to `67aff595e14f0f6af7b6f2a4be7eae9fbf3bd7c6`. No re-review was run: the findings were mechanical corrections, and the plan's decisions did not change except Note 1, which was adopted (the helper is declared unconditionally, in `services/CronRegistry.php`, as `services/Logger.php` does for `ntdst_log()`).

1. Helper and binding moved into `services/CronRegistry.php`; `ntdst-core.php` gets the `require_once` only; the binding test runs the file in a subprocess (or a named static method, the builder says which).
2. Task 2 opens with the `WP_CONTENT_DIR` and `DB_PASSWORD` defines and lists them under Consumes.
3. Task 4 re-runs INV-5 and INV-8 (A) (Step 4b), names the two new hits, pins measured counts, and runs the drift reviewer's Part 1.
4. 23 cases ported, 27 tests expected.
5. Review Focus 3 now names the two tests that own it.
- Note 1: unconditional helper; Task 3's reverse-order test dropped, its RED is now "unguard the fixture's copy".
- Note 2: one in-process case defines all ten constants, the ten names spelled in the test.
- Note 3: INV-12's status names the open hits on a Stride root.
- Note 4: fixtures carry the ABSPATH literal; PSR-12 spacing is copied from Stride.
