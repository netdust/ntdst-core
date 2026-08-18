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

# v3.0.0 removed the v2 routing facades. A deleted symbol with a surviving
# caller is a RUNTIME fatal that no other gate step can see: `composer lint` is
# php -l (syntax only, never resolves a name) and the unit suite does not load
# every shipped file. Cluster A shipped two such fatals with a 155/155 green
# suite — admin/RelationField.php called ntdst_api_action(), and core/Theme.php
# guarded on ntdst_router() so a required mixin silently stopped registering.
# This grep is what makes the next rename fail loudly instead.
REMOVED=$(grep -rnE "ntdst_api_action|ntdst_router|ntdst_route\(|NTDST_Router|NTDST_Endpoints" \
    --include='*.php' . \
    | grep -v /vendor/ | grep -v '^\./tests/' | grep -v '^\./specs/' \
    | grep -vE ':[0-9]+: *(\*|//|#|/\*)' || true)
if [ -n "$REMOVED" ]; then
    echo "Shipped code still references a symbol removed in v3.0.0:"
    echo "$REMOVED"
    exit 1
fi

if ! grep -q "PHP_SAPI === 'cli'" tests/bootstrap.php; then
    echo "tests/bootstrap.php is missing its CLI guard (PHP_SAPI === 'cli' || exit;)"
    exit 1
fi

exit 0
