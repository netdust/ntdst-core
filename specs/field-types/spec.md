# field-types — ntdst-core 5.0.0: one vocabulary, one table, WordPress reads it

**Status:** spec, revision 1 — planning ground-truth 2026-08-22 (Stefan present): FR-8/SC-6 consumer gates run on each site's pinned core (Stefan: "Rename + own gate"); josworld already pins the `5.0.0` tag (`3cc96b7`, pre-core-shape) and lays out as `app/content/themes/josworld/...`; stride pins `v3.0.0` on `staging`; todai declares no ntdst fields. Revision 0 written 2026-08-22 from the brainstorm of the same evening.
**Target release:** v5.0.0 (breaking, unreleased — same major as `core-shape`).
**Relation to `core-shape`:** lands between core-shape Cluster 2 and Cluster 3 —
it rewrites the Cluster 1 code (`restSchemaFor()`, `schemaFor()`,
`registerRestMeta()`'s type table) and `MetaboxGenerator`, which T07 also touches.
Ordering is the other session's call; see `## Sequencing`.
**Invariants:** touches INV-1 (`ARCHITECTURE-INVARIANTS.md`); adds INV-8 (one
vocabulary). The invariants doc is amended by this spec's last task.

---

## Intent decisions

Rulings, each a quote. A change to any of these is a spec revision.

| # | Question | Ruling | Source |
|---|---|---|---|
| D1 | What is `Data.php` for? | **The ORM — `register()` and the query chain. No other public methods.** | Stefan 2026-08-22: "we agreed that data would be orm, nothing else"; "data.php is only used as orm (as it should be, i don't want to expose too many other public methods here)" |
| D2 | The governing rule for core | **Minimal and solid; an API over WordPress; conventions and ease of use; logical for agents; no duct tape.** | Stefan 2026-08-22: "ntdst-core needs to be minimal and very solid, providing an api over wordpress and adding conventions and ease of use for all projects. the code should be logical and easy to understand for agents. no ducktape code here at all" |
| D3 | Aliases in the type vocabulary? | **None. One name per stored shape.** | Stefan 2026-08-22: "we need to clean up that table, no aliases. this should have been picked up by drift reviewers" |
| D4 | The canonical set | **17 names:** `int` `float` `bool` `text` `textarea` `html` `email` `url` `date` `select` `array` `json` `relation` `gallery` `image` `file` `repeater`. `signed_int` folds into a signed `int`; `wysiwyg`/`content` → `html`; `person`/`post_relation` → `relation`. | Stefan 2026-08-22: "1 ok for the 17 names / 2 html / 3 person is a relation field, so relation" |
| D5 | A consumer that still writes an alias? | **Consumers are adapted; the vocabulary throws for anything invented.** | Stefan 2026-08-22: "we need to adapt consumers and throw error for new ones that invent" |
| D6 | Which consumers count | **daan, josworld, todai, stride.** acerta is dead. | Stefan 2026-08-22: "acerta is dead project. only daan, josworld, todai, stride matter now" |
| D7 | `register(string $name, array $config = [])` | **Unchanged.** | Stefan 2026-08-22: "if we can keep the api register(string $name, array $config = []) and still reduce the ducktap, i would be happy" |
| D8 | How far into `MetaboxGenerator` | **Full: its sanitizer goes, its two render switches become one renderer keyed by the registry's control.** | Stefan 2026-08-22: "Q3 A full" |
| D9 | Registry shape | **One value object (`NTDST_FieldType`) and one static table (`NTDST_FieldTypes`), closed set, no filter.** | Stefan 2026-08-22: "i go with recommendation" |
| D10 | YOOtheme | **YOOtheme reads ntdst fields through a site-side bridge today (josworld `YOOthemeSourcesService` + `SchemaMapper` + `FieldResolver`). This spec owes that bridge a public read of each type's shape; promoting the bridge to a shared package is its own spec.** | Stefan 2026-08-22: "yootheme will need to have access to these fields"; "theres a service YOOthemeSourcesService" |

---

## Context — measured 2026-08-22 against `feat/core-shape` @ `6b6b3d6`

The field type is the package's central abstraction. Today it is spelled out in
**eight tables that must agree**, seven in core and one in a consumer:

| # | Table | Where | Names it knows |
|---|---|---|---|
| 1 | type → sanitizer | `api/Data.php:460` `getDefaultSanitizer()` | 21 + 5 aliases |
| 2 | "supported types" error text | `api/Data.php:497` | hand-written; already missing `content`, `integer`, `double`, `boolean` |
| 3 | type → REST JSON schema | `api/Data.php:385` `schemaFor()` | 21 (+ the `default =>` throw that says "the two tables are one vocabulary") |
| 4 | `image`/`file` inside a repeater row | `api/Data.php:636` `sanitizeRepeater()` | 2 |
| 5 | type → admin input | `admin/MetaboxGenerator.php:865` | ~18, incl. `string`, `decimal`, `longtext`, `datetime`, `number` that Data rejects |
| 6 | type → repeater **cell** input | `admin/MetaboxGenerator.php:1547` | 10; no `html`, `bool`, `email`, `relation`, `gallery` — they fall through to `<input type="text">` |
| 7 | type → sanitizer, again | `admin/MetaboxGenerator.php:2040` `sanitize_field()` + nested repeater switch | ~16; `bool` → `(bool)` (Data: `wp_validate_boolean`); `array`/`json` stored as-is (Data: nested-sanitized) |
| 8 | type → GraphQL scalar | josworld `themes/josworld/services/yootheme/SchemaMapper.php` `TYPE_MAP` | 5 (`boolean` among them); everything else omitted |

Live defects the eight tables produce:

- A metabox save sanitizes **twice** (table 7, then `$model->update()` → table 1)
  with two different answers for `bool` and `array`/`json`.
- A `html` (`wysiwyg`) sub-field in a repeater: sanitized as HTML (table 4),
  published as `string` (table 3), rendered as a single-line text box (table 6).
  Same silent fall-through for `bool`, `email`, `relation`, `gallery`,
  `signed_int` in a row.
- `int` is `absint()`; `signed_int` exists only to undo that.
- Adding a type means editing seven places; only table 3's `default =>` fails
  loudly, and only for a field declared `show_in_rest`.

What consumers declare inside `fields` (D6 set; counted 2026-08-22):

| Group | In use | Canonical |
|---|---|---|
| integer | `int` 21 · `integer` 6 (daan 5, stride 1) · `signed_int` 1 (stride) | `int` |
| boolean | `bool` 3 · `boolean` 6 (stride 3, josworld 2, daan 1) | `bool` |
| HTML | `wysiwyg` 1 (daan) | `html` |
| post ids | `relation` 4 · `post_relation` 1 · `person` 1 (both daan) | `relation` |
| everything else | `text` 98 · `textarea` 31 · `select` 11 · `url` 28 · `date` 14 · `json` 11 · `float` 4 · `array` 5 · `repeater` 14 · `email` 6 · `file` 6 · `image` 2 · `gallery` 2 | unchanged |

`Data`'s public surface outside the chain: `getSchema()` (read by
`admin/RelationField.php:157,255` and the josworld bridge), `getMetaPrefix()`,
`restFields()` (read by `register()`), `registerRestMeta()` (read by
`register()`), `restSubFields()` (**0 readers**), `restSchemaFor()` (**0 readers**
— a test seam). The `ntdst/model/registered` action carries the raw `$config`
to the bridge.

`docs/philosophy.md` §1 cites `api/Data.php`'s first line: *"A chain API over
WP_Query plus the CPT/field vocabulary the metabox generator reads. Nothing
else."* The metabox generator does not read it. This spec makes that sentence
true.

---

## Functional requirements

### Phase 1 — the vocabulary has one home

- **FR-1:** `api/FieldTypes.php` defines `final class NTDST_FieldType` — a readonly value object with `name`, `sanitize` (`\Closure(mixed $value, array $config): mixed`), `schema` (`?array`, the REST JSON schema; `null` means never publishable), `control` (`string`, the admin input), `cell` (`bool`, may render inside a repeater row) — and `final class NTDST_FieldTypes` with `public static function get(string $name): NTDST_FieldType` and `public static function names(): array`. The table is built once, privately; it is a closed set of exactly the 17 names in D4. No filter, no registration method: there is no named consumer for an open vocabulary. Establishes INV-8.
  Source: D4, D9 — Stefan 2026-08-22 "ok for the 17 names", "i go with recommendation"
- **FR-2:** The 17 entries, each with its sanitizer, schema and control: `int` → `(int)` cast, signed; refuses arrays to `0` · `['type' => 'integer']` · `number`. `float` → `floatval` · `number` · `number` (step 0.01). `bool` → `wp_validate_boolean` · `boolean` · `checkbox`. `text` → `sanitize_text_field` · `string` · `text`. `textarea` → `sanitize_textarea_field` · `string` · `textarea`. `html` → `wp_kses_post` · `string` · `html` (`cell = false`). `email` → `sanitize_email` · `string, format: email` · `email`. `url` → `esc_url_raw` · `string, format: uri` · `url`. `date` → today's `sanitizeDate` · `string` · `date`. `select` → `sanitize_text_field` of the string · `string` · `select`. `array` → today's `sanitizeNestedArray` (accepts a JSON string as today's `sanitizeJson` does) · `array of string` · `textarea`. `json` → today's `sanitizeJson` · **`null`** · `textarea`. `relation` → list of `absint` ids · `array of integer` · `relation` (`cell = false`). `gallery` → list of `absint` ids · `array of integer` · `gallery` (`cell = false`). `image` / `file` → today's `sanitizeAttachmentId`; in a row an empty pick stores `''` not `0` (today's cell rule) · `integer` · `media`. `repeater` → today's `sanitizeRepeater`, reading `$config['sub_fields']` · the recursive closed-object schema (FR-4) · `repeater` (`cell = false`). Every sanitizer is idempotent (`register_post_meta()` applies it again on write).
  Source: invented — approved 2026-08-22 (brainstorm, design §1 and §2); today's behaviour ground-truthed against `api/Data.php:460–672`, `admin/MetaboxGenerator.php:2040–2160`
- **FR-3:** `NTDST_FieldTypes::get()` with any name outside the 17 throws `InvalidArgumentException`. For the 13 retired names (`integer`, `signed_int`, `double`, `boolean`, `string`, `number`, `decimal`, `longtext`, `wysiwyg`, `content`, `datetime`, `person`, `post_relation`) the message names the canonical: `Unknown field type 'integer'. Use 'int'.` (`datetime` → `date`). For any other name: `Unknown field type 'x'. Known: int, float, …`. No alias map exists anywhere in the package; the retired names appear only in this one message table and README's migration table.
  Source: D3, D5 — Stefan 2026-08-22 "no aliases", "throw error for new ones that invent"

### Phase 2 — Data reads the vocabulary

- **FR-4:** `NTDST_Data_Model` loses `getDefaultSanitizer()`, `sanitizeBoolean()`, `sanitizeJson()`, `sanitizeNestedArray()`, `sanitizeDate()`, `sanitizeAttachmentId()`, `sanitizeRepeater()`, the `schemaFor()` type match, `restSubFields()` and `restSchemaFor()`. `setupSanitizers()` binds `NTDST_FieldTypes::get($type)->sanitize` with the field's config (a declared `sanitizer` callable still overrides, as today). `schemaFor()` keeps only the structural rule — the repeater recursion and the all-or-nothing refusal from core-shape FR-2 — and reads each leaf's shape from `->schema`. The constructor resolves every field **and every `sub_field`** through `get()`, so an unknown name, an alias, or a `cell = false` type inside `sub_fields` throws at `register()` with a message naming the field (`"Field 'provenance' sub-field 'notes': 'html' cannot be a repeater sub-field"`). `Data`'s public surface after: the chain, `getSchema()`, `getMetaPrefix()`, `restFields()`, `registerRestMeta()`. Touches INV-1 (the convergence point is unchanged; `schemaFor()` now asks one table instead of carrying one).
  Source: D1, D7 — Stefan 2026-08-22 "data would be orm, nothing else", "keep the api register()"; core-shape FR-2/FR-3 for the repeater rule
- **FR-5:** `int` stores a signed integer. A negative value written through any path (`create()`, `update()`, metabox, REST) is stored as written. `signed_int` is not a name. This is a storage-behaviour change for every existing `int` field on every site and is stated in README's 5.0.0 section as such.
  Source: D4 — Stefan 2026-08-22 "ok for the 17 names" (the set has one `int`)

### Phase 3 — the metabox reads the vocabulary

- **FR-6:** `NTDST_MetaboxGenerator::sanitize_field()` and its nested repeater switch are deleted. On save of a registered Data model the metabox `wp_unslash`es the posted row and hands it to `$model->update()` / `create()` unsanitized; the model sanitizes once. On save of a post type that has a metabox but no Data model, the metabox applies `NTDST_FieldTypes::get($type)->sanitize` per field (sub-fields included) before `update_post_meta()`. The relation/gallery "not submitted means cleared" rule stays.
  Source: D8 — Stefan 2026-08-22 "Q3 A full"; the double-sanitize defect measured in `## Context`
- **FR-7:** The two render switches (`admin/MetaboxGenerator.php:865` and `:1547`) become one `render_control(string $control, ..., bool $inCell)`; every case is keyed by the registry's `control`, never by a type name. The top-level `select`, `media`, `relation`, `gallery`, `repeater`, `html` renderers are the existing ones, moved. A control with `$inCell` renders without label wrapper and with the row's naming scheme, as the cell variant does today. There is no `default:` text input: an unknown control is a `LogicException`, because `get()` already refused an unknown type at `register()`.
  Source: D8 — Stefan 2026-08-22 "Q3 A full"; design §1 approved

### Phase 4 — consumers, docs, invariants

- **FR-8:** daan, josworld and stride each get one commit on their current branch that renames retired types in every `fields`/`sub_fields` declaration to the canonical (counted per site by the plan's ground-truth task — the first count, 44, predates stride's 61 `integer` + 16 `boolean`), and josworld's `SchemaMapper::TYPE_MAP` (`app/content/themes/josworld/services/yootheme/SchemaMapper.php`) key `boolean` becomes `bool`. Revision 1: each site's gate runs on the core it pins today (stride `v3.0.0`, josworld the `5.0.0` tag) — both accept the canonical names — and only daan proves the `5.0.x-dev` path repo; moving stride/josworld onto 5.0.x-dev is the fleet-migration spec. todai declares no ntdst fields today; its `composer gate` is run to prove nothing else broke. No consumer code is changed beyond the rename.
  Source: D5, D6 — Stefan 2026-08-22 "we need to adapt consumers", "only daan, josworld, todai, stride matter now"
- **FR-9:** README's 5.0.0 section gains a "Field types" migration table (retired name → canonical, one row each, 13 rows), states the `int` sign change (FR-5) and the cell rule (which types cannot be sub-fields), and documents `NTDST_FieldTypes::get()` as the public read for bridges. `docs/philosophy.md` §1's citation of `Data.php`'s first line is updated to the new first line. `ARCHITECTURE-INVARIANTS.md` gains INV-8 — *"The field vocabulary has one table; every reader of a type name asks `NTDST_FieldTypes::get()`"* — with a mechanical check (`grep -rn "case 'text'\|'int', 'integer'\|=> 'absint'" api admin` = 0 hits outside `api/FieldTypes.php`) and a bypass smell (a `match`/`switch` over type names anywhere else). INV-1's text drops its mention of `restSubFields()`.
  Source: D2 — Stefan 2026-08-22 "logical and easy to understand for agents"; the core-shape README-upgrade-guide and invariants-reconciliation pattern

---

## Success criteria

- **SC-1:** Under Brain Monkey, `FieldTypesTest`: for each of the 17 names, `get()` returns an entry whose `sanitize` produces the expected value on 3 inputs (valid, hostile, empty), whose `schema` equals the FR-2 shape, and whose `control` equals the FR-2 control — 17 × 3 sanitize assertions + 17 schema + 17 control; and for each of the 13 retired names, `get()` throws with the canonical in the message — 13 assertions. 0 other public methods on `NTDST_FieldTypes` (ReflectionClass).
- **SC-2:** `grep -rn "case '\(text\|int\|integer\|float\|bool\|boolean\|string\|wysiwyg\|html\)'" api admin` returns 0 lines outside `api/FieldTypes.php`; `grep -c "function sanitize_field\|function getDefaultSanitizer\|function restSubFields\|function restSchemaFor\|signed_int" api/Data.php admin/MetaboxGenerator.php` = 0.
- **SC-3:** Under Brain Monkey, a model declared with `'price' => ['type' => 'int']` stores `-250` from `update()` as `-250` (1 assertion); a model declared with `'n' => 'integer'` throws at construction with a message containing `Use 'int'` (1 assertion); a repeater whose `sub_fields` contains `['type' => 'html']` throws at construction naming the field and the sub-field (1 assertion).
- **SC-4:** Under Brain Monkey, `MetaboxGeneratorSaveTest`: a metabox save of a Data model reaches `update()` with the posted value unchanged except for `wp_unslash` and `update()`'s sanitizer is invoked exactly 1 time per field; a save on a non-Data post type reaches `update_post_meta()` with the registry-sanitized value (`"false"` → `false` for `bool`); 2 test methods minimum.
- **SC-5:** `ReflectionClass(NTDST_Data_Model)` public methods, minus the query chain and CRUD, are exactly `getSchema`, `getMetaPrefix`, `restFields`, `registerRestMeta` — 4 names (pinned by the existing `DataRegistersRestMetaTest` surface assertion, re-pointed).
- **SC-6:** `composer gate` exits 0 in core; in daan against the `5.0.x-dev` path repo; in josworld, stride and todai on the core each pins today (revision 1) — 5 green gates; each consumer's rename commit touches 0 files outside its `fields` declarations and josworld's `SchemaMapper.php`.
- **SC-7:** On daan's DDEV, `GET /wp-json/wp/v2/gigs/{id}` anonymous returns the same declared `meta` keys as before this spec (core-shape SC-1 re-run, 0 keys added or lost), and the gig edit screen renders every declared field with its FR-2 control — 1 screenshot per field group, a `html` top-level field shows the editor.
- **SC-8:** `ARCHITECTURE-INVARIANTS.md` INV-8's mechanical check returns 0 hits at the merge commit; README's "Field types" table has 13 rows.

---

## Security-relevant surfaces

The plan owes a `## Threat model`.

- [x] **Sanitization is the boundary** — every field value on every site passes through table 1; this spec rewrites table 1 and deletes table 7. A wrong closure stores unsanitized markup (`html` → `wp_kses_post` must survive), or a wrong `int` cast. SC-1's hostile input per type is the mitigation; the plan names the hostile input for each of the 17.
- [x] **Anonymous reach of post meta** — unchanged mechanism (core-shape INV-1); the schema facet moves, the opt-in does not. SC-7 re-runs core-shape SC-1 to prove 0 keys gained.
- [x] **Metabox save path** — today's double sanitize is replaced by one; a Data-model save must still be sanitized exactly once and never zero times. SC-4 counts invocations.
- [x] **`int` sign** — a field that relied on `absint()` to refuse negatives (none found in D6 consumers; stride's `signed_int` wanted the opposite) now stores them. Stated in README; the plan's ground-truth task greps each consumer for `int` fields whose handlers assume ≥ 0.
- [ ] None of the above

## User-facing surfaces

The plan owes `## Acceptance flows`.

- [x] **The wp-admin edit screen of every Data model** — every field's control is re-keyed; the repeater row renders through the same renderer; a `html` sub-field that used to render as text now refuses at registration.
- [x] **Consumer sites boot** — a retired name in any declaration fatals at `init` until renamed (FR-8 lands first on each site).
- [x] **YOOtheme builder on josworld** — the bridge's `TYPE_MAP` key rename keeps `bool` fields bindable; 0 fields lost in the picker.
- [ ] None of the above

---

## Sequencing

This spec is independent of core-shape Cluster 2 (Rest) and 3 (Actions), and
collides with core-shape **T07** on `admin/MetaboxGenerator.php` (T07 changes the
relation picker's JS call; this spec changes the renderers and the save path). The
smaller diff should go first; this spec's Phase 3 is the larger one. Recommended:
land this spec's Phases 1–3 after core-shape's Cluster 2 gate and before T07, on
`feat/core-shape`. The other session rules.

---

## Assumptions

One line each; visible at the review gate.

- The metabox posts `array`/`json` fields as a JSON string in a textarea (today's control); the registry's `array` sanitizer accepts a string as `sanitizeJson()` does today. Ground-truthed in the plan.
- `date` keeps today's `sanitizeDate()` semantics (`Y-m-d`); `datetime` had no Data sanitizer and is not carried.
- `select` does not validate the value against `options` — today's behaviour; tightening it is a separate decision.
- Stride declares `'type' => 'string'` 4 times and daan 5 times **outside** `fields` blocks (REST arg schemas); those are not field declarations and are untouched.
- 5.0.0 is unreleased, so no shim, alias or deprecation path is owed to anyone (core-shape assumption, restated).
- Core's suite stays Brain Monkey unit-only; SC-7 is the shake-out's.

---

## Out of scope

- Promoting josworld's YOOtheme bridge (`YOOthemeSourcesService`, `SchemaMapper`, `FieldResolver`) to a shared package — its own spec; the registry's public `get()` is what it will read (D10).
- Splitting `NTDST_Data_Model` (2,600 lines) into chain / CRUD / registration classes — parked; it tidies, it does not fix a behaviour.
- `select` option validation; `relation` target validation at sanitize time.
- An extension point for site-defined types (no named consumer).
- acerta (dead, D6).
- `netdust-core.php`'s `netdust_core_project_repeaters()` (netdust site) — reads `getSchema()`, unaffected by name; superseded by core-shape's `register_post_meta` path in its own migration.
