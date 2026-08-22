# field-types — tasks

Plan: `specs/field-types/plan.md` · Spec: `specs/field-types/spec.md` (rev 1) · Invariants: `ARCHITECTURE-INVARIANTS.md` · Schedule: plan.md `## Schedule` (runs between core-shape Cluster 2 and Cluster 3).

Repos: **CORE** = `~/Sites/ntdst-core` (T01…T06, T09). **DAAN** = `~/Sites/daan` branch `chore/core-path-repo` (T07). **JOSWORLD** / **STRIDE** / **TODAI** = their current branches (T08), each gated on the core it pins.

Every CORE task closes with `cd ~/Sites/ntdst-core && composer gate` exit 0 and one atomic commit on `feat/core-shape`, committed with explicit pathspecs (nothing left staged).

---

### Cluster A — the vocabulary has one home (CORE)

Stakes: high — this is the sanitizer table for every field value on every site.

Behaviour: every one of the 17 canonical names answers with one sanitizer, one REST shape and one admin control, each sanitizer survives its hostile input and is idempotent, and every other name — alias or invention — throws at the registry naming the canonical.
Observable: `php -r 'define("ABSPATH","/tmp/"); require "api/FieldTypes.php"; $t = NTDST_FieldTypes::get("int"); echo json_encode([$t->name,$t->schema,$t->control,$t->cell]),"\n"; try { NTDST_FieldTypes::get("integer"); } catch (InvalidArgumentException $e) { echo $e->getMessage(),"\n"; }'` prints `["int",{"type":"integer"},"number",true]` then `Unknown field type 'integer'. Use 'int'.`
RED until: tests/Unit/FieldTypesTest.php

- [x] T01 — api/FieldTypes.php: NTDST_FieldType value object + NTDST_FieldTypes closed table of 17 [Tier A]  (files: api/FieldTypes.php, ntdst-core.php, tests/bootstrap.php, tests/Unit/FieldTypesTest.php)
  Satisfies: FR-1, FR-2, FR-3, SC-1
  Test-author: split
  Proven by: new test
  Unit test: for each of the 17 names `get($name)` returns an `NTDST_FieldType` whose `name` equals the argument, whose `sanitize` closure gives the plan's threat-row-#1 answer on the valid, hostile and empty input (51 assertions) and is idempotent on the hostile input (17), whose `schema` `===` the plan's Interfaces shape (17; `json` → null), whose `control` and `cell` equal the table (34); `names()` returns exactly the 17 in D4 order; each of the 13 retired names throws `InvalidArgumentException` whose message contains `Use '<canonical>'.` (13) and an invented name throws with `Known: int, float, …` (1); `ReflectionClass(NTDST_FieldTypes)` public methods are exactly `get` and `names` (1) and `NTDST_FieldType` is readonly (1). Sanitizers that call WordPress functions are stubbed the way `DataRegistersRestMetaTest` stubs them (tagged, not pass-through) except `wp_validate_boolean`, which the test reproduces from WordPress source so the `"false"` → `false` answer is WordPress's. `ntdst-core.php` requires `api/FieldTypes.php` before `api/Data.php`; `tests/bootstrap.php` requires it before `api/Data.php`.

- [x] T02 — consumer ground-truth: retired-name counts per site and the int-sign audit [Tier B]  (files: specs/field-types/ground-truth.md)
  Satisfies: FR-8 (its input), threat row #4
  Test-author: solo — a report, no code path
  Proven by: machine gate — the report's counts are reproducible by the greps it records
  Integration test: `specs/field-types/ground-truth.md` lists, per site (daan `chore/core-path-repo`, josworld `chore/ntdst-core-4`, stride `staging`, todai `master`), every `'type' => '<retired>'` hit that sits inside a `fields`/`sub_fields` declaration, as `file:line → canonical`, with the exact grep that produced it and the count; separately lists the `'type' => 'string'` hits that are REST arg schemas (not fields, untouched); for every `int`/`integer` field on each site lists the readers of its meta key and whether any assumes `>= 0` (expected: none; stride's `signed_int` wanted negatives); records josworld's `TYPE_MAP` file path and key. Every count in the report is reproduced by running the recorded grep at the recorded commit — the reviewer re-runs two at random.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && php -r 'define("ABSPATH","/tmp/"); require "api/FieldTypes.php"; foreach (NTDST_FieldTypes::names() as $n) { $t = NTDST_FieldTypes::get($n); echo $n, " ", json_encode($t->schema), " ", $t->control, " ", var_export($t->cell, true), "\n"; }'` prints 17 lines matching the plan's Interfaces table.

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster B — Data reads the vocabulary (CORE)

Stakes: high — the registry now binds into create()/update() and into the /wp/v2 schemas.

Behaviour: a model binds each field's sanitizer from the registry and refuses, at register(), any field or sub-field whose type is not one of the 17 or cannot live in a repeater row; an int keeps its sign on every write path; the REST schema of a declared field is the registry's leaf shape under the unchanged structural rule; Data's public surface is the chain, CRUD and four readers.
Observable: `cd ~/Sites/daan && ddev exec wp eval 'echo json_encode(array_keys(get_registered_meta_keys("post","gig")));'` prints the same keys as before Cluster B, and `curl -s https://daan.ddev.site/wp-json/wp/v2/gigs/297050 | jq '.meta | keys'` is unchanged (core-shape SC-1 re-run).
RED until: tests/Unit/DataReadsTheVocabularyTest.php

- [ ] T03 — Data binds the registry: setupSanitizers() asks get(), the constructor validates every field and sub-field, int is signed, seven helpers and getDefaultSanitizer() go [Tier A]  (files: api/Data.php, tests/Unit/DataReadsTheVocabularyTest.php, tests/Unit/SignedIntFieldTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-4 (sanitizer half), FR-5, SC-3
  Test-author: split
  Proven by: new test
  Unit test: `new NTDST_Data_Model('p', ['price' => ['type' => 'int']], '_p_')` then `update(1, ['price' => '-250'])` (with `update_post_meta`/`get_post_meta` stubbed) stores `-250`; `['n' => 'integer']` throws at construction with a message containing `Field 'n'` and `Use 'int'.`; `['n' => ['type' => 'signed_int']]` throws with `Use 'int'.`; a repeater whose `sub_fields` holds `['type' => 'html']` throws naming `provenance` and `notes` and `cannot be a repeater sub-field`; a field with its own `'sanitizer'` callable runs it AFTER the registry's (spec rev 3): a no-op override on a `html` field still strips `<script>` and a tightening override still applies; an unknown `type` beside it still throws — the registry is asked first; the `register_post_meta` `sanitize_callback` stays a one-argument wrapper (WordPress calls it with `($value, $meta_key, $object_type)` — passing the two-argument registry closure raw would `TypeError` on every write); the bound sanitizer for `'bool'` returns `false` for `"false"`; `formatMeta()`'s read-side `match` over type names (`api/Data.php:~1996–2021`) is gone and its casts go through `NTDST_FieldTypes::get($type)->sanitize`; the two `getDefaultSanitizer()` calls in `updateMeta()`/`updateMetaBatch()` (`:~1185`, `:~1224`) bind through the registry; the two `'string'` defaults (`:~321`, `:~418`) become `'text'`; the docblocks at `:~305` and `:~390–396` that call `getDefaultSanitizer()` the vocabulary go with it (a stored `"1"` for a `bool` field reads back `true`; a stored `"-3"` for an `int` reads back `-3`); `ReflectionClass(NTDST_Data_Model)` has no method named `getDefaultSanitizer`, `sanitizeBoolean`, `sanitizeJson`, `sanitizeNestedArray`, `sanitizeDate`, `sanitizeAttachmentId` or `sanitizeRepeater`; `SignedIntFieldTest` is re-pointed to `int` by the test-author (the sign behaviour is the same promise under the canonical name); `bin/guard.sh`'s REMOVED alternation and `PackageBootIntegrityTest::removedSymbolProvider()` gain `getDefaultSanitizer`, `sanitizeRepeater`, `signed_int`, `'integer'`/`'boolean'`/`'wysiwyg'`/`'person'`/`'post_relation'` as type literals (version `5.0.0`), and the provider's `grep` must not match `api/FieldTypes.php`'s own message table or README's migration rows (exclude by path, as the existing rows do).

- [ ] T04 — schemaFor() reads the leaf shape from the registry; restSubFields() and restSchemaFor() go; contract tests re-pointed [Tier A]  (files: api/Data.php, tests/Unit/DataRegistersRestMetaTest.php, tests/Unit/DataRestFieldsTest.php, tests/Unit/DataDeclaresWordPressReadsTest.php, tests/Unit/FieldTypesTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-4 (schema half), SC-2, SC-5, SC-7 (the integration gate re-runs core-shape SC-1)
  Test-author: split
  Proven by: new test
  Unit test: the test-author moves every type→schema case of `DataRegistersRestMetaTest` to `FieldTypesTest` (they assert the registry now) and rewrites the structural cases to drive `registerRestMeta()`'s captured `register_post_meta` args instead of `restSchemaFor()` (the repeater all-or-nothing rule, `json` unpublishable, depth-2 nesting, the `show_in_rest` strict opt-in — unchanged promises, observed through the registration); `DataRestFieldsTest` loses its `restSubFields()` cases (0 readers) and keeps `restFields()`; the SC-5 surface assertion pins `ReflectionClass(NTDST_Data_Model)` public methods minus the chain/CRUD list `=== ['getSchema','getMetaPrefix','restFields','registerRestMeta']`; `grep -c "function restSubFields\|function restSchemaFor" api/Data.php` = 0; `bin/guard.sh` + the provider gain `restSubFields`, `restSchemaFor`; `composer gate` exit 0 with the suite losing exactly the moved/deleted cases (set-difference of `--list-tests`, recorded in the report); the report states, from `~/Sites/daan/web/wp/wp-includes/rest-api.php`, whether `rest_sanitize_value_from_schema` coerces or drops VALUES on a REST write to an `array` field (its published leaf is `array of string` while the sanitizer keeps nested arrays and typed scalars) — a finding for T09's README if so.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && curl -s https://daan.ddev.site/wp-json/wp/v2/gigs/297050 | jq -e '(.meta | keys) == ["venue_city","venue_country"]' && ddev exec wp eval 'echo json_encode(array_keys(get_registered_meta_keys("post","gig")));'`

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster C — the metabox reads the vocabulary (CORE)

Stakes: high — the save path goes from sanitized twice to once; once must never become zero.

Behaviour: a metabox save of a Data model reaches the model unsanitized and is sanitized exactly once by the model; a save on a non-Data post type is sanitized exactly once by the registry; every field renders through one renderer keyed by the registry's control, in a row or at top level, and an unknown control is a fault, never a text box.
Observable: on daan's edit screen for gig 297050 every declared field renders its FR-2 control (the `html` field shows the editor) and `ddev exec wp post meta get 297050 venue_city` after a save through the screen equals the typed value, stripped of tags, once.
RED until: tests/Unit/MetaboxGeneratorSaveTest.php

- [ ] T05 — save path: sanitize_field() and its nested switch go; Data models sanitize once; non-Data post types use the registry [Tier A]  (files: admin/MetaboxGenerator.php, tests/Unit/MetaboxGeneratorSaveTest.php, bin/guard.sh, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-6, SC-4
  Test-author: split
  Proven by: new test
  Unit test: with `wp_verify_nonce`, `current_user_can`, `wp_unslash`, `update_post_meta`, `get_post_meta` stubbed and `ntdst_data()` returning a manager with a model whose sanitizer is a counting spy, `save_metabox_data()` for that model's post type with `$_POST['ntdst_fields'] = ['venue_city' => '  <b>x</b>  ']` calls the model's `update()` once with the unslashed value unchanged (`'  <b>x</b>  '`) and the spy counts exactly 1 invocation per submitted field and 0 for a field the model does not declare; for a post type with a metabox but no model, `update_post_meta()` receives the registry-sanitized value (`"false"` → `false` for `bool`; `'<b>x</b>'` → `'x'` for `text`; a repeater row's `html` cell cannot exist — the type was refused at registration) and the registry sanitizer is invoked exactly once per field; a `relation` field absent from `$_POST` is stored as `[]` (today's rule); a repeater row whose only filled cell is `0` or `false` is KEPT (the metabox's `!== '' && !== null` rule survives; `array_filter()`'s drop-falsy rule does not — ruled at the Cluster A gate); `ReflectionClass(NTDST_MetaboxGenerator)` has no `sanitize_field`; the unslash/sanitize loop runs INSIDE the save's `try` so a sanitizer throw surfaces as the existing save-error notice, never a white screen with all meta lost; `bin/guard.sh` + the provider gain `sanitize_field(`.

- [ ] T06 — one renderer: render_control(control, …, inCell) keyed by the registry; the two switches go; unknown control is a LogicException [Tier A]  (files: admin/MetaboxGenerator.php, tests/Unit/MetaboxGeneratorRenderTest.php)
  Satisfies: FR-7
  Test-author: solo — rendering, no authorization semantics; the denial path (`LogicException`) is pinned
  Proven by: new test
  Unit test: with `esc_attr`/`esc_html`/`esc_textarea`/`wp_editor`/`wp_kses_post` stubbed, rendering a model with one field per control (13 controls) through the public metabox render entry (output-buffered) produces, per field, the control's distinguishing markup (`type="number"` for `number`; `type="checkbox"` for `checkbox`; `<textarea` for `textarea`; the `wp_editor` stub's marker for `html`; `type="email"`, `type="url"`, `type="date"`; `<select` with the field's options for `select`; the media button markup for `media`; the relation picker container for `relation`; the gallery container for `gallery`; the repeater table for `repeater`) and each field's `name="ntdst_fields[<field>]"`; a repeater with `text`, `number` and `media` sub-fields renders each cell through the same controls with the row naming `ntdst_fields[<field>][<i>][<sub>]` and no `<label>` wrapper; `render_control('nope', …)` throws `LogicException`; `grep -c "switch (\$type)\|case 'text':\|case 'wysiwyg':\|case 'integer':" admin/MetaboxGenerator.php` = 0; `render_repeater_media_cell()` is either folded into the `media` control's `$inCell` branch or deleted — `grep -c "function render_repeater_media_cell" admin/MetaboxGenerator.php` = 0. `MARKER_ONLY_REQUIRED_TYPES` (`:64`, a second type list) is deleted — the native-`required` decision reads `NTDST_FieldType` (`$cell`/`$control`), pinned by one case; the readonly-display branches at `:~849–853` route through `$control`. `admin/RelationField.php:158,261` (`=== 'relation'` selectors) are untouched and recorded as INV-8's deliberate exception by T09.

Integration gate: `cd ~/Sites/ntdst-core && composer gate && cd ~/Sites/daan && ddev composer update netdust/ntdst-core && ddev exec wp eval 'ob_start(); do_action("add_meta_boxes", "gig", get_post(297050)); global $wp_meta_boxes; foreach ($wp_meta_boxes["gig"] as $ctx) foreach ($ctx as $prio) foreach ($prio as $box) if (str_starts_with($box["id"], "ntdst")) call_user_func($box["callback"], get_post(297050), $box); $html = ob_get_clean(); echo substr_count($html, "ntdst_fields["), " fields · checkbox:", substr_count($html, "type=\"checkbox\""), " · editor:", (int) str_contains($html, "wp-editor"), "\n";'` prints a field count equal to the gig model's declared fields and a non-zero editor flag — then Artifact-load: the real edit screen in the browser before the panel.

── REVIEW GATE ── *(provisional tier: FULL — reviewer + security-sentinel + invariant-auditor + code-simplicity)*

---

### Cluster D — consumers, docs, invariants (CORE + consumers)

Stakes: standard — renames and prose; a wrong rename fatals at init, visibly.

Behaviour: every D6 consumer declares only canonical names and its own gate is green; README names every retired name with its canonical and the int sign change; the invariants doc has INV-8 with a mechanical check that returns 0 hits. (The RED below is PackageBootIntegrityTest's README scan + removed-symbol rows; the consumer gates are machine gates.)
Observable: `bash -c 'for s in daan josworld stride; do grep -rnoE "'"'"'type'"'"' *=> *'"'"'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation)'"'"'" --include=*.php ~/Sites/$s/web/app/mu-plugins ~/Sites/$s/app/content/themes 2>/dev/null | grep -v "/vendor/\|ntdst-core\|ntdst-baseline" | grep -v "REST arg" | wc -l; done'` prints 0 for the `fields` hits listed in `ground-truth.md` (REST arg schemas excluded as the report lists them), and `grep -c "^| \`" README.md` over the "Field types" table prints 13.
RED until: tests/Unit/PackageBootIntegrityTest.php

- [ ] T07 — daan: rename commit on chore/core-path-repo + path-repo gate [Tier B]  (files: ../daan/web/app/mu-plugins/daan-core/services/**/*.php per ground-truth.md)
  Satisfies: FR-8 (daan), SC-6 (daan), SC-7 (the edit screen, AF-1)
  Test-author: solo — a rename, no code path
  Proven by: machine gate — daan's `composer gate` + the ground-truth grep at 0
  Integration test: every daan hit in `ground-truth.md` is renamed to its canonical in one commit that touches no file outside `fields`/`sub_fields` declarations; `cd ~/Sites/daan && ddev composer update netdust/ntdst-core && ddev composer gate` (or daan's gate command per its `composer.json`) exit 0; `curl -s https://daan.ddev.site/wp-json/wp/v2/gigs/297050 | jq -e '(.meta | keys) == ["venue_city","venue_country"]'`; the edit screen loads (AF-1) — recorded as Artifact-load. `[HUMAN]` — the commit is on the never-merged branch; Stefan cherry-picks it to `master` when daan updates.

- [ ] T08 — josworld, stride, todai: rename commits on their current branches + TYPE_MAP key; each gated on the core it pins [Tier B]  (files: ../josworld/app/content/themes/josworld/services/yootheme/SchemaMapper.php, ../josworld/** per ground-truth.md, ../stride/web/app/mu-plugins/** per ground-truth.md)
  Satisfies: FR-8 (josworld, stride, todai), SC-6
  Test-author: solo — renames, no code path
  Proven by: machine gate — three consumer gates + the ground-truth grep at 0
  Integration test: josworld — every `fields` hit renamed + `SchemaMapper::TYPE_MAP['boolean']` → `'bool'`, one commit on `chore/ntdst-core-4`, its `composer gate` exit 0 on the `5.0.0` tag it pins; stride — every `fields` hit renamed (61 `integer`, 16 `boolean`, 1 `signed_int` → `int`; `string` REST-arg hits untouched), one commit on `staging`, its gate exit 0 on `v3.0.0` (which already accepts `int`/`bool`/`relation`/`html`); todai — no hits; its gate run once, exit 0. Each commit touches 0 files outside the declarations the report lists (reviewer checks by `git show --stat`). `[HUMAN]` before this task: confirm the three target branches.

- [ ] T09 — README "Field types" migration table + int sign + cell rule + get() for bridges; philosophy citation; INV-8; INV-1 readers text [Tier B]  (files: README.md, docs/philosophy.md, ARCHITECTURE-INVARIANTS.md, tests/Unit/PackageBootIntegrityTest.php)
  Satisfies: FR-9, SC-2, SC-8
  Test-author: solo
  Proven by: machine gate — the SC-2 and INV-8 greps + PackageBootIntegrityTest's README scan
  Integration test: README's `### 5.0.0 — BREAKING` section gains a "Field types" table with exactly 13 rows (retired → canonical), a paragraph stating `int` now stores negatives (FR-5; and saturates at `PHP_INT_MAX`), and rows for the other storage changes (`bool` is `wp_validate_boolean()` alone — the old non-WordPress fallback was dead code; `date` stores `Y-m-d` read and written on one clock (WordPress forces UTC) and refuses years outside 0000–9999; `text`/`textarea`/`select` keep NUL bytes as WordPress does; `url` keeps protocol-relative URLs; a per-field `sanitizer` composes after the registry and can only tighten; `array` is not published) the registry makes (`relation`/`gallery` drop zeros and re-index; a `relation` scalar that is not an id stores `[]`, not `[0]`; `array` accepts a JSON string; every sanitizer is return-typed, so a filter that returns `null` into one raises a `TypeError` instead of storing junk), a sentence listing the types that cannot be repeater sub-fields (`html`, `relation`, `gallery`, `repeater`), and a paragraph documenting `NTDST_FieldTypes::get()` as the public read for bridges (D10); two facts from the Cluster B gate: `format` on `email`/`url` never reaches `/wp/v2` under `show_in_rest => true` (WordPress derives the schema from `type`), and `array` was never actually published (`class-wp-rest-meta-fields.php:567` nulls a keyed map that fails `rest_is_array()`, `rest-api.php:1593`) so its removal loses no working data; INV-1's deliberate-exceptions bullet lists `array` beside `json`; `docs/philosophy.md` §1 quotes `api/Data.php`'s new first line verbatim; `ARCHITECTURE-INVARIANTS.md` gains INV-8 (text; convergence point `api/FieldTypes.php`; bypass smell "a `match`/`switch`/`===` over a type name, or a second type list, anywhere else"; the TWO-command mechanical check from the Cluster A invariant audit — (A) `(switch|match) *\( *\$[A-Za-z_]*[Tt]ype|case '(<30 names>)'|'(<name>)' *, *'(<name>)'|\$[A-Za-z_]*[Tt]ype[A-Za-z_]* *[!=]== *'(<name>)'` over `api core admin services support` with `--include=*.php`, `-vE` exclusions for `api/FieldTypes\.php`, vendor, tests; (B) `(const|static +\??array +\$)[ A-Za-z_]*[Tt][Yy][Pp][Ee][Ss]?[A-Za-z_]* *=` same scope — amended at the Cluster B audit: (A)'s comparison alternative is subject-agnostic (`[!=]== *'(<names>|media|checkbox|number|decimal|json|relation|gallery|repeater|date)'` and `'(<names>)' *(,|=>)`) and `(switch|match) *\(` is restricted only by the file exclusions, so `declaredType() === 'repeater'`, a `match ($entry->control)` head and `['type'] ?? ''` selectors all surface; `api/Data.php`'s `in_array($type, ['array','object'], true)` (a JSON-Schema type) is named as a false-positive shape — both empty except the named exceptions; Status "established by field-types Clusters A–C; code holds at <sha>"), with `## Deliberate exceptions` entries for `api/Data.php`'s two `=== 'repeater'` structural tests and `admin/RelationField.php:158,261`'s `=== 'relation'` selectors, and for `nested()` not being `map_deep()`; INV-5's check broadened to `(private|protected) +(static +\??array +\$|const +)…= *(\[|null)` with an exception naming `NTDST_FieldTypes`' table and `RETIRED` as a vocabulary WordPress does not keep; INV-1's first check gains a comment-line filter `grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"` (it hits `api/FieldTypes.php:46`'s docblock today) and its readers sentence no longer mentions `restSubFields()`/`restSchemaFor()` (the depth clause re-points at `schemaFor()` behind `registerRestMeta()`); INV-4's Status names `admin/MetaboxGenerator.php:1777`'s form nonce as WordPress's own; `tests/Unit/PackageBootIntegrityTest.php` gains a case that reads `NTDST_FieldTypes::RETIRED` by reflection and asserts README's 13 rows equal it; `docs/philosophy.md` §1's citation moves to `api/FieldTypes.php`'s "where WordPress has the word" line; `bin/guard.sh` and the provider gain the five retired type LITERALS (`'integer'`, `'boolean'`, `'wysiwyg'`, `'person'`, `'post_relation'` — quoted, so the registry's own `['type' => 'integer']` schema shapes do not match; exempt `api/FieldTypes.php`'s message table) once T06 has removed the last live uses in `admin/MetaboxGenerator.php`; README rows for the read-side changes (a deleted attachment reads `0`; `json` keys read back `sanitize_key()`'d — no, see the Cluster B ruling: `json` is decode-only on read; repeater cells read back as stored); the retired names inside README live under `## Versions` only (PackageBootIntegrityTest's scan stays green); `specs/core-shape/tasks.md` Cluster 3 header gains one line: "runs after field-types Cluster D (see specs/field-types/plan.md `## Schedule`)".

Integration gate: `cd ~/Sites/ntdst-core && composer gate && grep -rnE "case '(text|int|integer|float|bool|boolean|string|wysiwyg|html)'|'int', 'integer'|=> 'absint'" api admin | grep -vE "^(\./)?api/FieldTypes\.php" | wc -l` prints 0 `&& python3 ~/.claude/plugins/cache/netdust-plugins/netdust-agent/0.19.2/bin/gate-check.py specs/field-types` PASS.

── REVIEW GATE ── *(provisional tier: STANDARD — reviewer + code-simplicity + invariant-auditor)*

---

## [HUMAN] yield points

- After T03 — `int` now stores negatives on every site that updates; confirm README's wording of FR-5 before Cluster C starts.
- Before the Cluster C panel — load the daan gig edit screen once in the browser (AF-1) and record what you saw.
- Before T08 — confirm the three consumer target branches (`chore/ntdst-core-4`, `staging`, `master`) and that commits land there.
- After Cluster D's gate — core-shape Cluster 3 (T07) starts; its relation-picker JS change now targets the single renderer.
