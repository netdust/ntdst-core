#!/usr/bin/env bash
# bin/guard.sh — assert every shipped PHP file exits on a direct web hit.
#
# Every .php file (excluding vendor/ and tests/bootstrap.php) must carry the
# `defined('ABSPATH') || exit;` guard so a direct web request 404s instead of
# executing framework code outside WordPress. tests/bootstrap.php is exempt
# because it runs BEFORE ABSPATH exists — it carries the CLI guard instead
# (`PHP_SAPI === 'cli' || exit;`), asserted separately below.
set -euo pipefail
cd "$(dirname "$0")/.."

BAD=$(grep -rL "defined('ABSPATH')" --include='*.php' . | grep -v vendor | grep -v tests/bootstrap.php || true)
if [ -n "$BAD" ]; then
    echo "Missing ABSPATH guard:"
    echo "$BAD"
    exit 1
fi

# Symbols removed across v3.0.0 and v5.0.0. A deleted symbol with a surviving
# caller is a RUNTIME fatal that no other gate step can see: `composer lint` is
# php -l (syntax only, never resolves a name) and the unit suite does not load
# every shipped file. Cluster A shipped two such fatals with a 155/155 green
# suite — admin/RelationField.php called ntdst_api_action(), and core/Theme.php
# guarded on ntdst_router() so a required mixin silently stopped registering.
# This grep is what makes the next rename fail loudly instead.
#
# THE ROWS ARE PackageBootIntegrityTest::removedSymbolProvider()'s, by hand.
# Read that provider when you add one: the shape of a row — bare word, call
# shape `name(`, declaration position — is decided there, with the reason, and
# a row spelled differently here sweeps something the test does not. This copy
# exists because the gate has to fail on a fresh checkout with no vendor/ and
# no PHP test run, and the two lists being the same list is the property an
# audit checks. Six names were behind the provider when the invariant auditor
# last compared them (getServices, NTDST_SectorRegistry, ntdst_sectors,
# ntdst_endpoints, MARKER_ONLY_REQUIRED_TYPES, render_repeater_media_cell);
# deriving this pattern FROM the provider is the parked fix.
#
# v3.0.0: the v2 routing facades.
# v5.0.0: the NTDST_Rest surface registry — WordPress's get_routes() is the
#         registry now — and the test file that asserted on it. The removed
#         METHOD is pinned as the three ways PHP can reach it (::surface(,
#         ->surface(, $surface) rather than as the bare word: this codebase
#         writes "the exposure surface" in prose, and a grep for `surface`
#         would fail on a sentence.
# v5.0.0 field-types: the model's own sanitizer table and its six helpers. Each
#         was a SECOND vocabulary that could disagree with the first (INV-8);
#         NTDST_FieldTypes::get() is the table now. `signed_int` is a retired
#         TYPE NAME, not a symbol — shipped code may not declare a field with
#         it, and ONE LINE of api/FieldTypes.php is exempted for that one word
#         because its RETIRED table has to spell it out in order to answer
#         "use 'int'". The exemption anchors to that ROW — `'signed_int' =>
#         'int',` — and not to the file: exempting the file would let a real
#         `new NTDST_FieldType('signed_int', …)` back in beside the row that
#         retires it, in the one file where nobody would look for it.
#         (Same line-anchored exemption PackageBootIntegrityTest applies, which
#         pins these rows per symbol.)
# v5.0.0 field-types: the OTHER twelve retired type names, pinned by
#         DECLARATION POSITION rather than bare. `signed_int` is a distinctive
#         token and stays pinned as the bare word. The other twelve are ordinary
#         JSON-Schema and English words — a bare sweep hits 617 shipped lines,
#         and api/FieldTypes.php legitimately writes `['type' => 'integer']` as
#         the publish column of a LIVE type. What no shipped line may do is
#         DECLARE a field with a retired name, and a declaration has three
#         shapes: `'type' => '<retired>'`, the bare shorthand `=> '<retired>'`,
#         and `new NTDST_FieldType('<retired>'`. ONE exemption follows, stated
#         by CONTENT — a retirement entry, or a registry entry's JSON-Schema
#         leaf. It anchors to the ROW and never to the file: a real
#         `new NTDST_FieldType('integer', …)` still fires inside the very file
#         that retires the name.
# v5.0.0 field-types: the metabox's own sanitize_field() and its nested
#         sub-field switch — the SECOND vocabulary on the write side. The
#         edit screen now unslashes and hands on: a Data model cleans inside
#         update(), a post type without one goes straight to
#         NTDST_FieldTypes::get(). Pinned as `sanitize_field(` with the paren,
#         not bare: the name is a plausible one for a future unrelated helper,
#         and the CALL is what would fatal.
# v5.0.0 field-types: restSubFields() and restSchemaFor() — the two PUBLIC
#         reads of the field description, 0 shipped readers. What a field
#         publishes is asked once, by registerRestMeta() through the private
#         schemaFor(); a second public way to ask it is a second exposure a
#         consumer can assemble beside the convergence point (INV-1). Neither
#         name is part of any other word, so both are pinned bare.
# v5.0.0 core-trim: Bootstrap's service scanner and the two config keys that
#         armed it (FR-1 / INV-10). The scanner globbed `*Service.php` under
#         `services.discovery_paths`, `require_once`d every hit and regex-parsed
#         the source for its class name, and `registerService()` turned a class
#         name into a file path — a writable directory on that list was code
#         execution. Core resolves a listed name with `class_exists()` now, or
#         refuses it loudly.
#         The two KEYS are swept beside the two methods, and that pairing is
#         the point: deleting the methods while a shipped line still reads
#         `$config['services']['auto_discover']` leaves the switch half-alive —
#         a key core consults and then does nothing with is "loads nothing by
#         guessing" read back as a maybe. A consumer config may still CARRY
#         both keys; core simply never reads them (AF-4). This sweep is over
#         what the PACKAGE ships, not over what a site writes.
#         `discoverServicesInPath` needs no term: `discoverServices` is a
#         substring of it. All four are pinned bare — no other word contains
#         them — which mirrors PackageBootIntegrityTest's rows exactly.
# v5.0.0 core-trim: the per-service enable switch and the four read-only copies
#         of the service registry (FR-2). `ntdst_service_` is swept as a
#         PREFIX, and that is the point: it is the shared stem of the retired
#         option `ntdst_service_{slug}`, the retired DENY filter
#         `ntdst_service_{slug}_enabled` and the retired config filter
#         `ntdst_service_{slug}_config`, and every one of them is interpolated
#         (`"ntdst_service_{$slug}_enabled"`), so a row per full name would
#         match nothing. The stem is the only shape a sweep can see. The enable
#         switch failed OPEN — a filter nobody answers returns true — so a
#         half-removal that leaves one interpolation behind is a service a site
#         believes is off and is not.
#         getServiceConfig / getBootedServices / hasService / isBooted had zero
#         readers across daan, josworld, stride, todai and netdust: a second,
#         read-only copy of the registry that could disagree with the original.
#         All five are pinned bare and mirror PackageBootIntegrityTest's rows.
#         That sweep also reads README.md and exempts its migration ROWS; this
#         one greps *.php only, so it needs no exemption.
# v5.0.0 core-trim: the SECOND query API and the term helpers (FR-4).
#         `getFormattedPosts` and its global front door
#         `ntdst_get_formatted_posts` returned rows without naming a model, and
#         therefore without the schema that says what the rows mean — a second
#         read path beside the chain, which is the shape that already produced a
#         gate-with-one, read-with-the-other bypass in this layer. The engine
#         survives as a PRIVATE method of NTDST_Data_Model under a different
#         name; `getPostTerms` was its public half. `attachTerms`, `syncTerms`
#         and `detachTerms` wrapped one `wp_set_post_terms()` call each with
#         zero readers on the fleet, and `whereDate` / `orWhere` restate
#         `date_query` and a flat root-level OR `meta_query`.
#         These eight are why the sweep exists rather than a reflection test:
#         two live callers sat OUTSIDE api/Data.php when the names went —
#         admin/RelationField.php's relation picker and services/Logger.php's
#         clearOld() — and no test touched either, so both would have shipped
#         as a call-time fatal with the suite green. All eight are pinned bare
#         (none is a substring of another; `detachTerms` does not contain
#         `attachTerms`) and mirror PackageBootIntegrityTest's rows exactly.
REMOVED=$(grep -rnE "getFormattedPosts|ntdst_get_formatted_posts|getPostTerms|attachTerms|syncTerms|detachTerms|whereDate|orWhere|discoverServices|getClassNameFromFile|auto_discover|discovery_paths|ntdst_service_|getServiceConfig|getServices|getBootedServices|hasService|isBooted|ntdst_api_action|ntdst_router|ntdst_route\(|NTDST_Router|NTDST_Endpoints|ntdst_endpoints|NTDST_SectorRegistry|ntdst_sectors|MARKER_ONLY_REQUIRED_TYPES|render_repeater_media_cell\(|publicSurface|opaqueSurface|forgetSurface|NtdstRestSurfaceTest|::surface\(|->surface\(|\\\$surface|getDefaultSanitizer|sanitizeRepeater|sanitizeBoolean|sanitizeJson|sanitizeNestedArray|sanitizeDate|sanitizeAttachmentId|sanitize_field\\(|restSubFields|restSchemaFor|signed_int|'type' *=> *'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'|=> *'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'|NTDST_FieldType\('(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'" \
    --include='*.php' . \
    | grep -v /vendor/ \
    | grep -vE "^(\./)?tests/|^(\./)?specs/" \
    | grep -vE "^(\./)?api/FieldTypes\.php:[0-9]+: *('(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)' *=> *'(int|float|bool|text|textarea|html|date|relation)',|\['type' *=> *'(integer|number|boolean|string|array)'.*\], *'[a-z_]+', *(true|false),)$" \
    | grep -vE ':[0-9]+: *(\*|//|#|/\*)' || true)
if [ -n "$REMOVED" ]; then
    echo "Shipped code still references a symbol removed in v3.0.0 or v5.0.0:"
    echo "$REMOVED"
    exit 1
fi

# v5.0.0 core-trim (FR-3): no CALL-SITE function_exists() guard on a core helper.
#
# Core's helpers are not optional. Every one of them is defined by a file on
# ntdst-core.php's own require list, so a call site that asks whether
# ntdst_log() exists is asking whether core finished loading — and then
# answering "no" by silently skipping the work. That is load-order duct tape:
# the guards existed because services/Logger.php was required LAST, after
# api/ and admin/ had already run. Logger is required FIRST now — asserted by
# BootstrapLoadsNothingByGuessingTest::testEveryRequiredFileThatCallsTheLog
# HelperIsRequiredAfterTheLogger, which reads the require list out of
# ntdst-core.php and demands the ordering of every caller it finds, rather than
# by a pair of line numbers picked here today. So the answer is always yes and
# the question is dead weight. A missing helper must fatal at boot, which is the
# correct moment to learn that core is half-loaded — not produce a request that
# quietly logs nothing.
#
# DEFINITION wrappers are exempt, and the exemption is BY SHAPE rather than by
# file: `if (!function_exists('ntdst_x')) {` whose very NEXT line declares that
# same ntdst_x(). Those are include-idempotency, not load-order — they answer
# "has this file already run", which is a real question. Anchoring to the shape
# and not to the file is what keeps a real call-site guard from sneaking back
# into services/Logger.php or api/Response.php, the two files where a blanket
# file exemption would hide it. FR-5/6/9/10 delete the helpers those wrappers
# still protect; when the last one goes, this exemption matches nothing and the
# literal SC-2 sweep (`grep -c "function_exists('ntdst_" ... ` = 0) lands with
# no further change here.
#
# The sweep reads BOTH quote styles — `function_exists('ntdst_log')` and
# `function_exists("ntdst_log")` are the same guard, and a single-quoted pattern
# is a sweep a reformatter can switch off. Its file list is the whole shipped
# tree that defines or calls a helper: api/, core/, admin/, services/, support/
# and ntdst-core.php itself, the file that owns the require order this rule is
# about.
#
# The lookahead is LINE-ADJACENT, not brace-aware: a definition wrapper is
# exempt when the guard's very NEXT non-blank, non-comment line declares that
# same function. A wrapper that opens a brace, does something else first and
# then declares it is not exempt and will be reported.
#
# ONE row-anchored exemption: admin/RelationField.php's prefixedMetaKey() still
# guards on ntdst_data(). It is a REAL call-site guard and a real FR-3 target —
# it is exempted here only because FR-10/T11 owns that file and deletes it with
# the rest of the class's trim. The exemption is anchored to that exact guard
# line, never to the file, so any OTHER call-site guard in RelationField.php
# still fires. When T11 lands, this exemption matches nothing and must be
# deleted with it; SC-2 is not met until it is gone.
CALLGUARDS=$(awk '
    FNR == 1                  { pend = "" }
    /^[ \t]*(\*|\/\/|#|\/\*)/ { next }
    /^[ \t]*$/                { next }
    {
        if (pend != "" && $0 ~ /^[ \t]*function[ \t]+ntdst_[A-Za-z0-9_]*[ \t]*\(/) {
            name = $0
            sub(/^[ \t]*function[ \t]+/, "", name)
            sub(/[ \t]*\(.*$/, "", name)
            if (viol[pend] ~ ("function_exists\\([ \t]*[\"\047]" name "[\"\047]")) {
                delete viol[pend]
            }
        }
        pend = ""
        if ($0 ~ "function_exists\\([ \t]*[\"\047]ntdst_") {
            pend = FILENAME ":" FNR
            viol[pend] = $0
        }
    }
    END { for (k in viol) print k ": " viol[k] }
' ntdst-core.php api/*.php core/*.php admin/*.php services/*.php support/*.php \
    | grep -vE "^admin/RelationField\.php:[0-9]+: *if \(!function_exists\('ntdst_data'\)\) \{$" \
    | sort || true)
if [ -n "$CALLGUARDS" ]; then
    echo "Call-site function_exists() guard on a core helper (FR-3 deleted these;"
    echo "core's helpers load before every caller, so the guard only hides a half-load):"
    echo "$CALLGUARDS"
    exit 1
fi

# The other half of FR-3 — that services/Logger.php is required before every
# file on the list that calls ntdst_log() — is asserted by
# BootstrapLoadsNothingByGuessingTest, not here. The check that stood in this
# spot compared Logger's line number against api/FieldTypes.php's, which is one
# hand-picked pair: it goes quiet the moment a NEW caller appears above Logger
# in support/ or core/. The test reads the require list and asks the question of
# every caller it finds, which is the property; two homes for one rule is how
# they come to disagree.

if ! grep -q "PHP_SAPI === 'cli'" tests/bootstrap.php; then
    echo "tests/bootstrap.php is missing its CLI guard (PHP_SAPI === 'cli' || exit;)"
    exit 1
fi

exit 0
