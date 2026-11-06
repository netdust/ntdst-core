# core-lint-gate — triage ledger

One row per finding of `vendor/bin/phpcs --report=json` with the security-only ruleset (Task 1), grouped by the task that clears it.
`file:line` is the line at the start of the triage (295d28d plus the ruleset change); annotations added later shift lines below them.

- **verdict:** `real` (a hole, fixed with a failing test first) or `false positive`. A finding that cannot be classified is `real`.
- **action:** `fixed` + the test name, or `annotated`.
- **reason:** where the value is escaped, sanitized or prepared (`file:line`), or why it is safe by provenance.

Start: `A TOTAL OF 89 ERRORS AND 20 WARNINGS WERE FOUND IN 12 FILES` · `A TOTAL OF 109 SNIFF VIOLATIONS WERE FOUND IN 13 SOURCES`.

## Task 2 — input, nonce, SQL, compatibility (11)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 1 | `core/LogLevel.php:17` | `PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext` | | | |
| 2 | `core/Pages.php:238` | `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | | | |
| 3 | `core/Pages.php:238` | `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | | | |
| 4 | `admin/MetaboxGenerator.php:1871` | `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | | | |
| 5 | `admin/MetaboxGenerator.php:1890` | `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | | | |
| 6 | `admin/MetaboxGenerator.php:1890` | `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | | | |
| 7 | `admin/MetaboxGenerator.php:2101` | `WordPress.Security.NonceVerification.Recommended` | | | |
| 8 | `admin/RelationField.php:512` | `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | | | |
| 9 | `admin/RelationField.php:529` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | | | |
| 10 | `admin/RelationField.php:529` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | | | |
| 11 | `admin/RelationField.php:529` | `WordPress.DB.PreparedSQL.NotPrepared` | | | |

## Task 3 — `MetaboxGenerator` output (37)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 12 | `admin/MetaboxGenerator.php:358` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 13 | `admin/MetaboxGenerator.php:377` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 14 | `admin/MetaboxGenerator.php:851` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 15 | `admin/MetaboxGenerator.php:852` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 16 | `admin/MetaboxGenerator.php:852` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 17 | `admin/MetaboxGenerator.php:867` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 18 | `admin/MetaboxGenerator.php:961` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 19 | `admin/MetaboxGenerator.php:975` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 20 | `admin/MetaboxGenerator.php:977` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 21 | `admin/MetaboxGenerator.php:978` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 22 | `admin/MetaboxGenerator.php:978` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 23 | `admin/MetaboxGenerator.php:986` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 24 | `admin/MetaboxGenerator.php:987` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 25 | `admin/MetaboxGenerator.php:987` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 26 | `admin/MetaboxGenerator.php:1004` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 27 | `admin/MetaboxGenerator.php:1008` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 28 | `admin/MetaboxGenerator.php:1008` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 29 | `admin/MetaboxGenerator.php:1008` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 30 | `admin/MetaboxGenerator.php:1013` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 31 | `admin/MetaboxGenerator.php:1013` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 32 | `admin/MetaboxGenerator.php:1013` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 33 | `admin/MetaboxGenerator.php:1026` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 34 | `admin/MetaboxGenerator.php:1026` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 35 | `admin/MetaboxGenerator.php:1026` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 36 | `admin/MetaboxGenerator.php:1027` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 37 | `admin/MetaboxGenerator.php:1031` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 38 | `admin/MetaboxGenerator.php:1038` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 39 | `admin/MetaboxGenerator.php:1067` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 40 | `admin/MetaboxGenerator.php:1067` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 41 | `admin/MetaboxGenerator.php:1068` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 42 | `admin/MetaboxGenerator.php:1074` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 43 | `admin/MetaboxGenerator.php:1074` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 44 | `admin/MetaboxGenerator.php:1075` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 45 | `admin/MetaboxGenerator.php:1138` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 46 | `admin/MetaboxGenerator.php:1621` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 47 | `admin/MetaboxGenerator.php:1689` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 48 | `admin/MetaboxGenerator.php:1689` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |

## Task 4 — Bootstrap, Pages, Rest, Response output (23)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 49 | `core/Bootstrap.php:321` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 50 | `core/Bootstrap.php:331` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 51 | `core/Bootstrap.php:420` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 52 | `core/Bootstrap.php:470` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 53 | `core/Bootstrap.php:471` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 54 | `core/Bootstrap.php:472` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 55 | `core/Bootstrap.php:579` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 56 | `core/Bootstrap.php:581` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 57 | `core/Bootstrap.php:593` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 58 | `core/Bootstrap.php:594` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 59 | `core/Bootstrap.php:798` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 60 | `core/Pages.php:306` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 61 | `core/Pages.php:306` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 62 | `core/Pages.php:446` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 63 | `core/Pages.php:506` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 64 | `core/Pages.php:765` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 65 | `core/Pages.php:766` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 66 | `api/Rest.php:1507` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 67 | `api/Rest.php:1508` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 68 | `api/Rest.php:1508` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 69 | `api/Rest.php:1509` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 70 | `api/Response.php:164` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |
| 71 | `api/Response.php:267` | `WordPress.Security.EscapeOutput.OutputNotEscaped` | | | |

## Task 5 — exceptions and database (38)

| # | file:line | sniff | verdict | action | reason |
|---|---|---|---|---|---|
| 72 | `core/Container.php:120` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 73 | `core/Container.php:211` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 74 | `core/Container.php:259` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 75 | `core/Theme.php:169` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 76 | `api/FieldTypes.php:97` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 77 | `api/FieldTypes.php:97` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 78 | `api/FieldTypes.php:102` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 79 | `api/FieldTypes.php:102` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 80 | `api/FieldTypes.php:253` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 81 | `api/FieldTypes.php:259` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 82 | `api/FieldTypes.php:260` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 83 | `api/FieldTypes.php:271` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 84 | `api/FieldTypes.php:272` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 85 | `api/FieldTypes.php:288` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 86 | `api/FieldTypes.php:288` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 87 | `api/FieldTypes.php:288` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 88 | `api/Data.php:447` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 89 | `api/Data.php:447` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 90 | `api/Data.php:447` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 91 | `api/Data.php:1140` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 92 | `api/Data.php:1171` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | | | |
| 93 | `api/Data.php:1216` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 94 | `api/Data.php:1224` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | | | |
| 95 | `api/Data.php:1254` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | | | |
| 96 | `api/Data.php:1287` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | | | |
| 97 | `api/Data.php:1330` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | | | |
| 98 | `api/Data.php:1381` | `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | | | |
| 99 | `api/Data.php:1395` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | | | |
| 100 | `api/Data.php:1452` | `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` | | | |
| 101 | `api/Data.php:1476` | `WordPress.DB.SlowDBQuery.slow_db_query_tax_query` | | | |
| 102 | `api/Data.php:1856` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | | | |
| 103 | `api/Data.php:1856` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | | | |
| 104 | `api/Data.php:1925` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | | | |
| 105 | `api/Data.php:1925` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | | | |
| 106 | `services/RunLog.php:59` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | | | |
| 107 | `services/RunLog.php:59` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | | | |
| 108 | `services/RunLog.php:70` | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | | | |
| 109 | `services/RunLog.php:70` | `WordPress.DB.DirectDatabaseQuery.NoCaching` | | | |
