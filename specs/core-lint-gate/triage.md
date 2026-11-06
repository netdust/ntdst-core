# core-lint-gate — triage ledger

One row per finding of `vendor/bin/phpcs --report=json` with the security-only ruleset (Task 1), grouped by the task that clears it.
`file:line` is the line at the start of the triage (295d28d plus the ruleset change); annotations added later shift lines below them. A `file:line` cited in a **reason** is the source as of the commit that filled that row.

- **verdict:** `real` (a hole, fixed with a failing test first) or `false positive`. A finding that cannot be classified is `real`.
- **action:** `fixed` + the test name, or `annotated`.
- **reason:** where the value is escaped, sanitized or prepared (`file:line`), or why it is safe by provenance.

Start: `A TOTAL OF 89 ERRORS AND 20 WARNINGS WERE FOUND IN 12 FILES` · `A TOTAL OF 109 SNIFF VIOLATIONS WERE FOUND IN 13 SOURCES`.

## Task 2 — input, nonce, SQL, compatibility (11)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 1 | `core/LogLevel.php:17` | `PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext` | false positive | annotated | `match ($this)` inside an instance method of `enum LogLevel` (`core/LogLevel.php:7`): `$this` is the case. PHPCompatibility 9.3.5 predates enums. |
| 2 | `core/Pages.php:238` | `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | false positive | annotated | `REQUEST_METHOD` is only compared byte-exactly (`!==`) to the route's declared verb (`core/Pages.php:245`); any other value, slashed or not, 404s (`NtdstPagesTest::testPathDoesNotMatchADifferentMethod`). Validate: the `!==` compare; sanitize: n/a, never stored or echoed; escape: n/a, never output; authorize: n/a, a public page route by design. |
| 3 | `core/Pages.php:238` | `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | false positive | annotated | Same value and reason as #2, one annotation. |
| 4 | `admin/MetaboxGenerator.php:1871` | `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | false positive | annotated | The nonce is unslashed and only compared (`hash_equals()` inside `wp_verify_nonce()`), never stored or output. The refusal is pinned by `MetaboxGeneratorSaveTest.php:952-961` ('a forged nonce', 'no nonce at all'). Save-flow pillars: validate = nonce `:1872` + declared-field narrowing `:1893-1895`; sanitize = field registry (`api/Data.php:203` for a model, `admin/MetaboxGenerator.php:1937` otherwise); escape = n/a on save (Task 3 covers the render); authorize = `current_user_can('edit_post')` `:1880`. |
| 5 | `admin/MetaboxGenerator.php:1890` | `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | false positive | annotated | `$_POST['ntdst_fields']` is unslashed and narrowed to the declared fields on the next statement (`:1893-1895`); each value is cleaned once by the field registry, in the model (`api/Data.php:203`, INV-8) or directly (`:1937`). Pinned by `MetaboxGeneratorSaveTest::testADataModelFieldIsSanitizedExactlyOnceOnTheWayIn` and `::testANonDataPostTypeStoresTheRegistrySanitizedValueOncePerField`. Pillars as #4. |
| 6 | `admin/MetaboxGenerator.php:1890` | `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | false positive | annotated | Same value and reason as #5, one annotation. |
| 7 | `admin/MetaboxGenerator.php:2101` | `WordPress.Security.NonceVerification.Recommended` | false positive | annotated | `isset($_GET['post'])`, the presence check of the already-annotated display-only read on the next line (`:2104`, `absint(wp_unslash())`); the notice is authorized by `current_user_can('edit_post')` (`:2120`). No state changes, so no nonce. |
| 8 | `admin/RelationField.php:512` | `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | false positive | annotated | `$params` (`:499-509`) carries 1 + count($meta_keys) + 5 values, one per `%s`; the `IN` list's placeholders come from `array_fill('%s')` (`:494`), so the count is only known at run time. Values: `int $post_id`, the declared post type and field name — no request input. |
| 9 | `admin/RelationField.php:529` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | false positive | annotated | No WordPress API expresses a `LIKE` over serialized meta; the query only narrows candidates, verified exactly after it (`:533-`). Not security. |
| 10 | `admin/RelationField.php:529` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | false positive | annotated | An edit-screen reverse lookup must read live rows; not security. |
| 11 | `admin/RelationField.php:529` | `WordPress.DB.PreparedSQL.NotPrepared` | false positive | annotated | `$sql` is the `$wpdb->prepare()` result of `:513`; the sniff cannot follow the variable (`docs/gate.md` read it the same way). |

## Task 3 — `MetaboxGenerator` output (37)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 12 | `admin/MetaboxGenerator.php:358` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$active_class` is the literal `' nav-tab-active'` or `''` (`:356`). |
| 13 | `admin/MetaboxGenerator.php:377` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$display_style` is the literal `''` or `' style="display:none;"'` (`:376`). |
| 14 | `admin/MetaboxGenerator.php:851` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$wrapper_attrs` is the literal `' aria-required="true"'` or `''` (`:848`). Statement-scoped block over `:854-855`. |
| 15 | `admin/MetaboxGenerator.php:852` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$field_id_attr = esc_attr($field_id)` (`:785`). Same block as #14. |
| 16 | `admin/MetaboxGenerator.php:852` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$label_marker` is a literal `<span>` or `''` (`:849-851`). Same block as #14. |
| 17 | `admin/MetaboxGenerator.php:867` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$field_name_attr = esc_attr($field_name)` (`:786`); the stored value is `esc_attr()`'d inline; `LintTriageMetaboxRenderTest` 'readonly text'. |
| 18 | `admin/MetaboxGenerator.php:961` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `$control` is the registry entry's control name (`NTDST_FieldTypes::get()->control`), never request data; the `default` arm is a developer fault no declaration reaches. |
| 19 | `admin/MetaboxGenerator.php:975` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$input_type` is a literal of `render_control()`'s match arms (`:951-956`: the arm keys `text`/`email`/`url`, or `'date'`/`'number'`). Method-scoped block in `control_input()` (`:981-986`). The stored value is `esc_attr()`'d inline (`:983`); `LintTriageMetaboxRenderTest` text/email/url/date/int/float/repeater cell, RED proven by removing that `esc_attr`. |
| 20 | `admin/MetaboxGenerator.php:977` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$class` is a literal of the match arms (`:950-956`, `$line` `:948`). Same block as #19. |
| 21 | `admin/MetaboxGenerator.php:978` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$extra` is `''`, `' step="1"'` or `' step="0.01"'` (`:951-956`). Same block as #19. |
| 22 | `admin/MetaboxGenerator.php:978` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$required_attrs` is `render_control()`'s `$attrs`, the literal `' required aria-required="true"'` or `''` (`:944`). Same block as #19. |
| 23 | `admin/MetaboxGenerator.php:986` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$rows` is `'2'` or `'5'` (`:991`). Statement-scoped block in `control_textarea()` (`:994-997`). Stored value `esc_textarea()`'d inline; test case 'textarea'. |
| 24 | `admin/MetaboxGenerator.php:987` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$class` is a literal (`:992`). Same block as #23. |
| 25 | `admin/MetaboxGenerator.php:987` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$required_attrs` literal (`:944`). Same block as #23. |
| 26 | `admin/MetaboxGenerator.php:1004` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm = esc_attr($input_name)` (`:1012`). Method-scoped block in `control_checkbox()` (`:1014-1025`). No stored string is printed (`$checked` is a literal). |
| 27 | `admin/MetaboxGenerator.php:1008` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$id = esc_attr($field_id)` (`:1011`). Same block as #26. |
| 28 | `admin/MetaboxGenerator.php:1008` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm` (`:1012`). Same block as #26. |
| 29 | `admin/MetaboxGenerator.php:1008` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$checked` is `' checked'` or `''` (`:1010`). Same block as #26. |
| 30 | `admin/MetaboxGenerator.php:1013` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$id` (`:1011`). Same block as #26. |
| 31 | `admin/MetaboxGenerator.php:1013` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm` (`:1012`). Same block as #26. |
| 32 | `admin/MetaboxGenerator.php:1013` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$checked` (`:1010`). Same block as #26. |
| 33 | `admin/MetaboxGenerator.php:1026` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$id = esc_attr($field_id)` (`:1035`). Method-scoped block in `control_select()` (`:1038-1053`). |
| 34 | `admin/MetaboxGenerator.php:1026` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm = esc_attr($input_name)` (`:1036`). Same block as #33. |
| 35 | `admin/MetaboxGenerator.php:1026` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$class` is a literal (`:1033`). Same block as #33. |
| 36 | `admin/MetaboxGenerator.php:1027` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$required_attrs` literal (`:944`). Same block as #33. |
| 37 | `admin/MetaboxGenerator.php:1031` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$selected` is `' selected'` or `''` (`:1043`); option value and label escaped inline. Same block as #33. |
| 38 | `admin/MetaboxGenerator.php:1038` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm` (`:1036`); the stored value is `esc_attr()`'d inline; test case 'readonly select'. Same block as #33. |
| 39 | `admin/MetaboxGenerator.php:1067` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$id = esc_attr($field_id)` (`:1077`). Method-scoped block in `control_json()` (`:1080-1091`). Stored value `esc_textarea()`'d inline; test case 'json'. |
| 40 | `admin/MetaboxGenerator.php:1067` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm = esc_attr($input_name)` (`:1078`). Same block as #39. |
| 41 | `admin/MetaboxGenerator.php:1068` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$required_attrs` literal (`:944`). Same block as #39. |
| 42 | `admin/MetaboxGenerator.php:1074` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$id` (`:1077`). Same block as #39. |
| 43 | `admin/MetaboxGenerator.php:1074` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm` (`:1078`). Same block as #39. |
| 44 | `admin/MetaboxGenerator.php:1075` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$required_attrs` literal (`:944`). Same block as #39. |
| 45 | `admin/MetaboxGenerator.php:1138` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Every `$data_attrs` item is `esc_attr()`'d or a literal where it is built (`:1144-1151`). |
| 46 | `admin/MetaboxGenerator.php:1621` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `$name`, `$sub_field_name`, `$sub_type` are the developer's field declaration, never request data. Statement-scoped block around the `throw` (`:1637-1642`). |
| 47 | `admin/MetaboxGenerator.php:1689` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$id = esc_attr($field_id)` (`:1700`). |
| 48 | `admin/MetaboxGenerator.php:1689` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$nm = esc_attr($input_name)` (`:1701`). |

## Task 4 — Bootstrap, Pages, Rest, Response output (23)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 49 | `core/Bootstrap.php:321` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `_doing_it_wrong()` message; `$sector` is a `services.*` key of `plugin-config.php`. WP >= 6.4: `wp_trigger_error()` kses-filters the message (verified WP 6.8.2 `wp-includes/functions.php:6081,6105`). Statement-scoped block at `:319`. |
| 50 | `core/Bootstrap.php:331` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$sector`, `$entry` a listed `services.*` entry of `plugin-config.php`. Block at `:331`. |
| 51 | `core/Bootstrap.php:420` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$class`, `$sector` from the services list. Block at `:422`. |
| 52 | `core/Bootstrap.php:470` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$slug` is derived from the class name or its `metadata()['name']`, `$class`/`$sector` from the services list. Block at `:474`. |
| 53 | `core/Bootstrap.php:471` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same call as #52. |
| 54 | `core/Bootstrap.php:472` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same call as #52. |
| 55 | `core/Bootstrap.php:579` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$key` is a `services.overrides` key of `plugin-config.php`. Block at `:585`. |
| 56 | `core/Bootstrap.php:581` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same call as #55. |
| 57 | `core/Bootstrap.php:593` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$key` as #55, `gettype()` a PHP type name. Block at `:601`. |
| 58 | `core/Bootstrap.php:594` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same call as #57. |
| 59 | `core/Bootstrap.php:798` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$class` from the services list. Block at `:808`. |
| 60 | `core/Pages.php:306` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | real | fixed — `NtdstPagesTest::testABadTemplatePathIsNamedEscapedInTheWarning` | The bad-path warning's statement (#60/#61 are its two flagged values). `$route['pattern']` is the developer's declaration; the statement keeps its scoped block (`:301`) for it. Fixed with #61. |
| 61 | `core/Pages.php:306` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | real | fixed — `NtdstPagesTest::testABadTemplatePathIsNamedEscapedInTheWarning` (RED: the message held `<script>`) | `$result` is a consumer callback's return (`:280`) and may carry a URL `:param`; it reached the `_doing_it_wrong()` warning raw (A3, `5a32ae1`), safe only by condition (WP >= 6.4 kses). Now `esc_html($result)`; WordPress's `wp_kses()` keeps valid entities, so a plain path reads unchanged. First triaged false positive by condition (reviewer t31); the feature review showed A3's double-escape premise false and Stefan ruled fix (2026-11-06). |
| 62 | `core/Pages.php:446` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `_doing_it_wrong()` message; `$pattern` is the developer's `path()` declaration; WP >= 6.4 kses as #49. Block at `:447`. |
| 63 | `core/Pages.php:506` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$variants[0]['pattern']` is the developer's declaration. Block at `:509`. |
| 64 | `core/Pages.php:765` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same; `$function` is a core method name (`:320`, `:754`). Block at `:771`. |
| 65 | `core/Pages.php:766` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same call; `get_debug_type()` returns a PHP type name. |
| 66 | `api/Rest.php:1507` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `_doing_it_wrong()` in `refuse()`: `$from` is a core method name. Block at `:1506`. WP >= 6.4 kses as #49. |
| 67 | `api/Rest.php:1508` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$why` is core's refusal text about a route DECLARATION (the 24 `refuse()` call sites in `api/Rest.php`), at most with a `callSite()` `file:line`; never request data. |
| 68 | `api/Rest.php:1508` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | Same as #67 (the `sprintf()` branch). |
| 69 | `api/Rest.php:1509` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$since` is a version literal of the call sites. |
| 70 | `api/Response.php:164` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | `$status` is `int` by signature, printed with `%d`. Block at `:162`. |
| 71 | `api/Response.php:267` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | false positive | annotated | A file-download body after `fileHeaders()` (`:363`; `send_nosniff_header()` `:343`, `Content-Disposition` `:350`): bytes, not HTML; escaping would corrupt the file. |

## Task 5 — exceptions and database (38)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 72 | `core/Container.php:120` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `$chain` is the container ids being resolved in `resolve()` (`:116`): class names and ids in code, never request data. |
| 73 | `core/Container.php:211` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `$class` is a container id / class name in `resolveClass()` (`:206`). |
| 74 | `core/Container.php:259` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Parameter, type and class names come from Reflection of code. |
| 75 | `core/Theme.php:169` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `$arrayKey` is one of the literal keys of the `foreach` above it. Statement-scoped block at `:168`. |
| 76 | `api/FieldTypes.php:97` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `get()` (`:87`): `$name` is a DECLARED field type — every caller reads a model or metabox declaration (`admin/MetaboxGenerator.php:817,1417,1629,1957,1980`, `api/Data.php:445,1827`); the retired/known lists are the class's own tables. Block at `:95`. |
| 77 | `api/FieldTypes.php:97` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same throw as #76 (`self::RETIRED[$name]`). |
| 78 | `api/FieldTypes.php:102` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same block as #76. |
| 79 | `api/FieldTypes.php:102` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same block as #76 (`implode()` of the table's keys). |
| 80 | `api/FieldTypes.php:253` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Sub-field validation: `$where`, `$name`, `$key`, `$seen`, `$type` all come from the repeater's `sub_fields` declaration. Loop-body block at `:253`. |
| 81 | `api/FieldTypes.php:259` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same block as #80. |
| 82 | `api/FieldTypes.php:260` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same block as #80. |
| 83 | `api/FieldTypes.php:271` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same block as #80. |
| 84 | `api/FieldTypes.php:272` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same block as #80. |
| 85 | `api/FieldTypes.php:288` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `entry()` (`:287`) rethrows `get()`'s message (#76) prefixed with `$where`, the declaration's own name. One-line ignore at `:292`. |
| 86 | `api/FieldTypes.php:288` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same as #85. |
| 87 | `api/FieldTypes.php:288` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same as #85. |
| 88 | `api/Data.php:447` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | `typeFor()` (`:442`) rethrows `NTDST_FieldTypes::get()`'s message prefixed with the model's field declaration. |
| 89 | `api/Data.php:447` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same as #88. |
| 90 | `api/Data.php:447` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | false positive | annotated | Same as #88. |
| 91 | `api/Data.php:1140` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | real | fixed — `LintTriageOutputTest::testAnUnknownScopeIsNamedEscaped` (RED: the message held `<script>`) | `scope($name)` (`:1134`): `$name` is the caller's argument and may carry request input a consumer forwards; it reached the exception message raw, shown by `display_errors` and unescaped when `html_errors` is off. Now `esc_html($name)`. First triaged false positive by condition (reviewer t31); Stefan ruled fix (2026-11-06). |
| 92 | `api/Data.php:1171` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | false positive | annotated | By design: `where()` (`:1156`) is the data layer's meta query door; `WP_Query` runs it. Performance, not security. |
| 93 | `api/Data.php:1216` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | real | fixed — no RED possible, so no test | `whereNot($field)` (`:1191`): now `esc_html($field)`, as Stefan ruled for all five (2026-11-06). The message is reached only when `in_array($field, $core_fields)` (`:1200`) holds, so `$field` is always one of twelve literal core column names and no hostile value can reach it: a RED-first test cannot be written, and none is claimed. Defence in depth; counted `real` because the ruling moved all five. |
| 94 | `api/Data.php:1224` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | false positive | annotated | By design: `whereNot()` (`:1191`). As #92. |
| 95 | `api/Data.php:1254` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | false positive | annotated | By design: `whereIn()` (`:1251`). As #92. |
| 96 | `api/Data.php:1287` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | false positive | annotated | By design: `whereNotIn()` (`:1285`). As #92. |
| 97 | `api/Data.php:1330` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | false positive | annotated | By design: `whereMissing()` (`:1331`). As #92. |
| 98 | `api/Data.php:1381` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | real | fixed — `LintTriageOutputTest::testAnUnknownGroupRelationIsNamedEscaped` (RED: the message held `<SCRIPT>`) | `whereGroup($relation)` (`:1379`): the caller's argument, uppercased, reached the exception message raw. Now `esc_html($relation)`. First triaged false positive by condition (reviewer t31); Stefan ruled fix (2026-11-06). |
| 99 | `api/Data.php:1395` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | false positive | annotated | By design: `whereGroup()` (`:1379`). As #92. |
| 100 | `api/Data.php:1452` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` | false positive | annotated | By design: `orderBy()` (`:1429`) on a meta field is the data layer's meta ordering. |
| 101 | `api/Data.php:1476` | `WordPress.DB.SlowDBQuery.slow_db_query_tax_query` | false positive | annotated | By design: `whereTax()` (`:1479`) is the data layer's taxonomy query door. |
| 102 | `api/Data.php:1856` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | false positive | annotated | `readPostMeta()` (`:1848`): the cold-cache fallback behind core's `post_meta` cache (`:1850`); prepared with `%d`. Ignore at `:1862`. |
| 103 | `api/Data.php:1856` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | false positive | annotated | Same; caching it would be a second cache core does not invalidate (T04). |
| 104 | `api/Data.php:1925` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | false positive | annotated | `readPostTerms()` (`:1887`): the cold-cache fallback behind core's `{$taxonomy}_relationships` cache (`:1900`); prepared with `%d`. Ignore at `:1932`. |
| 105 | `api/Data.php:1925` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | false positive | annotated | Same as #103. |
| 106 | `services/RunLog.php:59` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | false positive | annotated | `acquireLock()` (`:55`): `SELECT GET_LOCK()`, a MySQL advisory lock — touches no table. |
| 107 | `services/RunLog.php:59` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | false positive | annotated | A lock answer must never be cached. |
| 108 | `services/RunLog.php:70` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | false positive | annotated | `releaseLock()` (`:67`): `SELECT RELEASE_LOCK()`, touches no table. |
| 109 | `services/RunLog.php:70` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | false positive | annotated | Never cacheable. |

## Totals

109 findings: **5 real, fixed** (#60/#61 — one statement, #91, #93, #98) and **104 false positives, annotated** by provenance or design.
The five were first triaged false positive by condition (reviewer t31); the feature review (`review.md`, Should fix 2, 3) and Stefan (2026-11-06) moved them to fixed: `esc_html()` on the caller-influenced value. Tests: `NtdstPagesTest::testABadTemplatePathIsNamedEscapedInTheWarning` (#60/#61), `LintTriageOutputTest::testAnUnknownScopeIsNamedEscaped` (#91), `::testAnUnknownGroupRelationIsNamedEscaped` (#98), each RED first; #93 has no test, because no hostile value can reach its message (see its row).
**Compensating control:** the method-scoped `phpcs:disable` blocks in `admin/MetaboxGenerator.php` (`control_input`, `control_textarea`, `control_checkbox`, `control_select`, `control_json`) also cover the lines where the stored value is escaped inline, so phpcs no longer watches those calls. `LintTriageMetaboxRenderTest` is what does: it fails if any of them stops escaping. It must not be pruned.
End: `composer lint` exit 0, `errors=0 warnings=0` (`vendor/bin/phpcs --report=json`).
