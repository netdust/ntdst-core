# field-types — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The field-type vocabulary gets ONE home — `api/FieldTypes.php` — 17
canonical names, no aliases; `Data` and `MetaboxGenerator` read it instead of
carrying their own tables; consumers rename; the invariants doc gains INV-8.

**Architecture:** Four phases on `feat/core-shape`, between core-shape Cluster 2
(closed at `7e17969`/`86f1546`) and core-shape Cluster 3 (T07). Phase 1 builds
`NTDST_FieldType` + `NTDST_FieldTypes` and proves each of the 17 entries on
valid / hostile / empty input. Phase 2 makes `NTDST_Data_Model` bind the
registry's sanitizer, validate every field and sub-field at `register()`, read
each leaf's REST shape from the registry, and lose seven sanitize helpers plus
`restSubFields()`/`restSchemaFor()`. Phase 3 deletes the metabox's own sanitizer
(the double-sanitize defect) and folds its two render switches into one
renderer keyed by the registry's `control`. Phase 4 renames the consumers, writes
README/philosophy/INV-8. Task ids are `T01…T09` inside this spec directory (gate-check grammar); in
prose and ledgers they are written `field-types T0n` so they never read as
core-shape's `T01…T14`.

**Tech Stack:** PHP 8.2+, WordPress 7.0.4 (`~/Sites/daan/web/wp` is the source
of truth), PHPUnit 10 + Brain Monkey (`composer gate`), daan's DDEV through the
existing composer path repository (`chore/core-path-repo`).

**Spec:** `specs/field-types/spec.md` (revision 1) ·
**Invariants:** `ARCHITECTURE-INVARIANTS.md` INV-1 (touched), INV-8 (added by T09) ·
**Sibling spec:** `specs/core-shape/` (Clusters 1–2 closed; Cluster 3 waits on this plan — see `## Schedule`).

**Repos:** CORE = `~/Sites/ntdst-core` (T01…T06, T09). DAAN = `~/Sites/daan`
on `chore/core-path-repo` (T07, never merged — the rename commit is
cherry-pickable to `master`). JOSWORLD = `~/Sites/josworld` (`chore/ntdst-core-4`,
pins the `5.0.0` tag). STRIDE = `~/Sites/stride` (`staging`, pins `v3.0.0`).
TODAI = `~/Sites/todai` (`master`, no ntdst fields).

## Global Constraints

- Target release **v5.0.0**, breaking, unreleased; no alias, shim or deprecation path (spec Assumptions).
- **The 17 names are the whole vocabulary** (D4): `int float bool text textarea html email url date select array json relation gallery image file repeater`. Anything else throws at `register()` (D5); the 13 retired names throw with the canonical in the message (FR-3).
- **`register(string $name, array $config = [])` is unchanged** (D7). A field may still pass its own `sanitizer` callable, which overrides the registry's (FR-4).
- **`Data`'s public surface after this plan:** the query chain + CRUD, `getSchema()`, `getMetaPrefix()`, `restFields()`, `registerRestMeta()` — nothing else (D1, SC-5).
- **Every sanitizer is idempotent** — `register_post_meta()` applies it again on REST writes (core-shape I4 ruling).
- **Where WordPress has a word, core uses it** (core-shape D8): sanitizers are WordPress's (`sanitize_text_field`, `wp_kses_post`, `wp_validate_boolean`, `sanitize_email`, `esc_url_raw`, `absint`); the registry maps names to them, it does not re-implement them.
- **Consumers count, never design** (core-shape D5): the D6 set is daan, josworld, stride, todai; each consumer's gate runs on the core it pins today (spec rev 1).
- Every CORE task closes with `cd ~/Sites/ntdst-core && composer gate` exit 0 and one atomic commit on `feat/core-shape`; `bin/guard.sh` and `tests/Unit/PackageBootIntegrityTest.php` are extended **in the task that removes a symbol** (T03, T04, T05).
- Dispatches commit with explicit pathspecs and never leave anything staged (the harness Stop hook sweeps staged files — see `memory/stop-hook-sweeps-staged-files`).

---

## Stakes

Stakes: high — sanitization is the boundary for every field value on every
site, and this plan rewrites the one table that decides it (FR-2) and deletes
the second one (FR-6). A wrong closure stores unsanitized markup on daan and
josworld the day they update; a wrong `int` cast changes stored numbers.

Per-cluster refinement:
- **Cluster A (the vocabulary):** high — the sanitizer table itself.
- **Cluster B (Data reads it):** high — the same table now binds into `create()`/`update()` and into `/wp/v2` schemas (core-shape INV-1's surface).
- **Cluster C (metabox reads it):** high — the save path goes from sanitized-twice to sanitized-once; "once" must never become "zero".
- **Cluster D (consumers, docs, invariants):** standard — renames and prose; a wrong rename fatals at `init`, visibly.

---

## Architecture invariants touched

| Invariant | How this plan touches it | Mechanical check after |
|---|---|---|
| **INV-1** (a field leaves only when it says `show_in_rest => true`; `declaresRest()` the one reader; `api/Data.php` the only caller of `register_post_meta()`) | Unchanged mechanism. `schemaFor()` keeps the structural rule (recursion, all-or-nothing repeaters, `json` unpublishable) and reads each leaf's shape from `NTDST_FieldTypes::get($type)->schema` instead of its own `match`. `restSubFields()` and `restSchemaFor()` (0 shipped readers) are deleted; INV-1's readers sentence drops them (T09). | INV-1's three greps stay empty/clean; SC-7 re-runs core-shape SC-1 on daan (0 keys gained or lost). |
| **INV-5** (core keeps no table WordPress already keeps) | `NTDST_FieldTypes`' table is a vocabulary WordPress has no function for — not an INV-5 table. Named in INV-8's text so the next auditor does not re-litigate it. | INV-5's grep gains `api/FieldTypes.php`'s one `private static array`; the exception names it. |
| **INV-8** (new, T09): *the field vocabulary has one table; every reader of a type name asks `NTDST_FieldTypes::get()`* | Established by T01…T06. | `grep -rnE "case '(text|int|integer|float|bool|boolean|string|wysiwyg|html)'|'int', 'integer'|=> 'absint'" api admin` → 0 hits outside `api/FieldTypes.php`; bypass smell: a `match`/`switch` over type names anywhere else. |
| **INV-4** (fail closed and loudly — the rule `register()` already cites in words) | An unknown type, an alias, or a `cell = false` type inside `sub_fields` throws `InvalidArgumentException` at `register()` naming the field; an unknown control throws `LogicException` at render. Never a silent `text` fallback. | SC-3's three throws; T06's `LogicException` case. |

---

## Spec-premise ground-truth

Every "today X does Y" premise read against real source on 2026-08-22 at `86f1546` (the spec measured at `6b6b3d6`; line numbers below are current):

| Premise | Verdict |
|---|---|
| "`Data.php` has seven sanitize helpers + `getDefaultSanitizer()`" | **Confirmed.** `getDefaultSanitizer()` :460, `sanitizeBoolean()` :506, `sanitizeJson()` :528, `sanitizeNestedArray()` :545, `sanitizeDate()` :590, `sanitizeAttachmentId()` :607, `sanitizeRepeater()` :620; `sanitizeField()` :566 is the one call site for `$this->sanitizers[$field]` and stays. |
| "`restSubFields()` and `restSchemaFor()` have 0 shipped readers" | **Confirmed.** `grep -rn "restSubFields\|restSchemaFor" api admin core services ntdst-core.php` → only `api/Data.php` itself (:147, :183). Test readers: `DataRegistersRestMetaTest` (26 uses), `DataRestFieldsTest` (2) — T04 re-points them; the type-table cases move to `FieldTypesTest`. |
| "`schemaFor()` carries the type match" | **Confirmed, at :311** — `private function schemaFor(mixed $config, ?array &$refusal = null): ?array`; the `match` arms are the 17 + aliases; the structural rule (repeater recursion, empty/partial refusal, `json` → null) is the part that stays. |
| "The metabox sanitizes, then `$model->update()` sanitizes again" | **Confirmed.** `save_metabox_data()` :1760 → `wp_unslash()` :1809 → `sanitize_field()` :1812 (`switch ($type)` :2040, nested repeater switch :2185) → the model. `int`/`integer` → `absint()` there; `bool` → `(bool)`; `array`/`json` pass through. |
| "Two render switches" | **Confirmed.** `render_field()` :786 (`switch` from :879: `text string email integer int float decimal boolean bool textarea longtext wysiwyg array json date datetime url relation gallery`) and `render_repeater_row()` :1526 (`switch` from :1549: `text string textarea select number integer float decimal date url image file`); `render_repeater_media_cell()` :1628 handles `image`/`file` cells; `render_repeater_field()` :1275 is the table wrapper. |
| "`getSchema()` is read by `RelationField` and the josworld bridge" | **Confirmed.** `admin/RelationField.php:157,255`; josworld `app/content/themes/josworld/services/yootheme/SchemaMapper.php` `TYPE_MAP` :55–61 (`text textarea email select boolean`). |
| "The 44 retired-name hits across consumers" | **Refuted as a total.** daan: `integer` 3, `person` 1, `post_relation` 1, `wysiwyg` 1. stride (mu-plugins): ~110 hits of which 32 are `'type' => 'string'` in REST arg schemas (not fields) — T02 counts the `fields`/`sub_fields` hits per site exactly. josworld: the `boolean` key in `TYPE_MAP`; its `fields` declarations are counted by T02. todai: 0. |
| "josworld/stride/todai gate against the path repo" | **Refuted; spec rev 1.** josworld pins the `5.0.0` tag (`3cc96b7`, pre-core-shape); stride pins `v3.0.0`; neither can take `5.0.x-dev` without the fleet migration. Their gates run on the core they pin (Stefan, 2026-08-22). |
| "`wp_validate_boolean()` exists and returns a bool for `"false"`" | **Confirmed.** `~/Sites/daan/web/wp/wp-includes/functions.php` — `wp_validate_boolean( $value )` returns `false` for the string `'false'`. |
| "`Data.php`'s first line says the metabox reads its vocabulary" | **Confirmed** (`docs/philosophy.md` §1 cites it); the metabox does not read it today. T09 updates the citation to the new first line T03 writes. |

---

## First working version

**Task:** T01 — after it, the vocabulary exists and answers:

```bash
cd ~/Sites/ntdst-core && vendor/bin/phpunit tests/Unit/FieldTypesTest.php
php -r 'define("ABSPATH", "/tmp/"); require "api/FieldTypes.php"; $t = NTDST_FieldTypes::get("int"); echo json_encode([$t->name, $t->schema, $t->control, $t->cell]), "\n"; try { NTDST_FieldTypes::get("integer"); } catch (InvalidArgumentException $e) { echo $e->getMessage(), "\n"; }'
```

Expected: the suite green; `["int",{"type":"integer"},"number",true]` then
`Unknown field type 'integer'. Use 'int'.`

---

## Constitution check

- **ntdst-core is THE base** — the vocabulary moves *into* core's one table; nothing moves into a site (consumers only rename).
- **Wrap, never replace** — every sanitizer is a WordPress function; the registry names them, `Data` and the metabox ask the registry.
- **One name per concept** (D3) — 17 names, 0 aliases, 8 tables → 1.
- **Simplicity** — net line count negative: `Data` −~260 (seven helpers + the type match + two readers), `MetaboxGenerator` −~150 (`sanitize_field()` + nested switch, one render switch folded into the other), `FieldTypes.php` +~230.
- **`register()` unchanged** (D7); `Data` stays the ORM (D1).

## Phases & review clusters

| Cluster | Phase | Tasks | Stakes | Review tier |
|---|---|---|---|---|
| A — the vocabulary has one home | 1 | T01–T02 | high | FULL |
| B — Data reads the vocabulary | 2 | T03–T04 | high | FULL |
| C — the metabox reads the vocabulary | 3 | T05–T06 | high | FULL |
| D — consumers, docs, invariants | 4 | T07–T09 | standard | STANDARD + invariant-auditor |

Order A → B → C → D. B depends on A (binds `get()`); C depends on A and B (the metabox hands raw rows to a model that now sanitizes with the registry); D depends on C (README documents the final shape; consumers rename only after core accepts the canonical names — it already does today, so T07/T08 are only *blocked* by D's docs, not by code).

## Schedule — both specs on `feat/core-shape`

One wake = one cluster + its review gate (core-shape's loop budget rule, kept). Human yields are where Stefan's word is needed.

| # | Wake | Work | Gate | Yield |
|---|---|---|---|---|
| 0 | done | core-shape Clusters 1–2 (T01–T06) + Class D `'public'` string | closed at `86f1546` | — |
| 1 | next | **field-types Cluster A** (T01 registry, T02 consumer ground-truth) | FULL | none |
| 2 | | **field-types Cluster B** (T03 Data binds + validates, T04 schema reads + deletions) | FULL | after T03: the `int` sign change is live for every site that updates — confirm README wording |
| 3 | | **field-types Cluster C** (T05 save path, T06 one renderer) | FULL | edit-screen artifact load before the panel |
| 4 | | **field-types Cluster D** (T07 daan rename, T08 josworld/stride/todai renames, T09 README/philosophy/INV-8) | STANDARD + invariant-auditor | before T08: consumer branches receive commits — confirm each target branch |
| 5 | | core-shape **Cluster 3** (T07 relation route — now against the single renderer; T08 Actions out) | FULL | after Cluster 3: fleet-breakage acceptance (core-shape yield) |
| 6 | | core-shape **Cluster 4a** (T09) | STANDARD | — |
| 7 | | core-shape **Cluster 4b** (T10–T11) | STANDARD | — |
| 8 | | core-shape **Cluster 5** (T12–T14) | STANDARD | before T14: README read by a human; the remote `5.0.0` tag at `3cc96b7` must be reconciled |

Tracking: `specs/field-types/tasks.md` checkboxes (FT-xx) and `specs/core-shape/tasks.md` checkboxes (T-xx) are the ledger of record; the plan artifact's status strip mirrors them; the SDD ledger lives in `.superpowers/sdd/plan/progress.md` (gitignored).

## Interfaces

Names every task must use. An implementer sees only its own task.

```php
// api/FieldTypes.php — Cluster A (T01)
final class NTDST_FieldType
{
    public function __construct(
        public readonly string $name,
        public readonly \Closure $sanitize,   // fn(mixed $value, array $config): mixed — idempotent
        public readonly ?array $schema,       // REST JSON schema for the leaf; null = never publishable
        public readonly string $control,     // admin input key: number|checkbox|text|textarea|html|email|url|date|select|media|relation|gallery|repeater
        public readonly bool $cell,          // may render inside a repeater row
    ) {}
}
final class NTDST_FieldTypes
{
    public static function get(string $name): NTDST_FieldType;   // InvalidArgumentException for anything outside the 17; retired names name the canonical
    public static function names(): array;                        // the 17, in D4 order
    // no other public method; the table is a private static array built once
}
// FR-2 entries (name → sanitize · schema · control · cell):
//  int      (int) cast, arrays → 0, signed                 · ['type'=>'integer']                    · number   · true
//  float    floatval                                        · ['type'=>'number']                     · number   · true
//  bool     wp_validate_boolean                             · ['type'=>'boolean']                    · checkbox · true
//  text     sanitize_text_field                             · ['type'=>'string']                     · text     · true
//  textarea sanitize_textarea_field                         · ['type'=>'string']                     · textarea · true
//  html     wp_kses_post                                    · ['type'=>'string']                     · html     · false
//  email    sanitize_email                                  · ['type'=>'string','format'=>'email']   · email    · true
//  url      esc_url_raw                                     · ['type'=>'string','format'=>'uri']     · url      · true
//  date     today's sanitizeDate (Y-m-d or '')              · ['type'=>'string']                     · date     · true
//  select   sanitize_text_field((string) $v)                · ['type'=>'string']                     · select   · true
//  array    today's sanitizeNestedArray (JSON string ok)    · ['type'=>'array','items'=>['type'=>'string']] · textarea · true
//  json     today's sanitizeJson                            · null                                    · textarea · true
//  relation list of absint ids                              · ['type'=>'array','items'=>['type'=>'integer']] · relation · false
//  gallery  list of absint ids                              · ['type'=>'array','items'=>['type'=>'integer']] · gallery  · false
//  image    today's sanitizeAttachmentId (int; '' in a row) · ['type'=>'integer']                    · media    · true
//  file     same as image                                   · ['type'=>'integer']                    · media    · true
//  repeater today's sanitizeRepeater via $config['sub_fields'] · structural (schemaFor builds it)    · repeater · false

// api/Data.php — Cluster B (T03, T04)
final class NTDST_Data_Model {
    // constructor: every field and every sub_field resolves through NTDST_FieldTypes::get();
    //   InvalidArgumentException "Field 'provenance' sub-field 'notes': 'html' cannot be a repeater sub-field" (cell=false in sub_fields)
    //   InvalidArgumentException "Field 'n': Unknown field type 'integer'. Use 'int'." (the registry's message, prefixed with the field)
    // setupSanitizers(): $this->sanitizers[$field] = $config['sanitizer'] ?? fn($v) => (NTDST_FieldTypes::get($type)->sanitize)($v, $config)
    // schemaFor(mixed $config, ?array &$refusal = null): ?array — structural rule only; leaf shape = NTDST_FieldTypes::get($type)->schema
    // REMOVED: getDefaultSanitizer(), sanitizeBoolean(), sanitizeJson(), sanitizeNestedArray(), sanitizeDate(), sanitizeAttachmentId(), sanitizeRepeater(), restSubFields(), restSchemaFor()
    // KEPT public: chain + CRUD, getSchema(), getMetaPrefix(), restFields(), registerRestMeta()
}

// admin/MetaboxGenerator.php — Cluster C (T05, T06)
final class NTDST_MetaboxGenerator {
    // save_metabox_data(): Data-model post type → $model->update()/create() with the wp_unslash()ed row, NO metabox-side sanitize;
    //                      non-Data post type → (NTDST_FieldTypes::get($type)->sanitize)($value, $config) per field (sub-fields included) before update_post_meta();
    //                      relation/gallery "not submitted means cleared" stays.
    // REMOVED: sanitize_field() and its nested repeater switch
    private function render_control(string $control, string $field_id, string $field_name, mixed $value, array $config, bool $inCell): void;
    //   every case keyed by NTDST_FieldType::$control; unknown control → LogicException; $inCell = no label wrapper, row naming scheme
    // REMOVED: the switch in render_field() and the switch in render_repeater_row() (both call render_control())
}
```

## Threat model

Named assets → attacks → mitigations. Reviewers converge on these; a task that weakens a numbered mitigation is rejected at its gate.

1. **Every stored field value on every site** — this plan rewrites the sanitizer table (FR-2) and deletes the second one (FR-6). *Attack:* a wrong or missing closure stores unsanitized markup or an unsanitized id. *Mitigation:* `FieldTypesTest` proves each of the 17 on a **named hostile input**: `int` `"12<script>"`→`12`, `"-5"`→`-5`, `[1]`→`0`; `float` `"1.5e3<b>"`→`1500.0`; `bool` `"false"`→`false` (WordPress's `wp_validate_boolean()`: only the exact string `"false"` is false — `"<b>1"` is `true`, pinned as WordPress's answer); `text` `"<b>x</b>\n"`→`"x"`; `textarea` `"<script>a</script>\nb"`→`"a\nb"` (tags stripped, newline kept); `html` `"<p>a</p><script>x</script>"`→`"<p>a</p>"`; `email` `"a@b.c<script>"`→`"a@b.cscript"` (WordPress's own answer — pinned as WordPress's); `url` `"javascript:alert(1)"`→`""`; `date` `"2026-13-45"`→`""`; `select` `"<b>x"`→`"x"`; `array` `'{"a":"<b>x"}'`→`["a"=>"x"]`; `json` `'{"k":"<b>v"}'`→`["k"=>"v"]`; `relation`/`gallery` `["1","x","-3"]`→`[1,0,3]` filtered of 0 → `[1,3]` (today's rule: `array_filter` then `absint`); `image`/`file` `"7<b>"`→`7`, `"x"`→`0`; `repeater` a row with an undeclared key → the key is still sanitized as text (today) — pinned, and T04's structural rule keeps that repeater unpublishable. Every entry also proves **idempotence** (`sanitize(sanitize(x)) === sanitize(x)`). (T01)
2. **Anonymous reach of post meta** (core-shape INV-1). *Attack:* moving the schema facet into the registry changes what `/wp/v2` publishes. *Mitigation:* the opt-in and the structural rule stay in `schemaFor()`; the leaf shapes are byte-identical to core-shape FR-2's (asserted per type in `FieldTypesTest`); SC-7 re-runs core-shape SC-1 on daan (0 keys gained or lost). (T04)
3. **The metabox save path** — sanitized-twice becomes sanitized-once. *Attack:* "once" becomes "zero" (a Data-model save whose model lacks the field, or a non-Data save that skips the registry). *Mitigation:* `MetaboxGeneratorSaveTest` counts sanitizer invocations: exactly 1 per field on a Data-model save (the model's), exactly 1 per field on a non-Data save (the registry's), never 0; a field the model does not declare is not stored. (T05)
4. **`int` stores negatives** (FR-5). *Attack:* a consumer handler that assumed `absint()` refused negatives now receives them (a quantity, a price). *Mitigation:* T02 greps each D6 consumer's `int`/`integer` fields and their readers for `>= 0` assumptions and reports them (none expected — stride's `signed_int` wanted the opposite); README states the change. (T02, T09)
5. **A type inside a repeater row that cannot render in a cell** (`html`, `relation`, `gallery`, `repeater`). *Attack:* today it silently renders as a text input and stores whatever the text box held. *Mitigation:* `cell = false` types inside `sub_fields` throw at `register()` naming the field and sub-field (SC-3); no `default:` text input exists in the renderer — an unknown control is a `LogicException`. (T03, T06)
6. **The vocabulary is a closed set** (D9). *Attack:* a plugin injects a type whose sanitizer is a no-op. *Mitigation:* no filter, no registration method; `ReflectionClass(NTDST_FieldTypes)` has exactly two public static methods (SC-1). (T01)
7. **A consumer boots on a retired name.** *Attack:* the site fatals at `init` with an unhelpful message. *Mitigation:* the message names the canonical (`Use 'int'.`) and the field; T07/T08 rename every declaration before any consumer updates core; README's migration table has 13 rows (SC-8). (T03, T07, T08, T09)

**Explicitly out of scope:** `select` option validation; `relation` target validation; promoting the josworld YOOtheme bridge; splitting `NTDST_Data_Model`.

## Acceptance flows

Driven at shake-out on daan's DDEV (`chore/core-path-repo`, re-mirrored) through the real edit screen and real HTTP — never direct PHP calls. Edges: empty, denied, re-entry, concurrent, boundary, mid-flow failure.

| # | Flow | Edge | Expected |
|---|---|---|---|
| AF-1 | open the gig edit screen as Administrator | happy path | every declared field renders with its FR-2 control (text, number, checkbox, date, textarea, select, media, relation, gallery, repeater); the `html` field shows the editor |
| AF-2 | save the gig with every field filled | happy path | each value is stored once-sanitized; `wp post meta get` matches; the model's sanitizer ran exactly once per field (debug log count) |
| AF-3 | save with `<script>` in a `text`, a `textarea`, and the `html` field | **hostile** | text/textarea stripped; html keeps `<p>` and drops `<script>` |
| AF-4 | save an `int` field as `-250` via the edit screen, then via `PATCH /wp/v2/gigs/{id}` as Administrator | **boundary** | stored `-250` both ways (FR-5) |
| AF-5 | a repeater row with an `image` sub-field left empty | **empty** | row stored with `''` for that cell, not `0`; the row is kept if any other cell is filled, dropped if all are empty |
| AF-6 | declare a repeater with an `html` sub-field on the daan branch (probe) and load any page | **denied** | `init` throws `InvalidArgumentException` naming the field and sub-field — loud, not a text box |
| AF-7 | declare `'type' => 'integer'` on the daan branch (probe) | **denied** | `Unknown field type 'integer'. Use 'int'.` at `init` |
| AF-8 | `GET /wp-json/wp/v2/gigs/{id}` anonymous before and after the plan | **boundary** | identical `.meta` key set (SC-7) |
| AF-9 | a post type with a metabox but no Data model (daan probe) — save `"false"` in a `bool` | happy path (non-Data) | stored `false` (registry sanitizer), not `"false"` |
| AF-10 | two quick saves of the same gig (double submit) | **concurrent** | the second save stores the same values; no duplicated repeater rows |
| AF-11 | save fails mid-way (a sanitizer throws — simulated by a probe field with a throwing `sanitizer`) | **mid-flow failure** | WordPress's own error; no partial row; the nonce check still ran first |
| AF-12 | josworld YOOtheme builder, a `bool` field after the `TYPE_MAP` rename | happy path (consumer) | the field is bindable in the picker; 0 fields lost |
| AF-13 | stride boots on `v3.0.0` after its rename commit | **re-entry (consumer)** | `composer gate` green; the renamed fields behave as before (3.0 accepts `int`/`bool`/`relation`/`html`) |

## Loop budget

Loop budget: ~13 iterations — 9 tasks plus four review-fix rounds (one per cluster). Not an unattended run: Clusters A–C are high-stakes. If driven by `/loop`, cap at one cluster per wake and stop at every `── REVIEW GATE ──`.

## Sequencing note

T01 (the registry) precedes everything and is the first working version. T02 (consumer ground-truth) runs in the same cluster because its numbers feed T07/T08 and threat row #4, and it touches no core file. T04's test re-pointing is the one place this plan edits core-shape's contract tests (`DataRegistersRestMetaTest`, `DataRestFieldsTest`, `SignedIntFieldTest`): the type-table cases move to `FieldTypesTest`, the structural cases stay — the independent test-author does that re-pointing (split), never the implementer. core-shape T07 starts only after Cluster D's gate closes.
