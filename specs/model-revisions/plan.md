# Model revisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every admin-editable ntdst model keeps WordPress revisions, its declared fields included, at most 5 per record — ntdst-core 5.6.0.

**Architecture:** `NTDST_Data_Manager::register()` adds `revisions` support to a model's post type when the registered post type has `show_ui` and the model's config does not say `'revisions' => false`. A private manager method then hooks three WordPress filters/actions for its own post type only: `wp_post_revision_meta_keys` (its field keys, so WordPress copies them into revisions, notices a field-only change and restores them), `wp_{type}_revisions_to_keep` (5), and a guard around `wp_restore_post_revision` that keeps a field's current value when the revision holds no copy of it. Nothing is registered with `register_post_meta()`, no sanitizer attaches, no stored value changes.

**First working version:** Task 1 — on the unit tier, a `show_ui` model registers with `revisions` support and its prefixed field keys in `wp_post_revision_meta_keys`; Task 2 shows it on real WordPress.

**Tech Stack:** PHP 8.1+, WordPress ≥ 6.4 (revisioned meta; the suites run 7.1.4), PHPUnit 9 + Brain Monkey (core's only tier), ntdst-baseline's wp-phpunit suite on DDEV for the real-WordPress proof.

**Spec:** `specs/model-revisions/spec.md` (R1–R5, approved by Stefan 2026-11-04).

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

The spec's own:

- No `register_post_meta()` for revisions; no new sanitizer on any key; no migration of stored values (spec Approaches, Non-goals).
- No new public method in `api/Data.php` (field-types D1: Data is the ORM; INV-9; `tests/Unit/DataSurfaceTest.php:211-236` pins the model's surface). The work is one `private` method on `NTDST_Data_Manager`, reading the model through its existing public `getSchema()` and `getMetaPrefix()`.
- The cap is exactly 5; the opt-out is exactly `'revisions' => false` in the model's config.
- `callback` fields are not revisioned (their storage is the consumer's).
- Base: `origin/main` `6a3b9df` (v5.5.0). Branch `feature/model-revisions`. Golden path: `content-type-feature` — the change is inside the model registration that slice uses; departure: none.

No threat model: no security-boundary file is touched (no capability check, input parse, nonce or credential); restoring a revision stays behind WordPress's own `edit_post`. Four pillars: no new data flow of core's own — `n/a — the change hooks WordPress's revision lifecycle; core adds no route, form or query`. WordPress itself mounts `/revisions` and `/autosaves` for `show_in_rest` models (behind `edit_post`; only registered fields appear, INV-1 holds), and its autosave stores raw `$_POST[<key>]` for named keys (wp-admin/includes/post.php:2043-2077) — reachable only by a crafted request from a user who already has `edit_post` (plan-review Note).

## Review Focus

1. A model that passes its own `supports` list (e.g. `['title']`) still gains `revisions` when `show_ui` — not only the default list. → Row 1.
2. `show_ui` resolved as WordPress resolves it (unset → follows `public`); read from the registered post type object, never from `$config['show_ui']` alone. → Row 1.
3. A pre-5.6 revision (no meta copy) restored → every field keeps its current value; a revision that holds a key restores it. → Rows 1 and 2.
4. Hooks scoped per post type: a page/post restore and a second model's keys are untouched by one model's hooks. → Rows 1 and 2.
5. A field-only change (title and content equal) makes a new revision — WordPress's `wp_check_revisioned_meta_fields_have_changed` reads the keys we name. → Row 2.

## Simplest design

The simplest design that meets R1–R5 is the one planned: add a support flag and three hooks per model, all inside the model registration core already runs. Registering every field with `revisions_enabled` is shorter to write but attaches sanitizers to keys site code writes directly (spec Approaches 2); a separate service class would be a second home for model registration. The restore guard is the one piece WordPress lacks — two hooks around its own restore (priority 9 snapshot of keys the revision lacks, priority 11 put them back), leaving WordPress's `wp_restore_post_revision_meta` in place rather than removing a core hook.

## Test map

| Unit | Seam | Tests: tier · file · command | E2E flows |
|---|---|---|---|
| 1. Model revisions (support, keys, cap, restore guard) | `ntdst_data()->register($name, $config)` and the WordPress hooks it adds | unit · `tests/Unit/DataModelRevisionsTest.php` · `composer test -- --filter DataModelRevisionsTest` | — |
| 2. The same on real WordPress | `ntdst_data()->register()` → `wp_update_post` / `wp_restore_post_revision` | integration · ntdst-baseline proof branch `chore/model-revisions-proof`, `tests/Integration/ModelRevisionsProofTest.php` · `ddev composer test:int -- --filter ModelRevisionsProofTest` | — (wp-admin's revision screen is WordPress's own) |

Core has no integration tier yet (plan 5 adds it); the real-WordPress proof runs in ntdst-baseline's suite with core required at `dev-feature/model-revisions`, on a branch that is pushed as evidence and never merged — the abilities-bridge precedent (Stride `chore/abilities-bridge`).

Mode: **Native** — no security-boundary unit; three tasks.

---

### Task 1: Model revisions in core

**Files:**
- Modify: `api/Data.php` — `NTDST_Data_Manager::register()` (`:2206`; the `array_diff_key` exclusion list `:2237-2244` gains `'revisions'`; after the successful `register_post_type()` `:2263-2273`) and a new private `NTDST_Data_Manager::registerRevisions()`
- Test: `tests/Unit/DataModelRevisionsTest.php`

**Interfaces — produces:**
- `private function registerRevisions(string $name, NTDST_Data_Model $model): void` on `NTDST_Data_Manager` — hooks, for `$name` only: `wp_post_revision_meta_keys` (this model's prefixed keys of every declared field whose `NTDST_FieldTypes::declaredType()` is not `callback`, merged with `array_unique`), `"wp_{$name}_revisions_to_keep"` → `self::REVISIONS_TO_KEEP`, `wp_restore_post_revision` at 9 and 11 (the guard).
- `private const REVISIONS_TO_KEEP = 5;` on `NTDST_Data_Manager`.
- In `register()`, after the WP_Error check: when `($registered->show_ui ?? false) === true` (the `WP_Post_Type` `register_post_type()` returned; WordPress resolves an unset `show_ui` from `public`, `class-wp-post-type.php:576-577`) and `($config['revisions'] ?? true) !== false` → `add_post_type_support($name, 'revisions')` and `$this->registerRevisions($name, $model)`. The existing suites' `register_post_type` stubs return `stdClass` without `show_ui`, which reads as "no revisions" — no other suite changes.

- [ ] **Step 1: Tests** in `DataModelRevisionsTest` (Brain Monkey; `register_post_type` stubbed inline as `tests/Unit/DataRegistersRestMetaTest.php:652` does, returning an object that carries `show_ui`): `testAShowUiModelGetsRevisionSupport`; `testAnOwnSupportsListStillGainsRevisions` (`'supports' => ['title']`); `testRevisionsFalseGivesNone` (no support, no hooks); `testAModelWithoutShowUiGetsNone`; `testTheModelsFieldKeysAreRevisioned` (prefixed keys, a `callback` field absent, another post type's list unchanged); `testFiveRevisionsAreKept` (the per-type filter returns 5); `testRestoringARevisionWithoutAFieldKeepsItsValue` (the kept value holds a backslash, `{"a":"b\\c"}`, and comes back byte-identical) and `testRestoringARevisionWithTheFieldRestoresIt` (drive the 9/11 callbacks with `metadata_exists` stubbed); `testRegisteringTwiceNamesEachKeyOnce`; `testAnotherPostTypesRestoreIsUntouched`. Run; RED (method absent).
- [ ] **Step 2:** Implement. The restore guard is the one part the tests do not determine:

```php
$kept = [];
add_action('wp_restore_post_revision', static function (int $postId, int $revisionId) use ($name, $keys, &$kept): void {
    $kept = [];
    if (get_post_type($postId) !== $name) {
        return;
    }
    foreach ($keys as $key) {
        if (!metadata_exists('post', $revisionId, $key) && metadata_exists('post', $postId, $key)) {
            $kept[$key] = get_post_meta($postId, $key);
        }
    }
}, 9, 2);
// WordPress's own step at 10 deletes every listed key and copies only what the revision holds.
add_action('wp_restore_post_revision', static function (int $postId) use (&$kept): void {
    foreach ($kept as $key => $values) {
        foreach ($values as $value) {
            add_post_meta($postId, $key, wp_slash($value));
        }
    }
    $kept = [];
}, 11, 1);
```

  `composer test -- --filter DataModelRevisionsTest` GREEN; `composer gate` exit 0 (`DataSurfaceTest` unchanged and green); commit `feat(data): an editable model keeps revisions, its fields included`.

### Task 2: The proof on real WordPress

**Files (in `~/Sites/ntdst-baseline`, branch `chore/model-revisions-proof` from `origin/main`):** `composer.json`/`composer.lock` (`netdust/ntdst-core`: `dev-feature/model-revisions as 5.6.0`), Create `tests/Integration/ModelRevisionsProofTest.php`. Push core's `feature/model-revisions` first so Composer can resolve it.

- [ ] **Step 1:** RED on baseline's current core (5.5.0), GREEN after the require: a throwaway model per test registered **with a `label`** (`'proof_' . bin2hex(random_bytes(4))`, `show_ui => true`, a `text` field), as `tests/Integration/ChatTextTest.php:199` does — without `label` no post type is registered (`Data.php:2226`); baseline's global limit set to 50 for the test (`add_filter('wp_revisions_to_keep', fn() => 50, 99)`, removed after) so the cap can only come from core's per-type filter: six saves → 5 revisions; a field-only change → a new revision; restore an older revision → the field's old value is back (writes go through `wp_update_post` + `update_post_meta` in `save_post`, the wp-admin order; see S1 for the Data-API writer); a revision whose meta copy is deleted (simulating pre-5.6) → restore keeps the current field value; a page's restore unaffected. `ddev composer test:int -- --filter ModelRevisionsProofTest` RED then GREEN, quoted.
- [ ] **Step 2:** Commit and push the proof branch (evidence; never merged, never promoted). Quote the commit.

### Task 3: Release notes

**Files:** `ntdst-core.php` (`Version: 5.6.0`), `CHANGELOG.md` (`## 5.6.0`: what changes on update — revisions appear for every `show_ui` model, at most 5, fields included; `'revisions' => false` to opt out; `callback` fields not revisioned; models without a `label` register no post type and get none; a revision with no copy of a field (pre-5.6, or taken while the field was unset) restores without emptying it; DB growth; WordPress's own `/revisions` and `/autosaves` routes now mount for `show_in_rest` models, behind `edit_post`), `README.md` (the model config key `revisions`).

- [ ] **Step 1:** Write them; `composer gate` exit 0; commit `release: 5.6.0 — an editable model keeps revisions`. Push the feature branch. Tag and merge are Stefan's.

## Close

`composer gate` green, push; the feature review (`/feature-review model-revisions`) with `invariant-auditor` (core carries `ARCHITECTURE-INVARIANTS.md`) and `ntdst-drift-reviewer`; one fix pass; `specs/model-revisions/review.md`. No `/shakeout` (no new screen). After the tag: ntdst-baseline's chat drops its model-type refusal (its own plan).
