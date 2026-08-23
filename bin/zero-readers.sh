#!/usr/bin/env bash
# bin/zero-readers.sh — INV-9's mechanical check: a public symbol of core has at
# least one reader outside its own file.
#
# A symbol nobody reads is not an API, it is a liability: it must keep working,
# it must be tested, and the next reader of the file has to decide whether it
# matters. core-trim deleted ~45 of them. This script is what keeps the next one
# from arriving unnoticed.
#
# WHAT IT COUNTS. Three halves, all authoritative on a ZERO — a name with no
# occurrence anywhere but the file that defines it has no reader, whatever the
# receiver was:
#   hooks    every quoted `ntdst/…` hook the package fires. A dynamic hook
#            (`"ntdst/service/{$slug}/config"`) is searched by its literal stem.
#   globals  every `function ntdst_…()` the package declares, searched as
#            `ntdst_name(`.
#   methods  every `public [static] function NAME()` the package declares,
#            searched as `->NAME(`, `::NAME(` and the array-callable shape
#            `[$this, 'NAME']`. ADVISORY — it prints to stderr and does not fail
#            the gate.
#
# WHY THE METHODS HALF IS ADVISORY. A name search is receiver-blind, and it is
# wrong in BOTH directions. It under-reports: `->register(` cannot tell
# NTDST_Bootstrap::register() from NTDST_Data_Manager::register(), so a common
# name is masked by any other class's caller. It over-reports: the reader of
# `render_metabox()` is WordPress, reached through a callback this file
# registers on itself, and the reader of `NTDST_Rest::put()` is a consumer route
# in a repository that is not on this machine. The callable shape below removes
# the first class of false positive; nothing in a grep removes the second.
# So the half runs on every sweep, prints its list to stderr, and is read by a
# human — a candidate list, never a verdict. The hooks and globals halves are
# exact, and they are what the gate counts.
#
# WHERE IT LOOKS. The package itself plus the twelve D4 consumer roots below —
# the four sites the spec keeps (daan, josworld, stride, todai) and netdust's own
# code. vendor/ and tests/ are excluded everywhere: a vendored copy of core is
# not a reader of core, and a test that names a symbol in order to assert its
# shape is not a consumer of it.
#
# OUTPUT AND EXIT. Every line on STDOUT is a finding and fails the gate; the
# gate is `bash bin/zero-readers.sh | wc -l` = 0. Notes and progress go to
# STDERR. A consumer root that is not on this machine is a FINDING, not a
# skip: a sweep that quietly drops four of twelve roots prints 0 for the wrong
# reason, which is the failure mode this check exists to prevent.
#
# Exit 0 when stdout is empty, 1 otherwise.
set -uo pipefail
cd "$(dirname "$0")/.."

PACKAGE=(api core admin services support ntdst-core.php)

CONSUMER_ROOTS=(
    ../daan/web/app/mu-plugins/daan-core
    ../daan/web/app/themes/daan
    ../josworld/app/content/mu-plugins/josworld-core
    ../josworld/app/content/themes/josworld
    ../stride/web/app/mu-plugins/stride-core
    ../stride/web/app/plugins/netdust-lti
    ../stride/web/app/plugins/netdust-mail
    ../stride/web/app/themes/stridence
    ../todai-client/web/app/mu-plugins/todai-core
    ../todai-client/web/app/themes/todai-child
    ../netdust/web/app/mu-plugins/netdust-core
    ../netdust/web/app/themes/netdust
)

# Symbols kept with no reader in the searched tree, each with the reason. An
# entry is `name|reason`. Every one of them must also be named in README.md —
# asserted below, so an exemption cannot be added here and left undocumented.
# That assertion is what "documented extension point" means mechanically.
EXCEPTIONS=(
    'ntdst/model/creating|published model lifecycle (FR-11) — README extension-point table'
    'ntdst/model/created|published model lifecycle (FR-11) — README extension-point table'
    'ntdst/model/updating|published model lifecycle (FR-11) — README extension-point table'
    'ntdst/model/updated|published model lifecycle (FR-11) — README extension-point table'
    'ntdst/model/deleting|published model lifecycle (FR-11) — README extension-point table'
    'ntdst/model/deleted|published model lifecycle (FR-11) — README extension-point table'
    'ntdst/model/registering|published registration hook — README extension-point table'
    'ntdst/metabox_saved/|published metabox hook (raw payload) — README extension-point table'
    'ntdst/api/allowed_origins|published CORS extension point — README extension-point table'
    'ntdst/service_before_boot/|published per-class service lifecycle — README extension-point table'
    'ntdst/service_after_boot/|published per-class service lifecycle — README extension-point table'
    'NTDST_Service_Meta|optional service-shape interface; eight fleet implementers outside the D4 roots (bavi, netdust-legacy) — README extension-point table'
    'ntdst_container|kept by FR-6 as the container accessor; its readers are the fleet test tearDowns (22 files) and consumer bootstraps, and tests/ is excluded from this sweep by design — README extension-point table'
    'ntdst_inline|kept by core-shape as the other half of the terminal response pair; ntdst_download() is read and ntdst_inline() is not. Documented as a pair — README extension-point table. A deletion candidate for core-shape, recorded there rather than exempted silently'
    'config|NTDST_Bootstrap::config() reads the merged config a consumer passed to register(); kept by FR-2 as the one read-back of that array — README extension-point table'
)

is_exception() {
    local name="$1" row
    for row in "${EXCEPTIONS[@]}"; do
        [ "${row%%|*}" = "$name" ] && return 0
    done
    return 1
}

FINDINGS=0
finding() { printf '%s\n' "$*"; FINDINGS=$((FINDINGS + 1)); }

# ── the searched tree ────────────────────────────────────────────────────────
SEARCH=("${PACKAGE[@]}")
for root in "${CONSUMER_ROOTS[@]}"; do
    if [ -d "$root" ]; then
        SEARCH+=("$root")
    else
        finding "missing-consumer-root: $root — the sweep did not look there; 0 findings would be a false pass"
    fi
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

find "${SEARCH[@]}" -name '*.php' -not -path '*/vendor/*' -not -path '*/tests/*' -print0 \
    | xargs -0 awk '{ print FILENAME ":" $0 }' > "$TMP/corpus"

printf 'zero-readers: %s files, %s lines searched\n' \
    "$(find "${SEARCH[@]}" -name '*.php' -not -path '*/vendor/*' -not -path '*/tests/*' | wc -l)" \
    "$(wc -l < "$TMP/corpus")" >&2

# `grep -e` everywhere: this machine's grep is ugrep, which reads a pattern
# beginning with `-` as an option. `->NAME(` is exactly that shape.
readers() { # readers <pattern> <defining-file>
    grep -F -e "$1" "$TMP/corpus" | grep -v -e "^$2:" | wc -l
}

# ── hooks ────────────────────────────────────────────────────────────────────
grep -rhoE "['\"]ntdst/[^'\"]*['\"]" --include='*.php' "${PACKAGE[@]}" \
    | tr -d "'\"" | sort -u > "$TMP/hooks"

while read -r hook; do
    stem="${hook%%\{*}"                       # "ntdst/service/{$slug}/config" → "ntdst/service/"
    [ -z "$stem" ] && continue
    is_exception "$stem" && continue
    is_exception "$hook" && continue
    file="$(grep -rlF -e "$hook" --include='*.php' "${PACKAGE[@]}" | head -1)"
    [ "$(readers "$stem" "$file")" -eq 0 ] && finding "$file:$hook — hook with no reader"
done < "$TMP/hooks"

# ── global functions ─────────────────────────────────────────────────────────
grep -rnoE "^ *function (ntdst_[a-z_]+)" --include='*.php' "${PACKAGE[@]}" \
    | sed -E 's/^([^:]+):[0-9]+: *function /\1 /' | sort -u > "$TMP/globals"

while read -r file name; do
    is_exception "$name" && continue
    [ "$(readers "${name}(" "$file")" -eq 0 ] && finding "$file:$name() — global function with no reader"
done < "$TMP/globals"

# ── public methods — ADVISORY, stderr only (see the header) ──────────────────
grep -rnoE "^ *public (static )?function [a-zA-Z_]+" --include='*.php' "${PACKAGE[@]}" \
    | sed -E 's/^([^:]+):[0-9]+: *public (static )?function /\1 /' | sort -u > "$TMP/methods"

ADVISORY=0
while read -r file name; do
    case "$name" in __*) continue ;; esac       # magic methods answer to PHP, not to a caller
    is_exception "$name" && continue
    [ "$(readers "->${name}(" "$file")" -gt 0 ] && continue
    [ "$(readers "::${name}(" "$file")" -gt 0 ] && continue
    # An array callable is a reader, and it is normally registered by the very
    # file that defines the method: `add_action('admin_menu', [$this, 'x'])`.
    # The defining file is NOT excluded here, which is the whole point.
    [ "$(grep -F -c -e ", '${name}']" -e ", \"${name}\"]" "$TMP/corpus")" -gt 0 ] && continue
    printf 'advisory: %s:%s() — no call site found (receiver-blind; see the header)\n' "$file" "$name" >&2
    ADVISORY=$((ADVISORY + 1))
done < "$TMP/methods"

# ── every exception must be documented ───────────────────────────────────────
for row in "${EXCEPTIONS[@]}"; do
    name="${row%%|*}"
    grep -qF -e "$name" README.md \
        || finding "undocumented-exception: $name — an exemption here must be named in README.md"
done

printf 'zero-readers: %s advisory method(s)\n' "$ADVISORY" >&2

if [ "$FINDINGS" -gt 0 ]; then
    printf 'zero-readers: %s finding(s)\n' "$FINDINGS" >&2
    exit 1
fi
printf 'zero-readers: 0 findings\n' >&2
exit 0
