# Feature review — model-revisions (ntdst-core 5.6.0)

**Reviewed:** `origin/feature/model-revisions` `f7f77a0` over `6a3b9df` (v5.5.0) — spec `specs/model-revisions/spec.md` (R1–R6), plan `plan.md` (three review rounds), the real-WordPress proof on ntdst-baseline `chore/model-revisions-proof` `7003a52`. Four reviewers on a detached checkout, 2026-11-04: superpowers' code reviewer, `invariant-auditor` (INV-1…11), `netdust-wp:ntdst-drift-reviewer`, and `security-sentinel` (joined once the auditor found an input path the plan's "no threat model" missed). The gate result (`composer gate` exit 0, 1298 tests; proof RED on 5.5.0 → 8/8 GREEN) is the coder's. Verdicts: code "ready with fixes"; invariants "no invariant broken, 2 bypasses, 5 doc-drift"; drift "0 Critical, 1 Drift, 4 Borderline"; security "B1 Should fix, not Blocking".

## Blocking

None.

## Should fix — one fix pass, each RED→GREEN through its seam

1. **Raw values reach model fields through autosaves** (invariant-auditor B1; security-sentinel B1). Naming the keys in `wp_post_revision_meta_keys` (`api/Data.php:2137`) makes WordPress copy raw `$_POST[<key>]` / REST `/autosaves` `meta` into an autosave (`wp-admin/includes/post.php:2043-2073`, `class-wp-rest-autosaves-controller.php:400-406,441-447`); restoring it writes them to the live post with no model sanitizer (only `show_in_rest` fields have one, `api/Data.php:200-217`). New exposure for protected-prefix, non-REST fields (Stride's `VoucherCPT` `_ntdst_` clamp). Needs a logged-in `edit_post` holder; no anonymous or CSRF path. Fix (sentinel): keep model fields out of autosaves — in `registerRevisions()`, `add_post_metadata` / `update_post_metadata` short-circuits returning `false` for this model's keys when the object is an autosave of `$name` (`wp_is_post_autosave()`); the existing 9/11 guard then keeps the current value on restore. Closes the preview leak and the stale-autosave-fields bug too. Not the auditor's "route through `update()`" (fails open: `update()` returns at validation, `:706-709`). RED: a raw REST `/autosaves` meta (and a crafted preview) then a restore → field unchanged.
2. **A record's first edit cannot be undone** (code S1). With no revision yet, WordPress saves only the post-update state (`api/Data.php:739-747`); every existing record and every `create()`d one. Fix: in `update()`, before the writes, `wp_save_post_revision($id)` when the record has no revisions yet. RED on real WordPress: a record with no revisions, one `update()` → a revision holding the original value exists.
3. **After a restore the newest revision holds the old fields** (code S2). `wp_restore_post_revision`'s own `wp_update_post` takes its revision before the fields are copied back (`api/Data.php:2143-2164`). Fix: `wp_save_post_revision($postId)` after the priority-11 step. RED: restore, then the latest revision's field = the restored value.
4. **The cap overrides a site's own revision setting** (code S3; invariant-auditor B2; sentinel). `wp_{type}_revisions_to_keep` returns 5 whatever it is passed (`api/Data.php:2141`), turning revisions back on where `WP_POST_REVISIONS` is `false`/`0` (five consumers set it from env). Ruling (reviewer, technical — R3 says "at most 5"): return `min(5, $num)` with WordPress's `-1` (unlimited) read as 5, so 0 stays off. RED: global filter 0 → no revision; 50 → 5; -1 → 5.
5. **Registering a model twice doubles the restore guard** (code N2; drift open question). Each `register()` adds another 9/11 pair, so a no-copy restore re-adds the kept value twice (duplicate meta rows); `testRegisteringTwiceNamesEachKeyOnce` never registers twice. Fix: hook once per model name. RED: register twice, restore a revision without the field → exactly one meta row.
6. **INV-8 reads as failing** (drift Drift; invariant-auditor D-a). Check (A) now returns 56 and the `callback` exception has 7 hits (4 in `api/Data.php`); `ARCHITECTURE-INVARIANTS.md:470,785,800` still pin 55 / 6. Name the `registerRevisions()` site in the exception and re-pin at 5.6.0.
7. **CHANGELOG 5.6.0 says what is not so** (code S3, N4, N5; invariant-auditor D-d; drift Borderline). It must say: the per-model cap respects a lower site setting (after item 4); "no stored value changes" holds only after item 1; a fields-only `update()` now saves a revision (`save_post` fires for the revision post); `restorePostData()` (protected) was removed — a "Removed" line, not "Additive".
8. **CODE-MAP has no entry for the revision decisions** (invariant-auditor D-e): revisioned meta through the filter (no registration), the write order, the restore guard, autosaves kept out. The auditor's report has a proposed entry.

## Note — no change required on this branch

- `show_ui` compared `=== true` (`api/Data.php:2313`): `'show_ui' => 1` gives no revisions. Cheap to read as a boolean — do it in the same pass if trivial. (code N1)
- `restoreMetaData()` (`api/Data.php:901-913`) does not `wp_slash`; pre-existing, newly reachable through a failed `wp_update_post` in R6's order. Cheap — same pass if trivial. (code N3)
- The comment at `api/Data.php:749-750` still says `$previous_post_data` is "for rollback"; it now feeds only the updated hook. (drift)
- `wp_save_post_revision()` and `add_post_meta()` return values unchecked (`:746`, `:2160`). (drift)
- A revision restore changes fields with no `ntdst/model/*` hook, so the audit log misses it — record as a deliberate exception in the invariants doc. (invariant-auditor B3)
- Restoring a revision taken while a field was unset keeps the current value — R5 as approved, documented. (code N6)
- Two proof tests could not fail on 5.5.0 for the reason they name; R5's guard is truly proven by the unit test. (code N7)
- The "skip `callback` fields" predicate is now written in four places (`:148/:388/:1802/:2132`) — an allowed exception; a shared predicate is a later call. (drift)
- `getMetaPrefix() . $field` re-spells protected `prefixMetaKey()`; forced by the no-new-public-method constraint. (invariant-auditor R1)
- INV-9 only answers truly when run beside the consumer roots; stale line refs at `ARCHITECTURE-INVARIANTS.md:52,155` predate this branch. (invariant-auditor D-b, D-c)
- Process: the plan said no threat model; the diff gives `$_POST` and REST input a new route into stored data — one was owed. (security-sentinel)

Findings: 0 Blocking · 8 Should fix · 11 Note
