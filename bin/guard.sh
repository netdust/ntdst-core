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
# audit checks. The Cluster D audit found `wireMixins` and `templatePath`
# behind the provider — the same drift, a second time — and they are in the
# alternation now. Deriving this pattern FROM the provider is the parked fix,
# and it stays parked because the provider carries per-row exemption columns
# (`signed_int`) that a joined string cannot express.
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
# v5.0.0 core-trim: the Logger's database half and its handler API (FR-5).
#         The database half wrote REQUEST_URI, the client IP and the whole
#         context array into post meta on every error, armed by
#         `ntdst_log_database_enabled` and ON whenever WP_DEBUG was — a PII sink
#         switched on during the incident, answering each error with
#         wp_insert_post + N meta writes + a save_post cascade.
#         The two constructor names carry the load-order half, which is the
#         reason this sweep wants them: registering the post type reached the
#         Data layer from NTDST_Logger::__construct(), so services/Logger.php
#         could not load before api/Data.php, which is why core's call sites
#         guarded their logging with function_exists('ntdst_log') at all (FR-3,
#         I-2). A reference left behind is that load order coming back.
#         The handler API (add/remove, the level setter, the batching switch)
#         let a consumer move the sink, the gate or the write moment at
#         runtime, so no call site could say where a line would go. Zero
#         readers on daan, josworld or stride.
#         All nine are pinned bare — none is a substring of another, none is in
#         README — and mirror PackageBootIntegrityTest's rows exactly. `recent`
#         and `clearOld` get no term on purpose: they are ordinary words a bare
#         sweep would find in prose, and LoggerSurfaceTest's exact
#         public-method list pins them harder than a grep could.
# v5.0.0 core-trim: the six model lifecycle hooks, retired spellings (FR-11).
#         They were RENAMED, not deleted — ntdst/model/{creating,created,
#         updating,updated,deleting,deleted} fire in their place with the same
#         arguments — and a rename is the one removal that leaves no fatal
#         behind. A shipped `add_action('ntdst_model_create_after', ...)` simply
#         stops running: php -l passes, the suite stays green, and a listener
#         has gone quiet. daan's PressKitService is the live instance (T12
#         renames it); this sweep is what makes the next one fail loudly.
#         All six are pinned bare — each is a full, distinctive hook name, none
#         is a substring of another — and mirror PackageBootIntegrityTest's rows
#         exactly. That sweep also reads README.md and exempts its migration
#         ROWS; this one greps *.php only, so it needs no exemption.
# v5.0.0 FR-4: `getPostMeta` is the ninth read-helper row and landed a gate
#         later than its eight siblings, so this copy was short one name. Bare,
#         like the provider's row: WordPress's own get_post_meta() is
#         snake_case, and getPostMetaFromCache() — the accessor josworld calls,
#         and the one KNOWN outside reader of the removed static — does not ship
#         in core.
# v5.0.0 core-trim: the container's second resolution path (FR-6 / FR-10).
#         `ntdst_make` is make()'s GLOBAL front door — the one spelling of the
#         removed path that can appear outside a `$container->` call — and
#         `callableReflections` was call()'s reflection cache, whose only writer
#         was call() itself. Both are pinned BARE: neither is a substring of
#         another word here, neither is in README, and both mirror
#         PackageBootIntegrityTest's rows exactly.
#         The five METHOD names get no row and cannot: `make`, `call`, `forget`,
#         `flush` and `keys` are ordinary English this codebase writes constantly
#         ("make fresh", "the call site", "flush the cache"), so a bare sweep
#         hits prose, and a call-shape sweep (`->make(`) misses a wrapped line.
#         They are pinned below instead, at the ONE place they can come back.
# v5.0.0 core-trim: the Scheduler leaves the package (FR-7). WordPress already
#         has a recurring-task primitive (wp_schedule_event /
#         wp_next_scheduled); stride's GateReminderService writes the two
#         WordPress lines directly (T11) instead of going through a copy. All
#         four are pinned bare — none is a substring of another, none is in
#         README — and mirror PackageBootIntegrityTest's rows exactly.
#
# v5.0.0 core-trim: the Mailer leaves the package too (FR-9). WordPress has
#         wp_mail(); stride's netdust-mail owns the builder now as
#         `Netdust\Mail\Mailer` (T11). Nine stems stand for the thirteen rows
#         in PackageBootIntegrityTest: `ntdst_mail` is a PREFIX and swallows
#         `ntdst_mail_before_send`, `_sent`, `_template_paths` and
#         `_attachment_bases`. README is not swept by this line (*.php only),
#         so the migration table needs no exemption here.
# v5.0.0 core-shape: the command dispatcher leaves the package (FR-7). Seven
#         rows here, and the four `api*` envelopes go to METHOD_PINS instead —
#         see that table. `NTDST_Actions` owned `POST /ntdst/v1/action` and
#         `POST /ntdst/v1/get_nonce`, verified `Origin` itself and minted its
#         own nonce; `assets/js/ntdst-api.js` (`window.ntdstAPI`) was the
#         browser half and `ntdst_enqueue_api_client()` put it on the page. A
#         resource route through `ntdst_rest()` and `wp.apiFetch` do all of it
#         on WordPress's own CSRF now (INV-2, INV-4).
#         The two FILTER names earn their rows for the reason the model hooks
#         did: a handler that outlives its dispatcher goes QUIET — php -l
#         passes, the suite stays green, and a site's command silently stops
#         answering. `ntdst/api_data/{action}` is interpolated, so the row is
#         the STEM `ntdst/api_data`. `get_nonce` is the retired ROUTE segment,
#         pinned bare: nothing else in the package spells it, and README is out
#         of scope here (*.php only).
#         All seven are pinned bare — none is a substring of another — and
#         mirror PackageBootIntegrityTest's rows exactly. `ntdstAPI` is swept in
#         PHP because ntdst-core.php used to PRINT it (`window.ntdstAPIConfig`)
#         from an inline script; the JS file itself is gone, so there is no
#         *.js half left to sweep.
# v5.0.0 core-shape: Response keeps only what WordPress has no word for
#         (FR-11 / INV-5). Two rows here, and the eight retired METHODS go to
#         METHOD_PINS instead — see that table for why.
#         `ntdst_redirect` was wp_safe_redirect() plus an exit, spelled a third
#         time (Response::redirect() and Pages::redirect() were the other two);
#         it is pinned BARE because nothing else in shipped PHP contains the
#         word, and README is out of scope here (*.php only).
#         `$mimeTypes` was a 19-row copy of a table WordPress keeps as
#         wp_get_mime_types(), read through getMimeType() — three rows of it
#         (csv, txt, ics) already disagreed with WordPress's own values. It is
#         pinned WITH THE DOLLAR, the way `$surface` is, and that is load-
#         bearing: `mimeTypes()` SURVIVES as the `mime_types` filter callback
#         that adds the four types WordPress lacks, so a bare `mimeTypes` row
#         would fire on the convergence point itself.
#
# The two families are named separately because the retired-TYPE one is the
# only one whose names collide with WordPress's own vocabulary: a REST route
# declares ['type' => 'string', 'required' => true], which is JSON Schema and
# not a field declaration — the same distinction api/FieldTypes.php's schema
# rows already carry. So a line that shows it IS a REST `args` schema (a
# `type` beside a `sanitize_callback`, a `validate_callback`, an `items` or an
# `enum` — never a bare `required`, which the field registry spells too) is
# exempt from the TYPE family ONLY. A line that also names one of the removed
# functions, classes, hooks or `signed_int` still
# fires, whatever else is written on it — which is what keeps this exemption
# from becoming a way to smuggle a retired name past the guard. It is also
# why the two must be written on ONE LINE each: a bare `'type' => 'string'`
# on a line of its own is indistinguishable from the retired declaration.
# Mirrors PackageBootIntegrityTest::REST_ARG_SCHEMA_LINE; the shapes are the
# contract, not the file they were found in.
REMOVED_SYMBOLS="NTDST_Mailer|ntdst_mail|ntdst_send_mail|ntdst_send_queued_mail|ntdst_notify|ntdst_notification|ntdst_wrap_email_in_layout|ntdst_wrap_all_emails|ntdst_email_layout_paths|NTDST_Scheduler|ntdst_scheduler|ntdst_schedule_recurring|ntdst_clear_recurring|ntdst_model_create_before|ntdst_model_create_after|ntdst_model_update_before|ntdst_model_update_after|ntdst_model_delete_before|ntdst_model_delete_after|log_entry|ntdst_log_database_enabled|addHandler|removeHandler|setMinLevel|setBatchingEnabled|ntdst_log_debug|ntdst_log_info|ntdst_log_error|getFormattedPosts|ntdst_get_formatted_posts|ntdst_make|callableReflections|wireMixins|templatePath|getPostMeta|getPostTerms|attachTerms|syncTerms|detachTerms|whereDate|orWhere|discoverServices|getClassNameFromFile|auto_discover|discovery_paths|ntdst_service_|getServiceConfig|getServices|getBootedServices|hasService|isBooted|ntdst_api_action|ntdst_router|ntdst_route\(|NTDST_Router|NTDST_Endpoints|ntdst_endpoints|NTDST_SectorRegistry|ntdst_sectors|MARKER_ONLY_REQUIRED_TYPES|render_repeater_media_cell\(|publicSurface|opaqueSurface|forgetSurface|NtdstRestSurfaceTest|::surface\(|->surface\(|\\\$surface|getDefaultSanitizer|sanitizeRepeater|sanitizeBoolean|sanitizeJson|sanitizeNestedArray|sanitizeDate|sanitizeAttachmentId|sanitize_field\\(|restSubFields|restSchemaFor|signed_int|NTDST_Actions|ntdst_actions|ntdst_enqueue_api_client|ntdstAPI|get_nonce|ntdst/api_data|ntdst/api/public_actions|ntdst_redirect|\\\$mimeTypes"
RETIRED_TYPES="'type' *=> *'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'|=> *'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'|NTDST_FieldType\('(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)'"
REST_ARG_SCHEMA_LINE="'type' *=> *'[a-z_]+'.*'(sanitize_callback|validate_callback|items|enum)' *=>|'(sanitize_callback|validate_callback|items|enum)' *=>.*'type' *=> *'[a-z_]+'"

REMOVED_RAW=$(grep -rnE "${REMOVED_SYMBOLS}|${RETIRED_TYPES}" \
    --include='*.php' . \
    | grep -v /vendor/ \
    | grep -vE "^(\./)?tests/|^(\./)?specs/" \
    | grep -vE "^(\./)?api/FieldTypes\.php:[0-9]+: *('(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)' *=> *'(int|float|bool|text|textarea|html|date|relation)',|\['type' *=> *'(integer|number|boolean|string|array)'.*\], *'[a-z_]+', *(true|false),)$" \
    | grep -vE ':[0-9]+: *(\*|//|#|/\*)' || true)

# Kept: every line that trips a NON-type name, plus every line that is not a
# REST `args` schema. What that subtracts is exactly the type-only hit on a
# schema line.
REMOVED=""
if [ -n "$REMOVED_RAW" ]; then
    REMOVED=$( { printf '%s\n' "$REMOVED_RAW" | grep -E "$REMOVED_SYMBOLS" || true; \
                 printf '%s\n' "$REMOVED_RAW" | grep -vE "$REST_ARG_SCHEMA_LINE" || true; } | sort -u)
fi
if [ -n "$REMOVED" ]; then
    echo "Shipped code still references a symbol removed in v3.0.0 or v5.0.0:"
    echo "$REMOVED"
    exit 1
fi

# v5.0.0 core-trim: PER-FILE METHOD PINS. This table is the home for every
# "this file must not declare this method again" rule — add a row here rather
# than a new grep block. Each entry keeps its own rationale above it.
#
# WHY DECLARATION GREPS AND NOT REMOVED-SYMBOL ROWS (both entries): every name
# pinned here is an ordinary English word this codebase writes constantly, so a
# bare sweep would hit prose. `when` is sharper still — core/Pages.php declares
# a LIVE when() of its own (`ntdst_pages()->when(...)`, its docblock at :39), so
# even a call-shape sweep (`->when(`) would hit a shipped caller. Each name is
# therefore pinned where it can actually come back: as a `function <name>`
# DECLARATION in ONE named file. The PHP suites pin the same properties harder
# (ContainerSurfaceTest by exact public-method list, ThemeTrimTest by
# reflection) and also catch a mechanism arriving under a NEW name; these lines
# are what fail on a fresh checkout with no vendor/ and no PHP test run.
#
# `templatePath` and `wireMixins` are NOT here: both are distinctive names that
# appear nowhere else in shipped PHP, so they are pinned as ordinary bare rows
# in the REMOVED sweep above, mirroring
# PackageBootIntegrityTest::removedSymbolProvider() exactly. core/Theme.php:23
# says the word once, in the docblock recording what left; the sweep's
# comment-line filter is what makes that legal, and prose in README and specs/
# is out of scope because the sweep reads `*.php` only.
declare -A METHOD_PINS=(
    # FR-6 / SC-3: the container declares set/get/has, and no second way to ask
    # one of them. make() resolved WITHOUT the singleton cache, call() injected
    # arguments into a callable after construction, forget() and flush() mutated
    # the registry at runtime, and keys() handed back a read-only copy of it.
    # Five public methods, zero shipped readers, and each one a second answer to
    # a question set/get/has already answers — the shape FR-2 removed from
    # Bootstrap.
    ["core/Container.php"]="make call forget flush keys"

    # FR-8 / SC-4: NTDST_Theme wires the theme's hooks, and carries no mixin
    # mechanism. mixin() + __call() + the $mixins registry proxied
    # `data`/`pages`/`response`/`log`/`mail`, so `$theme->data()` reached
    # another layer through a magic method — a surface that cannot be READ,
    # because nothing in the file said which names resolved. `when()` was an
    # `if` with a fluent return. Both go; a theme names the owner at the call
    # site now (`ntdst_data()`).
    #
    # FR-12 / INV-5 (core-shape T12): the five OTHER retirements from this file.
    # style() and script() were `wp_enqueue_scripts` closures with no decision
    # in them, and single()/page()/archive() were one-line forwarders onto
    # NTDST_Pages — a second public surface that had to track its owner's
    # signature. `$theme->on('wp_enqueue_scripts', ...)` and `ntdst_pages()->…`
    # are the call sites now.
    #
    # Pinned HERE and only here, for the reason `json`/`render`/`addPath` are:
    # every one of the five is an ordinary word this package writes constantly,
    # and three of them name LIVE methods of NTDST_Pages (single, page,
    # archive), so a bare REMOVED row would fire on the survivors. A method can
    # only come back where it is DECLARED. PackageBootIntegrityTest carries the
    # `Theme::style`-shaped rows instead — the sweep that answers for README.
    ["core/Theme.php"]="__call mixin when style script single page archive"

    # FR-7 / SC-3: NTDST_Response has ONE wire shape, and no api* envelope
    # beside it. apiSuccess()/apiError() built a {success,data:{message,code}}
    # body that DELIBERATELY disagreed with jsonPayload()'s {success,error},
    # and apiSuccessResponse()/apiErrorResponse() emitted it as a REST
    # response — a second wire vocabulary whose only consumers were
    # NTDST_Actions and the ntdstAPI JS client, both deleted here.
    #
    # Pinned as DECLARATIONS in this one file rather than as bare REMOVED rows,
    # for the reason the container's five methods are: the sweep above reads
    # *.php only and these four names are not in shipped PHP prose, but they
    # ARE in README's migration table and in the provider's rows, and a name
    # pinned in two shapes across two lists is how the two come to disagree.
    # A method can only come back where it is DECLARED, and that is here.
    # PackageBootIntegrityTest pins all four as bare provider rows, which is
    # the sweep that also answers for README.
    #
    # FR-11 / SC-6 (core-shape T11): the eight OTHER retirements from this file.
    # json()/jsonPayload() built a `{success, error|data}` envelope
    # wp_send_json_success()/wp_send_json_error() already spell; render(),
    # renderError() and getErrorHtml() echoed a template (or a red core-styled
    # <div>) and exit()ed, which is the contract INV-6 removed — a callback
    # returns a PATH; commitRenderStatus() cleared the `is_404` WordPress had
    # just set, and was the LAST such write in the package; getMimeType() and
    # registerMimeType() read and wrote the deleted table.
    #
    # Here and not as bare provider rows, for the reason the container's five
    # are: `json`, `render` and `addPath` are ordinary words this codebase and
    # README write constantly, and `addPath` names a SURVIVOR in another file
    # (NTDST_Template_Loader::addPath()). A method can only come back where it
    # is DECLARED. The SIX distinctive ones (jsonPayload, renderError,
    # getErrorHtml, commitRenderStatus, getMimeType, registerMimeType) sit in
    # BOTH homes on purpose: this line fails on a fresh checkout with no
    # vendor/, the provider's rows fail on a README that never told the adopter.
    #
    # `jsonPayload` is named here in full (A1). The `json` row already prefix-
    # matches it, so the pin is the same either way — but a comment that calls
    # a name a both-homes example while the list it points at does not carry
    # it is a list nobody can check, and the day `json` leaves this row
    # jsonPayload would leave with it, silently.
    ["api/Response.php"]="apiSuccess apiError apiSuccessResponse apiErrorResponse addPath json jsonPayload render renderError getErrorHtml commitRenderStatus getMimeType registerMimeType"

    # FR-10 / INV-5: the loader picks from WordPress's candidate list and
    # writes no list of its own. templateInclude() hand-listed
    # single-{type}-{slug}, single-{type}, single, archive-{type} and archive
    # on `template_include` — a PARTIAL copy of a hierarchy WordPress builds
    # itself and hands to `{$type}_template` as the filter's third argument.
    # pickFromCandidates() takes that argument.
    #
    # Pinned as a DECLARATION in this one file, and `addPath` is pinned in
    # api/Response.php above, for the same reason and a second one. The reason
    # both share: the sweep reads *.php only, and README's migration table
    # spells both names, so a bare row would need a README exemption in a list
    # this file cannot express (PackageBootIntegrityTest carries it). The
    # second, which is only addPath's: NTDST_Template_Loader::addPath() SURVIVES
    # in the file below — it is the ONE registry FR-10 converged on — so a
    # package-wide sweep on the bare word would fire on the survivor. A
    # per-file declaration pin says exactly what is true: Response declares no
    # addPath, the loader does.
    # `getCustomPaths` joins it as the T11 carry (Cluster 4a simplicity
    # review): a read-only copy of $custom_paths with zero readers on the
    # fleet — the same shape FR-2 removed from Bootstrap, and the same reason
    # it is pinned per-file rather than bare (README's migration row spells it,
    # and the sweep here reads *.php only).
    ["core/TemplateLoader.php"]="templateInclude getCustomPaths"

    # FR-9 / INV-6: a page URL is a WordPress rewrite rule, so the router has
    # nothing left to fight. handleTemplateInclude() re-matched REQUEST_URI
    # against a private regex INSIDE template_include — after WordPress had
    # already parsed the URL, found nothing and marked the request not-found —
    # and the other four were the machinery that made that work:
    # preventRedirectForRoutes() answered the canonical-redirect filter,
    # commitOk() cleared the not-found flag WordPress had just set,
    # renderResponse() rendered-and-exited from inside a template filter, and
    # resolveRouteResult() was the contract tying the three together. path()
    # registers a rule now; WordPress parses, and a callback returns a path.
    #
    # `redirect` is in this table and NOT in the REMOVED sweep, and that is the
    # whole reason this table exists: api/Response.php DECLARES a live
    # redirect() (`ntdst_response()->redirect(...)`), README documents it, and a
    # bare row would fire on the survivor. INV-6 takes `function redirect` in
    # api + core to ONE, and this pin is which one went. The other five are
    # distinctive names and are pinned as bare provider rows in
    # PackageBootIntegrityTest — the sweep that also answers for README — so
    # they sit in both homes on purpose: this file fails on a fresh checkout
    # with no vendor/, that one fails with a README that never told the adopter.
    #
    # `compilePattern` is the seventh, and the only RENAME (A2): 5bee797 took
    # the private URL-to-regex compiler to compileRule(), which builds a
    # REWRITE rule instead of a regex the router re-matches itself. A file that
    # declares compilePattern() again has the old shape back. It owes README no
    # migration row — it was never public — so it is pinned here only.
    ["core/Pages.php"]="redirect handleTemplateInclude resolveRouteResult commitOk renderResponse preventRedirectForRoutes compilePattern"
)
for PIN_FILE in $(printf '%s\n' "${!METHOD_PINS[@]}" | sort); do
    PIN_METHODS="${METHOD_PINS[$PIN_FILE]}"
    PIN_HITS=$(grep -nE "function (${PIN_METHODS// /|})" "$PIN_FILE" || true)
    if [ -n "$PIN_HITS" ]; then
        echo "$PIN_FILE declares a method removed in v5.0.0 ($(echo "$PIN_METHODS" | tr ' ' '/')):"
        echo "$PIN_HITS"
        exit 1
    fi
done


# v5.0.0 core-trim (FR-11 / SC-4): core spells every hook `ntdst/...`, never
# `ntdst_...`. The convention is declared once, in Bootstrap's header, and the
# underscore spelling is the OTHER one — the sweep above pins six names that
# are gone, and this line pins the SHAPE, so the seventh hook nobody wrote a
# row for cannot arrive misspelled. A consumer reads the hook name off the
# source; two conventions in one package means every listener is a guess.
#
# SCOPE. All four shipped directories, `services` included: T10 deleted
# services/Mailer.php and the eight `ntdst_` hooks it carried, which were the
# last of that spelling in the package — so SC-4 closes and the scope is whole.
HOOKSPELLING=$(grep -rn "do_action('ntdst_\|apply_filters('ntdst_\|do_action(\"ntdst_\|apply_filters(\"ntdst_" api core admin services || true)
if [ -n "$HOOKSPELLING" ]; then
    echo "Hook spelled ntdst_ instead of ntdst/ (FR-11: core's convention is ntdst/...):"
    echo "$HOOKSPELLING"
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

# v5.0.0 core-shape (R-I1): README never spells a call signature the package
# does not have. Two shapes, both of which an adopter copies verbatim:
#
#   ntdst_data('gig')  — ntdst_data() takes NO argument (api/Data.php, the
#                        helper returns the manager). The model comes from
#                        ntdst_data()->get('gig'), and where() is the MODEL's
#                        query builder, so the chain is
#                        ntdst_data()->get('gig')->where(...).
#   ->sanitizer        — NTDST_FieldType's property is `sanitize`
#                        (api/FieldTypes.php). `->sanitizer` reads null and a
#                        caller invoking it fatals.
#
# Migration rows are NOT exempt here, unlike the removed-symbol sweep: these
# two are not names that LEFT the package, they are calls that never existed,
# so a row spelling one is a wrong instruction wherever it stands. Both quote
# styles are read — a single-quoted-only sweep is one a reformatter switches
# off. Mirrored in PackageBootIntegrityTest::testReadmeNeverSpellsACall
# SignatureThePackageDoesNotHave, and the two must move together.
BADSIGNATURES=$(grep -n "ntdst_data('\|ntdst_data(\"\|->sanitizer" README.md || true)
if [ -n "$BADSIGNATURES" ]; then
    echo "README spells a call signature this package does not have"
    echo "(ntdst_data() takes no argument — ntdst_data()->get('type')->where(...);"
    echo " NTDST_FieldType's property is ->sanitize, not ->sanitizer):"
    echo "$BADSIGNATURES"
    exit 1
fi

if ! grep -q "PHP_SAPI === 'cli'" tests/bootstrap.php; then
    echo "tests/bootstrap.php is missing its CLI guard (PHP_SAPI === 'cli' || exit;)"
    exit 1
fi

exit 0
