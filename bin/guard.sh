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

if ! grep -q "PHP_SAPI === 'cli'" tests/bootstrap.php; then
    echo "tests/bootstrap.php is missing its CLI guard (PHP_SAPI === 'cli' || exit;)"
    exit 1
fi

exit 0
