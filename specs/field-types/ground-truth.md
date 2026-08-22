# field-types T02 — consumer ground-truth: retired-name counts and the int-sign audit

Measured 2026-08-22 against the four D6 consumers. Every count below is
reproduced by re-running the recorded grep at the recorded sha. Scope
excludes `vendor/`, any `ntdst-core`/`ntdst-baseline` mirror directory
(the path-repo copy of this package inside each consumer), and `tests/`.

Canonical mapping used below (FR-3, D4): `integer`→`int` · `signed_int`→`int`
· `double`→`float` · `boolean`→`bool` · `wysiwyg`→`html` · `content`→`html`
· `person`→`relation` · `post_relation`→`relation` · `datetime`→`date`.
(`string`, `number`, `decimal`, `longtext` are in the 13-name retired list
but no hit below is one of them; `themes/` directories on daan and stride were also grepped — 0 hits — — they map `string`→`text`, `number`→`int` (the registry's message table: the metabox rendered `number` as a step-1 integer),
`decimal`→`float`, `longtext`→`textarea` per FR-2/D4 if ever found.)

---

## daan

- Branch: `chore/core-path-repo`
- HEAD: `2ca6a9606cee19bcc8e345251083108365ab5f93`
- Grep (retired names, all `'type' =>` hits):
  ```
  cd ~/Sites/daan && grep -rnE "'type'\s*=>\s*'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation)'" web/app/mu-plugins --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/"
  ```

### Field declarations (inside `fields`/`sub_fields`, to rename)

| file:line | retired | canonical |
|---|---|---|
| `web/app/mu-plugins/daan-core/services/musician/ProfileService.php:66` | `wysiwyg` | `html` |
| `web/app/mu-plugins/daan-core/services/musician/PressKitService.php:1720` | `integer` | `int` |
| `web/app/mu-plugins/daan-core/services/musician/DiscographyService.php:153` | `integer` | `int` |
| `web/app/mu-plugins/daan-core/services/musician/ProjectService.php:346` | `integer` | `int` |

**Count: 4.** Each verified inside a `private function getFields(): array`
whose return value is passed as `'fields' => $this->getFields()` to
`->registerDataModel()` / `->register()` (confirmed by reading the enclosing
method for each hit).

### Non-field hits (untouched) — 2, both false positives against the plan's earlier "person 1, post_relation 1" count

| file:line | hit | reason |
|---|---|---|
| `web/app/mu-plugins/daan-core/traits/AdminTableFiltersTrait.php:23` | `post_relation` | inside a PHPDoc `@example`/usage comment block for `AdminTableFiltersTrait::getFilterConfig()` (admin table column filters) — not code, not a Data field |
| `web/app/mu-plugins/daan-core/daan-core.php:152` | `person` | schema.org `Person` JSON-LD type marker in the `ntdst/baseline/schema/config` filter (SEO structured data) — same string as a retired ntdst field name, unrelated vocabulary |

**Ground-truth correction:** the plan's "Refuted as a total" row (plan.md
line 88) recorded daan as `integer 3, person 1, post_relation 1, wysiwyg 1`
— that earlier grep did not distinguish comment/schema.org hits from real
field declarations. Real daan field-declaration count is **4**, not 6; the
`person` and `post_relation` hits are not fields at all and need no rename.

### Int-sign audit

Int-fields grep:
```
cd ~/Sites/daan && grep -rnE "'type'\s*=>\s*'(int|integer|signed_int)'" web/app/mu-plugins --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/"
```

| field | file:line | readers checked | `>= 0` assumption? |
|---|---|---|---|
| `content_id` | `AccessGrantService.php:1912` | `AccessGrantService.php:1249,1338,1353,1507,1590,1645,1656,1751,1785,1858` — all plain `(int)` casts or chain `->where()`/`->whereIn()` | No |
| `access_count` | `AccessGrantService.php:1922` | `AccessGrantService.php:554,1314,1509` — `(int)` cast, `+1` increment, `esc_html((string)(int)...)` display | No |
| `download_count` (`PressKitService::DOWNLOAD_COUNT_FIELD`) | `PressKitService.php:1719` | `PressKitService.php:396-397,1544,1576` — `(int)` cast, `+1` increment, display | No |
| `display_order` | `DiscographyService.php:153` | `DiscographyService.php:190` (`'fields' => ['featured','display_order']`, read-back for admin table) — no cast/clamp beyond the field's own type | No |
| `display_order` | `ProjectService.php:346` | `ProjectService.php:398,440` (`orderBy('display_order','ASC',true)`) — sort key, no clamp | No |

**5 int fields audited, 0 `>= 0` assumptions found.**

---

## josworld

- Branch: `chore/ntdst-core-4`
- HEAD: `e3ce47d0833abcdea6f59355f7a484c16c01fc4d`
- Grep (retired names, own code only — excludes the vendored YOOtheme
  builder package `themes/yootheme/` and third-party plugins, neither of
  which is josworld's own field declaration):
  ```
  cd ~/Sites/josworld && grep -rnE "'type'\s*=>\s*'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation)'" app/content/mu-plugins app/content/themes/josworld --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/"
  ```

### Field declarations (inside `fields`/`sub_fields`, to rename)

| file:line | retired | canonical |
|---|---|---|
| `app/content/mu-plugins/josworld-core/services/content/CaseService.php:113` | `boolean` | `bool` |
| `app/content/mu-plugins/josworld-core/services/content/CaseService.php:150` | `boolean` | `bool` |

**Count: 2** (`has_takeaways`, `has_interview`) — verified inside
`CaseService::getFields()` / `->register()`, matching the "2 (stride 3,
josworld 2, daan 1)" split the spec's `## Context` boolean row already had
right for josworld.

### Non-field hits (untouched)

None inside josworld's own code (mu-plugins + `themes/josworld`). The wider
`app/content` tree (excluded above) carries hundreds of `'type' => 'number'`
/ `'datetime'` hits inside `themes/yootheme/packages/builder-wordpress*` —
the third-party YOOtheme builder framework's own GraphQL/element schemas,
never touched by this spec and outside the D6 field vocabulary entirely.

### josworld `TYPE_MAP`

- File: `app/content/themes/josworld/services/yootheme/SchemaMapper.php`
- Key: `boolean` (line 60, inside the `private const TYPE_MAP = [...]` array
  at line 55) — becomes `bool` per FR-8.

### Int-sign audit

Int-fields grep:
```
cd ~/Sites/josworld && grep -rnE "'type'\s*=>\s*'(int|integer|signed_int)'" app/content/mu-plugins app/content/themes/josworld --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/"
```
Result: **0 hits.** josworld declares no `int`/`integer`/`signed_int` field.
Nothing to audit.

---

## stride

- Branch: `staging`
- HEAD: `47c23e2216ca22069cef67da867df16742321291`
- Grep (retired names, all `'type' =>` hits):
  ```
  cd ~/Sites/stride && grep -rnE "'type'\s*=>\s*'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation)'" web/app/mu-plugins --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/"
  ```
  Total: **110 hits** (matches the plan's "~110" estimate exactly): 61
  `integer`, 32 `string`, 16 `boolean`, 1 `signed_int`.

### Field declarations (inside `fields`/`sub_fields`, to rename) — 16

`web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php`
(`'fields' => self::getFields()` at line 45) — 8 `boolean` → `bool`:

| line | field |
|---|---|
| 180 | `requires_approval` |
| 185 | `requires_questionnaire` |
| 190 | `requires_documents` |
| 205 | `requires_session_selection` |
| 210 | `selection_open` |
| 215 | `post_requires_evaluation` |
| 220 | `post_requires_documents` |
| 235 | `post_requires_approval` |

`web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php`
(`'fields' => self::getFields()` at line 42) — 6 `boolean` → `bool`:

| line | field |
|---|---|
| 156 | `requires_approval` |
| 166 | `requires_questionnaire` |
| 171 | `requires_documents` |
| 181 | `post_requires_evaluation` |
| 186 | `post_requires_documents` |
| 196 | `post_requires_approval` |

`web/app/mu-plugins/stride-core/Modules/Edition/SessionCPT.php`
(`'fields' => self::getFields()` at line 32) — 1 `boolean` + 1 `signed_int`:

| line | field | retired | canonical |
|---|---|---|---|
| 96 | `optional` | `boolean` | `bool` |
| 101 | `price_modifier` | `signed_int` | `int` |

8 + 6 + 2 = **16.**

### Non-field hits (untouched) — 94

| file | count | reason |
|---|---|---|
| `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` | 59 | `register_rest_route(...)`'s `'args' => [...]` REST arg schemas (verified: file has no `fields`/`sub_fields` Data declaration; its two `'fields'` keys at :2463/:2643 are plain response-projection string arrays with no nested `type`) |
| `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php` | 26 | `wp_register_ability(...)`'s `'input_schema'`/`'output_schema'` JSON schemas (AI tool abilities), not Data fields |
| `web/app/mu-plugins/stride-core/Modules/Assistant/WriteAbilityRegistrar.php` | 8 | same — `wp_register_ability(...)` `'input_schema'` |
| `web/app/mu-plugins/stride-core/Modules/User/DashboardPageMetabox.php` | 1 | `register_post_meta('page', ...)`'s `show_in_rest.schema.items` — the `page` CPT is explicitly NOT ntdst-Data-registered (file docblock: "the `page` CPT is NOT ntdst_data-registered, so no `_ntdst_` prefix applies") |

59 + 26 + 8 + 1 = **94.** 16 field hits + 94 non-field hits = **110**,
matching the total grep count exactly — every hit is accounted for.

This also refutes the assumption line in spec.md ("Stride declares
`'type' => 'string'` 4 times ... outside `fields` blocks"): the real count
of stride's non-field `'string'` hits is 32 (all inside `AdminAPIController`
REST `args`), not 4 — the spec's assumption pre-dates a full grep of the
59-hit REST controller.

### Int-sign audit — 21 fields

Int-fields grep:
```
cd ~/Sites/stride && grep -rnE "'type'\s*=>\s*'(int|integer|signed_int)'" web/app/mu-plugins --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/"
```

| field | file:line | readers checked | `>= 0` assumption? |
|---|---|---|---|
| `course_id` | `EditionCPT.php:84` | `EditionRepository.php:809-810,888`, `EditionService.php:632,814` — plain `(int)` casts / query arg | No |
| `capacity` (Edition) | `EditionCPT.php:98` | `EditionAdminController.php:557` — `'int' => absint($fields[$field])` in the basic-fields save loop | **Yes** — save path clamps to `>= 0` via `absint()` |
| `completion_threshold` | `EditionCPT.php:170` | `EditionCompletion.php:106` (`$threshold ? (int) $threshold : 100`), `:272` (`updateMeta` plain int) | No |
| `edition_id` (Session) | `SessionCPT.php:41` | reads via `getField()`/chain, no absint/clamp found | No |
| `capacity` (Session) | `SessionCPT.php:91` | `EditionAdminController.php:1443` — `sanitizeSessionData()`: `'capacity' => absint($input['capacity'] ?? 0)` | **Yes** |
| `price_modifier` (`signed_int`) | `SessionCPT.php:101` | `EditionSessionsMetabox.php:157`, `session-row.php:44`, `QuoteService.php:285`, `SessionService.php:310,360`, `EditionAdminController.php:1449-1451` — every reader is a plain `(int)`/`round()` cast, **no** absint/clamp anywhere | **No — confirmed negatives pass through unclamped**, matching the spec's/plan's stated exception ("stride's `signed_int` wanted negatives") |
| `capacity` (Trajectory) | `TrajectoryCPT.php:80` | `TrajectoryAdminController.php:1200` — `'capacity' => absint($fields['capacity'])` | **Yes** |
| `deadline_months` | `TrajectoryCPT.php:141` | `TrajectoryAdminController.php:1241` — `absint($fields['deadline_months']) ?: null` | **Yes** |
| `user_id` | `QuoteCPT.php:43` | `QuoteAdminController.php:626` — `absint($fields['user_id'] ?? 0)` | **Yes** |
| `registration_id` | `QuoteCPT.php:48` | `QuoteService.php:78,133` — plain `(int)` cast; request-param uses elsewhere (`EditionAdminController.php:1063` etc.) `absint()` the URL param, not the stored meta value | No (on the stored field itself) |
| `edition_id` (Quote) | `QuoteCPT.php:52` | `QuoteAdminController.php:627` — `absint($fields['edition_id'] ?? 0)` | **Yes** |
| `subtotal` | `QuoteCPT.php:70` | `QuoteCalculator::deriveTotalsFromCents()` (`Helpers/QuoteCalculator.php:69-70`) — input passed through unchanged (not clamped itself) | No |
| `discount` | `QuoteCPT.php:74` | `QuoteCalculator.php:69` — `min(max(0,$discountCents), max(0,$subtotalCents))`, docblock: "clamped to `[0, subtotal]` ... identical to what the cents-level write paths persist" | **Yes** |
| `tax` | `QuoteCPT.php:78` | `QuoteCalculator.php:70-71` — derived from the clamped taxable base, `max(0, ...)` | **Yes** |
| `total` | `QuoteCPT.php:82` | `QuoteCalculator.php:73` — `$taxableCents + $taxCents`, both `>= 0` by construction above | **Yes** |
| `voucher_redeemed_user_id` | `QuoteCPT.php:100` | `QuoteService.php:608,709` — plain `(int)` cast | No |
| `discount_value` | `VoucherCPT.php:61` | `VoucherAdminController.php:496-503` — `(int) round($value * 100)` or `(int) $value`, no absint | No |
| `usage_limit` | `VoucherCPT.php:66` | `VoucherAdminController.php:483` — `absint($fields['usage_limit'])` | **Yes** |
| `used_count` | `VoucherCPT.php:72` | `VoucherAdminController.php:543` — `absint($fields['used_count'])`; `VoucherService.php:335` — `max(0, ((int)($voucher['used_count'] ?? 0)) - 1)` on decrement | **Yes** |
| `edition_id` (Voucher) | `VoucherCPT.php:87` | `VoucherAdminController.php:523` — `absint($fields['edition_id'])` | **Yes** |
| `created_by` | `VoucherCPT.php:125` | `VoucherAdminController.php:546` — `absint($fields['created_by'])` | **Yes** |

**21 int fields audited. 12 have a `>= 0` assumption in a reader/writer
(`absint()` or `max(0, ...)`): `capacity` ×2, `completion_threshold`: no,
`deadline_months`, `user_id`, `edition_id` ×2, `discount`, `tax`, `total`,
`usage_limit`, `used_count`, `created_by`.** These are all **ID or
count/money fields where positivity is the intended domain rule** (a
capacity, a usage limit, a discount in cents) — this contradicts the plan's
threat-row-#4 premise of "none expected": stride's admin controllers apply
their own `absint()`/`max(0,...)` on top of what `int`'s new signed cast
will allow, so for these specific fields the FR-5 sign change is **masked**
by a consumer-owned sanitizer that still clamps — the negative would have to
come from a path that bypasses these controllers (e.g. a raw REST PATCH) to
surface. `price_modifier` is the one field that was deliberately built
`signed_int` and has zero clamps anywhere — confirmed by direct read of
every reader.

---

## todai

- Branch: `master`
- HEAD: `875f711573998ae56e11a83eb70d37bc9a690006`
- Grep:
  ```
  cd ~/Sites/todai && grep -rlE "'type'\s*=>\s*'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation|int|bool)'" --include="*.php" . 2>/dev/null | grep -vE "vendor/|node_modules/"
  ```
  Result: **0 hits.** Confirmed further: no `composer.json`, no `ntdst`
  string anywhere in the repo (`grep -rl "ntdst" --include="*.php" .` →
  empty); `web/` is a static site (`index.html`, no WordPress). todai is not
  presently an ntdst-core consumer at all — 0 field declarations, 0 int
  fields, nothing to rename or audit.

---

## Totals

| Site | Field hits to rename | Non-field hits (untouched) | Int fields audited | `>= 0` assumptions found |
|---|---|---|---|---|
| daan | 4 | 2 | 5 | 0 |
| josworld | 2 | 0 (excl. 3rd-party YOOtheme package) | 0 | 0 |
| stride | 16 | 94 | 21 | 12 (all ID/count/money fields; `price_modifier` confirmed clamp-free) |
| todai | 0 | 0 | 0 | 0 |
| **Total** | **22** | **96** | **26** | **12** |

Reproduction: the reviewer re-runs any two of the six grep commands above at
the recorded sha and gets the same line counts.

---

## Test evidence

- Tier: B — a report; no code path.
- Contract test author: self — solo mode (plan: `Test-author: solo`).
- Test file(s): none — Tier B.
- RED proof: `no unit test: Tier B, report`.
- Weakened? n/a — self-authored (solo mode); no test to weaken.
- GREEN proof (two of the recorded greps, re-run):
  ```
  $ cd ~/Sites/daan && grep -rnE "'type'\s*=>\s*'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation)'" web/app/mu-plugins --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/" | wc -l
  6
  $ cd ~/Sites/stride && grep -rnE "'type'\s*=>\s*'(integer|signed_int|double|boolean|string|number|decimal|longtext|wysiwyg|content|datetime|person|post_relation)'" web/app/mu-plugins --include="*.php" | grep -vE "vendor/|ntdst-core/|ntdst-baseline/" | wc -l
  110
  ```
- Seam test: n/a — not a wiring task (report only, no code touched).
- Suite delta: n/a — no code changed, no test suite affected.
- Typecheck: n/a — no code changed (this file is Markdown).
- Deferral: Risk this does NOT cover: cross-actor — the 12 stride `>= 0`
  clamps found here are reported, not fixed; T09 (README) and T07/T08
  (consumer renames) are where the sign-change wording and the actual
  renames land. → integration-gate (T07/T08/T09 review the rename commits
  and README wording against this report's counts).
