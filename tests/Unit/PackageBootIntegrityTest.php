<?php // tests/Unit/PackageBootIntegrityTest.php
// Reproduces the two Cluster-A review Criticals, and closes the blind spot that
// let them through.
//
// The rename deleted ntdst_api_action() and ntdst_router(), but two shipped
// files still called them:
//   admin/RelationField.php:47  → fatal on EVERY request (it is required by
//                                 ntdst-core.php and constructed on after_setup_theme)
//   core/Theme.php:106          → SILENT: the function_exists() guard simply went
//                                 false, so the `router` mixin never registered and
//                                 Theme::single()/page()/archive() throw at call time
//
// The suite was 155/155 green while both were live, because `composer lint` is
// php -l (never resolves a function name), bin/guard.sh only checks ABSPATH, and
// no test loaded either file. An absence test proves the rename HAPPENED; it says
// nothing about whether the package survived it. This asserts survival.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use PHPUnit\Framework\TestCase;

final class PackageBootIntegrityTest extends TestCase
{
    /**
     * Every symbol a release REMOVED, with the release that removed it.
     *
     * The version is part of the row, not decoration: when this test fails,
     * the one thing the reader needs is which upgrade deleted the name they
     * are still calling — that is what tells them whether to fix the caller or
     * to pin the older package. The failure message says it out loud.
     *
     * The FR-5 rows carry the CALL SHAPES as well as the bare names.
     * `surface` on its own is an ordinary English word this codebase uses in
     * prose ("the exposure surface", "typos surface immediately"), so the
     * removed method is pinned as the three ways PHP can reach it — a static
     * call, an instance call, and the property.
     *
     * A third and fourth element EXEMPT one LINE — a path AND a pattern that
     * line must match, both or neither. Only the retired TYPE NAMES need it:
     * the registry (api/FieldTypes.php) has to spell each retired name out in
     * order to answer "use 'int' instead", and it writes `['type' => 'integer']`
     * as a publish column for a live type. Naming a retired name in the entry
     * that retires it is the same exemption README's `## Versions` section
     * already gets.
     *
     * The pattern is what keeps that exemption honest. A whole-FILE exemption
     * would let api/FieldTypes.php grow `new NTDST_FieldType('signed_int', …)`
     * — the retired name back in the registry, in the one file this test agreed
     * not to look at. The retirement entry may say it; nothing else in that
     * file may.
     *
     * @return array<string, array{0: string, 1: string, 2?: string, 3?: string}>
     */
    public static function removedSymbolProvider(): array
    {
        return [
            'ntdst_api_action' => ['ntdst_api_action', '3.0.0'],
            'ntdst_router' => ['ntdst_router', '3.0.0'],
            'ntdst_route' => ['ntdst_route', '3.0.0'],
            'ntdst_endpoints' => ['ntdst_endpoints', '3.0.0'],
            'NTDST_Router' => ['NTDST_Router', '3.0.0'],
            'NTDST_Endpoints' => ['NTDST_Endpoints', '3.0.0'],
            // The sector system left the package: product domain, not
            // framework, and no functional consumer anywhere on the fleet.
            'NTDST_SectorRegistry' => ['NTDST_SectorRegistry', '3.0.0'],
            'ntdst_sectors' => ['ntdst_sectors', '3.0.0'],
            // v5.0.0 — the NTDST_Rest surface registry. WordPress records every
            // route it registers, so a second registry was a copy that could
            // disagree with the original. rest_get_server()->get_routes() is
            // the list now, and README shows the assertion over it.
            'publicSurface' => ['publicSurface', '5.0.0'],
            'opaqueSurface' => ['opaqueSurface', '5.0.0'],
            'forgetSurface' => ['forgetSurface', '5.0.0'],
            'NtdstRestSurfaceTest' => ['NtdstRestSurfaceTest', '5.0.0'],
            'Rest::surface' => ['::surface(', '5.0.0'],
            'Rest->surface' => ['->surface(', '5.0.0'],
            'Rest $surface' => ['$surface', '5.0.0'],
            // v5.0.0 field-types — the model's own type tables. Every one of
            // these was a second vocabulary that could disagree with the first
            // (INV-8): a `bool` sanitized one way on the metabox path and
            // another on the model path, and adding a type meant editing seven
            // places. NTDST_FieldTypes::get() is the table now.
            'getDefaultSanitizer' => ['getDefaultSanitizer', '5.0.0'],
            'sanitizeRepeater' => ['sanitizeRepeater', '5.0.0'],
            'sanitizeBoolean' => ['sanitizeBoolean', '5.0.0'],
            'sanitizeJson' => ['sanitizeJson', '5.0.0'],
            'sanitizeNestedArray' => ['sanitizeNestedArray', '5.0.0'],
            'sanitizeDate' => ['sanitizeDate', '5.0.0'],
            'sanitizeAttachmentId' => ['sanitizeAttachmentId', '5.0.0'],
            // v5.0.0 field-types — the METABOX's own type switch. It was the
            // second half of the same defect: a value posted from the edit
            // screen was cleaned here by one table and again inside the model
            // by another, and the two disagreed (`bool 'false'` was true on
            // this path and false on the model's, `int '-500'` lost its sign
            // here and kept it there). Pinned as the CALL SHAPE rather than the
            // bare word: `sanitize_field` is a plausible name for a future
            // helper and for prose about sanitizing a field, but no shipped
            // line may CALL or DECLARE one.
            'sanitize_field' => ['sanitize_field(', '5.0.0'],
            // The metabox's other two second tables, retired by the same task
            // and pinned the same way (simplicity S17 — one sweep, not three
            // bespoke reflection cases). MARKER_ONLY_REQUIRED_TYPES was a
            // hand-kept list of type names deciding which controls may carry
            // native `required`; the registry entry's own $cell/$control answers
            // that now. render_repeater_media_cell() was the row's copy of the
            // media picker, and a copy of a control is a control that drifts —
            // the row cell and the top-level field must be the same widget.
            'MARKER_ONLY_REQUIRED_TYPES' => ['MARKER_ONLY_REQUIRED_TYPES', '5.0.0'],
            'render_repeater_media_cell' => ['render_repeater_media_cell(', '5.0.0'],
            // The retired type NAME (D4: it folded into a signed `int`). Shipped
            // code may not declare a field with it; the vocabulary's own
            // retirement table is the one place that still says the word.
            'signed_int' => ['signed_int', '5.0.0', 'api/FieldTypes.php', "/^\\s*'signed_int'\\s*=>\\s*'int',\\s*$/"],
            // v5.0.0 field-types — the two 0-reader REST reads of the field
            // description. What shape a field publishes is asked once, by
            // registerRestMeta(); a second PUBLIC way to ask it is a second
            // exposure a consumer can assemble beside the convergence point,
            // which is the thing INV-1 exists to prevent. Neither name is part
            // of any other word, so both are pinned bare.
            'restSubFields' => ['restSubFields', '5.0.0'],
            // The 13 retired type NAMES, pinned by DECLARATION POSITION.
            // Twelve of them are ordinary JSON-Schema and English words, so a
            // bare sweep would hit 617 shipped lines and api/FieldTypes.php's
            // own `['type' => 'integer']` publish column with them. What no
            // shipped line may do is DECLARE a field with one, and a
            // declaration has exactly three shapes: `'type' => '<retired>'`,
            // the bare shorthand `=> '<retired>'`, and
            // `new NTDST_FieldType('<retired>'`. The exemption is stated by
            // CONTENT — a retirement entry, or a registry entry's JSON-Schema
            // leaf — so it anchors to the ROW and not to the file: a real
            // `new NTDST_FieldType('integer', …)` still fires inside the very
            // file that retires the name. bin/guard.sh runs the same three
            // shapes and the same exemption over *.php; this sweep adds
            // README.md, where a retired name in an INSTRUCTION is a wrong
            // instruction.
            'retired type integer' => ["/'type' *=> *'integer'|=> *'integer'|NTDST_FieldType\\('integer'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type signed_int' => ["/'type' *=> *'signed_int'|=> *'signed_int'|NTDST_FieldType\\('signed_int'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type number' => ["/'type' *=> *'number'|=> *'number'|NTDST_FieldType\\('number'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type double' => ["/'type' *=> *'double'|=> *'double'|NTDST_FieldType\\('double'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type decimal' => ["/'type' *=> *'decimal'|=> *'decimal'|NTDST_FieldType\\('decimal'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type boolean' => ["/'type' *=> *'boolean'|=> *'boolean'|NTDST_FieldType\\('boolean'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type string' => ["/'type' *=> *'string'|=> *'string'|NTDST_FieldType\\('string'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type longtext' => ["/'type' *=> *'longtext'|=> *'longtext'|NTDST_FieldType\\('longtext'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type wysiwyg' => ["/'type' *=> *'wysiwyg'|=> *'wysiwyg'|NTDST_FieldType\\('wysiwyg'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type content' => ["/'type' *=> *'content'|=> *'content'|NTDST_FieldType\\('content'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type datetime' => ["/'type' *=> *'datetime'|=> *'datetime'|NTDST_FieldType\\('datetime'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type person' => ["/'type' *=> *'person'|=> *'person'|NTDST_FieldType\\('person'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type post_relation' => ["/'type' *=> *'post_relation'|=> *'post_relation'|NTDST_FieldType\\('post_relation'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'restSchemaFor' => ['restSchemaFor', '5.0.0'],
            // v5.0.0 core-shape (FR-9 / INV-6) — the page router's fight with
            // the WordPress loader. A page URL is a rewrite rule now, so
            // WordPress parses it: handleTemplateInclude() re-matched
            // REQUEST_URI against a private regex inside `template_include`,
            // commitOk() cleared the not-found flag WordPress had set on the
            // way past, preventRedirectForRoutes() answered the canonical
            // redirect filter so the loader could not undo that, and
            // renderResponse() rendered-and-exited from inside a template
            // filter. resolveRouteResult() was the return contract that tied
            // them together — a Response with a >=400 status meant "refuse", a
            // shape a callback returning a PATH has no need of.
            //
            // Five bare rows and not six: `redirect` is the sixth method the
            // task removed and it CANNOT be swept bare — api/Response.php
            // declares the surviving redirect() and README documents it, so the
            // row would fire on the survivor. It is pinned where it can come
            // back instead, as a `function redirect` declaration in
            // core/Pages.php (bin/guard.sh METHOD_PINS), and
            // NtdstPagesTest::testTheRouterNoLongerFightsTheWordPressLoader()
            // pins its absence by reflection. Each of these five is a
            // distinctive name no shipped line spells outside a comment.
            'handleTemplateInclude' => ['handleTemplateInclude', '5.0.0'],
            'resolveRouteResult' => ['resolveRouteResult', '5.0.0'],
            'commitOk' => ['commitOk', '5.0.0'],
            'renderResponse' => ['renderResponse', '5.0.0'],
            'preventRedirectForRoutes' => ['preventRedirectForRoutes', '5.0.0'],
            // v5.0.0 core-trim (FR-1, INV-10) — Bootstrap's service scanner and
            // the two config keys that armed it. The scanner globbed
            // `*Service.php` under `services.discovery_paths`, `require_once`d
            // every hit and regex-parsed the source for its class name; a
            // writable directory on that list was code execution. It had zero
            // users: all five consumer sites set `auto_discover => false` and
            // load their services by `require_once` or by Composer.
            //
            // The two KEYS are swept as well as the two methods, and that is
            // the point of the pair. Deleting the methods while a shipped line
            // still reads `$config['services']['auto_discover']` leaves the
            // switch half-alive — a config key core consults and then does
            // nothing with is exactly the "loads nothing by guessing" promise
            // read back as a maybe. `discoverServicesInPath` needs no row of
            // its own: `discoverServices` is a substring of it.
            //
            // A consumer config may still CARRY both keys — core simply never
            // reads them (AF-4), and README's core-trim table says so. This
            // sweep is over what the PACKAGE ships, not over what a site writes.
            'discoverServices' => ['discoverServices', '5.0.0'],
            'getClassNameFromFile' => ['getClassNameFromFile', '5.0.0'],
            'auto_discover' => ['auto_discover', '5.0.0'],
            'discovery_paths' => ['discovery_paths', '5.0.0'],
            // v5.0.0 core-trim (FR-2) — the per-service enable switch and the
            // read-only copies of the service registry.
            //
            // `ntdst_service_` is swept as a PREFIX, and that is deliberate: it
            // is the shared stem of the retired option `ntdst_service_{slug}`,
            // the retired DENY filter `ntdst_service_{slug}_enabled` and the
            // retired config filter `ntdst_service_{slug}_config`, and every
            // one of them is interpolated (`"ntdst_service_{$slug}_enabled"`),
            // so a row per full name would match nothing. The stem is the only
            // shape a sweep can see. The enable switch failed OPEN — a filter
            // nobody answers returns true — so a half-removal that leaves one
            // interpolation behind is a service a site believes is off and is
            // not, which is the exact wart philosophy §4 records.
            //
            // The four accessors had zero readers across daan, josworld,
            // stride, todai and netdust: a second, read-only copy of the
            // registry that could disagree with the original.
            //
            // README.md is the one file whose MIGRATION ROWS may spell them —
            // naming what was removed is the entire job of that table, and the
            // exemption is by ROW (a table line whose first cell is a code
            // span), not by file, so a README that grows a live INSTRUCTION
            // using a retired name still fails. The `## Versions` section is
            // already exempt wholesale; this keeps the rows honest wherever the
            // table ends up.
            'ntdst_service_' => ['ntdst_service_', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'getServiceConfig' => ['getServiceConfig', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // The fifth accessor. FR-2 names five and the four rows around this
            // one pinned four, so `getServices()` — the read-only copy of the
            // whole registry, and the widest of the five — could come back
            // without a single test noticing. `discoverServices` does not cover
            // it: neither name contains the other.
            'getServices' => ['getServices', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'getBootedServices' => ['getBootedServices', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'hasService' => ['hasService', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'isBooted' => ['isBooted', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // v5.0.0 core-trim (FR-4) — the SECOND query API and the term
            // helpers. `getFormattedPosts()` and its global front door
            // `ntdst_get_formatted_posts()` answered "give me rows" without a
            // model and therefore without a schema, beside the chain that
            // answers it with one. Two read paths on one layer is the defect
            // find()/getMeta() already produced here once: a consumer that
            // gates with the path carrying the declaration and reads with the
            // path that does not has a bypass, and neither call site looks
            // wrong. The engine survives as the model's private business;
            // `getPostTerms()` was its public half and goes with it.
            //
            // The three term writers wrapped one `wp_set_post_terms()` call
            // each and had zero readers on the fleet, and `whereDate()`/
            // `orWhere()` restate `date_query` and a flat root-level OR
            // `meta_query` — arguments WordPress names better than the builder
            // does, since the builder cannot express the nested groups an
            // `orWhere()` caller usually means.
            //
            // These rows are what make the removal LOUD rather than silent.
            // Two shipped callers were live when the rows landed — admin/
            // RelationField.php's relation picker on the global, and
            // services/Logger.php's clearOld() on whereDate() — and neither is
            // covered by a test, so without this sweep both would have shipped
            // as a fatal nobody's suite could see. Each name is its own word
            // here: none is a substring of another (`detachTerms` does not
            // contain `attachTerms`), and none appears in README or docs, so
            // all nine are pinned bare with no exemption.
            //
            // `getPostMeta` is the ninth and landed a gate later than the rest:
            // T04 deleted the static without a row, so the one FR-4 removal with
            // a KNOWN outside reader (josworld's cached-meta accessor) was the
            // one removal this sweep could not see. It is pinned like its
            // siblings — bare, and not a substring of any other name here:
            // WordPress's own `get_post_meta()` is snake_case, and
            // `getPostMetaFromCache()` (the accessor josworld actually calls)
            // does not ship in core.
            'getFormattedPosts' => ['getFormattedPosts', '5.0.0'],
            'ntdst_get_formatted_posts' => ['ntdst_get_formatted_posts', '5.0.0'],
            'getPostMeta' => ['getPostMeta', '5.0.0'],
            'getPostTerms' => ['getPostTerms', '5.0.0'],
            'attachTerms' => ['attachTerms', '5.0.0'],
            'syncTerms' => ['syncTerms', '5.0.0'],
            'detachTerms' => ['detachTerms', '5.0.0'],
            'whereDate' => ['whereDate', '5.0.0'],
            'orWhere' => ['orWhere', '5.0.0'],
            // v5.0.0 core-trim (FR-5) — the Logger's database half and its
            // runtime handler API.
            //
            // The database half was a PII sink armed by a filter: on every
            // error it wrote REQUEST_URI, the client IP and the whole context
            // array into post meta, and it was ON whenever WP_DEBUG was — which
            // is what an operator turns on DURING the incident. It also
            // answered each error with wp_insert_post plus N meta writes plus a
            // save_post cascade, at the worst possible moment to do so.
            //
            // The two rows that matter most here are the CONSTRUCTOR's, not the
            // handler's. Registering the post type reached the Data layer from
            // NTDST_Logger::__construct(), so services/Logger.php could not
            // load before api/Data.php — which is the whole reason core's call
            // sites guarded their logging with function_exists('ntdst_log')
            // (FR-3, I-2). A stray reference left behind here is that load
            // order quietly coming back.
            //
            // The handler API let a consumer add a sink, move the level gate or
            // switch batching off from anywhere at runtime, so no call site
            // could say where a line would end up. Zero readers across daan,
            // josworld and stride.
            //
            // All nine are pinned BARE: none is a substring of another, none
            // appears in README, and each is either a distinctive method name
            // or an `ntdst_`-prefixed global. `recent()` and `clearOld()` are
            // FR-5 removals with no row, deliberately — they are ordinary
            // English words that a bare sweep would find in prose. What pins
            // those two is LoggerSurfaceTest's exact public-method list, which
            // is the stronger check anyway: it fails on a name coming BACK as
            // well as on one that never left.
            'log_entry' => ['log_entry', '5.0.0'],
            'ntdst_log_database_enabled' => ['ntdst_log_database_enabled', '5.0.0'],
            'addHandler' => ['addHandler', '5.0.0'],
            'removeHandler' => ['removeHandler', '5.0.0'],
            'setMinLevel' => ['setMinLevel', '5.0.0'],
            'setBatchingEnabled' => ['setBatchingEnabled', '5.0.0'],
            'ntdst_log_debug' => ['ntdst_log_debug', '5.0.0'],
            'ntdst_log_info' => ['ntdst_log_info', '5.0.0'],
            'ntdst_log_error' => ['ntdst_log_error', '5.0.0'],
            // v5.0.0 core-trim (FR-11) — the six model lifecycle hooks, retired
            // spellings. They are renamed, not deleted: `ntdst/model/creating`,
            // `created`, `updating`, `updated`, `deleting`, `deleted` fire in
            // their place with the same arguments, and that is exactly why the
            // old names need a sweep rather than a test alone. A rename leaves
            // no fatal behind — a shipped `add_action('ntdst_model_create_after')`
            // simply never runs again, and nothing in a green suite or a php -l
            // pass can see a listener that stopped listening. This is the
            // silent-inert failure the plan's threat row #4 names, and daan's
            // PressKitService is a live instance of it (renamed by T12).
            //
            // All six are pinned BARE. Each is a full, distinctive hook name; no
            // other word contains one, and none is a substring of another
            // (`ntdst_model_create_before` and `..._after` differ in their tail).
            // A prefix sweep on `ntdst_model_` would work equally well today,
            // but the six names are what FR-11 enumerates and what README's
            // migration table will spell, so the rows mirror the requirement.
            //
            // README.md's MIGRATION ROWS may spell them — naming what changed is
            // the entire job of that table (T13) — and the exemption is by ROW
            // (a table line whose first cell is a code span), never by file, so a
            // README that grows a live INSTRUCTION using a retired name still
            // fails. Same shape the `ntdst_service_` rows above use.
            'ntdst_model_create_before' => ['ntdst_model_create_before', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_model_create_after' => ['ntdst_model_create_after', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_model_update_before' => ['ntdst_model_update_before', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_model_update_after' => ['ntdst_model_update_after', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_model_delete_before' => ['ntdst_model_delete_before', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_model_delete_after' => ['ntdst_model_delete_after', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // v5.0.0 core-trim: the container's second resolution path and the
            // cache that armed method injection (FR-6 / FR-10). `make()` resolved
            // WITHOUT the singleton cache, so the same binding could be one
            // object on one call site and another on the next — a lifecycle no
            // declaration named. `ntdst_make` was its GLOBAL front door, which
            // is the spelling a consumer reaches for first, and the only one of
            // the five removals that can appear outside a `$container->` call.
            // `callableReflections` was call()'s reflection cache: call() is the
            // only thing that ever wrote it, so a surviving cache is method
            // injection surviving under another name.
            //
            // Both are pinned BARE — neither is a substring of another word in
            // this package, and neither appears in README. `make`, `call`,
            // `forget`, `flush` and `keys` are NOT rows here and cannot be: they
            // are ordinary English words this codebase writes constantly ("make
            // fresh", "the call site", "flush the cache"), and a bare sweep on
            // any of them hits hundreds of prose lines while a call-shape sweep
            // (`->make(`) would miss `$c->make(` split across a wrapped line.
            // bin/guard.sh pins those five instead, at the ONE place they could
            // come back — as declarations in core/Container.php — with
            // ContainerSurfaceTest's exact public-method list as the harder
            // pin beside it.
            'ntdst_make' => ['ntdst_make', '5.0.0'],
            'callableReflections' => ['callableReflections', '5.0.0'],
            // v5.0.0 core-trim (FR-7) — the Scheduler leaves the package.
            // WordPress already has a recurring-task primitive (wp_schedule_event /
            // wp_next_scheduled), so a second one inside core was a copy that could
            // disagree with it. stride's GateReminderService is the one consumer;
            // it writes the two WordPress lines directly (T11). All four are pinned
            // bare — none is a substring of another, and none appears in README.
            'NTDST_Scheduler' => ['NTDST_Scheduler', '5.0.0'],
            'ntdst_scheduler' => ['ntdst_scheduler', '5.0.0'],
            'ntdst_schedule_recurring' => ['ntdst_schedule_recurring', '5.0.0'],
            'ntdst_clear_recurring' => ['ntdst_clear_recurring', '5.0.0'],
            // v5.0.0 core-trim (FR-8) — NTDST_Theme's mixin mechanism. It
            // proxied `data`/`pages`/`response`/`log`/`mail` through __call(),
            // so a theme reached another layer by a magic method instead of by
            // that layer's own name; the surface could not be read without
            // running it. A theme writes `ntdst_data()` now.
            //
            // Only these TWO of the five removed names get a row, and which
            // ones is the whole decision. `wireMixins` and `templatePath` are
            // distinctive: neither is a substring of another word, neither
            // appears in README, and a surviving caller of either is a
            // call-time fatal — exactly what this sweep exists to catch (the
            // Cluster-A header above is core/Theme.php's own instance of it).
            //
            // `__call`, `mixin` and `when` CANNOT have a row here. `when` is
            // ordinary English this codebase writes constantly, and
            // core/Pages.php declares a LIVE when() of its own
            // (`ntdst_pages()->when(...)`) — a bare row would fail on a
            // shipped feature, and a call-shape row (`->when(`) would fail on
            // its real callers. All three are pinned instead at the one place
            // they can come back: a `function <name>` declaration in
            // core/Theme.php, by bin/guard.sh's THEMEMETHODS line and by
            // ThemeTrimTest's reflection assertion.
            'wireMixins' => ['wireMixins', '5.0.0'],
            'templatePath' => ['templatePath', '5.0.0'],
            // v5.0.0 core-trim (FR-9) - the Mailer leaves the package. Core
            // sends no mail: WordPress has wp_mail(), and the one consumer on
            // the fleet (stride's netdust-mail) now owns the class outright as
            // `Netdust\\Mail\\Mailer` (T11). A mail builder inside the framework
            // was a second, core-flavoured spelling of a WordPress primitive,
            // and it dragged eight `ntdst_` underscore hooks along with it -
            // the last ones in the package, which is why SC-4 closes here.
            //
            // Thirteen rows, and the split is deliberate. The four helper
            // FUNCTIONS and the class are the names a consumer CALLS; the eight
            // hook and option names are the names a consumer LISTENS on, and a
            // listener that outlives its hook fails silently (plan threat row
            // #4) rather than fatally, so it needs its own pin.
            //
            // `ntdst_mail` is deliberately a PREFIX row: it covers the helper
            // and every `ntdst_mail_*` hook in one sweep. The four `ntdst_mail_*`
            // rows below are kept beside it anyway, because FR-9 enumerates them
            // and a future edit that narrows the prefix row must not silently
            // un-pin four hook names with it.
            //
            // All thirteen carry README's MIGRATION-ROW exemption, the same
            // shape the `ntdst_service_` and `ntdst_model_*` rows use: the
            // migration table may SPELL a retired name (that is the table's
            // whole job), while a live INSTRUCTION anywhere else in README that
            // still uses one fails. `## Versions` is section-exempt already;
            // the row exemption is what holds if the table ever moves out.
            'NTDST_Mailer' => ['NTDST_Mailer', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_mail' => ['ntdst_mail', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_send_mail' => ['ntdst_send_mail', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_notify' => ['ntdst_notify', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_wrap_email_in_layout' => ['ntdst_wrap_email_in_layout', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_send_queued_mail' => ['ntdst_send_queued_mail', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_notification' => ['ntdst_notification', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_mail_before_send' => ['ntdst_mail_before_send', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_mail_sent' => ['ntdst_mail_sent', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_mail_template_paths' => ['ntdst_mail_template_paths', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_mail_attachment_bases' => ['ntdst_mail_attachment_bases', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_email_layout_paths' => ['ntdst_email_layout_paths', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_wrap_all_emails' => ['ntdst_wrap_all_emails', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // v5.0.0 core-shape: the command dispatcher leaves the package
            // (FR-7). `NTDST_Actions` owned `POST /ntdst/v1/action` and
            // `POST /ntdst/v1/get_nonce`, verified the `Origin` header itself
            // and minted its own nonce; `assets/js/ntdst-api.js` was the
            // browser half (`window.ntdstAPI`) and `ntdst_enqueue_api_client()`
            // put it on the page. A resource route through `ntdst_rest()` and
            // `wp.apiFetch` do all of it now, on WordPress's own CSRF (INV-2,
            // INV-4).
            //
            // Eleven rows in three families, and the split is the same one the
            // Mailer rows make. The class, the two globals and the browser
            // object are the names a consumer CALLS — a survivor is a
            // call-time fatal. The two FILTER names are what a consumer
            // LISTENS on: `ntdst/api_data/{action}` is interpolated, so the
            // row is the STEM `ntdst/api_data`, and a handler that outlives
            // its dispatcher goes quiet instead of fataling, which is the
            // failure a sweep has to see. `get_nonce` is the retired ROUTE
            // segment — bare, because nothing else in the package spells it.
            //
            // The four `api*` envelopes are METHODS of NTDST_Response and are
            // pinned bare here rather than by call shape: none is an ordinary
            // English word, none appears in prose, and `apiSuccess` /
            // `apiError` are substrings of their `*Response` siblings, so the
            // pairs are listed anyway for the same reason the `ntdst_mail_*`
            // hooks are — FR-7 enumerates four, and narrowing one row must not
            // silently un-pin two. bin/guard.sh pins the same four as
            // DECLARATIONS in api/Response.php (METHOD_PINS), because a *.php
            // sweep on a bare `apiError` would also have to answer for README.
            //
            // All eleven carry README's MIGRATION-ROW exemption, the shape the
            // `ntdst_service_` and `ntdst_model_*` rows use: the migration
            // table may SPELL a removed name, while a live INSTRUCTION
            // elsewhere in README that still uses one fails.
            'ntdst_actions' => ['ntdst_actions', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'NTDST_Actions' => ['NTDST_Actions', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst_enqueue_api_client' => ['ntdst_enqueue_api_client', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdstAPI' => ['ntdstAPI', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'get_nonce' => ['get_nonce', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst/api_data' => ['ntdst/api_data', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'ntdst/api/public_actions' => ['ntdst/api/public_actions', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'apiSuccessResponse' => ['apiSuccessResponse', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'apiErrorResponse' => ['apiErrorResponse', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'apiSuccess' => ['apiSuccess', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'apiError' => ['apiError', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // v5.0.0 core-shape: the hand-listed template hierarchy and the
            // second path registry leave with it (FR-10, INV-5/INV-6).
            //
            // `templateInclude` is pinned BARE: it is a distinctive name, no
            // shipped PHP spells it any more, and the only place it survives is
            // README's migration row — which is what the row exemption is for.
            // bin/guard.sh pins the same name as a DECLARATION in
            // core/TemplateLoader.php (METHOD_PINS), the shape the four `api*`
            // envelopes use, because a *.php sweep on it would also have to
            // answer for README.
            //
            // `addPath` CANNOT be a bare row and that is the whole reason this
            // one is a call shape: NTDST_Template_Loader::addPath() SURVIVES —
            // it is the one registry FR-10 converged on — so a bare sweep would
            // fire on the method this task kept. What went is the INSTANCE half
            // on NTDST_Response, and `->addPath(` is the only way PHP reaches
            // it. `::addPath(` is deliberately NOT pinned; it is the survivor.
            'templateInclude' => ['templateInclude', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            '->addPath(' => ['->addPath(', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
        ];
    }

    /**
     * @dataProvider removedSymbolProvider
     */
    public function testNoShippedFileReferencesARemovedSymbol(string $symbol, string $removedIn, string $exceptPath = '', string $exceptLine = ''): void
    {
        $hits = $this->sweep(dirname(__DIR__, 2), $symbol, $exceptPath, $exceptLine);

        $this->assertSame(
            [],
            $hits,
            "{$symbol} was removed in v{$removedIn} but is still referenced in shipped code:\n"
                . implode("\n", $hits),
        );
    }

    /**
     * The throwaway trees the exemption is judged on.
     *
     * Each case names a row of removedSymbolProvider() and the file that row's
     * exemption has to survive: the retirement entry, the registry's own
     * JSON-Schema line, and a `new NTDST_FieldType(<retired>)` line that would
     * put the name back into the registry. Only the last one is a hit.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function rowShapedExemptionProvider(): array
    {
        return [
            'signed_int — pinned bare' => ['signed_int', [
                "        'signed_int'    => 'int',",
                "            new NTDST_FieldType('signed_int', \$cast, ['type' => 'integer'], 'number', true),",
            ]],
            'integer — pinned by declaration position' => ['retired type integer', [
                "        'integer'       => 'int',",
                "                ['type' => 'integer'], 'number', true,",
                "            new NTDST_FieldType('integer', \$cast, ['type' => 'integer'], 'number', true),",
            ]],
        ];
    }

    /**
     * A retired name may be spelled by the ONE line that retires it, and by no
     * other line in that file.
     *
     * The sweep runs over a throwaway tree, because the promise is about a file
     * api/FieldTypes.php could GROW, not about the file it is today: a
     * whole-file exemption passes this and a row exemption does not.
     *
     * The symbol and the exemption are READ OFF the provider row rather than
     * copied here. A hand-copy proves a regex this file wrote; the shipped row
     * is what guard.sh and this suite actually run.
     *
     * @param list<string> $lines
     *
     * @dataProvider rowShapedExemptionProvider
     */
    public function testTheRetirementLineIsExemptAndNothingElseInThatFileIs(string $row, array $lines): void
    {
        $rows = self::removedSymbolProvider();

        $this->assertArrayHasKey($row, $rows, "removedSymbolProvider() must pin '{$row}'.");

        [$symbol, , $exceptPath, $exceptLine] = $rows[$row] + [2 => '', 3 => ''];

        $root = sys_get_temp_dir() . '/ntdst-sweep-' . getmypid() . '-' . uniqid();
        mkdir($root . '/api', 0777, true);
        file_put_contents($root . '/api/FieldTypes.php', implode("\n", ['<?php', ...$lines, '']));

        try {
            $hits = $this->sweep($root, $symbol, $exceptPath, $exceptLine);

            $this->assertCount(1, $hits, "Exactly one line is a hit — the retirement entry is exempt:\n" . implode("\n", $hits));
            $this->assertStringContainsString('api/FieldTypes.php:' . (count($lines) + 1), $hits[0]);
            $this->assertStringContainsString('new NTDST_FieldType', $hits[0], 'The registry may not grow the retired name back.');
        } finally {
            unlink($root . '/api/FieldTypes.php');
            rmdir($root . '/api');
            rmdir($root);
        }
    }

    /**
     * A REST `args` schema line is exempt in ANY file; a bare field
     * declaration in the same file still fires.
     *
     * The route that declares `GET /ntdst/v1/relation/search` writes
     * `'type' => 'string'` because WordPress's `register_rest_route()` reads
     * JSON-Schema, and the retired type NAMES are JSON-Schema words. Without
     * this distinction the pin turns every REST argument in the package into a
     * retired-type declaration, and the cure an author reaches for is a
     * per-file exemption — which is the whole-file exemption this pin exists
     * to refuse.
     *
     * So it is judged on CONTENT and on a throwaway tree that is not
     * api/FieldTypes.php: the exempt lines carry an arg key beside the type,
     * the hit does not, and the file they sit in decides nothing.
     */
    public function testARestArgSchemaLineIsExemptAnywhereAndABareTypeDeclarationStillFires(): void
    {
        $rows = self::removedSymbolProvider();

        $this->assertArrayHasKey('retired type string', $rows, "removedSymbolProvider() must pin 'retired type string'.");

        [$symbol, , $exceptPath, $exceptLine] = $rows['retired type string'] + [2 => '', 3 => ''];

        $lines = [
            // WordPress's args vocabulary, one argument per line — exempt.
            "                'search' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],",
            "                'post_type' => ['type' => 'array', 'items' => ['type' => 'string']],",
            "                'status' => ['type' => 'string', 'enum' => ['publish', 'draft']],",
            // A field declaration wearing the retired name — still a hit, in
            // the same file, three lines down.
            "        'blurb' => ['type' => 'string'],",
        ];

        $root = sys_get_temp_dir() . '/ntdst-sweep-rest-' . getmypid() . '-' . uniqid();
        mkdir($root . '/admin', 0777, true);
        file_put_contents($root . '/admin/RelationField.php', implode("\n", ['<?php', ...$lines, '']));

        try {
            $hits = $this->sweep($root, $symbol, $exceptPath, $exceptLine);

            $this->assertCount(
                1,
                $hits,
                "A REST arg schema line is WordPress's vocabulary and must not be read as a field declaration:\n"
                    . implode("\n", $hits),
            );
            $this->assertStringContainsString(
                'admin/RelationField.php:' . (count($lines) + 1),
                $hits[0],
                'The bare `type => string` declaration is the one line that fires.',
            );
        } finally {
            unlink($root . '/admin/RelationField.php');
            rmdir($root . '/admin');
            rmdir($root);
        }
    }

    /**
     * SPLIT RED — cluster-3 fix wave F1. Written by the independent
     * test-author; the implementer greens it without weakening an assertion.
     *
     * `required` ALONE does not make a line WordPress's vocabulary.
     *
     * The exemption above says a line is a REST `args` schema when it carries a
     * `'type' => '<name>'` beside one of WordPress's own arg keys. `required`
     * is not one of WordPress's own words: the field registry spells it too, so
     * `'title' => ['type' => 'string', 'required' => true],` — a FIELD
     * declaration wearing a retired type name — reads as exempt and walks past
     * the pin. The discriminator has to be a key only a route writes:
     * `sanitize_callback`, `validate_callback`, `items` or `enum`.
     *
     * The three exempt lines below are admin/RelationField.php:70-71 as
     * shipped, plus an `enum` sibling, so the narrowing cannot be done by
     * deleting the exemption: the two lines the package really ships must stay
     * out of the hits.
     */
    public function testARequiredFlagAloneDoesNotExemptAFieldDeclaration(): void
    {
        $rows = self::removedSymbolProvider();

        $this->assertArrayHasKey('retired type string', $rows, "removedSymbolProvider() must pin 'retired type string'.");

        [$symbol, , $exceptPath, $exceptLine] = $rows['retired type string'] + [2 => '', 3 => ''];

        $lines = [
            // admin/RelationField.php:70-71 as shipped — still exempt.
            "                'search'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],",
            "                'post_type' => ['type' => 'array', 'items' => ['type' => 'string']],",
            // WordPress's `enum`, the fourth discriminator — still exempt.
            "                'status'    => ['type' => 'string', 'enum' => ['publish', 'draft']],",
            // A FIELD declaration that says `required`. This is the hit.
            "        'title' => ['type' => 'string', 'required' => true],",
        ];

        $root = sys_get_temp_dir() . '/ntdst-sweep-required-' . getmypid() . '-' . uniqid();
        mkdir($root . '/admin', 0777, true);
        file_put_contents($root . '/admin/RelationField.php', implode("\n", ['<?php', ...$lines, '']));

        try {
            $hits = $this->sweep($root, $symbol, $exceptPath, $exceptLine);

            $this->assertCount(
                1,
                $hits,
                "`required` is the registry's word too, so it cannot be what tells a route's args from a field "
                    . "declaration. Exactly the `'title'` line must fire, and the two shipped arg lines must not:\n"
                    . implode("\n", $hits),
            );
            $this->assertStringContainsString(
                'admin/RelationField.php:' . (count($lines) + 1),
                $hits[0],
                "The field declaration that says `'required' => true` is the one line that fires.",
            );
        } finally {
            unlink($root . '/admin/RelationField.php');
            rmdir($root . '/admin');
            rmdir($root);
        }
    }

    /**
     * SPLIT RED — cluster-3 fix wave F1, the shell half.
     *
     * bin/guard.sh carries the same rule as a `grep -E` alternation, and the
     * two homes are a mirror by hand. There is no subprocess seam for guard.sh
     * anywhere in tests/, so this half is asserted on the SOURCE line: the
     * discriminator set must lose `required` and keep the other four. Losing
     * the whole exemption would be the other way to make `required` stop
     * exempting, and it would fire on the two lines the package ships — hence
     * the second half of the assertion.
     */
    public function testTheShellGuardDoesNotExemptOnRequiredAlone(): void
    {
        $guard = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/guard.sh');

        $this->assertSame(
            1,
            preg_match('/^REST_ARG_SCHEMA_LINE=.*$/m', $guard, $matched),
            'bin/guard.sh must declare REST_ARG_SCHEMA_LINE on one line.',
        );

        $this->assertStringNotContainsString(
            'required',
            $matched[0],
            "bin/guard.sh's REST arg-schema exemption still accepts `required` as the discriminator. "
                . "A field declaration spells `required` too, so the shell home exempts "
                . "`'title' => ['type' => 'string', 'required' => true],` exactly as the PHP mirror did.",
        );

        foreach (['sanitize_callback', 'validate_callback', 'items', 'enum'] as $keyword) {
            $this->assertStringContainsString(
                $keyword,
                $matched[0],
                "Narrowing the exemption is not deleting it: `{$keyword}` is a key only a route writes, and "
                    . 'admin/RelationField.php:70-71 must stay exempt.',
            );
        }
    }

    /**
     * SPLIT RED — cluster-3 fix wave F4.
     *
     * No migration row hands an adopter an option `ntdst_rest()` refuses.
     *
     * Two shapes, and both are a row that reads as an instruction. The first is
     * `'permission_callback' => …` written INSIDE an `ntdst_rest()` call: the
     * option is not in the declaration's known set, so the route refuses and
     * never registers — the adopter follows README and loses the endpoint. The
     * second is a `Now` cell that OFFERS `'__return_true'` as the value of a
     * permission: on this framework a permission string is a CAPABILITY, so
     * `'__return_true'` becomes `current_user_can('__return_true')` and answers
     * 403 forever (INV-3).
     *
     * The `publicSurface()` row is the shape that must stay: it DESCRIBES
     * reading WordPress's own register back and filtering on a
     * `permission_callback` that is `'__return_true'`. It states a fact about
     * WordPress's route table; it does not assign the value in a declaration.
     * That is why the second check reads the ASSIGNMENT and not the word.
     */
    public function testNoReadmeMigrationRowSpellsARefusedRestOption(): void
    {
        $lines = explode("\n", $this->readmeText());

        $refused = [];
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'ntdst_rest(') && preg_match("/'permission_callback' *=>/", $line) === 1) {
                $refused[] = 'README.md:' . ($index + 1) . ' — ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $refused,
            "`permission_callback` is not an `ntdst_rest()` option: the declaration refuses an unknown option and "
                . "the route never registers. The row must offer `['permission' => '<capability>']` or a callable:\n"
                . implode("\n", $refused),
        );

        $offered = [];
        $inVersion = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^### /', $line) === 1) {
                $inVersion = str_starts_with($line, '### 5.0.0');

                continue;
            }
            if (!$inVersion || !str_starts_with(ltrim($line), '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            $now   = (string) end($cells);

            if (preg_match("/=> *'__return_true'/", $now) === 1) {
                $offered[] = 'README.md:' . ($index + 1) . ' — ' . $now;
            }
        }

        $this->assertSame(
            [],
            $offered,
            "A `Now` cell assigns `'__return_true'` as a permission. On this framework a permission is a "
                . "CAPABILITY name, so that resolves to `current_user_can('__return_true')` — 403 forever, for "
                . "everybody, on a route the row says is public. The public spelling is `->public()` (INV-3):\n"
                . implode("\n", $offered),
        );
    }

    /**
     * SPLIT RED — cluster-3 fix wave F4, the structure list.
     *
     * `api/` no longer holds an Actions layer, so the bullet an adopter reads
     * first must not name one. A structure list that names a deleted file is
     * the cheapest possible wrong instruction.
     */
    public function testTheApiStructureBulletNoLongerNamesActions(): void
    {
        $bullets = array_values(array_filter(
            explode("\n", $this->readmeText()),
            static fn (string $line): bool => str_starts_with(trim($line), '- `api/`'),
        ));

        $this->assertCount(1, $bullets, "README's structure list must describe `api/` exactly once.");
        $this->assertStringNotContainsString(
            'Actions',
            $bullets[0],
            'The command dispatcher left with 5.0.0. The request flow is Rest, Data, Response: ' . trim($bullets[0]),
        );
    }

    /**
     * SPLIT RED — cluster-3 fix wave F4, the philosophy doc.
     *
     * `docs/philosophy.md` still explains the permission model as an
     * allow-list filter on a router that no longer exists. It is the document
     * that tells a reader HOW the package decides who may call something, so a
     * deleted model described there is a reader wiring a filter nothing reads.
     */
    public function testThePhilosophyDocDescribesNoDeletedPermissionModel(): void
    {
        $philosophy = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/philosophy.md');

        $this->assertSame(
            0,
            preg_match_all('#ntdst/api/public_actions#', $philosophy),
            "`ntdst/api/public_actions` is deleted. Permission is stated on the route — `['permission' => …]` or "
                . '`->public()` — and docs/philosophy.md is where a reader learns that.',
        );
        $this->assertSame(
            0,
            preg_match_all('/NTDST_Actions/', $philosophy),
            'NTDST_Actions is deleted; the doc still settles its questions through it.',
        );
    }

    /**
     * A markdown HEADING is not a PHP comment.
     *
     * The sweep skips a line that opens with `*`, `//`, `#` or `/*`, because a
     * PHP comment may DISCUSS a removed name. README is swept by the same
     * loop, and in markdown `#` opens a HEADING — so `### Configuring
     * discovery_paths` was exempt, which is the loudest possible place to
     * still name a symbol the release deleted. The `#` rule is PHP's, and it
     * applies to PHP files only. The other three shapes stay whole-corpus:
     * README's code fences are PHP, and a `//` line inside one is a comment
     * wherever it sits.
     */
    public function testAReadmeHeadingIsSweptAndNotSkippedAsAPhpComment(): void
    {
        $root = sys_get_temp_dir() . '/ntdst-sweep-md-' . getmypid() . '-' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents(
            $root . '/README.md',
            "# ntdst-core\n\n### Configuring discovery_paths\n\nList the folders to scan.\n",
        );

        try {
            $hits = $this->sweep($root, 'discovery_paths');

            $this->assertCount(
                1,
                $hits,
                "A README heading naming a removed symbol is a wrong instruction, not a comment:\n" . implode("\n", $hits),
            );
            $this->assertStringContainsString('README.md:3', $hits[0]);
        } finally {
            unlink($root . '/README.md');
            rmdir($root);
        }
    }

    /**
     * Every shipped line under $root that spells $symbol, except a line that
     * matches BOTH $exceptPath and $exceptLine.
     *
     * A $symbol beginning with `/` is a REGEX, so one row can pin the three
     * shapes a field DECLARATION takes without three near-identical rows. A
     * bare name stays a substring — a removed function is a substring question.
     *
     * @return list<string>
     */
    private function sweep(string $root, string $symbol, string $exceptPath = '', string $exceptLine = ''): array
    {
        $hits = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            // README.md is swept too: it is the first thing an adopter reads,
            // so a retired name in an INSTRUCTION is a wrong instruction, not a
            // typo. Its `## Versions` section is exempt — naming what was
            // removed is the entire job of a changelog line, and this test bit
            // that section the first time it ran.
            if (!str_ends_with($path, '.php') && $path !== $root . '/README.md') {
                continue;
            }
            $section = '';
            // vendor/ is third-party; tests/ and specs/ legitimately NAME the
            // removed symbols in order to assert their absence.
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/specs/')) {
                continue;
            }
            // The one file whose retirement LINE a row may exempt (see the
            // provider). The file itself is swept like any other.
            $exempt = $exceptPath !== '' && $exceptLine !== '' && str_contains($path, $exceptPath);

            foreach (file($path) as $n => $line) {
                if (str_starts_with($line, '## ')) {
                    $section = trim(substr($line, 3));
                }
                // `## Versions` is a changelog, and a MIGRATION ROW names a
                // removed symbol because naming it is the row's whole job. The
                // SECTION is not exempt: it is 500-odd lines, and an
                // INSTRUCTION that still spells a removed name is a wrong
                // instruction wherever it sits — README:90 told an adopter to
                // check a filter 5.0.0 had deleted, inside the section this
                // test agreed not to read. The exemption is the ROW SHAPE plus
                // the prose lines named in VERSIONS_PROSE_ALLOWANCES, each with
                // its reason. A further one has to be argued for.
                if ($section === 'Versions' && $this->isExemptVersionsLine($line)) {
                    continue;
                }

                // A comment may discuss a removed name; a call may not. `#`
                // is the one shape that is not portable: in markdown it opens
                // a HEADING, and a heading naming a removed symbol is the
                // loudest wrong instruction in the file. So `#` is skipped in
                // PHP only. The other three are PHP-comment shapes wherever
                // they sit, README's code fences included.
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                    continue;
                }
                if (str_ends_with($path, '.php') && str_starts_with($code, '#')) {
                    continue;
                }
                if ($exempt && preg_match($exceptLine, $line) === 1) {
                    continue;
                }
                // The retired-type rows, and only those: they are the family
                // whose names collide with JSON-Schema, so they are the family
                // a REST `args` declaration trips. No path — a route may be
                // declared in any shipped file.
                if (
                    str_starts_with($symbol, "/'type' *=> *'")
                    && preg_match(self::REST_ARG_SCHEMA_LINE, $line) === 1
                ) {
                    continue;
                }
                $spelled = str_starts_with($symbol, '/')
                    ? preg_match($symbol, $line) === 1
                    : str_contains($line, $symbol);

                if ($spelled) {
                    $hits[] = str_replace($root . '/', '', $path) . ':' . ($n + 1) . ' → ' . trim($line);
                }
            }
        }

        return $hits;
    }

    /**
     * README names every retired type, and names it once.
     *
     * The vocabulary's retirement table is the fact; README's migration table
     * is the ADOPTER's copy of it. A copy that drifts is the worst kind here:
     * the reader upgrades, hits a fatal, opens README, and the name that
     * fataled is not in the table. So the table is read back OFF the class —
     * RETIRED is private, because nothing in the package may branch on it, and
     * reflection is how a test reads a fact it must not make public.
     *
     * Exactly 13 rows, and no fourteenth: a row for a name the class does not
     * retire is an instruction to rewrite a type that still works.
     */
    public function testReadmeNamesEveryRetiredTypeAndItsCanonicalName(): void
    {
        $retired = (new ReflectionClass(NTDST_FieldTypes::class))->getConstant('RETIRED');

        $this->assertIsArray($retired, 'NTDST_FieldTypes::RETIRED is the fact this table copies.');

        $rows = $this->fieldTypeRows();

        $this->assertCount(
            count($retired),
            $rows,
            'README\'s "Field types" table must have one row per retired name, and no more.',
        );

        foreach ($retired as $name => $canonical) {
            $this->assertArrayHasKey(
                $name,
                $rows,
                "NTDST_FieldTypes retires '{$name}', and README's migration table never names it.",
            );
            $this->assertSame(
                $canonical,
                $rows[$name],
                "README sends '{$name}' to '{$rows[$name]}'; the vocabulary sends it to '{$canonical}'.",
            );
        }
    }

    /**
     * The one storage change no rename can carry: `int` keeps its sign now.
     *
     * A consumer who only reads the migration table renames `integer` to `int`
     * and ships, believing nothing else moved. absint() is gone from that path,
     * so a field that used to store 500 for -500 stores -500. The overflow half
     * is scoped: a numeric STRING saturates at PHP_INT_MAX, and an oversized
     * float is PHP's undefined cast (FieldTypesTest pins both). All of it is
     * named, or the section is telling half a truth.
     */
    public function testTheFieldTypesSectionStatesTheIntSignChange(): void
    {
        $section = $this->fieldTypesSection();

        $this->assertStringContainsString(
            'absint',
            $section,
            'Name the function that left the path — that is what the reader greps for.',
        );
        $this->assertMatchesRegularExpression(
            '/negative/i',
            $section,
            '`int` stores negatives now; a migration table alone does not say so.',
        );
        $this->assertMatchesRegularExpression(
            '/numeric string[^.]*saturates at `PHP_INT_MAX`/',
            $section,
            'Saturation is the numeric-STRING promise; unscoped it reads as a promise for every value.',
        );
        $this->assertMatchesRegularExpression(
            '/float[^.]*(undefined|clamp)/i',
            $section,
            'A float past the maximum is PHP\'s undefined cast — say so where the promise is made.',
        );
    }

    /**
     * I-2 — the section says which retired WORDS are not field types.
     *
     * `integer`, `string` and `boolean` are JSON-Schema words as well as
     * retired type names. A consumer who greps for a retired name and renames
     * every hit breaks the schema in a `register_post_meta()` description, in a
     * `register_rest_route()` `args` block and in an ability schema — `int` and
     * `bool` are not JSON-Schema words. On stride that shape is 94 of the 95
     * remaining hits, so it is the section's rule, not a footnote.
     */
    public function testTheFieldTypesSectionSaysWhichRetiredNamesAreNotFieldTypes(): void
    {
        $section = $this->fieldTypesSection();

        $this->assertMatchesRegularExpression(
            '/rename only what the site DECLARES/i',
            $section,
            'The rename instruction has a scope, and the scope is the DECLARATION.',
        );
        $this->assertStringContainsString(
            'register_post_meta',
            $section,
            'Name the function whose schema keeps WordPress\'s words.',
        );
        $this->assertStringContainsString(
            '`args`',
            $section,
            'A `register_rest_route()` `args` schema is the second place a retired word is not a field type.',
        );
        $this->assertMatchesRegularExpression(
            '/JSON.Schema/i',
            $section,
            'Say which vocabulary those words belong to instead.',
        );
    }

    /**
     * The section stops at the next heading, and only the retired-names table
     * is counted.
     *
     * Both halves are read off a throwaway document, because the promise is
     * about a README that GROWS — a later 5.0.0 subsection with a two-column
     * table of backticked words must not be read as thirteen more migration
     * rows, and a `####` sub-head inside the block must not close it early.
     */
    public function testTheFieldTypesSectionEndsAtTheNextHeadingAndCountsOnlyTheRetiredTable(): void
    {
        $readme = implode("\n", [
            '### 5.0.0 — BREAKING',
            '',
            '**Field types — one registry, two names retired.**',
            '',
            '| Retired | Write instead |',
            '|---|---|',
            '| `integer` | `int` |',
            '| `boolean` | `bool` |',
            '',
            '#### A sub-head does not close the block',
            '',
            'absint left that path.',
            '',
            '| Type | Control |',
            '|---|---|',
            '| `html` | `wysiwyg` |',
            '',
            '**What the registry stores differently.**',
            '',
            '| Type | What changed |',
            '|---|---|',
            '| `date` | `Y-m-d` |',
            '',
            '### 4.4.2',
            '',
        ]);

        $section = $this->fieldTypesSection($readme);

        $this->assertStringContainsString(
            'absint left that path.',
            $section,
            'A `####` sub-head is inside the block, not the end of it.',
        );
        $this->assertStringNotContainsString(
            'What the registry stores differently',
            $section,
            'The next `**` heading closes the block — that is the bound this docblock claims.',
        );
        $this->assertSame(
            ['integer' => 'int', 'boolean' => 'bool'],
            $this->fieldTypeRows($readme),
            'Only the retired-names table is counted; a second two-column table in the block is not migration rows.',
        );
    }

    /**
     * README's "Field types" migration table, as retired => canonical.
     *
     * ANCHORED on that table's own header row, and it stops at the first line
     * that is not a row. The block holds other two-column tables — what the
     * registry stores, what a read gives back — and a row of two backticked
     * words in one of those is not an instruction to rename anything.
     *
     * @return array<string, string>
     */
    private function fieldTypeRows(string $readme = ''): array
    {
        $rows = [];
        $inTable = false;

        foreach (explode("\n", $this->fieldTypesSection($readme)) as $line) {
            $line = trim($line);

            if ($line === '| Retired | Write instead |') {
                $inTable = true;

                continue;
            }
            if (!$inTable || str_starts_with($line, '|---')) {
                continue;
            }
            if (!str_starts_with($line, '|')) {
                break;
            }
            if (preg_match('/^\| `([a-z_]+)` \| `([a-z_]+)` \|$/', $line, $m) === 1) {
                $rows[$m[1]] = $m[2];
            }
        }

        return $rows;
    }

    /**
     * The "Field types" block of README's `### 5.0.0` section.
     *
     * Anchored on the bold lead-in and closed by the next `###` or `**`
     * heading, so a later 5.0.0 subsection that happens to spell a type name
     * cannot be read as part of the migration record. Sub-heads INSIDE the
     * block are `####`, which is neither, so the block keeps its own structure.
     *
     * $readme is the document to read; the default is the shipped README. The
     * bound is a promise about a file that grows, so it is provable on a
     * throwaway document rather than only on today's text.
     */
    private function fieldTypesSection(string $readme = ''): string
    {
        $readme = $this->readmeText($readme);

        $this->assertMatchesRegularExpression(
            '/^\*\*Field types — .*$/m',
            $readme,
            'README\'s 5.0.0 section must lead its field-type block with `**Field types — …**`.',
        );

        $block = [];

        foreach (explode("\n", $readme) as $line) {
            if ($block === []) {
                if (str_starts_with($line, '**Field types — ')) {
                    $block[] = $line;
                }

                continue;
            }
            if (str_starts_with($line, '### ') || str_starts_with($line, '**')) {
                break;
            }

            $block[] = $line;
        }

        return implode("\n", $block);
    }

    public function testThePackageNeverClaimsToBeOlderThanWhatItShips(): void
    {
        // F2 — v3.0.0 shipped with `Version: 2.4.1` in its header while
        // api/Rest.php's _doing_it_wrong() call announced 3.0.0. WordPress
        // reports the header, so a consumer asking "what do I actually have"
        // got the previous release — and the v3 rename is a hard break with no
        // class_alias shims, which is exactly when that answer decides whether
        // a consumer boots or fatals.
        //
        // The rule is an ORDERING, not an equality. A version passed to
        // _doing_it_wrong() is a @since marker — the release that introduced
        // that notice — and it stays put while the package moves on, so
        // requiring the two to MATCH would fail the next major for no reason.
        // (It did: bumping the header to 4.0.0 failed the first version of this
        // test against a 3.0.0 marker that was perfectly correct.) What must
        // never happen is the reverse: a header claiming a release older than a
        // change the package already contains.
        $root = dirname(__DIR__, 2);

        preg_match('/^ \* Version: (.+)$/m', file_get_contents($root . '/ntdst-core.php'), $matched);
        $this->assertNotEmpty($matched, 'ntdst-core.php must carry a Version header — WP reads it.');
        $header = trim($matched[1]);

        $shipped = [];
        foreach ($this->shippedFiles() as $path) {
            preg_match_all("/_doing_it_wrong\(.*?'([0-9]+\.[0-9]+\.[0-9]+)'/s", file_get_contents($path), $m);
            foreach ($m[1] as $version) {
                $shipped[$version] = str_replace($root . '/', '', $path);
            }
        }

        $this->assertNotEmpty(
            $shipped,
            'No shipped file names the release it belongs to, so nothing here can check the header.',
        );

        foreach ($shipped as $version => $where) {
            $this->assertTrue(
                version_compare($header, $version, '>='),
                "The header says {$header}, but {$where} already ships a change marked {$version}.",
            );
        }
    }

    /**
     * Every PHP file this package actually ships.
     *
     * @return list<string>
     */
    private function shippedFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/specs/')) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    public function testEveryFileInTheLoaderListParsesAndDefinesItsSymbols(): void
    {
        // The durable half: walk ntdst-core.php's OWN require list and confirm each
        // file at least parses. A deleted symbol with a surviving caller is a
        // runtime error, not a parse error — the grep above is what catches that —
        // but this pins the list itself against a rename that moves a file and
        // forgets the loader (T01 renamed core/Router.php → core/Pages.php).
        $root = dirname(__DIR__, 2);
        $loader = file_get_contents($root . '/ntdst-core.php');

        preg_match_all("#require_once NTDST_PATH \. '([^']+)'#", $loader, $m);
        $this->assertNotEmpty($m[1], 'ntdst-core.php must require its files explicitly, not scan a directory.');

        foreach ($m[1] as $rel) {
            $this->assertFileExists(
                $root . $rel,
                "ntdst-core.php requires {$rel}, which does not exist — a rename moved a file and left the loader behind.",
            );
        }
    }

    /**
     * The prose lines inside `## Versions` that may spell a removed symbol.
     *
     * A migration table ROW is exempt by shape. These are the sentences that
     * are not rows and still have to name the name — each is here with the
     * reason, and the list is short on purpose: it is the price of narrowing a
     * whole-section exemption to a line-shaped one.
     */
    /**
     * A REST `args` schema line, which is WordPress's vocabulary and not a
     * field declaration.
     *
     * The 13 retired type names are ordinary JSON-Schema words, and a route
     * declares its arguments in exactly those words: `GET
     * /ntdst/v1/relation/search` writes `'type' => 'string'` because that is
     * what `register_rest_route()` reads, the same way api/FieldTypes.php's
     * publish column does. The distinction this pin already makes for the
     * registry's own leaf is made here for the route's args, and it is made by
     * CONTENT, never by path: a line is a REST-arg schema when it carries a
     * `'type' => '<name>'` AND one of WordPress's own arg keys on the SAME
     * line. A bare `'type' => 'string',` is still a field declaration and
     * still fires — which is why the two must be written on one line each, and
     * why an author who splits them gets the pin back.
     *
     * bin/guard.sh mirrors this rule; the shapes are the contract, not the
     * file they were found in.
     */
    private const REST_ARG_SCHEMA_LINE = "/(?=.*'type' *=> *'[a-z_]+')(?=.*'(?:sanitize_callback|validate_callback|items|enum)' *=>)/";

    private const VERSIONS_PROSE_ALLOWANCES = [
        '/one exception, and it is `signed_int`/'
            => 'the 3.x upgrade-order instruction has to name the retired type it tells that reader to KEEP until the bump',
        '/must KEEP `signed_int` until the bump/'
            => 'the second line of that same instruction',
        '/Fall back to `ntdst_service_\{slug\}_config`/'
            => 'the bridge instruction names the retired spelling a straddling consumer must KEEP reading — the core it still runs fires that one',
        '/^\$entry->schema; +\/\/ \[\x27type\x27 => \x27string\x27\] \x{2014} the REST leaf shape, or null$/u'
            => "a JSON-Schema leaf in a code sample (`['type' => 'string']`) — WordPress's vocabulary, not a field declaration. Anchored to the WHOLE line: `^\$entry->schema;` alone exempts anything a future sample appends to that statement",
    ];

    private function isExemptVersionsLine(string $line): bool
    {
        // ANY `|` row inside `## Versions`, not only a migration row: the
        // section is all changelog, every table in it answers "what was
        // removed and what replaces it", and a shape that tried to tell those
        // tables apart would be a second copy of their layout.
        if (preg_match('/^\s*\|/', $line) === 1) {
            return true;
        }

        foreach (array_keys(self::VERSIONS_PROSE_ALLOWANCES) as $allowed) {
            if (preg_match($allowed, trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every symbol 5.0.0 removed has a migration row an adopter can find.
     *
     * The fleet upgrades by hitting a fatal and opening README. The provider
     * above is the FACT — what the release deleted — and README is the
     * adopter's copy of it, so the copy is read back OFF the provider by
     * reflection rather than counted by hand: a row added to the provider and
     * not to README fails here, which is the only moment anybody is looking.
     *
     * Two tables answer, and which one is not this test's business: the
     * field-type renames have their own `| Retired | Write instead |` table
     * (a rename, not a removal), and everything else belongs in the core-trim
     * table. A symbol in neither is a name that fatals with no written answer.
     */
    public function testEveryRemovedFiveOhSymbolHasAMigrationRow(): void
    {
        $fieldTypes = $this->fieldTypeRows();
        $spans = $this->coreTrimSpans();

        $this->assertNotSame([], $spans, 'README must ship a `#### Core-trim` migration table.');

        $missing = [];

        foreach (self::removedSymbolProvider() as $key => $row) {
            if (($row[1] ?? '') !== '5.0.0') {
                continue;
            }

            $name = $this->migrationName($key, $row[0]);

            if (isset($fieldTypes[$name])) {
                continue;
            }

            foreach ($spans as $span) {
                if (str_contains($span, $name)) {
                    continue 2;
                }
            }

            $missing[] = $key . ' → ' . $name;
        }

        $this->assertSame(
            [],
            $missing,
            "5.0.0 removed these and README's migration tables name none of them. "
            . 'An adopter meets each one as a fatal with no written answer.',
        );
    }

    /**
     * The name an adopter looks up, from a provider row.
     *
     * The row carries a SWEEP pattern — `::surface(`, `$surface`, or a regex
     * over three declaration shapes — and a sweep pattern is not a name. The
     * regex rows are the retired TYPE names and nothing else, and their key
     * spells the type, which is why the key is asserted rather than parsed.
     */
    private function migrationName(string $key, string $symbol): string
    {
        if (str_starts_with($symbol, '/')) {
            $this->assertStringStartsWith(
                'retired type ',
                $key,
                "A regex row needs a name this test can look up; key it `retired type <name>` or teach migrationName().",
            );

            return substr($key, strlen('retired type '));
        }

        return trim($symbol, '$(:->');
    }

    /**
     * The document a README reader reads: the text a test hands over, or the
     * shipped file.
     *
     * Both section readers below opened with this same line, and a second copy
     * of it is a second answer to "which README is under test" — the question
     * the throwaway-document tests exist to control.
     */
    private function readmeText(string $readme = ''): string
    {
        return $readme !== '' ? $readme : file_get_contents(dirname(__DIR__, 2) . '/README.md');
    }

    /**
     * Every backticked span in the core-trim table's rows.
     *
     * Spans, not rows: one row answers for one removed symbol and may spell
     * several names in its "write instead" cell, and the question this test
     * asks is only whether the removed name is written down.
     *
     * @return list<string>
     */
    private function coreTrimSpans(): array
    {
        $readme = $this->readmeText();

        $spans = [];
        $inSection = false;

        foreach (explode("\n", $readme) as $line) {
            if (str_starts_with($line, '#### Core-trim')) {
                $inSection = true;

                continue;
            }
            if ($inSection && preg_match('/^#{1,3} /', $line) === 1) {
                break; // the next version closes the table; a `####` sub-head does not
            }
            if (!$inSection || !str_starts_with(ltrim($line), '|')) {
                continue;
            }
            if (preg_match_all('/`([^`]+)`/', $line, $matched) > 0) {
                $spans = array_merge($spans, $matched[1]);
            }
        }

        return $spans;
    }

}
