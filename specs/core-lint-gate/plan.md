# core-lint-gate Implementation Plan (plan 2 of 2 of the security-checks spec: ntdst-core)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ntdst-core's `composer gate` runs a security-only phpcs lint, and every one of the 109 findings it reports today is either fixed (a real hole, with a failing test first) or annotated inline with the reason it is a false positive.

**Architecture:** `phpcs.xml` drops PSR12 and keeps `WordPress.Security`, `WordPress.DB` and `PHPCompatibilityWP`. A triage ledger, `specs/core-lint-gate/triage.md`, holds one row per finding (file:line, sniff, verdict, action, reason) so the review can count what was real. The findings are cleared riskiest first, file by file. Lint joins `composer gate` in the last task, when the count is zero, so the gate is never red.

**Tech Stack:** PHP 8.1+, phpcs 3 with WPCS 3 and PHPCompatibilityWP (already dev dependencies), PHPUnit with Brain Monkey (core's harness).

**Spec:** `/home/ntdst/Projects/netdust-plugins/specs/security-checks/spec.md`, requirements R5–R8 (the spec lives in netdust-plugins; plan 1, `exposure-probe`, shipped there as netdust-devops 0.14.0/0.14.1). The backlog this plan clears is the one `docs/gate.md` recorded on 2026-08-20 ("it wants its own pass").

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

- `phpcs.xml` keeps exactly `WordPress.Security`, `WordPress.DB` and `PHPCompatibilityWP` (testVersion `8.1-`), drops PSR12, keeps its file list (`core`, `api`, `admin`, `services`, `support`, `ntdst-core.php`). No whole-sniff, whole-file or whole-directory exclusion is added (R5, R6).
- "Zero" means errors AND warnings: `composer lint` exits 0 (R5).
- A finding that cannot be classified is real. A false positive is annotated inline in core's existing form, `// phpcs:ignore <Full.Sniff.Code> -- <reason>` (`admin/MetaboxGenerator.php:2102`), and the reason for escaping findings cites the `file:line` where the value is escaped or proven safe (R6).
- A real hole is fixed with a failing test first, listed in `CHANGELOG.md`, and named in the hand-over to Stefan; it is not a stop (R6, R8).
- Lint joins `composer gate` as `syntax`, `lint`, `test`, `guard`, `audit:deps`, in the LAST task, when `composer lint` exits 0 (R7). `docs/gate.md` is updated to say so.
- Release: 5.7.1 if any shipped code changed (a fix or an annotation that is a code comment counts as shipped code changing). Merge and tag are Stefan's (R8).

## Golden path

n/a — framework internals; no consumer golden path covers a lint pass. Departures: none.

## Security pillars

No data flow is added. Every existing flow a finding touches gets its four pillars re-verified in that finding's `triage.md` row, `file:line` per pillar: the `MetaboxGenerator` save and render (input, nonce, escape), `Pages` query-var reads, `RelationField`'s search query. Evidence per touched file in its task: `grep -nE "current_user_can|wp_verify_nonce|check_ajax_referer|check_admin_referer|permission_callback" <file>`.

## Threat model

**Assets.** Admin sessions (stored values rendered on edit screens); the database (custom queries); the integrity of a save (nonce).

**Attacks.**
1. A stored field value (written by an editor, an import or the REST API) is echoed unescaped into an admin edit screen → stored XSS against an administrator → the `OutputNotEscaped` findings in `admin/MetaboxGenerator.php` (the only file where stored values are rendered): each is escaped at output or annotated with the `file:line` where the FLAGGED variable itself is escaped or shown literal (most are pre-escaped locals such as `$id = esc_attr($field_id)` or config-derived fragments, while the stored value is already escaped inline, e.g. `esc_attr($value ?? '')` at `:976`). The other 23 `OutputNotEscaped` findings are not stored values: 22 are `_doing_it_wrong()` messages built from developer declarations (`core/Bootstrap.php`, `core/Pages.php`, `api/Rest.php:1505-1510`, `api/Response.php:162-165`) and one is a file-download body (`api/Response.php:267`, `echo $content;` after `fileHeaders()`, where escaping would be the bug).
2. A request value (`$_GET`, `$_POST`, `$_SERVER`) is used unsanitized or still slashed → injection or a false comparison → the `ValidatedSanitizedInput` findings (`MetaboxGenerator` ×3, `Pages` ×2) are sanitized and unslashed at the read, or annotated with why the value is compared byte-exactly.
3. A save is accepted without a nonce → CSRF → the save's `wp_verify_nonce` (`MetaboxGenerator.php:1871`, flagged only by `InputNotSanitized`) is already covered by `tests/Unit/MetaboxGeneratorSaveTest.php:952-961` ('a forged nonce', 'no nonce at all'); the one `NonceVerification.Recommended` finding is `MetaboxGenerator.php:2101`, `isset($_GET['post'])` in `render_save_error_notice()`, the display-only sibling of the already-annotated `:2102`.
4. A search term reaches SQL unprepared → SQL injection → `RelationField`'s `PreparedSQL.NotPrepared` and `PreparedSQLPlaceholders.ReplacementsWrongNumber` are proven prepared (docs/gate.md already reads it as a false positive) or fixed.
5. The annotation itself hides a real hole (the lint gate goes green by suppression) → every annotation cites where the safety comes from, the triage ledger lists all of them, and `security-sentinel` reads every one at review.
6. An escaping "fix" double-escapes HTML a builder already escaped → a broken admin screen, the pressure to revert the fix → every escaping change in a render path is covered by a render test that asserts a value with `<`, `&` and `"` appears exactly once escaped.

**Mitigations and where they live.** 1, 6: the render methods of `MetaboxGenerator` and the output lines named in Task 3 and Task 4, with their render tests. 2, 3: the input reads named in Task 2. 4: `admin/RelationField.php`'s query, Task 2. 5: `triage.md` and the review.

**Deferrals.** Performance findings (`SlowDBQuery` ×8) are not security: they are annotated with why the data layer queries meta and taxonomies by design. `ExceptionNotEscaped` (×24) is annotated by PROVENANCE: most are uncaught declaration-time errors whose interpolated values are declared type names or container ids, never request data (`api/FieldTypes.php:97`, `core/Container.php:211`); one whose message can carry request data and reach a page is a real finding.

## Simplest design

The simplest design is to drop PSR12 and exclude the noisy sniffs in `phpcs.xml` so the gate is green tomorrow. That is exactly the "green because the ruleset was widened" anti-goal of the spec, and it would put the 58 unescaped-output findings beyond any future look. So the plan triages each finding, fixes the real ones with tests, and annotates the rest in the source, one line or a method-scoped `phpcs:disable … -- reason` / `phpcs:enable` block with one reason (the form `admin/RelationField.php:511-528` already uses, which the spec leaves to the builder); only the PSR12 drop (style, not security) is a ruleset change.

## First working version

Task 1: `composer lint` on the branch reports the 109 security/DB/compat findings and nothing from PSR12, and `specs/core-lint-gate/triage.md` lists all 109 with a verdict column still open. The coder quotes the summary line and the ledger's row count in the Task 1 report.

## Test map

| Unit | Seam | Tests: tier · file · command | E2E flows |
|---|---|---|---|
| Ruleset + ledger | `composer lint` | none — proven by the lint summary (109 at Task 1, 0 at Task 6) | none |
| Input, nonce, SQL, compat findings (`MetaboxGenerator` input/nonce, `Pages` input, `RelationField` SQL, `LogLevel` compat) | the read or query each finding names, through its public method | unit · `tests/Unit/LintTriage<Area>Test.php` per real fix (none for an annotated false positive) · `composer test -- --filter LintTriage` | none |
| `MetaboxGenerator` output (35 + 2) | the field render methods | unit · `tests/Unit/LintTriageMetaboxRenderTest.php` (render a field with `<b>&"x` and assert one escaped occurrence) · `composer test -- --filter LintTriageMetaboxRender` | none — the shake-out of the edit screen is a consumer's |
| Bootstrap, Pages, Rest, Response output (23) | the method that prints | unit · `tests/Unit/LintTriageOutputTest.php` only for a real fix; expected `none` (the 22 are `_doing_it_wrong()` messages, a Brain Monkey stub in the unit tier, and the one download body must stay unescaped), reported as `none`, never as a pass · `composer test -- --filter LintTriageOutput` | none |
| Exceptions and DB annotations (22 + 16) | n/a — annotations | none — proven by `composer lint` and read by `security-sentinel` | none |
| Gate wiring, docs, release | `composer gate` | none — proven by `composer gate` exit 0 with `lint` listed | none |

## Review Focus

1. An annotation that hides a real hole: every `phpcs:ignore` added cites where the value is escaped, sanitized or prepared, `file:line`; the triage ledger lists every one — Tasks 2–5, read by `security-sentinel`.
2. A double-escaped value on an admin screen after an escaping fix — Task 3 (`LintTriageMetaboxRenderTest`), Task 4 (`LintTriageOutputTest`).
3. A stored value with `<script>` rendered on an edit screen — Task 3 (the hostile-value render test pins that the escaping STAYS: it is green from birth, so its RED is proven by removing one escaping call, then restored).
4. The gate never red on `main`: lint is wired only when `composer lint` exits 0 — Task 6.
5. No ruleset widening, now and later: Task 6 adds INV-13 to `ARCHITECTURE-INVARIANTS.md` (Stefan, 2026-11-06: "add the invariant"), so `invariant-auditor` re-runs the check at every review: `phpcs.xml` carries no `<exclude-pattern>`, no `<exclude name=`, and no `<severity>0</severity>`, and its `<rule ref=` list is exactly `WordPress.Security`, `WordPress.DB`, `PHPCompatibilityWP`.
6. Warnings count: `composer lint` exits 0 with warnings at zero too, not only errors — Task 6.

---

### Task 1: Security-only ruleset and the triage ledger

**Files:**
- Modify: `phpcs.xml` (remove `<rule ref="PSR12"/>`, adjust the description text), `composer.json` (`scripts-descriptions.lint`, line 49: drop "PSR-12"; it still says "NOT yet in gate" until Task 6)
- Create: `specs/core-lint-gate/triage.md`

**Interfaces:**
- Produces: `composer lint` with the security-only ruleset; `triage.md`, a table with columns `#`, `file:line`, `sniff`, `verdict` (`real` / `false positive`), `action` (`fixed` + test name / `annotated`), `reason`; one row per finding of `vendor/bin/phpcs --report=json`.

- [ ] **Step 1: Make sure phpcs runs here:** `composer install` (the dev dependencies include phpcs, WPCS, PHPCompatibilityWP and the installer plugin; `vendor/bin/phpcs` must exist). Quote `vendor/bin/phpcs --version`.
- [ ] **Step 2: Edit `phpcs.xml`** as above.
- [ ] **Step 3: Run** `composer lint`. Expected: `A TOTAL OF 89 ERRORS AND 20 WARNINGS WERE FOUND IN 12 FILES`, no `PSR` source in `vendor/bin/phpcs --report=source`. Quote both.
- [ ] **Step 4: Write `triage.md`** from `vendor/bin/phpcs --report=json` (109 rows, verdict and action open), grouped by the task that will clear them.
- [ ] **Step 5: Run `composer gate`** (lint is not in it yet). Expected: exit 0. Commit: `git add phpcs.xml composer.json specs/core-lint-gate/triage.md && git commit -m "chore(lint): security-only ruleset; the 109 findings in a triage ledger"`.

### Task 2: Input, nonce, SQL and compatibility findings (riskiest first)

**Files:**
- Modify: `admin/MetaboxGenerator.php` (`InputNotSanitized` ×2, `MissingUnslash` ×1, `NonceVerification.Recommended` ×1), `core/Pages.php` (`MissingUnslash` ×1, `InputNotSanitized` ×1), `admin/RelationField.php` (`PreparedSQL.NotPrepared`, `PreparedSQLPlaceholders.ReplacementsWrongNumber`, `DirectDatabaseQuery.DirectQuery`, `.NoCaching`), `core/LogLevel.php` (`PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext`), `specs/core-lint-gate/triage.md`
- Test: `tests/Unit/LintTriageInputTest.php` (only for a finding classified real)

**Interfaces:**
- Consumes: the ledger rows of Task 1.

- [ ] **Step 1: Classify each finding** by reading the code around it and the path the value takes; record the verdict and the pillar evidence (`file:line`) in its ledger row. The nonce finding is `:2101` (`isset($_GET['post'])` in `render_save_error_notice()`, display-only): extend the `:2102` annotation to cover it; the save's nonce at `:1871` is already tested (`MetaboxGeneratorSaveTest.php:952-961`), name that test in the row. The `RelationField` query against `docs/gate.md`'s reading (prepared with `%s`, the sniff cannot follow the variable); the `LogLevel` finding by whether `$this` is reachable outside an object (a static closure, an enum method).
- [ ] **Step 2: For each REAL finding, write the failing test first** through the public method that reads the input or runs the query (for example: a slashed `$_POST` value is stored unslashed; a nonce refusal is already pinned by `MetaboxGeneratorSaveTest.php:952-961` and is named, not rewritten), run `composer test -- --filter LintTriageInput`, quote the RED.
- [ ] **Step 3: Fix the real findings, annotate the false positives** (`// phpcs:ignore <Full.Sniff.Code> -- <reason with file:line>`). Run the filter again (GREEN, quoted) and `composer lint` (the Task 2 rows gone, quoted).
- [ ] **Step 4: Run `composer gate`.** Expected: exit 0. Commit: `git add -A admin core tests specs/core-lint-gate/triage.md && git commit -m "fix(lint): input, nonce, SQL and compatibility findings triaged"`.

### Task 3: `MetaboxGenerator` output (35 `OutputNotEscaped`, 2 `ExceptionNotEscaped`)

**Files:**
- Modify: `admin/MetaboxGenerator.php`, `specs/core-lint-gate/triage.md`
- Test: `tests/Unit/LintTriageMetaboxRenderTest.php`

**Interfaces:**
- Consumes: `render_metabox()` (public, `admin/MetaboxGenerator.php:680`) and the existing harness `tests/Unit/MetaboxGeneratorRenderTest.php`, which already drives it for all 15 controls with real-equivalent `esc_attr`/`esc_html`/`esc_textarea`/`esc_url` stubs (`:150-194`): the hostile-value case is one more stored value and one assertion there, or a copy of its `setUp` into the new file.

- [ ] **Step 1: Write the render test:** for each control, render a field whose stored value is `<b>&"x` and assert the output contains its escaped form exactly once and never `<b>`. It is expected GREEN from birth, because the stored value is already escaped inline (`:976`, `:987`, `:1068`, `:1075`, `:867`, `:1038`): prove it can fail by removing one escaping call (e.g. `esc_attr` at `:976`), quote the RED, restore it.
- [ ] **Step 2: Fix or annotate each of the 37 findings.** What the sniff flags is mostly not the stored value but pre-escaped locals (`$id = esc_attr($field_id)` `:1001-1002`, `:1023-1024`, `:1063-1064`) and config-derived fragments (`$active_class` `:356`, `$display_style` `:375`, `$wrapper_attrs` `:846`, `$label_marker` `:847`, `$required_attrs`/`$extra`/`$class` `:975-978`, `implode(' ', $data_attrs)` `:1138` whose items are escaped at `:1129-1135`): the annotation cites the `file:line` where the FLAGGED variable itself is escaped or shown literal; the render test corroborates, it does not substitute. A method-scoped block with one reason is fine where one method carries several of the same finding (`control_select`). Ledger rows updated. Run the filter (GREEN) and `composer lint` (the Task 3 rows gone). Quote both.
- [ ] **Step 3: Run `composer gate`.** Expected: exit 0. Commit: `git add admin/MetaboxGenerator.php tests/Unit/LintTriageMetaboxRenderTest.php specs/core-lint-gate/triage.md && git commit -m "fix(lint): MetaboxGenerator output triaged, a hostile stored value renders escaped"`.

### Task 4: The other output findings (Bootstrap 11, Pages 6, Rest 4, Response 2)

**Files:**
- Modify: `core/Bootstrap.php`, `core/Pages.php`, `api/Rest.php`, `api/Response.php`, `specs/core-lint-gate/triage.md`
- Test: `tests/Unit/LintTriageOutputTest.php` (only for a finding classified real)

- [ ] **Step 1: Classify each.** 22 are `_doing_it_wrong()` messages built from developer declarations (class names, config keys, route patterns): Bootstrap `:319-332`, `:418-421`, `:468-473`, `:577-595`, `:796-799`; Pages `:304-307`, `:444-447`, `:504-507`, `:763-767`; Rest `:1505-1510`; Response `:162-165`. Their annotation cites the provenance and that WordPress runs `_doing_it_wrong()` messages through `wp_kses()` (verify against the WordPress version core supports and quote where). One is `api/Response.php:267`, `echo $content;`, a file-download body after `fileHeaders()`: escaping it would corrupt the download, so it is annotated as binary. Ledger rows with the reason and `file:line`.
- [ ] **Step 2: For a real one, test first** through the method that prints (output buffer, assert the escaped form), RED quoted; then fix. Annotate the rest.
- [ ] **Step 3: Run** `composer test -- --filter LintTriageOutput` (if any test exists), `composer lint` (Task 4 rows gone), `composer gate` (exit 0); quote all. Commit: `git add -A core api tests specs/core-lint-gate/triage.md && git commit -m "fix(lint): Bootstrap, Pages, Rest and Response output triaged"`.

### Task 5: Exception messages and database annotations

**Files:**
- Modify: `core/Container.php` (3), `core/Theme.php` (1), `api/FieldTypes.php` (12), `api/Data.php` (6 `ExceptionNotEscaped`, 8 `SlowDBQuery`, 2 `DirectQuery`, 2 `NoCaching`), `services/RunLog.php` (2 `DirectQuery`, 2 `NoCaching`), `specs/core-lint-gate/triage.md`

- [ ] **Step 1: Exceptions:** for each of the 22 remaining `ExceptionNotEscaped` (`core/Container.php:120,211,259`, `api/FieldTypes.php:97-288`, `api/Data.php:447,1140,1216,1381`, `core/Theme.php:169`; uncaught declaration-time errors), annotate by provenance: the interpolated value is a declared type name, a container id or a config key, never request data. If one CAN carry request data and reach a page, it is real: write its failing test first in `tests/Unit/LintTriageOutputTest.php` (RED quoted), then escape it. Ledger rows.
- [ ] **Step 2: Database:** annotate `SlowDBQuery` as by-design meta and taxonomy queries of the data layer (`file:line` of the public method), `DirectQuery`/`NoCaching` in `RunLog.php` as the advisory lock (`SELECT GET_LOCK`, no table touched, never cacheable) and in `Data.php` with what the query does. Ledger rows.
- [ ] **Step 3: Run** `composer lint`. Expected: `0` findings — errors and warnings; quote the line. Run `composer gate` (exit 0). Commit: `git add -A core api services specs/core-lint-gate/triage.md && git commit -m "chore(lint): exception and database findings annotated with their reasons"`.

### Task 6: Lint joins the gate; release

**Files:**
- Modify: `composer.json` (`gate`: `@syntax`, `@lint`, `@test`, `@guard`, `@audit:deps`; `scripts-descriptions` for `lint` ("part of gate") and `gate` ("syntax, security lint, unit suite, guards, dependency audit")), `bin/guard.sh` (the stale comment at lines 20-21 that says `composer lint` is `php -l`), `ARCHITECTURE-INVARIANTS.md` (INV-4's recorded nonce lines, refreshed after re-running its two commands verbatim, since Tasks 2-3 move them; and a new INV-13, below), `docs/gate.md` (five steps; the "not yet in gate" section rewritten as history: the backlog cleared, the count real versus annotated from the ledger), `CHANGELOG.md` (`## 5.7.1`: every real fix; "lint is part of composer gate"), `ntdst-core.php` (`Version: 5.7.1`), `README.md` `## Versions` (a one-paragraph `### 5.7.1` if a consumer-visible fix landed)

- [ ] **Step 1: Wire it** and run `composer gate`. Expected: exit 0, the `lint` step listed in the output. Quote it.
- [ ] **Step 2: Prove the gate catches a finding:** add a scratch file under `core/` that carries the ABSPATH guard and one unescaped `echo $_GET['x'];`, run `composer gate`, quote that it stops at `lint` (which now runs before `guard`) with a non-zero exit; remove the file.
- [ ] **Step 2b: INV-13 — the lint is not widened to get green.** Add it to `ARCHITECTURE-INVARIANTS.md` in the file's shape: convergence point `phpcs.xml`; bypass smell an `<exclude-pattern>`, an `<exclude name=`, a `<severity>0</severity>`, a removed `<rule ref=`, or a ruleset passed on the command line; a false positive is annotated in the source with its reason (`// phpcs:ignore <Sniff> -- <reason>` or a method-scoped `phpcs:disable`/`phpcs:enable` with one reason), never excluded in the ruleset. Mechanical check, in the file's `-E` form: `grep -cE '<exclude|<severity>0' phpcs.xml` → `0`, and `grep -oE '<rule ref="[^"]+"' phpcs.xml | sort` → exactly the three refs. Prove it bites: add a scratch `<exclude-pattern>` line, quote the non-zero count, remove it. Status: established by core-lint-gate.
- [ ] **Step 3: Docs and version** as listed; the ledger's final count (real / annotated) quoted in `docs/gate.md` and in the task report.
- [ ] **Step 4: Run `composer gate`** once more after the last edit; quote it. Commit: `git add composer.json bin/guard.sh ARCHITECTURE-INVARIANTS.md docs/gate.md CHANGELOG.md ntdst-core.php README.md specs/core-lint-gate/triage.md && git commit -m "release: 5.7.1 — security lint in composer gate, the backlog triaged"`. Push the branch; do not merge or tag.

## Hand-over

To Stefan: the ledger's count of real findings and annotations, every real fix by name, and the review. Merge and tag are his. Consumers pick 5.7.1 up through `deps:latest` in their own gate; nothing changes for them unless a real fix touched a render they rely on, which the CHANGELOG then names.
