# Plan review — model-revisions (round 3, scoped)

Reviewed-plan: fc7460a244419d8102f267ac3b0d9bcd852f5a1a
Verdict: ready

I checked the scope you set: the round-2 findings, the file:line citations the edits added, and the diff `cfb5c54..79d56be`. The working-tree plan hashes to fc7460a. All round-2 findings are resolved, the edits add no false premise, and nothing else regressed.

## Blocking
None.

## Should fix
None.

## Note
- Round 2's Row 2 RED note still applies and still needs no change. `testAColumnsUpdateTakesNoExtraRevision` passes on today's code, so "RED (today's order)" in Task 2 Step 1 is true of the file, not of every test in it.

## Premises checked

**Round-2 findings**
- **B1, test files: resolved.** Task 2 Files lists three test files, each with its stub line and the exact stub (`Functions\when('wp_save_post_revision')->justReturn(0);`). Each cited line is that file's existing `wp_update_post` stub:
  - `tests/Unit/DataModelHooksTest.php:108` → holds
  - `tests/Unit/DataReadsTheVocabularyTest.php:166` → holds
  - `tests/Unit/CoreTrimClusterBFeatureTest.php:684` → holds
- **B1, Step 2 gate wording: resolved.** Step 2 now requires `composer gate` exit 0. It says why `--filter Data` is not enough: `CoreTrimClusterBFeatureTest` would escape it.
- **S1: resolved.** Review Focus 3 and 4 now point at Rows 1 and 3, and line 5 at Row 3. The new line 6 (R6) points at Rows 2 and 3. Test map Row 2 is `DataModelUpdateOrderTest` (plan:86). Row 3 is the baseline proof (plan:87), and Task 3 Step 1 does drive both R6 cases (fields-only, and title + field). → holds
- **S2: resolved.** "First working version" now names Task 3 as the real-WordPress proof (plan:9).
- **Note, `restorePostData()`: resolved.** Task 2 Files says to delete it at `:899` and that its only caller is at `:741`.
  - `api/Data.php:899` is `protected function restorePostData(...)` → holds
  - `api/Data.php:741` is the only call, inside the meta-failure branch → holds
  - No other reference to it in `api/`, `admin/`, `src/` or `tests/` → holds
- **Note, the simplest-design R6 sentence: resolved.** The added sentence matches round 2's analysis: no reorder plus a `wp_save_post_revision` call gives two revisions, writing the fields first gives one.

**Regression check**
- The diff touches only plan:9, plan:72-75, plan:78, and Task 2's Files and Step 2. Task 2's Interfaces and Step 1, Task 1, Task 3 and the Test map are unchanged from the blob round 2 approved. → no regression

---

