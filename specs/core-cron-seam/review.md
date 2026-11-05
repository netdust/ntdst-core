# Feature review — core-cron-seam (ntdst-core 5.7.0, plan 1 of 2)

Reviewed: range `abcaab1c212b61cd935309d8fd3a76118fab1f21..4e5806af6d59a56a5a733bf8faec50ec92ab8014` against production `origin/main`, with `specs/core-cron-seam/plan.md` and `spec.md`. Reviewers, each on the detached head checkout: the whole-branch review (superpowers' code-reviewer), `security-sentinel` (the plan carries a threat model), `invariant-auditor`, `netdust-wp:ntdst-drift-reviewer`. 2026-11-05. `composer lint` NOT RUN (phpcs is not installed on this machine).

Verdict: no Blocking. R1-R7 met. All twelve invariant checks pass as written; `bin/guard.sh` and `bin/zero-readers.sh` green; no bypass, no second home. Both load-order questions (braced namespace, unconditional helper) settled in core's favour, including by a simulation with Stride's unmodified plugin file.

Findings: 0 Blocking · 8 Should fix · 14 Note

## Should fix (one fix pass)

1. `tests/Unit/Cron/CronRegistryTest.php:16` — a fixed `define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content')` moves two existing Logger tests into a shared directory that `LoggerSurfaceTest` wipes; two suites in parallel flake each other. Fix: a unique path (pid + uniqid). [code review]
2. `services/CronRegistry.php:265-269`, `README.md` (~:69) — a call to `ntdst_cron_register()` before `ntdst/services_registered` autowires a second registry; the binding then replaces it; the first instance's jobs run but never reach the panel and `pause()` does nothing. README says "nothing is bound". RULED by Stefan 2026-11-05: bind at file scope (the two `ntdst_set()` calls at load, container is required first), so the helper works from core load onward. Spec R4 amended. [code review; drift reviewer: the contract names two windows]
3. `CronRegistry.php:191-193` — the success-path summary is stored with no redaction. [security-sentinel, Medium]
4. `CronRegistry.php:226-231` — failure redaction: overlapping secrets leave a fragment (L1); an anonymous exception class puts the absolute path and a NUL byte into the summary (L2); paths outside `ABSPATH`/`WP_CONTENT_DIR` (Bedrock `vendor/`) survive while the README claims they are removed (L3). Fix with items 3: one `redact()` for both paths, secrets sorted longest-first, strip `[ABSPATH, WP_CONTENT_DIR, dirname(WP_CONTENT_DIR, 2)]` and cap applied to the composed `strtok($e::class, "\0") . ': ' . message`. A test per edge, RED then GREEN. [security-sentinel, Low]
5. `services/Logger.php:252-266`, written from `CronRegistry.php:198-202` — the unredacted message goes to `WP_CONTENT_DIR/logs/`, in the webroot on Bedrock, safe on Nginx only if the server config denies `/app/logs/` (the logger's own docblock says so). OPEN for Stefan: verify the fleet Nginx template; the README gets a one-line precondition. [security-sentinel, Medium; pre-existing Logger behaviour]
6. `ARCHITECTURE-INVARIANTS.md` INV-5 status — `api/Data.php:2097 $revisionKeys` is counted ("22 on the 5.6.0 tree") but never named (the `$corsOrigins` kind: the input to `wp_post_revision_meta_keys`). [invariant-auditor, drift reviewer]
7. `ARCHITECTURE-INVARIANTS.md:710` — INV-12 status omits two ludoluykx hits (`themes/ntdstheme/services/infrastructure/DatabaseMaintenanceService.php:84`, `services/artist/ExhibitionService.php:526`) and Stride's own plugin copy (`plugins/ntdst-cron/src/CronRegistry.php:167`, until plan 2); the baseline `MaintenanceService` is gitignored on Bedrock roots, so the command lists it only with `--no-ignore-files`. [invariant-auditor, drift reviewer]
8. `README.md:812-814` — the 5.0.0 Scheduler migration rows still teach a bare `wp_schedule_event`, INV-12's bypass since 5.7.0. [invariant-auditor]

## Note

1. INV-12's grep has no comment filter, unlike INV-11 (`bin/guard.sh:289-290`, `ARCHITECTURE-INVARIANTS.md:709`); a docblock naming the function would fail the gate. [invariant-auditor, drift reviewer, code review]
2. INV-12's status says the drift reviewer reports known hits as known; the agent only parses the mechanical-check span. [invariant-auditor]
3. Stale FR-7 rationale: `bin/guard.sh:187-192`, `tests/Unit/PackageBootIntegrityTest.php:440-444`. [invariant-auditor]
4. Stale names: `ntdst-core.php:13` header ("services/ → Built-in services (Logger)"), test `CoreTrimClusterCFeatureTest.php:116` `testTheServicesDirectoryHoldsOnlyTheLogger` (asserts three files). [invariant-auditor, drift reviewer]
5. `CronRegistry::isPaused()` (`:113`) and `RunLog::MAX_RUNS` (`:15`) have no reader outside their file; candidate to make private in plan 2. [drift reviewer]
6. Dutch log strings at `CronRegistry.php:54,172,198` where core's other literals are English; a refused registration raises no `_doing_it_wrong`. [drift reviewer]
7. `RunLog.php:19` — the advisory lock name is server-global; MySQL < 5.7.5 holds one lock per session; a read/write-split `$wpdb` would split lock and write. [code review, security-sentinel]
8. `callback` config must come from code, never storage or a request (`CronRegistry.php:53-67`); a re-registration silently replaces a callback. [security-sentinel]
9. `DB_NAME` / `DB_HOST` are not in `SECRET_CONSTANTS` (`:30-41`); reconnaissance only. [security-sentinel]
10. Test hygiene: `CronCoreFirstLoadTest.php:29-33` registers the stand-in autoloader after core loads; `testTheBindingResolvesOneRegistryFromServicesRegistered`'s `same` assertion tests the stub; a 16-space indent at `CronRegistryTest.php:435`. [code review]
11. `composer lint` not run; `RunLog.php:59,70` will likely trip `WordPress.DB.DirectDatabaseQuery` and carry no inline `phpcs:ignore` (core's convention). [code review, security-sentinel]
12. `composer.json` `extra.branch-alias` still `5.5.x-dev`. [code review]
13. Plan 2 hand-over: the old plugin's two bindings overwrite core's on the same hook (benign, remove them); the panel's escaping is all that stands between a stored summary and an admin's browser; Stride's `AuditService`/`GateReminderService` fallbacks are dead code; baseline's migrated job must register on `ntdst/features_ready` (item 2's ruling makes the early window safe, the rule still stands where no Bootstrap exists). [security-sentinel, code review]
14. Skill gap, not core's: `netdust-wp:ntdst-framework` SKILL.md "Scheduling. Not core" is false from 5.7.0; the drift reviewer brief expects ten checks (there are twelve) and ugrep's `-r` does not descend symlinked roots. [drift reviewer]

## The two questions asked of the whole-branch review

Braced `namespace Ntdst\Cron { }` plus `namespace { ntdst_cron_register }`: not a runtime hazard (nothing autoloads core; Stride's PSR-4 still resolves the panel from the plugin). Unconditional `ntdst_cron_register()`: no redeclare on a transitional site (mu-plugins load first; Stride's copy is `function_exists`-guarded), proven by the fixture test and by a simulation with the unmodified plugin file.

## Declined to judge (the whole-branch reviewer)

Stride-side removal and constraints (plan 2); real-WordPress `wp_schedule_event`/`GET_LOCK` behaviour (Stride's integration suite on 5.7.0); baseline's `MaintenanceService`; the panel's capability and nonce posture; third-party secrets in a thrown message (spec deferral); `services/` versus `support/` (ruled by Stefan); phpcs findings.
