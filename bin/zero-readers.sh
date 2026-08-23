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
#   hooks    every quoted `ntdst/…` hook the package fires, searched by its
#            literal stem — the text before the first interpolation.
#            `"ntdst/service/{$slug}/config"` is searched as `ntdst/service/`.
#   globals  every `function ntdst_…()` the package declares, searched as
#            `ntdst_name(`.
#   methods  every `public [static] function NAME()` the package declares,
#            searched as `->NAME(`, `::NAME(` and the array-callable shape
#            `[$this, 'NAME']`. ADVISORY — it prints to stderr and does not fail
#            the gate.
#
# WHAT A READER IS. A line that REGISTERS on the name, ASKS about it or CALLS
# it: `add_action`, `add_filter`, `has_action`, `has_filter`, `did_action`, a
# call. Two kinds of line name a symbol and read nothing, so `readers()` drops
# both:
#   documentation  a line whose first non-space character is `*`, `//`, `#` or
#                  `/*`. A docblock that explains a hook is not a listener on
#                  it. This is what let `ntdst/trusted_proxies` pass: its own
#                  two docblocks in support/ClientIp.php counted as readers.
#   the firing     `do_action(` / `apply_filters(`. The publisher of a hook is
#                  not its reader — it is the thing the reader is missing.
# Every PACKAGE file that fires a hook is excluded as well, not just the first
# one `grep -l` happens to print: a hook fired in two files was reading itself
# out of the second.
#
# THE `ntdst/` STEM GUARD. A hook whose interpolation is in the FIRST segment
# (`"ntdst/{$name}/fields"`) has the stem `ntdst/`, which matches every hook in
# the package and most of the fleet — a stem that broad is not a search, it is
# a pass. Those are searched by their literal SUFFIX instead (`/fields`,
# `/field_groups`), which is the part a consumer actually writes out.
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
# WHERE IT LOOKS. The package itself plus the consumer roots in CONSUMER_ROOTS
# below — the four sites the spec keeps (daan, josworld, stride, todai),
# netdust's own code, and ludoluykx, whose theme services are the fleet's
# readers of the per-model field filters. vendor/ and tests/ are excluded
# everywhere: a vendored copy of core is not a reader of core, and a test that
# names a symbol in order to assert its shape is not a consumer of it.
# ludoluykx's own mu-plugins/ntdst-core is a COPY of this package, so the theme
# is the only root taken from that site.
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
    ../ludoluykx/web/app/themes/ntdstheme
)

# Symbols kept with no reader in the searched tree, each with the reason. An
# entry is `name|reason`. Every one of them must also be named in README.md's
# `#### Extension points` table — asserted below, so an exemption cannot be
# added here and left undocumented. That assertion is what "documented
# extension point" means mechanically, and it reads that ONE span rather than
# the whole file: a removed symbol has a migration row further down, and a
# migration row is a record of a DELETION, never a promise to keep something.
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
    'ntdst/service/|the ONE per-service config filter; stride SecurityService and PerformanceService read it — README extension-point table'
    'ntdst/trusted_proxies|published security knob; a site config sets it, no fleet reader today — README extension-point table'
    'ntdst/api/rate_window/|published security knob (the window beside the limit); a site config sets it, no fleet reader today — README extension-point table'
    'NTDST_Service_Meta|optional service-shape interface; six implementers in bavi and dozens in netdust-legacy, all outside the swept roots. An INTERFACE cannot be enumerated by this script at all — README is its only check — README extension-point table'
    'ntdst_container|kept by FR-6 as the container accessor. INERT since ludoluykx joined the roots: FluentCRMIntegrationService calls it. Its other readers are the fleet test tearDowns (22 files) and consumer bootstraps, and tests/ is excluded from this sweep by design — README extension-point table'
    'ntdst_inline|kept by core-shape as the other half of the terminal response pair; ntdst_download() is read and ntdst_inline() is not. Documented as a pair — README extension-point table. A deletion candidate for core-shape, recorded there rather than exempted silently'
    'ntdst_api_floor_cap|the capability floor for a public API action; it leaves with api/Actions.php at core-shape T08, so it is recorded there rather than deleted here — README extension-point table'
    'NTDST_Bootstrap::config()|reads the merged config a consumer passed to register(); kept by FR-2 as the one read-back of that array — README extension-point table'
)

# SEVEN of these names are INERT: drop the row and the sweep still says
# nothing, because the symbol has a reader the script can see, or is a shape it
# cannot judge at all. They are `ntdst/model/created`, `ntdst/model/updated`,
# `ntdst/model/registering`, `ntdst/service/`, `ntdst_container`,
# `NTDST_Bootstrap::config()` and `NTDST_Service_Meta`. They stay so the list
# reads as the WHOLE published set — a reader of this array should not have to
# ask which published extension point was left out because it happened to be
# called somewhere. The other twelve are load-bearing: drop one and a finding
# appears. See ARCHITECTURE-INVARIANTS.md `## Deliberate exceptions`.

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
# beginning with `-` as an option. `->NAME(` is exactly that shape. The grep is
# only a fast prefilter — awk decides, so that the needle is matched in the LINE
# and never in the file PATH the corpus prefixes it with.
readers() { # readers <literal-needle> <file listing the paths that do not count>
    grep -F -e "$1" "$TMP/corpus" | awk -v needle="$1" '
        FILENAME == ARGV[1] { skip[$0] = 1; next }
        {
            p = index($0, ":")
            if (substr($0, 1, p - 1) in skip) next
            line = substr($0, p + 1)
            if (index(line, needle) == 0) next
            if (line ~ /^[[:space:]]*(\*|\/\/|#|\/\*)/) next
            if (line ~ /(do_action|apply_filters)[[:space:]]*\(/) next
            n++
        }
        END { print n + 0 }
    ' "$2" -
}

# ── hooks ────────────────────────────────────────────────────────────────────
grep -rhoE "['\"]ntdst/[^'\"]*['\"]" --include='*.php' "${PACKAGE[@]}" \
    | tr -d "'\"" | sort -u > "$TMP/hooks"

while read -r hook; do
    stem="${hook%%\{*}"                       # "ntdst/service/{$slug}/config" → "ntdst/service/"
    [ -z "$stem" ] && continue
    needle="$stem"
    if [ "$stem" = "ntdst/" ]; then           # interpolation in the FIRST segment
        needle="${hook#*\}}"                  # "ntdst/{$name}/fields" → "/fields"
        [ -z "$needle" ] && continue
    fi
    is_exception "$stem" && continue
    is_exception "$hook" && continue
    # every package file that FIRES this hook, not the first one grep prints
    grep -rnF -e "$hook" --include='*.php' "${PACKAGE[@]}" \
        | grep -E '(do_action|apply_filters)[[:space:]]*\(' \
        | cut -d: -f1 | sort -u > "$TMP/fires"
    [ -s "$TMP/fires" ] \
        || grep -rlF -e "$hook" --include='*.php' "${PACKAGE[@]}" | sort -u > "$TMP/fires"
    [ "$(readers "$needle" "$TMP/fires")" -eq 0 ] \
        && finding "$(head -1 "$TMP/fires"):$hook — hook with no reader"
done < "$TMP/hooks"

# ── global functions ─────────────────────────────────────────────────────────
grep -rnoE "^ *function (ntdst_[a-z_]+)" --include='*.php' "${PACKAGE[@]}" \
    | sed -E 's/^([^:]+):[0-9]+: *function /\1 /' | sort -u > "$TMP/globals"

while read -r file name; do
    is_exception "$name" && continue
    printf '%s\n' "$file" > "$TMP/self"
    [ "$(readers "${name}(" "$TMP/self")" -eq 0 ] && finding "$file:$name() — global function with no reader"
done < "$TMP/globals"

# ── public methods — ADVISORY, stderr only (see the header) ──────────────────
grep -rnoE "^ *public (static )?function [a-zA-Z_]+" --include='*.php' "${PACKAGE[@]}" \
    | sed -E 's/^([^:]+):[0-9]+: *public (static )?function /\1 /' | sort -u > "$TMP/methods"

ADVISORY=0
while read -r file name; do
    case "$name" in __*) continue ;; esac       # magic methods answer to PHP, not to a caller
    is_exception "$name" && continue
    printf '%s\n' "$file" > "$TMP/self"
    [ "$(readers "->${name}(" "$TMP/self")" -gt 0 ] && continue
    [ "$(readers "::${name}(" "$TMP/self")" -gt 0 ] && continue
    # An array callable is a reader, and it is normally registered by the very
    # file that defines the method: `add_action('admin_menu', [$this, 'x'])`.
    # The defining file is NOT excluded here, which is the whole point.
    [ "$(grep -F -c -e ", '${name}']" -e ", \"${name}\"]" "$TMP/corpus")" -gt 0 ] && continue
    printf 'advisory: %s:%s() — no call site found (receiver-blind; see the header)\n' "$file" "$name" >&2
    ADVISORY=$((ADVISORY + 1))
done < "$TMP/methods"

# ── every exception must be documented ───────────────────────────────────────
# Scoped to the `#### Extension points` span: a name that appears only in a
# core-trim migration row is a DELETED symbol, and a record of a deletion must
# not satisfy a promise to keep something.
README_SPAN="${README_SPAN:-README.md}"
awk '/^#### Extension points/ { inside = 1; next }
     inside && /^#+ / { exit }
     inside { print }' "$README_SPAN" > "$TMP/extension-points"

for row in "${EXCEPTIONS[@]}"; do
    name="${row%%|*}"
    grep -qF -e "$name" "$TMP/extension-points" \
        || finding "undocumented-exception: $name — an exemption here must be named in README.md's Extension points table"
done

printf 'zero-readers: %s advisory method(s)\n' "$ADVISORY" >&2

if [ "$FINDINGS" -gt 0 ]; then
    printf 'zero-readers: %s finding(s)\n' "$FINDINGS" >&2
    exit 1
fi
printf 'zero-readers: 0 findings\n' >&2
exit 0
