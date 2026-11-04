# Plan review — model-revisions (round 2)

Reviewed-plan: 96e68edd299090acf2ac62f9e8d733555484be56
Verdict: ready with fixes

All six round-1 findings are fixed in this blob, and the WordPress premises still hold. One premise in the new Task 2 is false in source. I applied Task 2's reorder to a scratch copy of core and ran the unit suite: 10 existing tests error out. Adding a stub fixes them and no assertion has to change, but Task 2 does not list those files, so the plan needs that one edit before Stefan reads it. The other findings are stale cross-references.

## Blocking

**B1. Task 2's reorder breaks 10 existing unit tests in three files the task does not list.**
- **The plan's claim:** Task 2 Files (plan:137-138) lists only `api/Data.php` and the new test. Step 2 (plan:143) says "every existing `Data*` unit test green with no assertion edited (`composer test -- --filter Data`)".
- **What the source does:** the new fields-only branch calls `wp_save_post_revision($id)`. No unit suite stubs that function, and `tests/bootstrap.php` does not define it.
- **What I ran:** a copy of core at `/tmp/claude-1000/-home-ntdst-Sites-ntdst-baseline/15a372a4-dafa-4d37-8bbe-d5c361730f8f/scratchpad/core`, with the vendor directory symlinked to the real one, and the reorder written as Task 2's Interfaces describes it. Result: `Error: Call to undefined function wp_save_post_revision()` (Data.php:745 in the copy) in 10 tests:
  - `tests/Unit/DataModelHooksTest.php`: 1 test (its update() call at :165).
  - `tests/Unit/DataReadsTheVocabularyTest.php`: 7 tests (update() calls at :475, :517, :601, :615, :635, :915, :977).
  - `tests/Unit/CoreTrimClusterBFeatureTest.php`: 2 tests (update() calls at :241, :284).
- **The filter misses one file.** `--filter Data` does not match the CoreTrimClusterB tests. Step 2's filter run would look green while `composer gate` is red.
- **What fixes it:** I added `Functions\when('wp_save_post_revision')->justReturn(0);` next to each file's existing `wp_update_post` stub (DataModelHooksTest:108, CoreTrimClusterBFeatureTest:684, DataReadsTheVocabularyTest:166). The whole suite then went green apart from one test that only fails in the copy: CoreTrimClusterD's sweep looks for sibling sites relative to the repo, and the real repo passes it. So "no assertion edited" holds: nothing pins today's write order or rollback.
- **Smallest change:** add those three test files to Task 2 Files as "Modify: setUp gains a `wp_save_post_revision` stub". In Step 2, change "no assertion edited (`--filter Data`)" to "no assertion edited; `composer gate` exit 0". Do not suggest defining the function in bootstrap: Row 2's new test must record calls to it.

## Should fix

**S1. Three Review Focus lines still point at the old row numbers.** Row 2 was inserted (update order), so the real-WordPress proof is now Row 3.
- Review Focus 3 (plan:72), pre-5.6 restore → "Rows 1 and 2": should be Rows 1 and 3.
- Review Focus 4 (plan:73), per-type hook scope → "Rows 1 and 2": should be Rows 1 and 3.
- Review Focus 5 (plan:74), a field-only change makes a revision → "Row 2": should be Row 3. Row 2 is a Brain Monkey test of call order; it cannot show that `wp_check_revisioned_meta_fields_have_changed` reads our keys. Only Task 3 on real WordPress shows that.
- **Smallest change:** renumber the three lines. Optionally add a sixth Review Focus line for R6 ("a fields-only `update()` makes one revision holding the new values") → Rows 2 and 3.

**S2. "First working version" (plan:9) says "Task 2 shows it on real WordPress".** That is now Task 3. Smallest change: replace "Task 2" with "Task 3".

## Note

- **`restorePostData()` (Data.php:899) loses its only caller.** After the reorder no post column is written before a meta failure, so the call at Data.php:741 goes away. The only other references are the method itself and its definition. It is `protected`, so the zero-readers sweep (`bin/zero-readers.sh`, which checks public symbols) will not flag it. Grepping the sibling repos found no class extending `NTDST_Data_Model`, only test files in vendored ntdst-core copies. Task 2 Step 2 could say "delete `restorePostData()`" rather than leave the coder to decide.
- **"Simplest design" (plan:76-78) only argues R1–R5.** The design does hold for R6. Calling `wp_save_post_revision` after the meta loop without reordering would give a title+field `update()` two revisions: a stale one from `wp_update_post`'s `wp_after_insert_post`, then the fields one. Reordering gives one. One sentence in the paragraph would record that.
- **Row 2 RED.** `testAColumnsUpdateTakesNoExtraRevision` passes on today's code: `wp_save_post_revision` is never called there now. Step 1's "RED (today's order)" is true of the file, not of every test in it. No change needed.
- **wp-admin classic save after R6.** `MetaboxGenerator::save_metabox_data` calls `$model->update($post_id, $values)` (admin/MetaboxGenerator.php:1988) inside `save_post`. A fields-only call there now takes the revision during `save_post`: `doing_action('post_updated')` is false at that point (revision.php:136-138), and the post row already holds the new title. The later `wp_after_insert_post` → `wp_save_post_revision` sees no change and returns. The total is still one revision, now holding both the new title and the new fields.

## Premises checked

**Round-1 findings in the new blob**
- B1, a private manager method with no new public symbol → plan:61, :101 → resolved
- B2, `show_ui` read from `$registered` with `?? false`, and Step 1 stubbing `register_post_type` inline → plan:103, :105; Data.php:2263; class-wp-post-type.php:576-577; DataRegistersRestMetaTest.php:652 (returns `stdClass`); WP_Post_Type doubles carry no `show_ui` (RelationSearchRouteTest:96-104, CoreShapeCluster3FeatureTest:93-101) → resolved, holds
- S1 → spec R6 and plan Task 2 → resolved
- S2, global limit set to 50 so only core's per-type filter can produce 5 → plan:149; revision.php `wp_revisions_to_keep` applies the global filter (:24 of the function), then the per-type one (:44); MaintenanceService.php:123 → resolved, holds
- S3, cite ChatTextTest.php:199 and register "with a `label`" → baseline tests/Integration/ChatTextTest.php:199 → resolved, holds
- S4, the guard's code shown with `wp_slash`, plus a backslash test → plan:105-130 → resolved
- Note, `array_unique` on the keys and `testRegisteringTwiceNamesEachKeyOnce` → plan:101, :105 → resolved
- Notes, label-less models, autosave and the new routes in the CHANGELOG and the threat line → plan:66, :154 → resolved

**Task 1**
- `register()` at :2206, exclusion list :2237-2244, `register_post_type` and its WP_Error return :2263-2273 → Data.php:2206, 2237-2244, 2263-2273 → holds
- `getMetaPrefix()`, `getSchema()` and `declaredType()` are public → Data.php:97, :105; FieldTypes.php:151 → holds
- `DataSurfaceTest.php:211-236` pins the model's surface, and a private manager method leaves it unchanged → holds

**Task 2**
- `update()` at :696-765 → Data.php:696-765 → holds
- `ntdst/model/updating` fires before any write → Data.php:716 → holds after the reorder
- The after-hook's before-state is still correct after the reorder → `$previous_post_data` is captured before any write (:719-722); `$previous_meta` is captured per key before each write (:736-739) → holds
- Rollback promise: a meta failure restores the meta written so far, and a post error restores every written meta → `restoreMetaData` (Data.php:911-922) works without a post write → holds
- No existing unit test pins today's write order or rollback → no test references `meta_update_failed`, `restorePostData` or `restoreMetaData`; the full suite passes after the reorder once the stub is added → holds
- "No assertion edited" with `api/Data.php` the only modified file → 10 errors in three unlisted files → does not hold (B1)
- `wp_save_post_revision` returns early where revisions are off → revision.php `wp_save_post_revision` checks `post_type_supports(…, 'revisions')` and `wp_revisions_enabled` → holds
- A fields-only write then `wp_save_post_revision` makes exactly one revision holding the new fields → the title/content comparison finds no change, then the `wp_save_post_revision_post_has_changed` filter runs `wp_check_revisioned_meta_fields_have_changed` (default-filters.php:800). It compares `get_post_meta` on the post (already rewritten) with the latest revision, sees a difference, and calls `_wp_put_post_revision`. That fires `wp_save_revisioned_meta_fields` (:803), which copies the current, new values. With no earlier revision it saves one unconditionally → holds. An unchanged value makes no revision, as R2 requires.
- A title+field `update()` makes one revision holding both → the meta is written first; `wp_update_post` fires `post_updated`, where `wp_save_post_revision` returns early because the `wp_after_insert_post` hook exists (default-filters.php:445-446); then `wp_after_insert_post` saves one revision holding the new meta → holds

**Task 3**
- `proof_` + 8 hex characters stays within the 20-character post-type limit → holds
- Baseline has `test:int` → baseline composer.json:57 → holds

Coverage: R1–R5 → Task 1 and Row 1; R6 → Task 2 and Row 2, proved on real WordPress in Task 3 / Row 3. Every test line in the spec maps to a row. All five Review Focus lines point at a row, three with the wrong number (S1). No invented task. No contradiction with the spec: the spec's Non-goals already allow R6's change to the write order.

---

