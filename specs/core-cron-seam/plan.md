# core-cron-seam Implementation Plan (plan 1 of 2: ntdst-core)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ntdst-core 5.7.0 carries the scheduled-job registry and run log that live in Stride's `ntdst-cron` plugin today, with their public surface unchanged, so baseline and every site can register jobs through one door.

**Architecture:** `CronRegistry` and `RunLog` move into `services/` as the same classes in the same `Ntdst\Cron` namespace, loaded by an explicit `require_once` (INV-10). `services/CronRegistry.php` also carries, beneath the class, the binding of both on `ntdst/services_registered` and the global `ntdst_cron_register()` (the shape `services/Logger.php` uses for `ntdst_log()`), so a unit test can reach them; `ntdst-core.php` gains the `require_once` lines only. A new invariant (INV-12) names the one door for recurring jobs, and `bin/guard.sh` runs its check. The panel and every Stride change are plan 2.

**Tech Stack:** PHP 8.1+, PHPUnit with Brain Monkey (core's harness), `composer gate`.

**Spec:** `specs/core-cron-seam/spec.md`. Plan 2 (Stride: drop the plugin's copy, bindings and PSR-4 entry, require core ≥ 5.7, adjust its bootstrap) is written after 5.7.0 is tagged.

## Global Constraints

- Evidence: no task claims a line below without its evidence in the task report — the
  command as run with its output quoted, or the review that owns the line. No quote, not met.
- Base: ntdst-core is the framework base and its layering applies to every new class —
  `netdust-wp:ntdst-framework`; where files live — `netdust-wp:ntdst-patterns`. Owned by the
  whole-branch review, joined on WordPress by `netdust-wp:ntdst-drift-reviewer` on the changed
  paths (Part 1 runs core's invariants live); its `## Findings:` header is quoted.
- Golden path: the plan names the slice this feature is built to (`netdust-wp:ntdst-patterns`
  → `golden-paths/`); every departure from it is named and justified, or `none`. Judged, not
  grepped: `/plan-review` checks the slice is named, the whole-branch review that code keeps it.
- Security: every data flow (AJAX action, REST route, form post, shortcode attribute,
  settings save, custom query) states its four pillars — validate, sanitize, escape,
  authorize — as `netdust-wp:wp-security` defines them; a pillar that does not apply is
  written `n/a — <why>`, never omitted. Evidence per flow: each pillar cited `file:line`; the
  output of `grep -nE "current_user_can|wp_verify_nonce|check_ajax_referer|check_admin_referer|permission_callback" <changed files>`;
  the denial test's name, RED then GREEN. `security-sentinel` owns the review when the plan
  carries a threat model.
- Drift: nothing `netdust-wp:ntdst-drift-reviewer` reports — a Critical or Drift finding is
  fixed or is a deviation the plan names; the review quotes the agent's findings, never "looks clean".
- Tests: the close is `make gate` (a package: `composer gate`). Unit = Brain Monkey, integration = wp-phpunit through
  DDEV, e2e = Playwright; commands and setup are `netdust-wp:wp-testing`'s. Evidence: the exit
  code and the summary lines of a run after the task's last edit. A tier that did not run is
  reported as not run, never as a pass. A site's tests cover its code and its configuration of
  ntdst-core and ntdst-baseline; the packages' own behaviour is tested in their repos.
- Local and ship: DDEV always; the Makefile is the only route to a server
  (`netdust-devops:devops`); anything that must ship names its `deploy.payload` path, and
  `make deploy-test env=staging` output listing it is quoted. An rsync, scp or SFTP to a server
  outside the Makefile is a defect the review reports. A package ships by tag: no payload, no deploy line.
- Abilities: a route an agent will operate declares `ability` (`netdust-wp:ntdst-framework`, core 5.5.0); the task that touches such a route runs the upstream `wp-abilities-verify` skill on its own DDEV, in runtime mode as `ntdst-framework` reads it, and quotes its report in the task report — its reviewer reads the quote there, the way it reads every line's evidence, and never runs it (the headless review reads code, plan and spec; runtime mode executes write abilities); a hand-registered ability is drift check #16.

| Red flag — the excuse | The answer |
|---|---|
| "Admin-only screen, so no nonce" | Capability says who; the nonce says they meant it. Both. |
| "The value comes from the database" | Stored input is input. Escape it on output. |
| "The reviewer will catch it" | The reviewer checks the evidence you quoted. No quote, not met. |
| "`make gate` was green earlier" | Earlier is not after your last edit. Run it, quote it. |
| "The integration tier needs DDEV; skip it" | `ddev start`. A tier not run is not a pass. |
| "Plain WordPress is simpler here" | On ntdst-core that is drift. Use the framework's door or name the deviation. |
| "Too small for the golden path" | Then the departure is `none` or one named line. Silence is the defect. |
| "The denial is obvious, no test needed" | Obvious is untested. Write it RED first. |
| "Just rsync this one file" | A hand-deploy is drift nobody sees. The Makefile, or nothing. |

This plan's own constraints, from the spec:

- The namespace is `Ntdst\Cron`, the helper is `ntdst_cron_register( string $hook, array $config ): void`, the config keys are `label`, `description`, `recurrence`, `callback`, and the options are `ntdst_cron_runs` and `ntdst_cron_paused`, never autoloaded. None of them changes (R2).
- `RunLog` keeps at most 20 runs per hook, newest first; a stored summary is at most 500 characters; a failure summary has the credential constants and `ABSPATH`/`WP_CONTENT_DIR` removed (R5).
- Core loads by an explicit `require_once` after `services/Logger.php` and installs no autoloader (INV-10). Every PHP file carries `defined('ABSPATH') || exit;` (`bin/guard.sh`).
- The release is 5.7.0, additive, `^5.6` consumers upgrade without a code change (R7). Merge and tag are Stefan's.

## Golden path

n/a — a change to the framework's own internals; no consumer golden path covers it. Departures: none.

## Security pillars

No data flow is added: no route, AJAX action, form, shortcode, setting or query that takes input. Validate, sanitize, escape, authorize are each `n/a — core adds no entry point; the pause and run-now routes and their capability checks stay in Stride's panel (plan 2)`. The one database call is `SELECT GET_LOCK(%s, %d)` with a class constant and an integer constant, prepared. Evidence for the review: `grep -nE "register_rest_route|ntdst_rest\(|wp_ajax_|add_menu_page|current_user_can" services/CronRegistry.php services/RunLog.php` is empty.

## Threat model

**Assets.** The site's credentials and keys (`DB_PASSWORD`, `DB_USER`, the eight auth keys and salts) and its absolute paths, which a job's exception message can carry into the run log; the integrity and size of the `ntdst_cron_runs` option; the registry instance every consumer resolves.

**Attacks.**
1. A job throws an exception whose message holds a credential (wpdb, PDO, mailer or HTTP-client errors do) → the run log, stored in an option and rendered to admins, leaks it → `RunLog` stores only `CronRegistry::failureSummary()`: exact-match credential redaction, `ABSPATH` and `WP_CONTENT_DIR` stripped, 500 characters; the logger gets the full message.
2. A job returns, or throws, a huge string on every fire → the option grows without bound and is unserialised on every append → `MAX_SUMMARY` caps a summary where it is composed, `MAX_RUNS` caps the entries per hook, the option is never autoloaded.
3. A hook name chosen by a registering plugin becomes part of an option name → an attacker-influenced option key → hook names are only ever array keys inside two fixed options.
4. Two fires interleave and one run entry is lost, or the lock is never released and every fire waits → the lock is a fixed name with a 5-second timeout, released in `finally`; an unavailable lock still records the entry.
5. Core and an old `ntdst-cron` plugin both define the registry → a redeclare fatal, or a second instance swaps the first after consumers resolved it → core is a mu-plugin and loads first, its classes exist before any autoloader is asked, and the plugin's own helper is `function_exists`-guarded, so the plugin's copy is dead; core's helper is declared unconditionally (`services/Logger.php`'s reasoning: a guard here would let an older copy win silently, a redeclare fatal names the collision). Proven against a fixture standing in for the old plugin.
6. A new HTTP surface slips in with the move → core's one surface stays `ntdst_rest()` (INV-2); the Security pillars grep above is empty.

**Mitigations and where they live.** 1: `CronRegistry::failureSummary()` and `secrets()`. 2: `CronRegistry::MAX_SUMMARY`, `RunLog::MAX_RUNS`, `RunLog::append()`. 3: `CronRegistry::PAUSED_OPTION`, `RunLog::OPTION`. 4: `RunLog::acquireLock()` / `releaseLock()`. 5: `ntdst-core.php` load order and the unconditional helper declaration in `services/CronRegistry.php`. 6: INV-2's check.

**Deferrals.** A third-party secret in a message that is not one of core's constants is stored, truncated: guessing at secret-shaped text is not decidable, and the logger keeps the original for diagnosis. The run log is readable by anyone who can read options; who sees the panel is Stride's capability check. Real-WordPress behaviour (a real `wp_schedule_event`, a real `GET_LOCK`) is proven by Stride's integration suite on core 5.7.0 in plan 2; this plan's tier is Brain Monkey.

## Simplest design

The simplest design is to leave the registry in Stride, and it cannot serve baseline, which may depend on core only. The next simplest is to move the two files with their namespace and surface unchanged, add one function and one binding, and add one grep as the invariant. That is this plan: no registry behaviour changes, no new API, no panel.

One deviation is named: core's `ntdst_set()` calls were one gated call in `core/Bootstrap.php` (INV-10 check 4). Two more, in `services/CronRegistry.php`, bind the registry and run log so the container hands every consumer the same instance. INV-10's check (4) description is updated to name them, and INV-5 and INV-8 each gain one named hit from the moved file (Task 4).

## First working version

Task 2: `ntdst_cron_register( 'x', [ 'callback' => … ] )` schedules the hook once, fires through the wrapper and leaves one entry in `ntdst_cron_runs`. Task 1 (the run log it writes to) comes first because Task 2 needs it.

## Test map

| Unit | Seam | Tests: tier · file · command | E2E flows |
|---|---|---|---|
| RunLog | `RunLog::append()`, `runsFor()` | unit · `tests/Unit/Cron/RunLogTest.php` · `composer test -- --filter RunLogTest` | none — no screen |
| CronRegistry + helper + binding | `ntdst_cron_register()`, `CronRegistry::{register,all,has,run,pause,resume,isPaused}` | unit · `tests/Unit/Cron/CronRegistryTest.php` · `composer test -- --filter CronRegistryTest` | none — no screen |
| Core-first load | loading core's `services/CronRegistry.php`, then an old plugin's registry copy and guarded helper | unit (subprocess) · `tests/Unit/Cron/CronCoreFirstLoadTest.php` · `composer test -- --filter CronCoreFirstLoadTest` | none |
| INV-12 one door | `bin/guard.sh`, `ARCHITECTURE-INVARIANTS.md` | none — proven by the guard run, RED then GREEN (Task 4) | none |
| Release | `CHANGELOG.md`, `README.md`, `ntdst-core.php` header | none — proven by `composer gate` | none |

## Review Focus

1. A thrown credential never reaches the stored run log: DB password, an auth key and `ABSPATH` all removed, 500-character cap on a failure — Task 2 (`testTheDatabasePasswordIsNotStoredInAFailureSummary`, `testAnAbsolutePathIsStrippedFromAFailureSummary`, `testTheUnredactedFailureIsSentToTheLogger`, plus the new `testEveryCredentialConstantIsRedacted`).
2. Core first, old plugin second: no fatal, and the registry that resolves is core's; an unguarded second helper is a loud redeclare — Task 3.
3. Two fires at once, lock unavailable: the entry is still recorded, and a failing write still releases the lock — Task 1 (`testAnEntryIsStillRecordedWhenTheLockIsUnavailable`, `testTheLockIsReleasedWhenTheWriteThrows`).
4. A hook name is never part of an option name — Task 2 (`testAHookNameNeverBecomesPartOfAnOptionName`).
5. A second registration of one hook fires the job once; a paused job is not rescheduled on boot; an unschedulable recurrence is logged, not dropped — Task 2 (the ported cases).
6. The invariant's grep fails when it should, not only passes: a planted bare `wp_schedule_event` turns `bin/guard.sh` red under this machine's ugrep — Task 4.
7. A registry call before `ntdst/services_registered` is a wrong-order case; the contract is "from `ntdst/features_ready`", written in the helper's docblock — Task 2.

---

### Task 1: RunLog

**Files:**
- Create: `services/RunLog.php`
- Modify: `ntdst-core.php` (one `require_once` line, after `services/Logger.php`)
- Test: `tests/Unit/Cron/RunLogTest.php`

**Interfaces:**
- Consumes: Brain Monkey stubs for `get_option` and `update_option` backed by one array, and a `$wpdb` double with `prepare()` and `get_var()` (the lock calls).
- Produces: `Ntdst\Cron\RunLog` with `public const MAX_RUNS = 20`, `append( string $hook, array $entry ): void`, `runsFor( string $hook ): array`. The option is `ntdst_cron_runs`, written with autoload `false`.

- [ ] **Step 1: Port the five cases of Stride's `tests/Unit/Cron/RunLogTest.php`** onto core's harness (Brain Monkey `Functions\when`, an in-test option array): `testTwentyFirstEntryEvictsTheOldestAndKeepsNewestFirst`, `testASecondHooksEntriesAreUntouched`, `testRunsForAnUnknownHookIsEmpty`, `testAppendPersistsTheWholeEntryShape`, `testTheOptionIsWrittenWithAutoloadDisabled`. Add `testAnEntryIsStillRecordedWhenTheLockIsUnavailable`: the `$wpdb` double's `get_var()` returns `0` for `GET_LOCK`, and `append()` still stores the entry and never calls `RELEASE_LOCK`. Add `testTheLockIsReleasedWhenTheWriteThrows`: `update_option` throws, and `RELEASE_LOCK` was still called.
- [ ] **Step 2: Run** `composer test -- --filter RunLogTest`. Expected: FAIL, class `Ntdst\Cron\RunLog` not found.
- [ ] **Step 3: Create `services/RunLog.php`** by copying `~/Sites/stride/web/app/plugins/ntdst-cron/src/RunLog.php` unchanged, adding `defined('ABSPATH') || exit;` after the `namespace` line (core's guard rule), and `require_once NTDST_PATH . '/services/RunLog.php';` in `ntdst-core.php` directly after the Logger require.
- [ ] **Step 4: Run** `composer test -- --filter RunLogTest`. Expected: PASS. Then `composer lint -- services/RunLog.php`; the `$wpdb->get_var()` lock calls may trip `WordPress.DB.DirectDatabaseQuery`; fix or add a one-line `phpcs:ignore` naming why (a fixed advisory lock, no table touched).
- [ ] **Step 5: Run `composer gate`.** Expected: exit 0, including `bin/guard.sh` and `BootstrapLoadsNothingByGuessingTest` (the new file is required after the Logger). Commit: `git add services/RunLog.php ntdst-core.php tests/Unit/Cron/RunLogTest.php && git commit -m "feat(cron): RunLog moves into core"`.

### Task 2: CronRegistry, the helper and the binding

**Files:**
- Create: `services/CronRegistry.php` (the class, then beneath it the binding and the helper)
- Modify: `ntdst-core.php` (one `require_once` line)
- Test: `tests/Unit/Cron/CronRegistryTest.php`

**Interfaces:**
- Consumes: `Ntdst\Cron\RunLog` (Task 1); `ntdst_log( 'cron' )` from the Logger; `ntdst_set()` / `ntdst_get()` from `core/Container.php`; the constants `WP_CONTENT_DIR` and `DB_PASSWORD`, which core's harness does not define: the test file opens with `defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content' )` and `defined( 'DB_PASSWORD' ) || define( 'DB_PASSWORD', 'unit-test-db-password' )` (Stride's idiom; a bootstrap define would collide with the two in-file defines of `WP_CONTENT_DIR` in `LoggerSurfaceTest` and `CoreTrimClusterBFeatureTest`).
- Produces: `Ntdst\Cron\CronRegistry` with `__construct( RunLog $log )`, `register( string $hook, array $config ): void`, `all(): array`, `has( string $hook ): bool`, `run( string $hook, bool $manual ): void`, `isPaused( string $hook ): bool`, `pause( string $hook ): void`, `resume( string $hook ): void`; the global `ntdst_cron_register( string $hook, array $config ): void`; container ids `Ntdst\Cron\RunLog::class` and `Ntdst\Cron\CronRegistry::class`.

- [ ] **Step 1: Port the 23 cases of Stride's `tests/Unit/Cron/CronRegistryTest.php`** onto core's harness, same names, same assertions, with `wp_schedule_event`, `wp_next_scheduled`, `wp_clear_scheduled_hook`, `get_option`, `update_option` and `ntdst_log` stubbed through Brain Monkey (the log recorder in `tests/bootstrap.php` already captures `ntdst_log`). Copy Stride's PSR-12 spacing, not WordPress spacing (`phpcs.xml` is PSR-12). Add:
  - `testEveryCredentialConstantIsRedacted`: the test file defines each of the ten names in `SECRET_CONSTANTS` once, with `defined( … ) || define( …, 'unit-test-<name>' )` (the ten names are spelled in the test, not read by reflection from the private constant); a registered job throws a message containing all ten values and the stored summary holds `[REDACTED]` for each and none of the values.
  - `testAHookNameNeverBecomesPartOfAnOptionName`: registering and pausing a hook named `x'; DROP--` writes only `ntdst_cron_paused` and `ntdst_cron_runs`, with the hook as an array key.
  - `testTheBindingResolvesOneRegistryFromServicesRegistered`: running the callback the file mounts on `ntdst/services_registered` makes `ntdst_get( CronRegistry::class )` return one instance, the same on a second call, built with the bound `RunLog`.
  - `testTheHelperRegistersThroughTheBoundRegistry`: `ntdst_cron_register( 'h', [...] )` after the binding leaves `h` in `ntdst_get( CronRegistry::class )->all()`.
  - Reaching the file-scope `add_action( … )`: Brain Monkey defines `add_action` only inside `Monkey\setUp()` and core's `tests/bootstrap.php` defines `add_filter` only, so the binding test requires the file inside a subprocess (the pattern of Task 3) with `add_action`, `ntdst_set` and `ntdst_get` stubbed to record, and asserts on what it recorded; the builder may instead bind inside a named static method on `CronRegistry` that the test calls directly, and states which in the task report.
- [ ] **Step 2: Run** `composer test -- --filter CronRegistryTest`. Expected: FAIL, class not found.
- [ ] **Step 3: Create `services/CronRegistry.php`** by copying Stride's `src/CronRegistry.php` unchanged, adding the ABSPATH guard. Beneath the class, in the same file: an `add_action( 'ntdst/services_registered', … )` that calls `ntdst_set( RunLog::class )` and `ntdst_set( CronRegistry::class, fn( $c ) => new CronRegistry( $c->get( RunLog::class ) ) )`, and the global `function ntdst_cron_register( string $hook, array $config ): void { ntdst_get( CronRegistry::class )->register( $hook, $config ); }` declared unconditionally, with a docblock saying it is callable from `ntdst/features_ready` onward. In `ntdst-core.php`, after the Task 1 require: `require_once NTDST_PATH . '/services/CronRegistry.php';`.
- [ ] **Step 4: Run** `composer test -- --filter CronRegistryTest`. Expected: PASS, 27 tests. Then `composer lint -- services/CronRegistry.php ntdst-core.php`; fix findings or `phpcs:ignore` with the reason.
- [ ] **Step 5: Run `composer gate`.** Expected: exit 0. Quote the summary line. Commit: `git add services/CronRegistry.php ntdst-core.php tests/Unit/Cron/CronRegistryTest.php && git commit -m "feat(cron): CronRegistry, ntdst_cron_register() and its binding move into core"`.

### Task 3: Core first, old plugin second

**Files:**
- Create: `tests/Unit/Cron/CronCoreFirstLoadTest.php`, `tests/Unit/Cron/fixtures/old-plugin/CronRegistry.php`, `tests/Unit/Cron/fixtures/old-plugin/bootstrap.php`
- Modify: none (this task proves R4; it changes no shipped file unless the test finds a defect)

**Interfaces:**
- Consumes: core's load order from Tasks 1–2.
- Produces: the proof that a stale copy of the old plugin is dead, not fatal.

- [ ] **Step 1: Write the test** `testTheOldPluginsCopyIsDeadWhenCoreLoadsFirst`: a subprocess (the pattern of Stride's `CronBootstrapGuardTest::bootWithoutCore()`) loads core's `services/RunLog.php` and `services/CronRegistry.php` (with `add_action`, `ntdst_set`, `ntdst_get` and `ntdst_log` stubbed), then `fixtures/old-plugin/bootstrap.php` (the plugin's `function_exists`-guarded `ntdst_cron_register`) and registers an autoloader mapping `Ntdst\Cron\` to `fixtures/old-plugin/` where `CronRegistry.php` throws on load. Assert the subprocess exits 0, prints no fatal, `(new ReflectionClass( Ntdst\Cron\CronRegistry::class ))->getFileName()` ends in `services/CronRegistry.php`, and the helper that answers is core's (its `ReflectionFunction` file is core's). Every fixture file carries `defined('ABSPATH') || exit;` before anything else, including the one that throws (`bin/guard.sh` demands the literal in every `.php`).
- [ ] **Step 2: Run** `composer test -- --filter CronCoreFirstLoadTest`. Expected: PASS if Tasks 1–2 hold; if it FAILS, the failure is a real defect in the load order: fix it in `ntdst-core.php` or `services/CronRegistry.php` (not in the test), record it as a `Ruling:`.
- [ ] **Step 3: Prove the test can fail:** make the fixture's helper unguarded, re-run, quote the RED (a "Cannot redeclare ntdst_cron_register()" fatal, which is the collision plan 2 removes), restore the guard, re-run GREEN.
- [ ] **Step 4: Run `composer gate`.** Expected: exit 0. Commit: `git add tests/Unit/Cron && git commit -m "test(cron): core loading first leaves the old plugin's copy dead"`.

### Task 4: INV-12, the one door

**Files:**
- Modify: `ARCHITECTURE-INVARIANTS.md` (new INV-12; INV-10 check (4) and its status line), `bin/guard.sh` (the INV-12 grep), `README.md` (an `#### Extension points` row only if `bin/zero-readers.sh` asks for it)

**Interfaces:**
- Consumes: the registry file path `services/CronRegistry.php`.
- Produces: INV-12 and a guard line that fails on a bare `wp_schedule_event` outside the registry.

- [ ] **Step 1: Write INV-12** in the file's shape (convergence point `services/CronRegistry.php::register()`; bypass smell a bare `wp_schedule_event(` in consumer or baseline code; single-event offloads with per-call arguments are out of scope, naming Stride's `stride/mail/admin_notify_async`; mechanical check `grep -rnE "wp_schedule_event" --include=*.php . | grep -vE "^(\./)?services/CronRegistry\.php|(^|/)vendor/|(^|/)tests/|(^|/)specs/"` → EMPTY, in the `-E` form the file's header demands; status names every known open hit on a Stride root as well as baseline's: `MaintenanceService` (baseline, until `baseline-vulnerability-check` migrates it, plus its vendored copy in Stride's `mu-plugins/ntdst-baseline`), and for plan 2 `stride-core/Support/cron.php:31`, `ntdst-audit/src/cron-fallback.php:34` (a fallback the spec's Risks call dead code) and `ntdst-assistant/ntdst-assistant.php:62` (a bare hourly cleanup)).
- [ ] **Step 2: Prove the check fails first (RED).** Plant `wp_schedule_event( time(), 'daily', 'x' );` in a scratch `services/ScratchPlant.php`, run the INV-12 command and quote the hit; run it with the plant removed and quote the empty output.
- [ ] **Step 3: Add the same line to `bin/guard.sh`**, in the style of the INV-11 line, then run `bash bin/guard.sh` with the plant (exit 1, names the file) and without it (exit 0). Quote both.
- [ ] **Step 4: Update INV-10 check (4)**: its description of the `ntdst_set(` hits names the two new calls in `services/CronRegistry.php` (registry and run log) beside `core/Bootstrap.php:501`, and its status line records the re-run. Run the four INV-10 commands verbatim and quote each answer.
- [ ] **Step 4b: Re-run INV-5's check and INV-8 (A) verbatim** against the moved file. They each gain one hit, and both invariants say any unnamed hit is a bypass: name `CronRegistry::SECRET_CONSTANTS` under INV-5's status (the credential constant names, a list WordPress keeps no table for, the `ClientIp::DEFAULT_TRUSTED_PROXIES` kind) and the `'callback' =>` job-config key under INV-8's "MAP KEYS that are not type names" group (the job's callable, the same shape as the `callback` argument key already named there). Pin the counts the re-run measures, not arithmetic from the old status lines: INV-8 (A) was 56 on the 5.6.0 tree and INV-5 returns 22 where its status says twenty-one. Then run the drift reviewer's Part 1 on the changed paths and quote its `## Findings:` header.
- [ ] **Step 5: Run `bash bin/zero-readers.sh | wc -l`** from beside the consumer roots. Expected: 0. If `ntdst_cron_register` is reported, its readers are Stride's `stride-core` callers and baseline's coming one; add the README `#### Extension points` row and the script's `EXCEPTIONS` line naming them, then re-run.
- [ ] **Step 6: Run `composer gate`.** Expected: exit 0. Commit: `git add ARCHITECTURE-INVARIANTS.md bin/guard.sh README.md bin/zero-readers.sh && git commit -m "docs(invariants): INV-12 — a recurring job registers through CronRegistry"`.

### Task 5: Release 5.7.0

**Files:**
- Modify: `ntdst-core.php` (`Version: 5.7.0`), `CHANGELOG.md`, `README.md` (`## Versions`)

**Interfaces:**
- Produces: the release commit; the tag and the merge are Stefan's.

- [ ] **Step 1: Bump** the header `Version:` to 5.7.0. **CHANGELOG** gets a `## 5.7.0` entry in 5.6.0's shape: `Added` (the registry, the run log, `ntdst_cron_register()`, INV-12), `Notes on update` (additive; a site running the Stride `ntdst-cron` plugin keeps working — core's copy loads first and the plugin's is dead — and plan 2 removes the plugin's copy; baseline's `MaintenanceService` is the known open INV-12 hit). **README `## Versions`** gets a `### 5.7.0 — one door for scheduled jobs` section: the helper's contract, its config keys, the two options, the "from `ntdst/features_ready`" rule, and the Stride-side change to make.
- [ ] **Step 2: Run `composer gate`** one last time on the tip. Expected: exit 0; quote the tier lines.
- [ ] **Step 3: Commit:** `git add ntdst-core.php CHANGELOG.md README.md && git commit -m "release: 5.7.0 — one door for scheduled jobs"`. Push the branch; do not merge or tag.

## Hand-over

Plan 2 (Stride) needs, from this plan: the final names of the two container ids, the helper's unconditional declaration in `services/CronRegistry.php` (so the plugin's classes, its two bindings and its `function_exists`-guarded helper all go), the known open INV-12 hits in Stride, and the CHANGELOG's "notes on update". Studio: the `core-cron-seam` werkpakket moves to `plan` at plan approval and `bouwen` at Task 1; `baseline-vulnerability-check` stays blocked by it.
