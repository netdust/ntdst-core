# Plan review — core-lint-gate
Reviewed-plan: 449c173774a6b57813594f4bd935646ba4ca8b28 /home/ntdst/Sites/ntdst-core/specs/core-lint-gate/plan.md
Verdict: ready with fixes

The count premise is real: a scratch copy of 3b1c80f (v5.7.0) with `<rule ref="PSR12"/>` removed reports `A TOTAL OF 89 ERRORS AND 20 WARNINGS WERE FOUND IN 12 FILES` (109 violations, 13 sources), and the per-file, per-sniff breakdown matches the plan to the line. R5–R8 each have a task, every threat-model mitigation has a home, the cut against plan 1 is clean. The fixes below are about four steps whose description of the findings does not match what is on those lines, and one step that cannot run in the order written.

## Blocking

None.

## Should fix

1. **Task 6 Step 1 cannot produce "stops at `lint`".** At Step 1 `composer.json:27-32` still has `gate: [@syntax, @test, @guard, @audit:deps]` — lint is wired in Step 2. A scratch file holding only `echo $_GET['x'];` fails at `guard` (`bin/guard.sh:12`: every shipped file needs `defined('ABSPATH')`), not at lint. Smallest change: swap Steps 1 and 2 (wire, then add the scratch file and quote the gate stopping at `lint`, which now runs before `guard`), or make Step 1 prove it with `composer lint` alone.

2. **Task 3 Step 1 — the hostile-value test is not proof for what the sniff flagged.** Every one of the 35 `OutputNotEscaped` lines already escapes the stored value inline: `esc_attr($value ?? '')` at `admin/MetaboxGenerator.php:976`, `esc_textarea(...)` at `:987`, `:1068`, `:1075`, `esc_attr($safe_value)` at `:867`, `:1038`. What the sniff flags are the pre-escaped locals (`$id = esc_attr($field_id)` `:1001-1002`, `:1023-1024`, `:1063-1064`) and config-derived fragments (`$active_class` `:356`, `$display_style` `:375`, `$wrapper_attrs` `:846`, `$label_marker` `:847`, `$required_attrs`/`$extra`/`$class` in `control_input` `:975-978`, `implode(' ', $data_attrs)` `:1138` whose elements are each `esc_attr`'d at `:1129-1135`). So a field rendered with `<b>&"x` is GREEN on every control from birth, and "GREEN … makes that finding a false positive with this test as its proof" (Step 1) proves `$value`, not `$required_attrs`. Change: (a) the annotation reason cites the `file:line` where the flagged variable itself is escaped or shown literal — the test corroborates, it does not substitute; (b) wp-testing rule 5 ("it can fail"): before trusting the green, remove one escaping call (e.g. `esc_attr` at `:976`), quote the RED, restore. Review Focus 3 then reads honestly: the test pins that escaping stays, not that it was missing.

3. **Task 4 Step 1 describes findings that are not on those lines.** "A REST body WordPress encodes as JSON" and "a template the response layer resolves" do not exist in the list: `api/Rest.php:1505-1510` is one `_doing_it_wrong()` call (4 findings on its message argument), `api/Response.php:162-165` is a `_doing_it_wrong()`, and `api/Response.php:267` is `echo $content;` — a file-download body after `fileHeaders()`, where escaping would be the bug. All 11 Bootstrap lines (`:319-332`, `:418-421`, `:468-473`, `:577-595`, `:796-799`) and Pages `:304-307`, `:444-447`, `:504-507`, `:763-767` are `_doing_it_wrong()` messages built from developer declarations (class names, config keys, route patterns). Change: say so — 22 messages through `_doing_it_wrong()` (the annotation cites that WordPress's `wp_trigger_error()` runs the message through `wp_kses()` since 6.4, for the coder to verify against the WP version core supports) and one binary body. Threat-model attack 1 also lumps these four files under "stored field value on an admin edit screen"; none of the 23 is a stored value.

4. **Task 5 Step 1 contradicts the plan's own constraint.** "If it can [reach a page], escape it at the throw and record why" is a fix with no failing test; the plan's constraint (line 61) and R6 say a real finding gets RED first, and Test map row 5 owes `none`. Change: an exception classified real goes to Task 4's `LintTriageOutputTest` (or `FieldTypesTest`) RED first, else the row stays `none`. Also the reason template "message is logged, never rendered, with where it is caught" does not fit most of the 22: `core/Container.php:120,211,259`, `api/FieldTypes.php:97-288`, `api/Data.php:447,1140,1216,1381`, `core/Theme.php:169` are uncaught declaration-time errors; the honest reason is provenance (`$name` at `FieldTypes.php:97` is a declared type name, `$class` at `Container.php:211` a container id — never request data).

5. **Threat-model attack 3 misattributes the nonce finding.** The one `NonceVerification.Recommended` is at `admin/MetaboxGenerator.php:2101` — `if (isset($_GET['post']))` in `render_save_error_notice()`, the sibling of the already-annotated `:2102`, a display-only GET read. The save path's `wp_verify_nonce` at `:1871` is flagged by `InputNotSanitized` only. Change: attack 3 and Task 2 Step 1 point at `:2101` (extend the `:2102` annotation to cover the `isset`); Task 2 Step 2's example "a request without the nonce is refused" already exists as `tests/Unit/MetaboxGeneratorSaveTest.php:952-961` ('a forged nonce', 'no nonce at all') — name it rather than write it again.

6. **`composer.json` `scripts-descriptions` are left lying.** `composer.json:49` `lint`: "PSR-12 + … NOT yet in `gate` — see docs/gate.md"; `:51` `gate`: "syntax, unit suite, guards, dependency audit". Task 1 drops PSR12 and Task 6 wires lint; neither touches these. Change: Task 1 edits the `lint` line, Task 6 edits both.

## Note

- **Existing harness.** `tests/Unit/MetaboxGeneratorRenderTest.php` already drives `render_metabox()` (public, `admin/MetaboxGenerator.php:680`) for all 15 controls with real-equivalent `esc_attr`/`esc_html`/`esc_textarea`/`esc_url` stubs (`:150-194`); `tests/bootstrap.php` defines only `add_filter`, `ntdst_log`, `sanitize_key`, `wp_unslash`. Task 3's "read the file for their names" should name this file: the hostile-value case is one more `$this->meta` value and one assertion there, or a copy of its 45-line `setUp` into the new file.
- **Test map row 5 arithmetic.** "22 + 18" counts `api/Data.php`'s 6 exceptions twice; Task 5's DB findings are 16 (Data 12, RunLog 4). With 16 the rows sum to 109.
- **Per-line versus block.** The spec leaves "one annotation per line or a short block" to the builder; the plan's Simplest design commits to "one line at a time". Core already carries the block form at `admin/RelationField.php:511-528` (`phpcs:disable … -- reason` / `phpcs:enable`). For `control_select` (`:1026-1038`, 7 findings on 4 lines, all `$id`/`$nm`/`$required_attrs`) a method-scoped block with one reason is the spec's own option and is not ruleset widening. A ruling at execution time.
- **INV-4's recorded line is stale.** `ARCHITECTURE-INVARIANTS.md:155,159,167` say `wp_verify_nonce` is at `:1864`; the source has it at `:1871` (stale since c14d6ac). The plan quotes `:1871` as "INV-4's" — the source's number, not the invariant's. Tasks 2–3 add annotation lines in MetaboxGenerator and shift it again; Task 6 should re-run INV-4's two commands verbatim and refresh the recorded lines, as the invariant's own convention does at each merge commit.
- **Review Focus 5 is a one-off.** `grep -c '<exclude' phpcs.xml` (0 at origin/main) runs once at this review. If the no-widening rule should outlive it, it lands as an `ARCHITECTURE-INVARIANTS.md` entry so `invariant-auditor` re-runs it — CLAUDE.md §8's growth rule. The plan does not touch the invariants file; Stefan's call.
- **Row 4 will stay empty.** `_doing_it_wrong()` is a Brain Monkey stub in the unit tier, so "test through the method that prints" for the 22 messages would assert the stub; the row's "only for a real fix" will be `none` — report it as `none`, never as a pass.
- `bin/guard.sh:20-21` still says "`composer lint` is php -l" — stale since 2026-08-20, beside the docs Task 6 rewrites; one line.

## Premises checked

- `phpcs.xml` = PSR12 + WordPress.Security + WordPress.DB + PHPCompatibilityWP, testVersion `8.1-`, files `core api admin services support ntdst-core.php` → `phpcs.xml:12-27` → holds
- phpcs `^3.9`, wpcs `^3.1`, phpcompatibility-wp `^2.1`, installer `^1.0` in require-dev; `composer.json` needs no dependency change → `composer.json:14-21` → holds
- `gate` = `syntax, test, guard, audit:deps`; `lint` = `phpcs` → `composer.json:23-33` → holds
- PSR12 removed → 109 findings, 89 E / 20 W, 12 files → scratch run of 3b1c80f (phpcs 3.13.6, WPCS 3, PHPCompatibility 9.3.5) → holds, breakdown identical to the plan's per file and per sniff
- phpcs exits non-zero on warnings alone → scratch `phpcs services/RunLog.php` (0 E, 4 W) exit 1 → holds
- `docs/gate.md` records the backlog, "it wants its own pass", RelationField read as prepared → `docs/gate.md:37-38, 46-49` → holds
- inline convention `// phpcs:ignore <Sniff> -- <reason>` at `admin/MetaboxGenerator.php:2102` → holds (block form also at `admin/RelationField.php:511,528`)
- nonce hits `:342, :712, :1871` → source `:342, :712, :1871` → holds in source; `ARCHITECTURE-INVARIANTS.md:155` says `:1864` → the invariant is stale
- `NonceVerification.Recommended` ×1 is the save's nonce → it is `:2101` `isset($_GET['post'])`, display-only → does not hold
- render methods drivable from a unit test → `render_metabox()` public `:680`; `MetaboxGeneratorRenderTest.php:150-194` → holds
- `LogLevel` compat finding is an enum method → `core/LogLevel.php:17` `match ($this)` in `enum LogLevel`, PHPCompatibility 9.3.5 → holds
- RelationField ×4 (`:512` ReplacementsWrongNumber, `:529` DirectQuery, NoCaching, NotPrepared) → holds
- RunLog ×4 are `GET_LOCK`/`RELEASE_LOCK` → `services/RunLog.php:59,70` → holds
- Data: SlowDBQuery ×8 (6 meta_query, 1 meta_key, 1 tax_query), DirectQuery/NoCaching at `:1856`, `:1925` → holds
- "58 unescaped-output findings", "ExceptionNotEscaped ×24", 22 outside MetaboxGenerator → source summary → holds
- Rest/Response findings are "a REST body" / "a template" → `api/Rest.php:1505-1510`, `api/Response.php:162-165` are `_doing_it_wrong()`, `:267` is `echo $content` → does not hold
- `ARCHITECTURE-INVARIANTS.md` lists what the gate runs → it does not (no `composer gate`/`lint` mention) → plan's omission matches R7's "if it does"
- `ntdst-core.php:5` `Version: 5.7.0`, `CHANGELOG.md` top `## 5.7.0`, `README.md:46` `## Versions` → holds
- plan 1 shipped as netdust-devops 0.14.0/0.14.1 → git log `41e0b68`, `f46e3c9` → holds

## The cut

- `exposure-probe` (`/home/ntdst/Projects/netdust-plugins/specs/exposure-probe/plan.md`) → one feature: R1–R4, `netdust-devops` Makefile and a vendored script, shipped on its own.
- `core-lint-gate` (`/home/ntdst/Sites/ntdst-core/specs/core-lint-gate/plan.md`) → one feature: R5–R8, ntdst-core's ruleset, source annotations and gate; shares no file with plan 1, builds and tests without it. No requirement unowned or owned twice.

## Applied after the review (by the planning session, not the reviewer)

No Blocking finding, so no re-review was run; the plan's blob moved from `449c173774a6b57813594f4bd935646ba4ca8b28` to `221064230a6e2c7813bc226c9bc5bc792e8efc0c`.

- Should fix 1: Task 6 wires lint first, then plants a guarded scratch file and shows the gate stopping at `lint`.
- Should fix 2: Task 3 names the flagged variables and requires the annotation to cite where each is escaped; the hostile-value test is expected green from birth and its RED is proven by removing one escaping call; Review Focus 3 rewritten.
- Should fix 3: Task 4 and threat-model attack 1 name the 22 `_doing_it_wrong()` messages by line and the one download body; Test map row 4 expects `none`.
- Should fix 4: Task 5 annotates exceptions by provenance and sends a real one to a failing test first.
- Should fix 5: threat-model attack 3 and Task 2 point at `:2101` and name `MetaboxGeneratorSaveTest.php:952-961`.
- Should fix 6: Task 1 edits the `lint` description, Task 6 both.
- Notes taken: the existing render harness is named in Task 3; Test map row 5 reads 22 + 16; the method-scoped block form is allowed (Simplest design); Task 6 refreshes INV-4's recorded lines and the stale `bin/guard.sh` comment.
- Left for Stefan: whether the no-widening rule (Review Focus 5) becomes an `ARCHITECTURE-INVARIANTS.md` entry so it outlives this review.
