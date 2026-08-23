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
REMOVED=$(grep -rnE "ntdst_api_action|ntdst_router|ntdst_route\(|NTDST_Router|NTDST_Endpoints|publicSurface|opaqueSurface|forgetSurface|NtdstRestSurfaceTest|::surface\(|->surface\(|\\\$surface|getDefaultSanitizer|sanitizeRepeater|sanitizeBoolean|sanitizeJson|sanitizeNestedArray|sanitizeDate|sanitizeAttachmentId|sanitize_field\\(|restSubFields|restSchemaFor|signed_int|'type' *=> *'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'|=> *'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'|NTDST_FieldType\('(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'" \
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

if ! grep -q "PHP_SAPI === 'cli'" tests/bootstrap.php; then
    echo "tests/bootstrap.php is missing its CLI guard (PHP_SAPI === 'cli' || exit;)"
    exit 1
fi

exit 0
