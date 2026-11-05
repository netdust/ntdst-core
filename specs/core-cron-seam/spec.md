# core-cron-seam — ntdst-core 5.7.0: one door for scheduled jobs

**Status:** DRAFT 2026-11-05, awaiting Stefan's approval.
**Target release:** v5.7.0 (minor, additive: `^5.6` consumers upgrade without a code change).
**Studio:** project `security-test-layers`, werkpakket `core-cron-seam`.
**Follows in other repos:** ntdst-baseline's daily vulnerability check (`baseline-vulnerability-check`) and `MaintenanceService` register through this door; Stride drops its copy.

## Why

Scheduled jobs have two homes today. Stride's `ntdst-cron` plugin owns the good one: `CronRegistry::register()` schedules the event, wraps the callback, records every fire in a run log (outcome, duration, redacted summary) and supports pause. Everything else calls `wp_schedule_event()` directly (baseline's `MaintenanceService` does), so those jobs get no run log, no "did it run" answer, no pause.

Baseline is about to gain a daily vulnerability check, which is exactly the job whose silent failure matters. Baseline can depend on core and on nothing else, and the registry lives in a Stride plugin, so it cannot use it. Many sites need scheduled jobs; the seam belongs in the base.

## Requirements

**R1 — Core ships the registry and the run log.** `CronRegistry` and `RunLog` move from `stride/web/app/plugins/ntdst-cron/src/` into ntdst-core, behaviour unchanged: register, schedule-once, wrapped callback, one log entry per fire, pause/resume, run-now, the redacted failure summary and the bounded ring buffer.
*Source: Stefan, 2026-11-05: "we should reuse that"; "core is a good place, many sites need it and baseline can only depend on core, not on extra plugins".*

**R2 — The public surface does not change.** Same namespace (`Ntdst\Cron\`), same helper `ntdst_cron_register( $hook, $config )`, same config keys (`label`, `description`, `recurrence`, `callback`), same two options (`ntdst_cron_runs`, `ntdst_cron_paused`), same non-autoloaded storage. Existing Stride jobs and their run history carry over untouched.
*Source: invented — approved: pending. Basis: the four Stride callers (`ntdst-audit` ×2, `GateReminderService`, `stride-core/Support/cron.php`) and the panel read these names.*

**R3 — The panel stays in the plugin.** `CronPanel`, its assets and templates remain in Stride's `ntdst-cron`, which becomes UI only over core's registry. Core adds no admin screen, no REST route and no capability surface.
*Source: invented — approved: pending. Basis: the panel is 492 lines of Stride-flavoured UI and the only reader of the run log; core stays minimal.*

**R4 — No double definition during the move.** A site running new core with an old `ntdst-cron` plugin, or the reverse, must not fatal. Core's classes load first (mu-plugin), the plugin's copy of `ntdst_cron_register()` is already `function_exists`-guarded, and the plugin's `register`/`RunLog` classes are removed in the Stride change. Core owns the container bindings for `RunLog` and `CronRegistry`, made at file load, so the helper is callable from core load onward (review 2026-11-05: a call before the hook autowired a second registry the panel never saw); the contract is the plugin's no more; the plugin keeps only the `CronPanel` binding and drops its other two in the Stride change. The contract is the plugin's: `ntdst_cron_register()` is callable from `ntdst/features_ready` onward. A test, with a fixture standing in for the old plugin's copy, proves core-first loading leaves it dead rather than fatal.
*Source: invented — approved: pending.*

**R5 — Failure redaction survives the move, with its tests.** A thrown exception is logged in full to the logger, and only a redacted, truncated form (credential constants removed, `ABSPATH`/`WP_CONTENT_DIR` stripped, 500 characters) reaches the stored run log. Stride's unit tests for the registry and run log (`CronRegistryTest`, `RunLogTest`) are ported into core's Brain Monkey suite, not copied: they run on Stride's own stub harness today, so each assertion is re-expressed on core's. They stay green, and the same cases are the proof of "behaviour unchanged". `CronBootstrapGuardTest` tests the plugin's bootstrap and `CronFallbackScheduleTest` tests Stride's own fallbacks, so both stay in Stride, as do the integration tests (`CronSeamMigrationTest`, the panel tests): Stride owns the panel and its callers, and its integration suite is the real-WordPress proof once it runs on core 5.7.0.
*Source: invented — approved: pending. Basis: the run log is persisted to an option and rendered to admins; this is the control that keeps wpdb/PDO/mailer messages from leaking credentials.*

**R6 — A named invariant: one door for recurring jobs.** `ARCHITECTURE-INVARIANTS.md` gains an entry: a recurring job is registered through `CronRegistry`, never by a bare `wp_schedule_event()` in consumer or baseline code. Single-event offloads with per-call arguments (Stride's `stride/mail/admin_notify_async`) are out of scope, as Stride's own migration test already rules, so `CronRegistry` gains no one-off API. Mechanical check: a grep for `wp_schedule_event` outside `CronRegistry` and tests, expectation empty, written in the file's `-E` form. `ntdst-drift-reviewer` picks it up as a Part 1 check. The check is clean on core's own tree at release. Baseline's `MaintenanceService` is a known open hit until `baseline-vulnerability-check` migrates it, and the drift reviewer reports it as such, not as a surprise.
*Source: invented — approved: pending. Basis: the invariants file exists to name "the ONE place each is decided".*

**R7 — Release.** Version 5.7.0, a `CHANGELOG.md` entry, and the `README.md` Versions section naming the Stride-side change (drop the plugin's classes, require core ≥ 5.7).
*Source: invented — approved: pending.*

## Scope of this plan

**One plan, ntdst-core only: R1, R2, R4 (its core half and the fixture test), R5, R6, R7.** The Stride change (drop the plugin's `CronRegistry` and `RunLog`, its two bindings and the PSR-4 entry; require core ≥ 5.7; move the unit tests; adjust `tests/bootstrap.php`) is a second plan in Stride's repo and branch, written after core 5.7.0 is tagged. One plan is one branch is one promotable unit, so the spec is cut in two. The plan review judges the cut.
*Source: invented — approved: pending.*

## Non-goals

- Moving the panel, or any new admin UI.
- New recurrence types, a job queue, retries, or anything for long-running jobs. WP-cron runs inside a visitor request; this seam makes jobs visible, not heavy.
- Migrating baseline's `MaintenanceService` or building the vulnerability check. Both belong to the baseline spec and register through this door once it ships.
- Rewriting run-log storage (one option, ring buffer of 20 per hook, advisory lock).

## Anti-goals

A change that makes a job's failure invisible, or that stores an unredacted exception message, fails even if every test passes.

## Approaches

1. **Move `CronRegistry` and `RunLog` into core; the panel stays in Stride (chosen).** Baseline and every site get the seam; the only cost is a core minor and a Stride cleanup.
2. **Make baseline depend on the `ntdst-cron` plugin.** Quick, but baseline then fails on sites without a Stride-origin plugin. Rejected: baseline can only depend on core.
3. **Leave it in Stride; give baseline a plain `wp_schedule_event`.** Cheapest, but it creates a second scheduling pattern and the daily check gets no run log. Rejected.

Stefan chose approach 1 on 2026-11-05.

## Named consumers

Core's rule is that a feature enters only with a named consumer. Four Stride callers exist today (above); baseline's `baseline-vulnerability-check` and `MaintenanceService` follow.

## Left to the builder

- Where the files sit in core (`support/`, `services/`) and how they are required and booted, following how `Logger` and `RateLimiter` are loaded; core currently uses `require_once` from `ntdst-core.php`, not a Composer autoload map, so check that Stride's `Ntdst\Cron\` PSR-4 entry cannot shadow core's copy.
- Whether the helper function lives beside the registry or in `ntdst-core.php`.
- The exact wording of the invariant check.

## Risks

- The Stride change and the core release must land in order: core first, then Stride. A Stride site pinned below 5.7 keeps its plugin's copy and works as today.
- `GateReminderService`, `ntdst-audit` and `stride-core/Support/cron.php` keep their `function_exists( 'ntdst_cron_register' )` guards and WordPress-idiom fallbacks; with core loaded the guard is always true, and the fallbacks become dead code to remove in a later Stride pass, not here.
