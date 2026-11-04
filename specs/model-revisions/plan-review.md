# Plan review — model-revisions

Reviewed-plan: 2e387dbea10b29633d7bf327e768049764e30c90
Verdict: not ready

The WordPress premises hold. The filter-only keys work through save, has-changed and restore. Every field sits under exactly one prefixed key. `show_ui` is resolved when the post type registers. The 9/11 hook order is sound. What sends it back is how the plan places the code inside core: the new public method breaks D1 and INV-9 and a test that pins the model's methods, and the unit-tier stub premise is false. Both have small fixes.

## Blocking

**B1. The new public `NTDST_Data_Model::registerRevisions()` breaks D1, INV-9 and a pinned surface test.**
- **Where the plan says it:** Global Constraints, "The spec's own" bullet 2 (plan:61): "No new public method on `NTDST_Data_Manager` (field-types D1 …). The model gains one public method, `registerRevisions()` … (INV-9: a public symbol has a reader)". Task 1 Interfaces (plan:100) says the same.
- **The spec says otherwise:** spec Design, "Where": "no new public method on `Data.php` (field-types D1)". Both classes live in `api/Data.php`. D1 itself (`specs/field-types/spec.md:20`) reads: "The ORM — `register()` and the query chain. No other public methods."
- **The source says otherwise:**
  - `tests/Unit/DataSurfaceTest.php:211-236` asserts the model's non-chain, non-CRUD public methods are exactly `['getMetaPrefix','getSchema','registerRestMeta','restFields']`. Adding the method turns that test red, and Task 1's Files does not list it.
  - `ARCHITECTURE-INVARIANTS.md:530-531` lists as an INV-9 bypass smell "a `public` method added beside its only caller in the same file". The only reader would be `NTDST_Data_Manager::register()`, in the same file.
  - INV-1 (`:41-42`) excuses `registerRestMeta()` as "public by necessity". That necessity does not exist here: the manager can already read everything it needs through public calls — `getSchema()` (Data.php:105), `getMetaPrefix()` (:97) and `NTDST_FieldTypes::declaredType()` (FieldTypes.php:151).
- **Smallest change:** make it a `private` method on `NTDST_Data_Manager`, `registerRevisions(string $name, NTDST_Data_Model $model)`, called from `register()`, with the constant on the manager. That adds no public symbol, leaves DataSurfaceTest untouched, and matches the spec's "Inside `NTDST_Data::register()`". Rewrite the Global Constraint and Task 1 Interfaces to match.

**B2. The unit-tier stub premise is false, and the change breaks existing suites the plan does not list.**
- **Where the plan says it:** Task 1 Step 1 (plan:104): "the stubs `tests/Support` already offer for `register_post_type`".
- **The source says otherwise:**
  - `tests/Support/` (BootstrapHarness, RestApiInitHarness, wp-rest-doubles) has no such stub. Each test stubs inline, for example `tests/Unit/DataRegistersRestMetaTest.php:652`, which returns `new stdClass()`.
  - The plan's `get_post_type_object($name)->show_ui` (plan:102) would then hit "undefined function" in every suite that registers a labelled model without stubbing it: DataRegistersRestMetaTest, DataDeclaresWordPressReadsTest, CoreTrimClusterBFeatureTest:622, DataReadsTheVocabularyTest, MetaboxGeneratorSaveTest.
  - In the suites that do stub it, it returns a test-double `WP_Post_Type` with no `show_ui` property (RelationSearchRouteTest:96-104, RelationFieldSearchTest:112-118, CoreShapeCluster3FeatureTest:197). That raises an undefined-property warning, and `phpunit.xml` sets `failOnWarning="true"`.
- **Smallest change:**
  - Read `show_ui` from what `register_post_type()` already returned: `$registered`, a `WP_Post_Type`. `WP_Post_Type::set_props` resolves an unset `show_ui` from `public` (wp-includes/class-wp-post-type.php:576-577), so Review Focus 2 still holds.
  - Write it as `($registered->show_ui ?? false) === true`. The existing `stdClass` stubs then mean "no revisions", and no other suite changes.
  - Fix the Step 1 sentence to say the new test stubs `register_post_type` inline, as DataRegistersRestMetaTest:652 does, returning an object that carries `show_ui`.

## Should fix

**S1. Core's own write path, `NTDST_Data_Model::update()`, gives no field-only revision, and a mixed write's revision holds the old fields.** Needs Stefan's ruling.
- **Why:**
  - `api/Data.php:725-745` calls `wp_update_post()` first. That fires `wp_after_insert_post`, which saves the revision (default-filters.php:445), and only then writes the meta (:734-745).
  - So `update($id, ['field' => x])` alone never calls `wp_update_post` and makes no revision. `updateMeta`/`updateMetaBatch` (:973, :1022) make none either.
  - A title+field `update()` saves a revision with the new title and the old field values. That revision does not match the record, which undercuts R2's "restored with it" and the spec's "Why: … through an agent".
  - wp-admin's classic metabox save is fine: `MetaboxGenerator::save_metabox_data` runs in `save_post` (admin/MetaboxGenerator.php:46, :1988), which comes before `wp_after_insert_post`.
- **Where the plan touches it:** Task 2's seam is `wp_update_post` (plan:85), so the proof avoids the framework path. If the coder drives the field-only row through `ntdst_data()->get($type)->update()`, it cannot go green.
- **Smallest change:** either (a) state the limit in Task 3's CHANGELOG/README ("revisions are taken on WordPress saves; a Data-API meta-only write takes none") and have Task 2 name the writer it uses, or (b) get Stefan's ruling to add the step to the spec: `update()` calls `wp_save_post_revision($id)` after its meta loop.

**S2. Task 2's "six saves → 5 revisions" cannot fail on baseline.**
- **Why:** baseline's `MaintenanceService` sets the global `wp_revisions_to_keep` to 5 (services/MaintenanceService.php:95, :122-123), and it is live in the integration suite (tests/Integration/ChatTextTest.php:293: "maintenance's default limit is live"). The row would pass with core's per-type filter missing.
- **Smallest change:** in that test, set the global filter to another value (for example `add_filter('wp_revisions_to_keep', fn() => 50, 99)`) so the 5 can only come from `wp_{type}_revisions_to_keep`.

**S3. Task 2 cites the wrong template.**
- **Why:** Task 2 Step 1 (plan:111) says to build the model "as `tests/Integration/TextBundleRealTest.php:27-28` does". That test registers **without `label`** (:28). `register()` then registers no post type (Data.php:2226, :2309-2316), so no `show_ui` and no revision hooks. Copied as written, the proof stays red for the wrong reason.
- **Smallest change:** cite `tests/Integration/ChatTextTest.php:199` instead (`label`, `meta_prefix`, `public => true`), or say "with a `label`".

**S4. Task 1 Step 2 has no code, and the restore guard has a trap.**
- **Why:**
  - Task 1 Step 2 is "Implement", with no code for the hooks or the 9/11 guard. The writing-plans format requires real code.
  - The put-back at 11 must `wp_slash()` each value, as `_wp_copy_post_meta` does (revision.php:562), because `add_metadata`/`update_post_meta` unslash. Without it, a field holding JSON or a backslash loses characters on every restore of an old revision. No test row would catch that: Task 2 uses one plain `text` field.
- **Smallest change:** show the guard's code in Step 2, and give one test (unit or Task 2) a field value containing a backslash.

## Note

- **Restore guard soundness.** The action fires once, after `wp_update_post` (revision.php:500, :517), with `($post_id, $revision_id)`. WP's step is at 10 (default-filters.php:809). A snapshot at 9 of the keys the revision lacks (`metadata_exists('post', $revisionId, $key)`), put back at 11, gives exactly R5. The spec's "replaces WordPress's restore step" reaches the same outcome by a different mechanism; the plan's reason for not removing core's global hook holds, because that hook is not per type.
- **A post-5.6 revision can't be told apart from a pre-5.6 one.** A post-5.6 revision taken while a field was *unset* also holds no copy (`wp_save_revisioned_meta_fields` copies only when `metadata_exists` on the post, revision.php:409). Restoring it keeps the later value. That is R5 as approved; worth one CHANGELOG line.
- **WordPress's restore saves a stale revision first.** `wp_update_post` inside restore saves a new revision before the action restores meta. That revision holds the restored title with the pre-restore fields. This is core WP behaviour for all revisioned meta; worth knowing when Task 2 counts revisions after a restore.
- **Duplicate keys on re-registration.** Calling `register()` twice for one name (nothing guards it, Data.php:2319) adds the keys twice. `wp_save_revisioned_meta_fields` then copies two rows into each revision. An `array_unique` in the keys filter costs one call.
- **Raw `$_POST` into autosaves.** The keys filter also feeds `wp_autosave_post_revisioned_meta_fields` (wp-admin/includes/post.php:2043-2077). That function stores raw `$_POST[$meta_key]` into an autosave, and restoring that autosave writes it to the record without the model's sanitizer. The metabox posts under `ntdst_fields[...]`, so this needs a crafted request from someone who already has `edit_post`. It is not a threat-model trigger, but the "no new data flow" line is slightly too strong.
- **WordPress mounts new routes.** Model types with `show_in_rest` now get WordPress's `/revisions` and `/autosaves` routes, behind `edit_post`. Only registered `show_in_rest` fields appear in them (class-wp-rest-revisions-controller.php:71, :715), so INV-1 holds. "No route is added" is true only of core's own routes.
- **Models without a `label`.** These register no post type and stay out of R1. Name that in the README line.
- **Block editor saves twice.** With the block editor, a model save with metaboxes makes two revisions (the REST save, then the meta-box-loader save). The first holds the old fields, so the cap of 5 covers fewer edits than it suggests. Not a plan defect.

## Premises checked

- `supports` default at `api/Data.php:2236` → Data.php:2236 → holds
- `register()` at `:2206`, exclusion list `:2237-2244`, `register_post_type` + WP_Error return `:2263-2273` → Data.php:2206, 2237-2244, 2263-2273 → holds
- `registerRestMeta()` at `:176` → Data.php:176 → holds
- "No new public method" read as Manager-only, model method allowed under INV-9 → spec Design; field-types/spec.md:20; ARCHITECTURE-INVARIANTS.md:530-531; DataSurfaceTest.php:232 → does not hold (B1)
- "stubs `tests/Support` already offer for `register_post_type`" → tests/Support/*; DataRegistersRestMetaTest.php:652 → does not hold (B2)
- `get_post_type_object($name)->show_ui` resolved right after `register_post_type` → class-wp-post-type.php:576-577 → holds on real WP; breaks existing unit suites (B2)
- Every field type stores under exactly one prefixed key (repeater, relation, gallery, image, file) → Data.php:885-887, :1739-1752 (single `update_post_meta` per field); MetaboxGenerator.php:2015; no other meta writer in api/ or admin/ → holds
- `NTDST_FieldTypes::declaredType()` is public and skips `callback` → FieldTypes.php:151; Data.php:148 → holds
- Keys added only through the `wp_post_revision_meta_keys` filter (not registered) drive save, has-changed and restore → revision.php:574-598 (filter applied last), :408, :612, :537; default-filters.php:800, 803, 809 → holds
- `wp_{type}_revisions_to_keep` runs after the global filter and overrides it → revision.php:835-850 → holds
- WP's restore deletes each key, then copies (R5's reason) → revision.php:537-543 → holds
- `wp_restore_post_revision` action passes `($post_id, $revision_id)`, fired once after `wp_update_post`; WP's meta step at priority 10 → revision.php:500, 517; default-filters.php:809 → holds
- A field-only change makes a revision → revision.php:208 + :611-618 on `wp_update_post` → holds; through `NTDST_Data_Model::update()` → Data.php:725-745 → does not hold (S1)
- Baseline can require core at `dev-feature/model-revisions as 5.6.0` → baseline composer.json: vcs repo for ntdst-core, `preferred-install netdust/* source`, installer-path `web/app/mu-plugins/{$name}/`, require `^5.5` → holds
- `TextBundleRealTest.php:27-28` as the model template → TextBundleRealTest.php:28 (no `label`) → does not hold (S3)
- Baseline's global revision limit is 5 → MaintenanceService.php:95, 122-123; ChatTextTest.php:293 → holds, and makes the cap row non-discriminating (S2)
- `ddev composer test:int` / `composer test -- --filter` → baseline composer.json `test:int`; core composer.json `test: phpunit` → holds
- Golden path `content-type-feature` → netdust-wp ntdst-patterns/golden-paths/content-type-feature.md → holds
- Core has no integration tier → `tests/` holds only Unit and Support → holds

Coverage: R1–R5 each have Task 1 tests; Task 2 proves R2, R3 and R5 on real WP (R3's row needs S2). Every spec test line maps to a Test map row; all five Review Focus lines point at rows. No invented task. Threat model omitted, with a reason that holds.
